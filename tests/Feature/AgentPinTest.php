<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class AgentPinTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    private string $confirmed;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->setUpUssd();
        $this->confirmed = '1*'.self::PU.'*300*120*80*20*5*1';
    }

    public function test_wrong_pin_is_counted_and_can_be_retried(): void
    {
        $this->ussd("{$this->confirmed}*9999")->assertContent("CON Wrong PIN. 2 tries left.\nEnter PIN:");
        $this->assertSame(1, $this->agent->refresh()->failed_pin_attempts);

        $this->ussd("{$this->confirmed}*9999*".self::PIN)->assertSee('END Submitted', false);
        $this->assertSame(0, $this->agent->refresh()->failed_pin_attempts);
    }

    public function test_earlier_wrong_pins_are_not_counted_again_on_replay(): void
    {
        $this->ussd("{$this->confirmed}*9999");
        $this->ussd("{$this->confirmed}*9999*8888")->assertContent("CON Wrong PIN. 1 tries left.\nEnter PIN:");

        $this->assertSame(2, $this->agent->refresh()->failed_pin_attempts);
    }

    public function test_too_many_wrong_pins_lock_the_agent(): void
    {
        $this->ussd("{$this->confirmed}*1111");
        $this->ussd("{$this->confirmed}*1111*2222");
        $this->ussd("{$this->confirmed}*1111*2222*3333")
            ->assertContent("END Too many wrong PINs.\nTry again in 30 minutes.");

        $this->assertTrue($this->agent->refresh()->isLocked());
        $this->assertDatabaseCount('results', 0);

        $this->ussd('')->assertContent("END Account locked.\nTry again later or\ncontact coordinator.");

        $this->travel(31)->minutes();
        $this->ussd('')->assertSee('CON Election Shield', false);
    }

    public function test_agent_without_pin_cannot_submit(): void
    {
        $agent = Agent::factory()->create(['pin' => null]);

        $this->ussd($this->confirmed, $agent)->assertContent("END No PIN set.\nContact coordinator.");
    }

    public function test_pin_is_stored_hashed(): void
    {
        $this->assertNotSame(self::PIN, $this->agent->getRawOriginal('pin'));
        $this->assertTrue($this->agent->pinMatches(self::PIN));
    }

    public function test_agent_pin_command_resets_and_unlocks(): void
    {
        $this->agent->forceFill(['locked_until' => now()->addHour(), 'failed_pin_attempts' => 2])->save();

        $this->artisan('agent:pin', ['phone' => '08011111111', '--pin' => '4321'])->assertSuccessful();

        $this->agent->refresh();
        $this->assertFalse($this->agent->isLocked());
        $this->assertSame(0, $this->agent->failed_pin_attempts);
        $this->assertTrue($this->agent->pinMatches('4321'));
    }
}
