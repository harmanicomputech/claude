<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Enums\MaterialStatus;
use App\Enums\ResultStatus;
use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use App\Services\ElectionStats;
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

    public function test_unknown_phone_numbers_get_the_public_menu(): void
    {
        $this->ussd('', Agent::factory()->make(['phone_number' => '+2348099999999']))
            ->assertContent("CON Election Shield\n1. Report Incident\n2. How can you help?\n3. Election info\n4. Exit");
    }

    public function test_registered_agent_sees_main_menu(): void
    {
        $this->ussd('')->assertContent(
            "CON Election Shield\n1. Presence\n2. Material Status\n3. Instructions\n4. Report Incident\n5. Submit Result\n6. How can you help?\n7. Exit"
        );
    }

    public function test_invalid_main_menu_option_shows_menu_again(): void
    {
        $this->ussd('9')->assertSee("CON Invalid input.\nElection Shield", false);
    }

    // Flow 1: Submit Result (EC8A) ------------------------------------------

    public function test_submit_result_steps(): void
    {
        $this->ussd('5')->assertContent("CON Your PU's LGA:\n1.Abakaliki");
        $this->ussd('5*1')->assertContent("CON Abakaliki: choose ward\n1.Amachi Ward");
        $this->ussd('5*1*1')->assertContent("CON Amachi Ward\nPU number: last 3 digits\nof the PU code (e.g. 007):");
        $this->ussd('5*'.self::PU_ENTRY)->assertContent("CON Amachi Pry Sch\nAccredited Voters:");
        $this->ussd('5*'.self::PU_ENTRY.'*300')->assertContent('CON Votes for APC:');
        $this->ussd('5*'.self::PU_ENTRY.'*300*120')->assertContent('CON Votes for PDP:');
        $this->ussd('5*'.self::PU_ENTRY.'*300*120*80')->assertContent('CON Votes for LP:');
        $this->ussd('5*'.self::PU_ENTRY.'*300*120*80*20')->assertContent('CON Rejected Votes:');
        $this->ussd('5*'.self::PU_ENTRY.'*300*120*80*20*5')->assertContent(
            "CON Confirm:\nPU:110101001\nAmachi Pry Sch\nAcc:300 Rej:5\nAPC:120 PDP:80\nLP:20\nValid:220\n1.Submit 2.Edit 3.Cancel"
        );
        $this->ussd('5*'.self::PU_ENTRY.'*300*120*80*20*5*1')->assertContent('CON Enter PIN to submit:');

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
        // Edit keeps the chosen PU and starts the figures again.
        $this->ussd('5*'.self::PU_ENTRY.'*300*120*80*20*5*2')->assertContent("CON Amachi Pry Sch\nAccredited Voters:");

        $this->ussd('5*'.self::PU_ENTRY.'*300*120*80*20*5*2*400*200*100*50*10')
            ->assertSee("Acc:400 Rej:10\nAPC:200 PDP:100\nLP:50\nValid:350", false);
    }

    public function test_cancel_does_not_submit(): void
    {
        $this->ussd('5*'.self::PU_ENTRY.'*300*120*80*20*5*3')->assertContent('END Submission cancelled.');

        $this->assertDatabaseCount('results', 0);
    }

    public function test_non_numeric_input_is_rejected_and_can_be_retried(): void
    {
        $this->ussd('5*'.self::PU_ENTRY.'*abc')->assertContent("CON Invalid input.\nEnter number only:");
        $this->ussd('5*'.self::PU_ENTRY.'*30x')->assertContent("CON Invalid input.\nEnter number only:");
        $this->ussd('5*'.self::PU_ENTRY.'*300*')->assertContent("CON Invalid input.\nEnter number only:");

        $this->ussd('5*'.self::PU_ENTRY.'*abc*30x*300*120*80*20*5')->assertSee('CON Confirm:', false);
    }

    public function test_badly_formed_pu_number_is_rejected(): void
    {
        $prompt = "Amachi Ward\nPU number: last 3 digits\nof the PU code (e.g. 007):";

        $this->ussd('5*1*1*abc')->assertContent("CON Invalid input.\n{$prompt}");
        $this->ussd('5*1*1*1234')->assertContent("CON Invalid input.\n{$prompt}");
        $this->ussd('5*1*1*000')->assertContent("CON Invalid input.\n{$prompt}");
        $this->ussd('5*1*1*9')->assertContent("CON No PU 009 in this ward.\n{$prompt}");
        $this->ussd('5*1*1*1')->assertContent("CON Amachi Pry Sch\nAccredited Voters:");
    }

    public function test_the_pu_number_is_looked_up_in_the_chosen_ward_only(): void
    {
        // The same PU number in another ward and another LGA.
        PollingUnit::factory()->create(['code' => '110102001', 'name' => 'Azuiyiokwu Pry Sch', 'ward' => 'Azuiyiokwu', 'lga' => 'Abakaliki']);
        PollingUnit::factory()->create(['code' => '120101001', 'name' => 'Afikpo Town Hall', 'ward' => 'Ugwuegu', 'lga' => 'Afikpo North']);

        $this->ussd('5*1')->assertContent("CON Abakaliki: choose ward\n1.Amachi Ward\n2.Azuiyiokwu");
        $this->ussd('5*1*2*001')->assertContent("CON Azuiyiokwu Pry Sch\nAccredited Voters:");
        $this->ussd('5*2*1*001')->assertContent("CON Afikpo Town Hall\nAccredited Voters:");
        $this->ussd('5*1*1*001')->assertContent("CON Amachi Pry Sch\nAccredited Voters:");
    }

    public function test_accredited_cannot_exceed_registered_voters(): void
    {
        $this->ussd('5*'.self::PU_ENTRY.'*1001')
            ->assertContent("CON Error:\nAccredited cannot exceed\nregistered voters (1000).\nRe-enter accredited:");

        $this->ussd('5*'.self::PU_ENTRY.'*1001*1000')->assertContent('CON Votes for APC:');
    }

    public function test_votes_cast_cannot_exceed_accredited(): void
    {
        $over = '5*'.self::PU_ENTRY.'*200*120*80*20*5';

        $this->ussd($over)->assertContent(
            "CON Error:\nVotes cast (225) exceed\naccredited (200).\n1. Re-enter accredited\n2. Start again"
        );

        $this->ussd("{$over}*1")->assertContent('CON Re-enter accredited:');
        $this->ussd("{$over}*1*220")->assertSee('Votes cast (225) exceed', false);
        $this->ussd("{$over}*1*300")->assertSee("CON Confirm:\nPU:110101001\nAmachi Pry Sch\nAcc:300", false);
        $this->ussd("{$over}*2")->assertContent("CON Amachi Pry Sch\nAccredited Voters:");
    }

    public function test_invalid_confirm_option_shows_confirmation_again(): void
    {
        $this->ussd('5*'.self::PU_ENTRY.'*300*120*80*20*5*7')->assertSee("CON Invalid input.\nConfirm:", false);
    }

    public function test_quick_code_goes_straight_to_confirmation(): void
    {
        // *XXX*5*1*1*001*300*120*80*20*5# arrives as this text.
        $this->ussd('5*'.self::PU_ENTRY.'*300*120*80*20*5')->assertSee('CON Confirm:', false);
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

        $this->ussd('5', $agent)->assertContent("CON Amachi Pry Sch\nAccredited Voters:");
        $this->ussd('5*300*120*80*20*5*1*'.self::PIN, $agent)->assertSee('END Submitted', false);

        $this->assertSame(self::PU, Result::sole()->polling_unit_code);
    }

    public function test_assigned_agent_with_unregistered_pu_is_stopped(): void
    {
        $agent = Agent::factory()->assignedTo('119999999')->create();

        $this->ussd('5', $agent)->assertContent("END Your PU is not registered.\nContact coordinator.");
    }

    // Report Incident (4) ---------------------------------------------------

    public function test_report_incident_steps(): void
    {
        $this->ussd('4')->assertContent("CON Incident Type:\n1. Ballot box/Result snatching\n2. Malpractice\n3. Intimidation\n4. Violence");
        $this->ussd('4*2')->assertContent("CON Your PU's LGA:\n1.Abakaliki");
        $this->ussd('4*2*'.self::PU_ENTRY)->assertContent('CON Short Note:');
        $this->ussd('4*2*'.self::PU_ENTRY.'*Cash at queue')
            ->assertContent("CON Confirm Incident:\nMalpractice\nAmachi Pry Sch\nPU:110101001\n1. Submit\n2. Cancel");

        $response = $this->ussd('4*2*'.self::PU_ENTRY.'*Cash at queue*1');

        $incident = Incident::sole();
        $response->assertContent("END Incident Logged ✔\nRef: {$incident->reference}");
        $this->assertMatchesRegularExpression('/^IN\d{6}$/', $incident->reference);
        $this->assertSame(IncidentType::Malpractice, $incident->type);
        $this->assertSame('Cash at queue', $incident->note);
        $this->assertSame(self::PU, $incident->polling_unit_code);
    }

    public function test_incident_can_be_cancelled(): void
    {
        $this->ussd('4*4*'.self::PU_ENTRY.'*Fight*2')->assertContent('END Report cancelled.');

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_invalid_incident_type_is_rejected(): void
    {
        $this->ussd('4*5')->assertSee("CON Invalid input.\nIncident Type:", false);
        $this->ussd('4*0')->assertSee("CON Invalid input.\nIncident Type:", false);
    }

    public function test_incident_note_length_is_limited(): void
    {
        $this->ussd('4*4*'.self::PU_ENTRY.'*'.str_repeat('a', 31))
            ->assertContent("CON Invalid input.\nNote must be 1-30 characters:");

        $this->ussd('4*4*'.self::PU_ENTRY.'*'.str_repeat('a', 30))->assertSee('CON Confirm Incident:', false);
    }

    public function test_assigned_agent_skips_pu_entry_for_incidents(): void
    {
        $agent = Agent::factory()->assignedTo(self::PU)->create();

        $this->ussd('4*2', $agent)->assertContent('CON Short Note:');
        $this->ussd('4*2*Late start*1', $agent);

        $this->assertSame(self::PU, Incident::sole()->polling_unit_code);
    }

    public function test_incident_types_and_which_are_urgent(): void
    {
        $this->ussd('4*1*'.self::PU_ENTRY.'*Box carted away*1')->assertSee('Incident Logged', false);
        $this->ussd('4*2*'.self::PU_ENTRY.'*Result sheet swapped*1')->assertSee('Incident Logged', false);
        $this->ussd('4*3*'.self::PU_ENTRY.'*Thugs at the PU*1')->assertSee('Incident Logged', false);
        $this->ussd('4*4*'.self::PU_ENTRY.'*Fight*1')->assertSee('Incident Logged', false);

        $incidents = Incident::orderBy('id')->get();
        $this->assertSame(
            [IncidentType::BallotSnatching, IncidentType::Malpractice, IncidentType::Intimidation, IncidentType::Violence],
            $incidents->pluck('type')->all(),
        );
        $this->assertSame([true, true, true, true], $incidents->map->isUrgent()->all());
        $this->assertSame('Ballot box/Result snatching', $incidents[0]->type->label());
    }

    // Presence (1) ----------------------------------------------------------

    public function test_confirm_presence(): void
    {
        $this->ussd('1')->assertContent("CON Your PU's LGA:\n1.Abakaliki");
        $this->ussd('1*'.self::PU_ENTRY)->assertContent("END Presence Confirmed ✔\nAmachi Pry Sch");

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

        $this->ussd('1', $agent)->assertContent("END Presence Confirmed ✔\nAmachi Pry Sch");
        $this->assertSame(self::PU, Presence::sole()->polling_unit_code);
    }

    // Material Status (2): materials and personnel in one answer -------------

    public function test_materials_report(): void
    {
        $this->ussd('2')->assertContent("CON Your PU's LGA:\n1.Abakaliki");
        $this->ussd('2*'.self::PU_ENTRY)->assertContent(
            "CON Amachi Pry Sch\nMaterial Status:\n1. Materials & personnel arrived\n2. Materials only\n3. Personnel only\n4. Materials incomplete\n5. Neither has arrived"
        );
        $this->ussd('2*'.self::PU_ENTRY.'*9')->assertSee("CON Invalid input.\nAmachi Pry Sch", false);
        $this->ussd('2*'.self::PU_ENTRY.'*5')->assertContent("END Report saved ✔\nNeither has arrived\nAmachi Pry Sch");

        $report = MaterialReport::sole();
        $this->assertSame(MaterialStatus::NotArrived, $report->status);
        $this->assertFalse($report->personnel_arrived);
        $this->assertSame(self::PU, $report->polling_unit_code);
    }

    public function test_each_materials_answer_is_stored_as_materials_and_personnel(): void
    {
        $agent = Agent::factory()->assignedTo(self::PU)->create();
        $expected = [
            '1' => [MaterialStatus::Arrived, true],
            '2' => [MaterialStatus::Arrived, false],
            '3' => [MaterialStatus::NotArrived, true],
            '4' => [MaterialStatus::Incomplete, null],
            '5' => [MaterialStatus::NotArrived, false],
        ];

        foreach ($expected as $answer => [$status, $personnel]) {
            $this->ussd("2*{$answer}", $agent)->assertSee('Report saved', false);
            $report = MaterialReport::latest('id')->first();
            $this->assertSame($status, $report->status, "answer {$answer}");
            $this->assertSame($personnel, $report->personnel_arrived, "answer {$answer}");
        }
    }

    public function test_assigned_agent_reports_materials_without_pu_code_and_latest_counts(): void
    {
        $agent = Agent::factory()->assignedTo(self::PU)->create();

        $this->ussd('2', $agent)->assertSee('Material Status:', false);
        $this->ussd('2*5', $agent);
        $this->ussd('2*1', $agent)->assertSee('Materials & personnel arrived', false);

        $summary = app(ElectionStats::class)->summary();
        $this->assertSame(1, $summary['materials']['arrived']);
        $this->assertSame(0, $summary['materials']['not_arrived']);
    }

    // Instructions (3), How can you help (6), Exit (7) ------------------------

    public function test_instructions(): void
    {
        $this->ussd('3')->assertContent(
            "END Stay at PU.\nSubmit results after counting.\nReport any issue immediately."
        );
    }

    public function test_exit(): void
    {
        $this->ussd('7')->assertContent('END Thank you');
    }

    // Screen size -----------------------------------------------------------

    public function test_screens_fit_on_a_ussd_display(): void
    {
        // Five parties, a 12-digit code and a long name are worse than election day.
        config(['election.parties' => ['APC', 'PDP', 'LP', 'APGA', 'OTHERS']]);
        PollingUnit::factory()->create(['code' => '212026330999', 'name' => str_repeat('Very Long Polling Unit Name ', 3), 'registered_voters' => 999999, 'lga' => 'Abakaliki', 'ward' => 'Amachi Ward']);
        Result::query()->delete();

        $big = '5*1*1*999*999999*999999*999999*999999*999999*999999*999999';
        $screens = ['', '1', '2', '4', '5', '5*1', '5*1*1', '5*1*1*1234', '5*1*1*998', '5*1*1*999', $big, "{$big}*1",
            '2*1*1*999', '2*1*1*999*9', '2*1*1*999*1', '4*1*1*1*999*'.str_repeat('a', 30)];

        foreach ($screens as $text) {
            $body = $this->ussd($text)->getContent();

            $this->assertLessThanOrEqual(182, mb_strlen($body), "Screen too long for input [{$text}]: {$body}");
        }
    }
}
