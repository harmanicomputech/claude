<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the background work (queued SMS/emails/dashboard deliveries and the
 * scheduled tasks) without relying on a per-minute cron, which many shared
 * hosts forbid. It is triggered from three places, all safe to overlap:
 *
 *  - after the response of ordinary requests (USSD callbacks, admin pages,
 *    web-app API calls): see RunBackgroundWork middleware
 *  - an external pinger calling /cron/{token} (e.g. cron-job.org, every minute)
 *  - the host's cron running `php artisan election:tick` (hourly is fine)
 *
 * Scheduled tasks remember the slot they last ran for, so a task never runs
 * twice however often the runner is triggered.
 */
class BackgroundRunner
{
    public const HEARTBEAT_KEY = 'election-shield:runner-heartbeat';

    /** Don't start another run from web requests more often than this. */
    private const MIN_SECONDS_BETWEEN_WEB_RUNS = 15;

    public function __construct(private ElectionCalendar $calendar) {}

    public static function token(): string
    {
        return substr(hash_hmac('sha256', 'election-shield-runner', (string) config('app.key')), 0, 32);
    }

    public static function enabled(): bool
    {
        return (bool) config('election.background_runner');
    }

    /**
     * Whether PHP can send the response to the client before we keep working
     * (PHP-FPM or LiteSpeed). Without it the caller would wait for the work.
     */
    public static function canWorkAfterResponse(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /**
     * Run after a web request, at most every few seconds.
     */
    public function runAfterWebRequest(): void
    {
        if (! self::enabled() || ! Cache::add('election-shield:runner-web-throttle', true, self::MIN_SECONDS_BETWEEN_WEB_RUNS)) {
            return;
        }

        $this->run(queueSeconds: 20, source: 'web');
    }

    /**
     * One pass: due scheduled tasks, then the queue for up to $queueSeconds.
     * Only one pass runs at a time.
     */
    public function run(int $queueSeconds = 20, string $source = 'cron'): bool
    {
        $lock = Cache::lock('election-shield:runner', $queueSeconds + 60);

        if (! $lock->get()) {
            return false;
        }

        try {
            @set_time_limit($queueSeconds + 40);
            ignore_user_abort(true);

            Cache::forever(self::HEARTBEAT_KEY, ['at' => now()->toIso8601String(), 'source' => $source]);

            $this->runDueTasks();

            Artisan::call('queue:work', [
                '--queue' => Queues::WORKER_ORDER,
                '--stop-when-empty' => true,
                '--max-time' => $queueSeconds,
                '--tries' => 3,
            ]);
        } catch (Throwable $e) {
            report($e);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * @return array{at: string, source: string}|null
     */
    public static function lastRun(): ?array
    {
        try {
            return Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable) {
            return null;
        }
    }

    private function runDueTasks(): void
    {
        $now = now();

        // Dashboard resend safety net: every 15 minutes.
        $this->once('dashboard-sync', $now->format('Y-m-d H:').str_pad((string) (intdiv((int) $now->format('i'), 15) * 15), 2, '0', STR_PAD_LEFT), fn () => Artisan::call('dashboard:sync'));

        // Hourly summary email while the election is running.
        if (config('election.hourly_summary') && $this->calendar->inReportingPeriod()) {
            $this->once('hourly-summary', $now->copy()->timezone($this->calendar->timezone())->format('Y-m-d H'), fn () => Artisan::call('election:summary'));
        }

        // Election-day reminders, once each, any time after their set time.
        if ($this->calendar->isElectionDay()) {
            $localNow = $now->copy()->timezone($this->calendar->timezone());

            foreach (['presence' => 'presence_reminder_at', 'results' => 'results_reminder_at'] as $type => $setting) {
                $at = config("election.{$setting}");

                if (filled($at) && $localNow->format('H:i') >= $at) {
                    $this->once("reminder-{$type}", $localNow->toDateString(), fn () => Artisan::call('election:remind', ['type' => $type]));
                }
            }
        }
    }

    /**
     * Run $task unless it already ran for this $slot (persisted in settings,
     * so a cache clear can't make reminders go out twice).
     */
    private function once(string $name, string $slot, callable $task): void
    {
        $key = "runner.{$name}";

        if (Settings::get($key) === $slot) {
            return;
        }

        Settings::set($key, $slot);

        try {
            $task();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
