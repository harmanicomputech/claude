<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class UssdProtectionTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUssd();
    }

    private function callUssd(string $uri, string $ip = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post($uri, ['sessionId' => 's', 'serviceCode' => '*384*123#', 'phoneNumber' => $this->agent->phone_number, 'text' => '']);
    }

    public function test_secret_in_callback_url_is_required_when_configured(): void
    {
        config(['ussd.callback_secret' => 'k3y']);

        $this->callUssd('/api/ussd')->assertNotFound();
        $this->callUssd('/api/ussd/wrong')->assertNotFound();
        $this->callUssd('/api/ussd/k3y')->assertOk()->assertSee('CON Election Shield', false);
    }

    public function test_ip_allowlist_is_enforced_when_configured(): void
    {
        config(['ussd.allowed_ips' => ['52.48.0.0/16', '10.0.0.5']]);

        $this->callUssd('/api/ussd', '203.0.113.9')->assertNotFound();
        $this->callUssd('/api/ussd', '52.48.10.20')->assertOk();
        $this->callUssd('/api/ussd', '10.0.0.5')->assertOk();
    }

    public function test_open_when_not_configured(): void
    {
        $this->callUssd('/api/ussd')->assertOk();
    }
}
