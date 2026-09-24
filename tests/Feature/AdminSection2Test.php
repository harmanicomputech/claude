<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Enums\MaterialStatus;
use App\Enums\UserRole;
use App\Jobs\SendSms;
use App\Mail\ResultSubmitted;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Coordinator;
use App\Models\DashboardDelivery;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Models\User;
use App\Services\ElectionRecorder;
use App\Support\Rehearsal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class AdminSection2Test extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->setUpUssd();
        $this->admin = User::factory()->admin()->create(['name' => 'Chief Admin', 'password' => 'admin-password']);
    }

    // Users -----------------------------------------------------------------

    public function test_admin_manages_users(): void
    {
        $this->actingAs($this->admin)->post('/admin/users', ['name' => 'Ngozi', 'email' => 'ngozi@example.com', 'role' => 'coordinator'])
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Temporary password: '));

        $ngozi = User::where('email', 'ngozi@example.com')->sole();
        $this->assertSame(UserRole::Coordinator, $ngozi->role);

        $this->actingAs($this->admin)->patch("/admin/users/{$ngozi->id}", ['role' => 'admin']);
        $this->assertTrue($ngozi->refresh()->isAdmin());

        $this->actingAs($this->admin)->post("/admin/users/{$ngozi->id}/password")->assertSessionHas('status');
        $this->actingAs($this->admin)->delete("/admin/users/{$ngozi->id}")->assertSessionHas('status');
        $this->assertModelMissing($ngozi);

        $this->assertSame(
            ['user.created', 'user.role_changed', 'user.password_reset', 'user.deleted'],
            AuditLog::orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_last_admin_and_self_are_protected(): void
    {
        $this->actingAs($this->admin)->patch("/admin/users/{$this->admin->id}", ['role' => 'coordinator'])->assertSessionHas('error');
        $this->actingAs($this->admin)->delete("/admin/users/{$this->admin->id}")->assertSessionHas('error');
        $this->assertTrue($this->admin->refresh()->isAdmin());
    }

    public function test_users_change_their_own_password(): void
    {
        $this->actingAs($this->admin)->post('/admin/account/password', ['current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($this->admin)->post('/admin/account/password', ['current_password' => 'admin-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHas('status');

        $this->assertTrue(auth()->validate(['email' => $this->admin->email, 'password' => 'new-password-1']));
    }

    // Audit log -------------------------------------------------------------

    public function test_correction_decisions_are_audited_with_the_reviewers_name(): void
    {
        $recorder = app(ElectionRecorder::class);
        $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 120, 'PDP' => 80, 'LP' => 20], 5);
        $correction = $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 130, 'PDP' => 80, 'LP' => 20], 5, correction: true);

        $this->actingAs($this->admin)->post("/admin/corrections/{$correction->reference}/approve", ['note' => 'Matches EC8A']);

        $this->assertSame('Chief Admin', $correction->refresh()->reviewed_by);

        $entry = AuditLog::where('action', 'correction.approved')->sole();
        $this->assertSame('Chief Admin', $entry->user_name);
        $this->assertSame($correction->reference, $entry->subject_id);
        $this->assertSame(120, $entry->details['previous']['votes']['APC']);
        $this->assertSame(130, $entry->details['proposed']['APC']);

        $this->actingAs($this->admin)->get('/admin/audit')->assertOk()->assertSee('Approved correction')->assertSee('Chief Admin');
        $this->actingAs($this->admin)->get('/admin/audit/export')->assertOk();
    }

    public function test_exports_and_pin_lockouts_are_audited(): void
    {
        $this->actingAs($this->admin)->get('/admin/results/export')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'result.exported', 'user_name' => 'Chief Admin']);

        $confirmed = '1*'.self::PU.'*300*120*80*20*5*1';
        $this->ussd("{$confirmed}*1111");
        $this->ussd("{$confirmed}*1111*2222");
        $this->ussd("{$confirmed}*1111*2222*3333");

        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.locked', 'user_name' => 'USSD', 'subject_id' => (string) $this->agent->id]);
    }

    // Rehearsal mode --------------------------------------------------------

    public function test_rehearsal_mode_labels_everything_and_opens_windows(): void
    {
        Mail::fake();
        config([
            'election.enforce_windows' => true,
            'election.date' => '2027-02-06',
            'ussd.notify_emails' => ['c@example.com'],
            'services.dashboard.url' => 'https://dashboard.test',
        ]);
        $this->travelTo(Carbon::parse('2026-12-10 10:00', 'Africa/Lagos'));

        $this->ussd('1')->assertSee('Result submission opens', false);

        $this->actingAs($this->admin)->post('/admin/settings/rehearsal', ['on' => 1])->assertSessionHas('status');
        $this->assertTrue(Rehearsal::active());

        $this->ussd('')->assertSee('Election Shield REHEARSAL', false);
        $this->ussd($this->resultInput())->assertSee('Submitted', false);

        Queue::assertPushed(SendSms::class, fn (SendSms $job) => str_starts_with($job->message, '[REHEARSAL] Result received'));
        Mail::assertQueued(ResultSubmitted::class, fn ($mail) => str_starts_with($mail->envelope()->subject, '[REHEARSAL] '));
        $this->assertTrue(DashboardDelivery::sole()->payload['rehearsal']);
        $this->actingAs($this->admin)->get('/admin')->assertSee('REHEARSAL MODE');

        $this->actingAs($this->admin)->post('/admin/settings/rehearsal', ['on' => 0]);
        $this->assertFalse(Rehearsal::active());
        $this->assertSame(['settings.rehearsal', 'settings.rehearsal'], AuditLog::where('action', 'like', 'settings.%')->pluck('action')->all());
    }

    // Clear test data -------------------------------------------------------

    private function seedSubmissions(): void
    {
        $recorder = app(ElectionRecorder::class);
        $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 120, 'PDP' => 80, 'LP' => 20], 5);
        $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 130, 'PDP' => 80, 'LP' => 20], 5, correction: true);
        $recorder->logIncident($this->agent, self::PU, IncidentType::Delay, 'Late');
        $recorder->confirmPresence($this->agent, self::PU);
        $recorder->reportMaterials($this->agent, self::PU, MaterialStatus::Arrived);
        $this->agent->forceFill(['locked_until' => now()->addHour()])->save();
        Coordinator::create(['name' => 'Lead', 'phone_number' => '08020000001']);
    }

    public function test_clear_test_data_needs_confirmation_and_password(): void
    {
        $this->seedSubmissions();

        $this->actingAs($this->admin)->post('/admin/settings/clear-test-data', ['confirm' => 'clear', 'password' => 'admin-password'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->admin)->post('/admin/settings/clear-test-data', ['confirm' => 'CLEAR', 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(2, Result::count());
    }

    public function test_clear_test_data_keeps_the_register_and_people(): void
    {
        $this->seedSubmissions();

        $this->actingAs($this->admin)->post('/admin/settings/clear-test-data', ['confirm' => 'CLEAR', 'password' => 'admin-password'])
            ->assertSessionHas('status', fn ($status) => str_contains($status, '2 results'));

        foreach (['results', 'result_votes', 'incidents', 'presences', 'material_reports', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertSame(1, PollingUnit::count());
        $this->assertSame(1, Coordinator::count());
        $this->assertFalse($this->agent->refresh()->isLocked());
        $this->assertFalse($this->agent->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.test_data_cleared']);

        // The PU can be submitted again from scratch.
        $this->ussd($this->resultInput())->assertSee('END Submitted', false);
    }

    public function test_clear_can_also_remove_agents(): void
    {
        $this->seedSubmissions();

        $this->actingAs($this->admin)->post('/admin/settings/clear-test-data', ['confirm' => 'CLEAR', 'password' => 'admin-password', 'remove_agents' => '1']);

        $this->assertSame(0, Agent::count());
    }

    public function test_clearing_is_blocked_during_the_real_election(): void
    {
        $this->seedSubmissions();
        config(['election.enforce_windows' => true, 'election.date' => '2027-02-06']);
        $this->travelTo(Carbon::parse('2027-02-06 15:00', 'Africa/Lagos'));

        $this->actingAs($this->admin)->get('/admin/settings')->assertSee('The election is under way');
        $this->actingAs($this->admin)->post('/admin/settings/clear-test-data', ['confirm' => 'CLEAR', 'password' => 'admin-password'])
            ->assertSessionHas('error');
        $this->assertSame(2, Result::count());
    }

    // Agent cards -----------------------------------------------------------

    public function test_agent_cards_show_pu_dial_code_and_steps(): void
    {
        config(['ussd.service_code' => '*384*92342#']);
        Agent::factory()->assignedTo(self::PU)->create(['name' => 'Assigned Agent']);
        Coordinator::create(['name' => 'Abakaliki Lead', 'phone_number' => '08020000001', 'lga' => 'Abakaliki']);

        $coordinator = User::factory()->create();

        $this->actingAs($coordinator)->get('/admin/agents/cards')->assertOk()
            ->assertSee('Assigned Agent')
            ->assertSee('Amachi Pry Sch')
            ->assertSee('*384*92342#')
            ->assertSee('APC → PDP → LP')
            ->assertSee('Abakaliki Lead')
            ->assertSee('Your PIN was sent to you by SMS')
            ->assertDontSee('Set new PINs'); // coordinators can't reset PINs
    }

    public function test_cards_with_new_pins(): void
    {
        $this->actingAs($this->admin)->post('/admin/agents/cards', [])->assertSessionHasErrors('confirm');
        $this->assertTrue($this->agent->refresh()->pinMatches(self::PIN));

        $response = $this->actingAs($this->admin)->post('/admin/agents/cards', ['confirm' => '1'])->assertOk()
            ->assertSee('New PINs are shown only on this page');

        preg_match('/<dt>Your PIN<\/dt><dd>(\d{4})<\/dd>/', $response->getContent(), $match);
        $this->assertTrue($this->agent->refresh()->pinMatches($match[1]));
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.cards_with_pins']);
    }
}
