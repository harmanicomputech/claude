<?php

namespace Tests\Feature;

use App\Jobs\SendSms;
use App\Support\BackgroundRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class BackgroundRunnerTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'election.background_runner' => true,
            'queue.default' => 'database',
            'mail.default' => 'array',
            'services.africastalking.api_key' => null,
            'ussd.notify_emails' => ['coordinator@example.com'],
            'election.date' => '2027-02-06',
            'election.results_close_at' => '2027-02-08 23:59',
        ]);
        $this->setUpUssd();
    }

    private function runner(): BackgroundRunner
    {
        return app(BackgroundRunner::class);
    }

    public function test_run_sends_waiting_jobs(): void
    {
        SendSms::dispatch('+2348011111111', 'Hello');
        $this->assertSame(1, DB::table('jobs')->count());

        $this->assertTrue($this->runner()->run(queueSeconds: 5));

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('cron', BackgroundRunner::lastRun()['source']);
    }

    public function test_hourly_summary_goes_out_once_per_hour(): void
    {
        $this->travelTo(Carbon::parse('2027-02-06 10:05', 'Africa/Lagos'));
        $this->runner()->run(queueSeconds: 5);
        $this->runner()->run(queueSeconds: 5);

        $this->travelTo(Carbon::parse('2027-02-06 10:55', 'Africa/Lagos'));
        $this->runner()->run(queueSeconds: 5);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());

        $this->travelTo(Carbon::parse('2027-02-06 11:01', 'Africa/Lagos'));
        $this->runner()->run(queueSeconds: 5);
        $this->assertCount(2, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_reminders_go_once_even_if_the_runner_was_late(): void
    {
        Queue::fake(); // capture the reminder SMS instead of sending them

        $this->travelTo(Carbon::parse('2027-02-06 07:59', 'Africa/Lagos'));
        $this->runner()->run(queueSeconds: 1);
        Queue::assertNotPushed(SendSms::class);

        // Nothing ran at exactly 08:00 (hourly cron, quiet pinger): it still goes at 08:40.
        $this->travelTo(Carbon::parse('2027-02-06 08:40', 'Africa/Lagos'));
        $this->runner()->run(queueSeconds: 1);
        Queue::assertPushedOn('bulk', SendSms::class, fn (SendSms $job) => str_contains($job->message, 'confirm you are at your PU'));
        Queue::assertPushed(SendSms::class, 1);

        $this->runner()->run(queueSeconds: 1);
        Queue::assertPushed(SendSms::class, 1); // not repeated
    }

    public function test_no_reminders_or_summary_outside_election_day(): void
    {
        $this->travelTo(Carbon::parse('2026-12-01 18:00', 'Africa/Lagos'));
        $this->runner()->run(queueSeconds: 5);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_pinger_url_needs_the_token(): void
    {
        $this->get('/cron/wrong')->assertNotFound();

        SendSms::dispatch('+2348011111111', 'Hello');

        $this->get('/cron/'.BackgroundRunner::token())->assertOk()->assertSee('OK');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('pinger', BackgroundRunner::lastRun()['source']);
    }

    public function test_only_one_run_at_a_time(): void
    {
        $lock = cache()->lock('election-shield:runner', 60);
        $lock->get();

        $this->assertFalse($this->runner()->run(queueSeconds: 5));

        $lock->release();
    }

    public function test_disabled_runner_does_nothing_after_web_requests(): void
    {
        config(['election.background_runner' => false]);
        SendSms::dispatch('+2348011111111', 'Hello');

        $this->runner()->runAfterWebRequest();

        $this->assertSame(1, DB::table('jobs')->count());
    }
}
