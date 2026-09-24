<?php

namespace Tests\Feature;

use App\Enums\ResultStatus;
use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\Coordinator;
use App\Models\PollingUnit;
use App\Services\ElectionRecorder;
use App\Support\SystemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['election.admin_password' => 'correct-horse']);
        $this->setUpUssd();
    }

    private function asAdmin(): static
    {
        return $this->withSession(['admin.authenticated' => true]);
    }

    public function test_console_does_not_exist_without_a_password(): void
    {
        config(['election.admin_password' => null]);

        $this->get('/admin/login')->assertNotFound();
        $this->asAdmin()->get('/admin')->assertNotFound();
    }

    public function test_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Admin password');

        $this->post('/admin/login', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertFalse(session()->has('admin.authenticated'));

        $this->post('/admin/login', ['password' => 'correct-horse'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Set-up checklist');

        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->post('/admin/login', ['password' => 'wrong']);
        }

        $this->post('/admin/login', ['password' => 'correct-horse'])->assertTooManyRequests();
    }

    public function test_overview_shows_checklist_callback_and_totals(): void
    {
        config(['ussd.callback_secret' => 's3cret']);
        app(ElectionRecorder::class)->submitResult($this->agent, self::PU, 300, ['APC' => 120, 'PDP' => 80, 'LP' => 20], 5);

        $this->asAdmin()->get('/admin')
            ->assertOk()
            ->assertSee('Database tables')
            ->assertSee('Up to date')
            ->assertSee(url('/api/ussd/s3cret'))
            ->assertSee('Francis Ogbonna Nwifuru')
            ->assertSee('Never run: add the cron job', false);
    }

    public function test_heartbeat_marks_cron_as_running(): void
    {
        $this->artisan('election:heartbeat')->assertSuccessful();

        $this->asAdmin()->get('/admin')->assertSee('Last run');
    }

    public function test_database_setup_and_polling_unit_import_buttons(): void
    {
        $this->asAdmin()->post('/admin/system/migrate')
            ->assertRedirect()
            ->assertSessionHas('status', 'Database set up');

        $this->asAdmin()->post('/admin/system/polling-units', [
            'file' => UploadedFile::fake()->createWithContent('pus.csv', "code,name,ward,lga\nEB/212/02633/901,New Hall 901,Abakaliki Ward 01,Abakaliki\n"),
        ])->assertSessionHas('status', 'Polling units imported');

        $this->assertNotNull(PollingUnit::findByCode('21202633901'));
    }

    public function test_bundled_register_import_button(): void
    {
        $this->asAdmin()->post('/admin/system/polling-units')->assertSessionHas('status');

        $this->assertSame(3309, PollingUnit::count()); // 3,308 + the test PU
    }

    public function test_test_email_button(): void
    {
        $this->asAdmin()->post('/admin/system/test-email', ['to' => 'me@example.com'])
            ->assertSessionHas('status', 'Test email sent');
    }

    public function test_add_agent_with_generated_pin(): void
    {
        $this->asAdmin()->post('/admin/agents', [
            'name' => 'Chidi Eze', 'phone' => '08033333333', 'pu_code' => self::PU, 'sms_pin' => '1',
        ])->assertSessionHas('status', fn ($status) => str_contains($status, 'PIN: '));

        $agent = Agent::findByPhone('08033333333');
        $this->assertSame(self::PU, $agent->polling_unit_code);
        Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->to === '+2348033333333');
    }

    public function test_add_agent_rejects_unknown_pu(): void
    {
        $this->asAdmin()->post('/admin/agents', ['name' => 'X', 'phone' => '08033333333', 'pu_code' => '119999999'])
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'Unknown or invalid PU code'));
    }

    public function test_agent_csv_import(): void
    {
        $csv = "name,phone,pu_code,pin\nAda One,08040000001,".self::PU.",1111\nBen Two,08040000002,,\nBad Row,123,,\n";

        $response = $this->asAdmin()->post('/admin/agents/import', [
            'file' => UploadedFile::fake()->createWithContent('agents.csv', $csv),
        ]);

        $response->assertSessionHas('import', function (array $report) {
            return count($report['imported']) === 2
                && $report['imported'][0]['pin'] === '1111'
                && preg_match('/^\d{4}$/', $report['imported'][1]['pin'])
                && str_contains($report['errors'][0], 'Line 4: Invalid phone number');
        });

        $this->assertTrue(Agent::findByPhone('08040000001')->pinMatches('1111'));
        $this->assertNull(Agent::findByPhone('08040000002')->polling_unit_code);
        Queue::assertNotPushed(SendSms::class);
    }

    public function test_agents_page_lists_and_searches(): void
    {
        Agent::factory()->create(['name' => 'Zainab Findme']);

        $this->asAdmin()->get('/admin/agents')->assertOk()->assertSee('Ada Obi')->assertSee('Zainab Findme');
        $this->asAdmin()->get('/admin/agents?q=Findme')->assertOk()->assertSee('Zainab Findme')->assertDontSee('Ada Obi');
    }

    public function test_reset_pin_unlocks(): void
    {
        $this->agent->forceFill(['locked_until' => now()->addHour()])->save();

        $this->asAdmin()->post("/admin/agents/{$this->agent->id}/pin", ['pin' => '9876'])
            ->assertSessionHas('status', 'New PIN for Ada Obi: 9876');

        $this->agent->refresh();
        $this->assertFalse($this->agent->isLocked());
        $this->assertTrue($this->agent->pinMatches('9876'));
    }

    public function test_agents_with_results_cannot_be_removed(): void
    {
        $spare = Agent::factory()->create();
        $this->asAdmin()->delete("/admin/agents/{$spare->id}")->assertSessionHas('status');
        $this->assertModelMissing($spare);

        app(ElectionRecorder::class)->submitResult($this->agent, self::PU, 300, ['APC' => 1, 'PDP' => 1, 'LP' => 1], 0);
        $this->asAdmin()->delete("/admin/agents/{$this->agent->id}")->assertSessionHas('error');
        $this->assertModelExists($this->agent);
    }

    public function test_coordinators(): void
    {
        $this->asAdmin()->post('/admin/coordinators', ['name' => 'Lead', 'phone' => '08050000001', 'lga' => 'Abakaliki'])
            ->assertSessionHas('status');
        $this->asAdmin()->post('/admin/coordinators', ['name' => 'Bad', 'phone' => '08050000002', 'lga' => 'Nowhere'])
            ->assertSessionHasErrors('lga');

        $this->asAdmin()->get('/admin/coordinators')->assertOk()->assertSee('Lead')->assertSee('Abakaliki');

        $coordinator = Coordinator::sole();
        $this->asAdmin()->delete("/admin/coordinators/{$coordinator->id}");
        $this->assertModelMissing($coordinator);
    }

    public function test_corrections_can_be_reviewed(): void
    {
        $recorder = app(ElectionRecorder::class);
        $original = $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 120, 'PDP' => 80, 'LP' => 20], 5);
        $correction = $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 130, 'PDP' => 80, 'LP' => 20], 5, correction: true);

        $this->asAdmin()->get('/admin/corrections')->assertOk()
            ->assertSee($correction->reference)
            ->assertSee('APC 120')
            ->assertSee('APC 130');

        $this->asAdmin()->post("/admin/corrections/{$correction->reference}/approve", ['note' => 'Checked EC8A'])
            ->assertSessionHas('status', "Approved {$correction->reference}.");

        $this->assertSame(ResultStatus::Accepted, $correction->refresh()->status);
        $this->assertSame('Checked EC8A', $correction->review_note);
        $this->assertSame(ResultStatus::Superseded, $original->refresh()->status);

        $this->asAdmin()->post("/admin/corrections/{$correction->reference}/reject")->assertSessionHas('error');
        $this->asAdmin()->get('/admin/corrections')->assertSee('Recently reviewed');
    }

    public function test_missing_page_redirects_to_polling_unit_filters(): void
    {
        $this->asAdmin()->get('/admin/missing?type=results&lga=Ikwo')->assertRedirect('/admin/polling-units?status=no_result&lga=Ikwo');
        $this->asAdmin()->get('/admin/missing?type=presence')->assertRedirect('/admin/polling-units?status=no_presence');
    }

    public function test_data_pages_wait_for_the_database(): void
    {
        $this->mock(SystemStatus::class, fn ($mock) => $mock->shouldReceive('isReady')->andReturn(false));

        $this->asAdmin()->get('/admin/agents')->assertRedirect('/admin');
    }

    public function test_root_redirects_to_console(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_session_cookie_is_secure_only_over_https(): void
    {
        config(['session.secure' => true]); // e.g. SESSION_SECURE_COOKIE=true in .env

        $this->get('http://localhost/admin/login')->assertCookieNotExpired(config('session.cookie'));
        $this->assertFalse(config('session.secure'));

        $this->get('https://localhost/admin/login');
        $this->assertTrue(config('session.secure'));
    }

    public function test_expired_session_on_login_explains_instead_of_419(): void
    {
        // CSRF checks are skipped in tests, so raise the same exception directly.
        Route::post('/admin/csrf-probe', fn () => throw new TokenMismatchException)->middleware('web');

        $this->post('/admin/csrf-probe')
            ->assertRedirect('/admin/login')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'session expired'));
    }

    public function test_checklist_flags_missing_https(): void
    {
        $this->asAdmin()->get('http://localhost/admin')->assertSee('Not in use: turn on SSL', false);
        $this->asAdmin()->get('https://localhost/admin')->assertDontSee('Not in use: turn on SSL', false);
    }
}
