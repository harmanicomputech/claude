<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Mail\IncidentReported;
use App\Mail\ResultSubmitted;
use App\Models\Agent;
use App\Services\ElectionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        config(['ussd.notify_emails' => ['coordinator@example.com', 'backup@example.com']]);

        $this->agent = Agent::factory()->create(['name' => 'Ada Obi']);
    }

    public function test_result_email_is_queued_to_every_recipient(): void
    {
        $result = app(ElectionRecorder::class)->submitResult($this->agent, '02345', 120, 300);

        Mail::assertQueued(ResultSubmitted::class, fn (ResultSubmitted $mail) => $mail->result->is($result)
            && $mail->hasTo('coordinator@example.com')
            && $mail->hasTo('backup@example.com'));
    }

    public function test_incident_email_is_queued(): void
    {
        $incident = app(ElectionRecorder::class)->logIncident($this->agent, '02345', IncidentType::Violence, 'Fight');

        Mail::assertQueued(IncidentReported::class, fn (IncidentReported $mail) => $mail->incident->is($incident));
    }

    public function test_presence_does_not_send_email(): void
    {
        app(ElectionRecorder::class)->confirmPresence($this->agent, '02345');

        Mail::assertNothingQueued();
    }

    public function test_no_email_without_recipients(): void
    {
        config(['ussd.notify_emails' => []]);

        app(ElectionRecorder::class)->submitResult($this->agent, '02345', 120, 300);

        Mail::assertNothingQueued();
    }

    public function test_emails_render_the_details(): void
    {
        $recorder = app(ElectionRecorder::class);
        $result = $recorder->submitResult($this->agent, '02345', 1200, 3000);
        $incident = $recorder->logIncident($this->agent, '02345', IncidentType::VoteBuying, 'Cash at queue');

        $resultMail = new ResultSubmitted($result);
        $resultMail->assertHasSubject("Result submitted: PU 02345 ({$result->reference})");
        $resultMail->assertSeeInHtml($result->reference);
        $resultMail->assertSeeInHtml('1,200');
        $resultMail->assertSeeInHtml('3,000');
        $resultMail->assertSeeInHtml('Ada Obi');

        $incidentMail = new IncidentReported($incident);
        $incidentMail->assertHasSubject("Incident: Vote Buying at PU 02345 ({$incident->reference})");
        $incidentMail->assertSeeInHtml('Cash at queue');
    }
}
