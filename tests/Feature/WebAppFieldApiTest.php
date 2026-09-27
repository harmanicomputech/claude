<?php

namespace Tests\Feature;

use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\DashboardDelivery;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class WebAppFieldApiTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    private array $headers = ['Authorization' => 'Bearer api-secret'];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['election.api_token' => 'api-secret', 'services.dashboard.url' => 'https://web.test/api/ussd-events']);
        $this->setUpUssd();
        $this->agent->update(['polling_unit_code' => self::PU]);
    }

    private function resultData(array $overrides = []): array
    {
        return array_replace_recursive([
            'phone_number' => '08011111111',
            'accredited_voters' => 300,
            'votes' => ['APC' => 120, 'PDP' => 80, 'LP' => 20],
            'rejected_votes' => 5,
        ], $overrides);
    }

    public function test_the_endpoints_need_the_api_token(): void
    {
        $this->postJson('/api/field/results', $this->resultData())->assertUnauthorized();
        $this->postJson('/api/agents/verify-pin', ['phone_number' => '08011111111', 'pin' => self::PIN])->assertUnauthorized();
    }

    public function test_a_web_result_is_recorded_like_a_ussd_one_with_channel_web(): void
    {
        $this->postJson('/api/field/results', $this->resultData(), $this->headers)
            ->assertCreated()
            ->assertJsonPath('result.channel', 'web')
            ->assertJsonPath('result.status', 'accepted')
            ->assertJsonPath('result.votes.APC', 120)
            ->assertJsonPath('result.agent.phone_number', '+2348011111111');

        $result = Result::sole();
        $this->assertSame('web', $result->channel);
        $this->assertSame(self::PU, $result->accepted_polling_unit_code);
        Queue::assertPushed(SendSms::class, fn (SendSms $job) => str_contains($job->message, $result->reference));
        $this->assertSame('web', DashboardDelivery::sole()->payload['channel']);

        // A second result for the PU needs to be a correction.
        $this->postJson('/api/field/results', $this->resultData(), $this->headers)->assertStatus(409)->assertJsonPath('error', 'result_exists');
        $this->postJson('/api/field/results', $this->resultData(['correction' => true, 'votes' => ['APC' => 121]]), $this->headers)
            ->assertCreated()
            ->assertJsonPath('result.status', 'pending')
            ->assertJsonPath('result.corrects_reference', $result->reference);
    }

    public function test_results_are_checked_like_on_ussd(): void
    {
        $this->postJson('/api/field/results', $this->resultData(['votes' => ['APC' => 500]]), $this->headers)->assertStatus(422)->assertJsonPath('error', 'over_accredited');
        $this->postJson('/api/field/results', $this->resultData(['accredited_voters' => 1500]), $this->headers)->assertStatus(422)->assertJsonPath('error', 'above_registered');
        $this->postJson('/api/field/results', $this->resultData(['votes' => ['LP' => null]]), $this->headers)->assertStatus(422)->assertJsonValidationErrors('votes.LP');
        $this->postJson('/api/field/results', $this->resultData(['polling_unit' => '999999999']), $this->headers)->assertStatus(422)->assertJsonPath('error', 'wrong_polling_unit');
        $this->postJson('/api/field/results', $this->resultData(['phone_number' => '08099999999']), $this->headers)->assertNotFound();

        config(['election.enforce_windows' => true]);
        $this->travelTo(now()->setDate(2020, 1, 1));
        $this->postJson('/api/field/results', $this->resultData(), $this->headers)->assertStatus(409)->assertJsonPath('error', 'closed');

        $this->assertSame(0, Result::count());
    }

    public function test_agents_without_a_pu_name_one_from_the_register(): void
    {
        $this->agent->update(['polling_unit_code' => null]);

        $this->postJson('/api/field/results', $this->resultData(), $this->headers)->assertStatus(422)->assertJsonPath('error', 'polling_unit_required');
        $this->postJson('/api/field/results', $this->resultData(['polling_unit' => '999999999']), $this->headers)->assertStatus(422)->assertJsonPath('error', 'unknown_polling_unit');
        $this->postJson('/api/field/results', $this->resultData(['polling_unit' => self::PU]), $this->headers)->assertCreated();
    }

    public function test_incidents_presence_and_materials(): void
    {
        $this->postJson('/api/field/incidents', ['phone_number' => '+2348011111111', 'type' => 'violence', 'note' => str_repeat('Thugs arrived and chased voters away. ', 5)], $this->headers)
            ->assertCreated()
            ->assertJsonPath('incident.channel', 'web')
            ->assertJsonPath('incident.urgent', true);
        $this->assertSame('web', Incident::sole()->channel);

        $this->postJson('/api/field/incidents', ['phone_number' => '+2348011111111', 'type' => 'aliens', 'note' => 'x'], $this->headers)->assertJsonValidationErrors(['type', 'note']);

        $this->postJson('/api/field/presence', ['phone_number' => '+2348011111111'], $this->headers)->assertCreated()->assertJsonPath('presence.channel', 'web');
        $this->assertTrue($this->agent->refresh()->is_active);

        $this->postJson('/api/field/materials', ['phone_number' => '+2348011111111', 'status' => 'incomplete'], $this->headers)
            ->assertCreated()
            ->assertJsonPath('materials.status', 'incomplete')
            ->assertJsonPath('materials.channel', 'web');
    }

    public function test_ussd_submissions_say_ussd(): void
    {
        $this->ussd($this->resultInput());

        $this->assertSame('ussd', Result::sole()->channel);
        $this->assertSame('ussd', Result::sole()->toDashboardArray()['channel']);
    }

    public function test_adding_an_agent_and_resetting_the_pin(): void
    {
        PollingUnit::factory()->create(['code' => '110101002']);

        $response = $this->postJson('/api/agents', ['name' => 'Chika Eze', 'phone_number' => '0803 222 3333', 'polling_unit' => 'EB/110/101/002', 'sms_pin' => true, 'by' => 'Admin One'], $this->headers)
            ->assertCreated()
            ->assertJsonPath('agent.phone_number', '+2348032223333')
            ->assertJsonPath('agent.polling_unit.code', '110101002')
            ->assertJsonPath('agent.has_pin', true)
            ->assertJsonPath('created', true);

        $agent = Agent::findByPhone('08032223333');
        $this->assertTrue($agent->pinMatches($response->json('pin')));
        Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->to === '+2348032223333' && str_contains($job->message, 'your PIN is'));

        $this->postJson('/api/agents', ['name' => 'Chika Eze', 'phone_number' => '08032223333', 'polling_unit' => '999'], $this->headers)->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->postJson('/api/agents', ['name' => 'Chika E.', 'phone_number' => '08032223333', 'polling_unit' => '110101002'], $this->headers)->assertOk()->assertJsonPath('created', false)->assertJsonPath('pin', null);

        $this->postJson('/api/agents/reset-pin', ['phone_number' => '08032223333', 'pin' => '4321'], $this->headers)->assertOk()->assertJsonPath('pin', '4321');
        $this->assertTrue($agent->refresh()->pinMatches('4321'));
        $this->postJson('/api/agents/reset-pin', ['phone_number' => '08030000000'], $this->headers)->assertNotFound();
    }

    public function test_verifying_a_pin_shares_the_ussd_lock_out(): void
    {
        config(['election.pin_max_attempts' => 3]);

        $this->postJson('/api/agents/verify-pin', ['phone_number' => '08011111111', 'pin' => self::PIN], $this->headers)
            ->assertOk()->assertJsonPath('agent.name', 'Ada Obi')->assertJsonPath('agent.polling_unit.code', self::PU);

        $this->postJson('/api/agents/verify-pin', ['phone_number' => '08011111111', 'pin' => '0000'], $this->headers)->assertStatus(401)->assertJsonPath('tries_left', 2);
        $this->postJson('/api/agents/verify-pin', ['phone_number' => '08011111111', 'pin' => '0000'], $this->headers)->assertStatus(401)->assertJsonPath('tries_left', 1);
        $this->postJson('/api/agents/verify-pin', ['phone_number' => '08011111111', 'pin' => '0000'], $this->headers)->assertStatus(423);
        $this->postJson('/api/agents/verify-pin', ['phone_number' => '08011111111', 'pin' => self::PIN], $this->headers)->assertStatus(423);
        $this->assertTrue($this->agent->refresh()->isLocked());

        $this->postJson('/api/agents/verify-pin', ['phone_number' => '08099999999', 'pin' => '1234'], $this->headers)->assertNotFound();
    }
}
