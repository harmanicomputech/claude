<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\Coordinator;
use App\Models\DashboardDelivery;
use App\Models\PollingUnit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Set-up checklist for the admin console. Every check is defensive: the
 * page must still render before the database is configured or migrated.
 */
class SystemStatus
{
    public const HEARTBEAT_KEY = 'election-shield:cron-heartbeat';

    public function __construct(private ElectionCalendar $calendar) {}

    /**
     * @return list<array{label: string, ok: ?bool, detail: string}>
     */
    public function checks(): array
    {
        $database = $this->databaseReachable();
        $migrated = $database && $this->pendingMigrations() === 0;

        return [
            $this->check('PHP version', version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION.' (8.3 or newer needed)'),
            $this->check('HTTPS', request()->isSecure(), request()->isSecure() ? 'On' : 'Not in use: turn on SSL (Let\'s Encrypt) and open this page with https://. Africa\'s Talking needs an https:// callback URL.'),
            $this->check('Debug mode off', ! config('app.debug'), config('app.debug') ? 'Set APP_DEBUG=false before going live' : 'Off'),
            $this->check('Database connection', $database, $database ? config('database.connections.'.config('database.default').'.database') : 'Cannot connect: check DB_* in .env'),
            $this->check('Database tables', $database ? $migrated : null, ! $database ? '—' : ($migrated ? 'Up to date' : $this->pendingMigrations().' pending: press "Set up / update database"')),
            $this->check('Polling unit register', $migrated ? PollingUnit::count() > 0 : null, $migrated ? number_format(PollingUnit::count()).' polling units' : '—'),
            $this->check('Agents', $migrated ? Agent::count() > 0 : null, $migrated ? number_format(Agent::count()).' registered' : '—'),
            $this->check('Coordinators (urgent alerts)', $migrated ? Coordinator::count() > 0 : null, $migrated ? number_format(Coordinator::count()).' registered' : '—'),
            $this->check('Email', config('mail.default') !== 'log' && self::isConfigured(config('mail.mailers.smtp.host')) && filled(config('ussd.notify_emails')), config('mail.default').' via '.(config('mail.mailers.smtp.host') ?? '—').'; to: '.(implode(', ', config('ussd.notify_emails')) ?: 'NOTIFY_EMAILS not set')),
            $this->check('Africa\'s Talking SMS', self::isConfigured(config('services.africastalking.api_key')), self::isConfigured(config('services.africastalking.api_key')) ? 'Username: '.config('services.africastalking.username') : 'AFRICASTALKING_API_KEY not set (SMS are only logged)'),
            $this->check('USSD callback secret', filled(config('ussd.callback_secret')), filled(config('ussd.callback_secret')) ? 'Set' : 'USSD_CALLBACK_SECRET not set: anyone could post to the callback'),
            $this->check('Cron (background jobs)', $this->cronRunning(), $this->cronDetail()),
            $this->check('Queue', $migrated ? $this->queueHealthy() : null, $migrated ? $this->queueDetail() : '—'),
            $this->check('Submission windows', config('election.enforce_windows') ? true : null, config('election.enforce_windows')
                ? 'On: presence '.$this->calendar->format($this->calendar->presenceOpensAt()).', results '.$this->calendar->format($this->calendar->resultsOpenAt())
                : 'Off (testing): turn on before election day'),
            $this->check('Results dashboard', filled(config('services.dashboard.url')) ? ($migrated ? DashboardDelivery::whereNull('delivered_at')->where('created_at', '<', now()->subMinutes(30))->doesntExist() : null) : null,
                filled(config('services.dashboard.url'))
                    ? ($migrated ? number_format(DashboardDelivery::whereNull('delivered_at')->count()).' undelivered event(s)' : '—')
                    : 'Not configured yet'),
        ];
    }

    public function callbackUrl(): string
    {
        $secret = config('ussd.callback_secret');

        return url('/api/ussd'.(filled($secret) ? '/'.$secret : ''));
    }

    public function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function pendingMigrations(): int
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return count($migrator->getMigrationFiles(database_path('migrations')));
            }

            $files = $migrator->getMigrationFiles(database_path('migrations'));

            return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return -1;
        }
    }

    public function isReady(): bool
    {
        return $this->databaseReachable() && $this->pendingMigrations() === 0;
    }

    /**
     * Set, and not the "CHANGE-ME" placeholder from the deploy template.
     */
    public static function isConfigured(mixed $value): bool
    {
        return filled($value) && ! str_contains((string) $value, 'CHANGE-ME');
    }

    /**
     * @return array{label: string, ok: ?bool, detail: string}
     */
    private function check(string $label, ?bool $ok, string $detail): array
    {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    private function lastHeartbeat(): ?Carbon
    {
        try {
            $value = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable) {
            return null;
        }

        return $value ? Carbon::parse($value) : null;
    }

    private function cronRunning(): bool
    {
        return $this->lastHeartbeat()?->greaterThan(now()->subMinutes(3)) ?? false;
    }

    private function cronDetail(): string
    {
        $last = $this->lastHeartbeat();

        return $last
            ? 'Last run '.$last->diffForHumans()
            : 'Never run: add the cron job (see set-up steps)';
    }

    private function queueHealthy(): bool
    {
        try {
            $oldest = DB::table('jobs')->min('created_at');
        } catch (Throwable) {
            return false;
        }

        return $oldest === null || (int) $oldest > now()->subMinutes(5)->getTimestamp();
    }

    private function queueDetail(): string
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return 'Queue tables missing';
        }

        return "{$pending} waiting, {$failed} failed";
    }
}
