<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Jobs\PushToDashboard;
use App\Jobs\SendSms;
use App\Mail\ElectionSummary;
use App\Mail\IncidentReported;
use App\Mail\ResultSubmitted;
use App\Models\Coordinator;
use App\Services\ElectionRecorder;
use App\Services\ElectionReminders;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class QueuePriorityTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUssd();
        config(['ussd.notify_emails' => ['coordinator@example.com'], 'services.dashboard.url' => 'https://dashboard.test']);
        Coordinator::create(['name' => 'Lead', 'phone_number' => '08020000001']);
    }

    public function test_each_kind_of_job_goes_to_its_queue(): void
    {
        Queue::fake();
        Mail::fake();

        $recorder = app(ElectionRecorder::class);
        $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 1, 'PDP' => 1, 'LP' => 1], 0);
        $recorder->logIncident($this->agent, self::PU, IncidentType::Violence, 'Fight');

        Queue::assertPushedOn(Queues::HIGH, SendSms::class, fn (SendSms $job) => str_contains($job->message, 'Result received'));
        Queue::assertPushedOn(Queues::HIGH, SendSms::class, fn (SendSms $job) => str_contains($job->message, 'VIOLENCE ALERT'));
        Queue::assertPushedOn(Queues::DEFAULT, PushToDashboard::class);
        Mail::assertQueued(ResultSubmitted::class, fn ($mail) => $mail->queue === Queues::MAIL);
        Mail::assertQueued(IncidentReported::class, fn ($mail) => $mail->queue === Queues::MAIL);

        Queue::fake();
        app(ElectionReminders::class)->presence();
        Queue::assertPushedOn(Queues::BULK, SendSms::class);
    }

    public function test_worker_sends_alerts_before_waiting_emails(): void
    {
        config(['queue.default' => 'database', 'services.africastalking.api_key' => null, 'mail.default' => 'array']);

        // Thousands of emails queued first...
        foreach (range(1, 5) as $i) {
            Mail::to('coordinator@example.com')->queue(new ElectionSummary(['generated_at' => now()->toIso8601String(), 'results' => ['polling_units' => 0], 'polling_units' => 0]));
        }
        // ...then a violence alert.
        SendSms::dispatch('+2348020000001', 'VIOLENCE ALERT');

        $this->artisan('queue:work', ['--queue' => Queues::WORKER_ORDER, '--once' => true]);

        $this->assertSame(0, DB::table('jobs')->where('queue', Queues::HIGH)->count(), 'The alert should be sent first.');
        $this->assertSame(5, DB::table('jobs')->where('queue', Queues::MAIL)->count());
    }
}
