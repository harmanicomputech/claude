<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Mail\CorrectionRequested;
use App\Mail\ElectionSummary;
use App\Mail\IncidentReported;
use App\Mail\ResultSubmitted;
use App\Services\ElectionRecorder;
use App\Services\ElectionStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class NotificationEmailTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        config(['ussd.notify_emails' => ['coordinator@example.com', 'backup@example.com']]);

        $this->setUpUssd();
    }

    private function submit(int $apc = 120, bool $correction = false)
    {
        return app(ElectionRecorder::class)->submitResult($this->agent, self::PU, 300, ['APC' => $apc, 'PDP' => 80, 'LP' => 20], 5, $correction);
    }

    public function test_result_email_is_queued_to_every_recipient(): void
    {
        $result = $this->submit();

        Mail::assertQueued(ResultSubmitted::class, fn (ResultSubmitted $mail) => $mail->result->is($result)
            && $mail->hasTo('coordinator@example.com')
            && $mail->hasTo('backup@example.com'));
    }

    public function test_per_result_emails_can_be_turned_off(): void
    {
        config(['election.email_each_result' => false]);

        $this->submit();

        Mail::assertNotQueued(ResultSubmitted::class);
    }

    public function test_correction_requests_are_always_emailed(): void
    {
        config(['election.email_each_result' => false]);
        $this->submit();

        $correction = $this->submit(130, correction: true);

        Mail::assertQueued(CorrectionRequested::class, fn (CorrectionRequested $mail) => $mail->result->is($correction));
    }

    public function test_incident_email_is_queued(): void
    {
        $incident = app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::Violence, 'Fight');

        Mail::assertQueued(IncidentReported::class, fn (IncidentReported $mail) => $mail->incident->is($incident));
    }

    public function test_presence_does_not_send_email(): void
    {
        app(ElectionRecorder::class)->confirmPresence($this->agent, self::PU);

        Mail::assertNothingQueued();
    }

    public function test_no_email_without_recipients(): void
    {
        config(['ussd.notify_emails' => []]);

        $this->submit();

        Mail::assertNothingQueued();
    }

    public function test_emails_render_the_details(): void
    {
        $result = $this->submit(1200);
        $correction = $this->submit(1300, correction: true);
        $incident = app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::VoteBuying, 'Cash at queue');

        $resultMail = new ResultSubmitted($result);
        $resultMail->assertHasSubject("Result submitted: PU 110101001 ({$result->reference})");
        $resultMail->assertSeeInHtml($result->reference);
        $resultMail->assertSeeInHtml('Amachi Pry Sch');
        $resultMail->assertSeeInHtml('1,200');
        $resultMail->assertSeeInHtml('Ada Obi');

        $correctionMail = new CorrectionRequested($correction);
        $correctionMail->assertHasSubject("Correction needs review: PU 110101001 ({$correction->reference})");
        $correctionMail->assertSeeInHtml('1,300');
        $correctionMail->assertSeeInHtml('1,200');
        $correctionMail->assertSeeInHtml("result:review approve {$correction->reference}");

        $incidentMail = new IncidentReported($incident);
        $incidentMail->assertHasSubject("Incident: Vote Buying at PU 110101001 ({$incident->reference})");
        $incidentMail->assertSeeInHtml('Cash at queue');
    }

    public function test_summary_email_renders(): void
    {
        $this->submit();
        app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::Violence, 'Fight');

        $mail = new ElectionSummary(app(ElectionStats::class)->summary());

        $mail->assertSeeInHtml('1 / 1');
        $mail->assertSeeInHtml('Abakaliki');
        $mail->assertSeeInHtml('Violence');
        $mail->assertSeeInHtml('APC');
        $mail->assertSeeInHtml('Francis Ogbonna Nwifuru');
        $this->assertStringContainsString('1/1 PUs reported', $mail->envelope()->subject);
    }

    public function test_summary_command_queues_the_email(): void
    {
        $this->artisan('election:summary')->assertSuccessful();

        Mail::assertQueued(ElectionSummary::class, fn (ElectionSummary $mail) => $mail->hasTo('coordinator@example.com'));
    }
}
