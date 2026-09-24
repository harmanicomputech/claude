<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Enums\ResultStatus;
use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class UssdTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->setUpUssd();
    }

    // Authentication --------------------------------------------------------

    public function test_unknown_phone_numbers_are_denied(): void
    {
        $this->ussd('', Agent::factory()->make(['phone_number' => '+2348099999999']))
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

    // Flow 1: Submit Result (EC8A) ------------------------------------------

    public function test_submit_result_steps(): void
    {
        $this->ussd('1')->assertContent('CON Enter PU Code:');
        $this->ussd('1*'.self::PU)->assertContent("CON Amachi Pry Sch\nAccredited Voters:");
        $this->ussd('1*'.self::PU.'*300')->assertContent('CON Votes for APC:');
        $this->ussd('1*'.self::PU.'*300*120')->assertContent('CON Votes for PDP:');
        $this->ussd('1*'.self::PU.'*300*120*80')->assertContent('CON Votes for LP:');
        $this->ussd('1*'.self::PU.'*300*120*80*20')->assertContent('CON Rejected Votes:');
        $this->ussd('1*'.self::PU.'*300*120*80*20*5')->assertContent(
            "CON Confirm:\nPU:110101001\nAmachi Pry Sch\nAcc:300 Rej:5\nAPC:120 PDP:80\nLP:20\nValid:220\n1.Submit 2.Edit 3.Cancel"
        );
        $this->ussd('1*'.self::PU.'*300*120*80*20*5*1')->assertContent('CON Enter PIN to submit:');

        $this->assertDatabaseCount('results', 0);
    }

    public function test_correct_pin_submits_the_result(): void
    {
        $response = $this->ussd($this->resultInput());

        $result = Result::sole();
        $response->assertContent("END Submitted ✔\nRef: {$result->reference}");

        $this->assertMatchesRegularExpression('/^RS\d{6}$/', $result->reference);
        $this->assertSame(ResultStatus::Accepted, $result->status);
        $this->assertSame(self::PU, $result->polling_unit_code);
        $this->assertSame(300, $result->accredited_voters);
        $this->assertSame(['APC' => 120, 'PDP' => 80, 'LP' => 20], $result->votesByParty());
        $this->assertSame(220, $result->total_valid_votes);
        $this->assertSame(5, $result->rejected_votes);
        $this->assertSame(225, $result->total_votes_cast);
        $this->assertTrue($result->agent->is($this->agent));

        Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->to === $this->agent->phone_number
            && $job->message === "Result received. Ref: {$result->reference}");
    }

    public function test_edit_restarts_result_entry(): void
    {
        $this->ussd('1*'.self::PU.'*300*120*80*20*5*2')->assertContent('CON Enter PU Code:');

        $this->ussd('1*'.self::PU.'*300*120*80*20*5*2*'.self::PU.'*400*200*100*50*10')
            ->assertSee("Acc:400 Rej:10\nAPC:200 PDP:100\nLP:50\nValid:350", false);
    }

    public function test_cancel_does_not_submit(): void
    {
        $this->ussd('1*'.self::PU.'*300*120*80*20*5*3')->assertContent('END Submission cancelled.');

        $this->assertDatabaseCount('results', 0);
    }

    public function test_non_numeric_input_is_rejected_and_can_be_retried(): void
    {
        $this->ussd('1*abc')->assertContent("CON Invalid input.\nEnter number only:");
        $this->ussd('1*'.self::PU.'*30x')->assertContent("CON Invalid input.\nEnter number only:");
        $this->ussd('1*'.self::PU.'*300*')->assertContent("CON Invalid input.\nEnter number only:");

        $this->ussd('1*abc*'.self::PU.'*30x*300*120*80*20*5')->assertSee('CON Confirm:', false);
    }

    public function test_badly_formed_pu_code_is_rejected(): void
    {
        $this->ussd('1*1')->assertContent("CON Invalid PU code.\nEnter PU Code:");
    }

    public function test_accredited_cannot_exceed_registered_voters(): void
    {
        $this->ussd('1*'.self::PU.'*1001')
            ->assertContent("CON Error:\nAccredited cannot exceed\nregistered voters (1000).\nRe-enter accredited:");

        $this->ussd('1*'.self::PU.'*1001*1000')->assertContent('CON Votes for APC:');
    }

    public function test_votes_cast_cannot_exceed_accredited(): void
    {
        $over = '1*'.self::PU.'*200*120*80*20*5';

        $this->ussd($over)->assertContent(
            "CON Error:\nVotes cast (225) exceed\naccredited (200).\n1. Re-enter accredited\n2. Start again"
        );

        $this->ussd("{$over}*1")->assertContent('CON Re-enter accredited:');
        $this->ussd("{$over}*1*220")->assertSee('Votes cast (225) exceed', false);
        $this->ussd("{$over}*1*300")->assertSee("CON Confirm:\nPU:110101001\nAmachi Pry Sch\nAcc:300", false);
        $this->ussd("{$over}*2")->assertContent('CON Enter PU Code:');
    }

    public function test_invalid_confirm_option_shows_confirmation_again(): void
    {
        $this->ussd('1*'.self::PU.'*300*120*80*20*5*7')->assertSee("CON Invalid input.\nConfirm:", false);
    }

    public function test_quick_code_goes_straight_to_confirmation(): void
    {
        // *XXX*1*110101001*300*120*80*20*5# arrives as this text.
        $this->ussd('1*'.self::PU.'*300*120*80*20*5')->assertSee('CON Confirm:', false);
    }

    public function test_sms_confirmation_can_be_disabled(): void
    {
        config(['ussd.sms_confirmation' => false]);

        $this->ussd($this->resultInput());

        Queue::assertNotPushed(SendSms::class);
    }

    // Auto-PU detection -----------------------------------------------------

    public function test_assigned_agent_skips_pu_entry(): void
    {
        $agent = Agent::factory()->assignedTo(self::PU)->create();

        $this->ussd('1', $agent)->assertContent("CON Amachi Pry Sch\nAccredited Voters:");
        $this->ussd('1*300*120*80*20*5*1*'.self::PIN, $agent)->assertSee('END Submitted', false);

        $this->assertSame(self::PU, Result::sole()->polling_unit_code);
    }

    public function test_assigned_agent_with_unregistered_pu_is_stopped(): void
    {
        $agent = Agent::factory()->assignedTo('119999999')->create();

        $this->ussd('1', $agent)->assertContent("END Your PU is not registered.\nContact coordinator.");
    }

    // Flow 2: Report Incident -----------------------------------------------

    public function test_report_incident_steps(): void
    {
        $this->ussd('2')->assertContent("CON Incident Type:\n1. Violence\n2. Vote Buying\n3. Delay\n4. Other");
        $this->ussd('2*2')->assertContent('CON Enter PU Code:');
        $this->ussd('2*2*'.self::PU)->assertContent('CON Short Note:');
        $this->ussd('2*2*'.self::PU.'*Cash at queue')
            ->assertContent("CON Confirm Incident:\nVote Buying\nAmachi Pry Sch\nPU:110101001\n1. Submit\n2. Cancel");

        $response = $this->ussd('2*2*'.self::PU.'*Cash at queue*1');

        $incident = Incident::sole();
        $response->assertContent("END Incident Logged ✔\nRef: {$incident->reference}");
        $this->assertMatchesRegularExpression('/^IN\d{6}$/', $incident->reference);
        $this->assertSame(IncidentType::VoteBuying, $incident->type);
        $this->assertSame('Cash at queue', $incident->note);
        $this->assertSame(self::PU, $incident->polling_unit_code);
    }

    public function test_incident_can_be_cancelled(): void
    {
        $this->ussd('2*1*'.self::PU.'*Fight*2')->assertContent('END Report cancelled.');

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_invalid_incident_type_is_rejected(): void
    {
        $this->ussd('2*5')->assertSee("CON Invalid input.\nIncident Type:", false);
    }

    public function test_incident_note_length_is_limited(): void
    {
        $this->ussd('2*1*'.self::PU.'*'.str_repeat('a', 31))
            ->assertContent("CON Invalid input.\nNote must be 1-30 characters:");

        $this->ussd('2*1*'.self::PU.'*'.str_repeat('a', 30))->assertSee('CON Confirm Incident:', false);
    }

    public function test_assigned_agent_skips_pu_entry_for_incidents(): void
    {
        $agent = Agent::factory()->assignedTo(self::PU)->create();

        $this->ussd('2*3', $agent)->assertContent('CON Short Note:');
        $this->ussd('2*3*Late start*1', $agent);

        $this->assertSame(self::PU, Incident::sole()->polling_unit_code);
    }

    // Flow 3: Confirm Presence ----------------------------------------------

    public function test_confirm_presence(): void
    {
        $this->ussd('3')->assertContent('CON Enter PU Code:');
        $this->ussd('3*'.self::PU)->assertContent("END Presence Confirmed ✔\nAmachi Pry Sch");

        $presence = Presence::sole();
        $this->assertSame(self::PU, $presence->polling_unit_code);
        $this->assertNotNull($presence->confirmed_at);

        $this->agent->refresh();
        $this->assertTrue($this->agent->is_active);
        $this->assertNotNull($this->agent->last_seen_at);
    }

    public function test_assigned_agent_confirms_presence_in_one_step(): void
    {
        $agent = Agent::factory()->assignedTo(self::PU)->create();

        $this->ussd('3', $agent)->assertContent("END Presence Confirmed ✔\nAmachi Pry Sch");
        $this->assertSame(self::PU, Presence::sole()->polling_unit_code);
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

    // Screen size -----------------------------------------------------------

    public function test_screens_fit_on_a_ussd_display(): void
    {
        // Five parties, a 12-digit code and a long name are worse than election day.
        config(['election.parties' => ['APC', 'PDP', 'LP', 'APGA', 'OTHERS']]);
        PollingUnit::factory()->create(['code' => '212026330999', 'name' => str_repeat('Very Long Polling Unit Name ', 3), 'registered_voters' => 999999]);
        Result::query()->delete();

        $big = '1*212026330999*999999*999999*999999*999999*999999*999999*999999';

        foreach (['', '1', '1*212026330999', $big, "{$big}*1", '2*1*212026330999*'.str_repeat('a', 30)] as $text) {
            $body = $this->ussd($text)->getContent();

            $this->assertLessThanOrEqual(182, mb_strlen($body), "Screen too long for input [{$text}]: {$body}");
        }
    }
}
