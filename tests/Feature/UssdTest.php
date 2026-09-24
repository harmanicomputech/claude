<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\Incident;
use App\Models\Presence;
use App\Models\Result;
use App\Services\ElectionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UssdTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->agent = Agent::factory()->create(['phone_number' => '+2348011111111']);
    }

    private function ussd(string $text, ?string $phoneNumber = null): TestResponse
    {
        return $this->post('/api/ussd', [
            'sessionId' => 'ATUid_test',
            'serviceCode' => '*384*123#',
            'phoneNumber' => $phoneNumber ?? $this->agent->phone_number,
            'text' => $text,
        ])->assertOk();
    }

    // Authentication --------------------------------------------------------

    public function test_unknown_phone_numbers_are_denied(): void
    {
        $this->ussd('', '+2348099999999')
            ->assertContent("END Access denied.\nContact coordinator.");
    }

    public function test_registered_agent_sees_main_menu(): void
    {
        $this->ussd('')->assertContent(
            "CON Election Shield\n1. Submit Result\n2. Report Incident\n3. Confirm Presence\n4. Instructions\n5. Exit"
        );
    }

    public function test_invalid_main_menu_option_shows_menu_again(): void
    {
        $this->ussd('9')->assertSee("CON Invalid input.\nElection Shield", false);
    }

    // Flow 1: Submit Result -------------------------------------------------

    public function test_submit_result_steps(): void
    {
        $this->ussd('1')->assertContent('CON Enter PU Code:');
        $this->ussd('1*02345')->assertContent('CON Votes for Candidate:');
        $this->ussd('1*02345*120')->assertContent('CON Total Votes Cast:');
        $this->ussd('1*02345*120*300')->assertContent(
            "CON Confirm:\nPU:02345\nVotes:120\nTotal:300\n\n1. Submit\n2. Edit\n3. Cancel"
        );

        $this->assertDatabaseCount('results', 0);
    }

    public function test_confirming_submits_the_result(): void
    {
        $response = $this->ussd('1*02345*120*300*1');

        $result = Result::sole();
        $response->assertContent("END Submitted ✔\nRef: {$result->reference}");

        $this->assertMatchesRegularExpression('/^RS\d{6}$/', $result->reference);
        $this->assertSame('02345', $result->polling_unit_code);
        $this->assertSame(120, $result->candidate_votes);
        $this->assertSame(300, $result->total_votes);
        $this->assertTrue($result->agent->is($this->agent));

        Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->to === $this->agent->phone_number
            && $job->message === "Result received. Ref: {$result->reference}");
    }

    public function test_edit_restarts_result_entry(): void
    {
        $this->ussd('1*02345*120*300*2')->assertContent('CON Enter PU Code:');

        $this->ussd('1*02345*120*300*2*02346*100*250')->assertSee("PU:02346\nVotes:100\nTotal:250", false);

        $this->ussd('1*02345*120*300*2*02346*100*250*1')->assertSee('END Submitted', false);
        $this->assertSame('02346', Result::sole()->polling_unit_code);
    }

    public function test_cancel_does_not_submit(): void
    {
        $this->ussd('1*02345*120*300*3')->assertContent('END Submission cancelled.');

        $this->assertDatabaseCount('results', 0);
    }

    public function test_non_numeric_input_is_rejected(): void
    {
        $this->ussd('1*abc')->assertContent("CON Invalid input.\nEnter number only:");
        $this->ussd('1*02345*12x')->assertContent("CON Invalid input.\nEnter number only:");
        $this->ussd('1*02345*120*')->assertContent("CON Invalid input.\nEnter number only:");

        // The agent can carry on after an invalid entry.
        $this->ussd('1*abc*02345*12x*120*300')->assertSee("PU:02345\nVotes:120\nTotal:300", false);
    }

    public function test_badly_formed_pu_code_is_rejected(): void
    {
        $this->ussd('1*1')->assertContent("CON Invalid PU code.\nEnter PU Code:");
    }

    public function test_votes_cannot_exceed_total(): void
    {
        $this->ussd('1*02345*120*100')
            ->assertContent("CON Error:\nVotes cannot exceed total.\nRe-enter total:");

        $this->ussd('1*02345*120*100*300')->assertSee("Votes:120\nTotal:300", false);
    }

    public function test_invalid_confirm_option_shows_confirmation_again(): void
    {
        $this->ussd('1*02345*120*300*7')->assertSee("CON Invalid input.\nConfirm:", false);
    }

    public function test_duplicate_submission_is_blocked_at_pu_entry(): void
    {
        $this->ussd('1*02345*120*300*1');

        $other = Agent::factory()->create();

        $this->ussd('1*02345', $other->phone_number)
            ->assertContent('END Result already submitted for this PU.');
        $this->assertDatabaseCount('results', 1);
    }

    public function test_duplicate_submission_is_blocked_at_confirmation(): void
    {
        // Another agent submits the PU while this agent is on the confirm screen.
        Result::create([
            'reference' => 'RS000001',
            'agent_id' => Agent::factory()->create()->id,
            'polling_unit_code' => '02345',
            'candidate_votes' => 1,
            'total_votes' => 2,
        ]);

        $recorder = app(ElectionRecorder::class);

        $this->assertNull($recorder->submitResult($this->agent, '02345', 120, 300));
        $this->assertDatabaseCount('results', 1);
    }

    public function test_quick_code_goes_straight_to_confirmation(): void
    {
        // *XXX*1*02345*120*300# arrives as text "1*02345*120*300".
        $this->ussd('1*02345*120*300')->assertSee('CON Confirm:', false);
    }

    public function test_sms_confirmation_can_be_disabled(): void
    {
        config(['ussd.sms_confirmation' => false]);

        $this->ussd('1*02345*120*300*1');

        Queue::assertNothingPushed();
    }

    // Auto-PU detection -----------------------------------------------------

    public function test_assigned_agent_skips_pu_entry(): void
    {
        $agent = Agent::factory()->assignedTo('07777')->create();

        $this->ussd('1', $agent->phone_number)->assertContent('CON Votes for Candidate:');
        $this->ussd('1*120*300', $agent->phone_number)->assertSee("PU:07777\nVotes:120", false);
        $this->ussd('1*120*300*1', $agent->phone_number)->assertSee('END Submitted', false);

        $this->assertSame('07777', Result::sole()->polling_unit_code);
    }

    public function test_assigned_agent_is_told_when_their_pu_already_has_a_result(): void
    {
        $agent = Agent::factory()->assignedTo('07777')->create();
        $this->ussd('1*120*300*1', $agent->phone_number);

        $this->ussd('1', $agent->phone_number)->assertContent('END Result already submitted for this PU.');
    }

    // Flow 2: Report Incident -----------------------------------------------

    public function test_report_incident_steps(): void
    {
        $this->ussd('2')->assertContent("CON Incident Type:\n1. Violence\n2. Vote Buying\n3. Delay\n4. Other");
        $this->ussd('2*2')->assertContent('CON Enter PU Code:');
        $this->ussd('2*2*02345')->assertContent('CON Short Note:');
        $this->ussd('2*2*02345*Cash at queue')
            ->assertContent("CON Confirm Incident:\nVote Buying\nPU:02345\n1. Submit\n2. Cancel");

        $response = $this->ussd('2*2*02345*Cash at queue*1');

        $incident = Incident::sole();
        $response->assertContent("END Incident Logged ✔\nRef: {$incident->reference}");
        $this->assertMatchesRegularExpression('/^IN\d{6}$/', $incident->reference);
        $this->assertSame(IncidentType::VoteBuying, $incident->type);
        $this->assertSame('Cash at queue', $incident->note);
        $this->assertSame('02345', $incident->polling_unit_code);
    }

    public function test_incident_can_be_cancelled(): void
    {
        $this->ussd('2*1*02345*Fight*2')->assertContent('END Report cancelled.');

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_invalid_incident_type_is_rejected(): void
    {
        $this->ussd('2*5')->assertSee("CON Invalid input.\nIncident Type:", false);
    }

    public function test_incident_note_length_is_limited(): void
    {
        $this->ussd('2*1*02345*'.str_repeat('a', 31))
            ->assertContent("CON Invalid input.\nNote must be 1-30 characters:");

        $this->ussd('2*1*02345*'.str_repeat('a', 30))->assertSee('CON Confirm Incident:', false);
    }

    public function test_assigned_agent_skips_pu_entry_for_incidents(): void
    {
        $agent = Agent::factory()->assignedTo('07777')->create();

        $this->ussd('2*3', $agent->phone_number)->assertContent('CON Short Note:');
        $this->ussd('2*3*Late start*1', $agent->phone_number);

        $this->assertSame('07777', Incident::sole()->polling_unit_code);
    }

    // Flow 3: Confirm Presence ----------------------------------------------

    public function test_confirm_presence(): void
    {
        $this->ussd('3')->assertContent('CON Enter PU Code:');
        $this->ussd('3*02345')->assertContent('END Presence Confirmed ✔');

        $presence = Presence::sole();
        $this->assertSame('02345', $presence->polling_unit_code);
        $this->assertNotNull($presence->confirmed_at);

        $this->agent->refresh();
        $this->assertTrue($this->agent->is_active);
        $this->assertNotNull($this->agent->last_seen_at);
    }

    public function test_assigned_agent_confirms_presence_in_one_step(): void
    {
        $agent = Agent::factory()->assignedTo('07777')->create();

        $this->ussd('3', $agent->phone_number)->assertContent('END Presence Confirmed ✔');
        $this->assertSame('07777', Presence::sole()->polling_unit_code);
    }

    // Flows 4 & 5 -----------------------------------------------------------

    public function test_instructions(): void
    {
        $this->ussd('4')->assertContent(
            "END Stay at PU.\nSubmit results after counting.\nReport any issue immediately."
        );
    }

    public function test_exit(): void
    {
        $this->ussd('5')->assertContent('END Thank you');
    }
}
