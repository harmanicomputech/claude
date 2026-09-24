<?php

namespace Tests\Concerns;

use App\Models\Agent;
use App\Models\PollingUnit;
use Illuminate\Testing\TestResponse;

/**
 * Shared set-up for USSD tests: one registered PU (110101001, 1,000 voters),
 * one agent with PIN 1234, and parties APC, PDP, LP (see phpunit.xml).
 */
trait InteractsWithUssd
{
    protected const PU = '110101001';

    protected const PIN = '1234';

    protected Agent $agent;

    protected PollingUnit $unit;

    protected function setUpUssd(): void
    {
        $this->unit = PollingUnit::factory()->create([
            'code' => self::PU,
            'name' => 'Amachi Pry Sch',
            'ward' => 'Amachi Ward',
            'lga' => 'Abakaliki',
            'registered_voters' => 1000,
        ]);

        $this->agent = Agent::factory()->create([
            'name' => 'Ada Obi',
            'phone_number' => '+2348011111111',
            'pin' => self::PIN,
        ]);
    }

    protected function ussd(string $text, ?Agent $agent = null): TestResponse
    {
        return $this->post('/api/ussd', [
            'sessionId' => 'ATUid_test',
            'serviceCode' => '*384*123#',
            'phoneNumber' => ($agent ?? $this->agent)->phone_number,
            'text' => $text,
        ])->assertOk();
    }

    /**
     * USSD input for a full result: accredited 300, APC 120, PDP 80, LP 20,
     * rejected 5, then Submit and PIN.
     */
    protected function resultInput(string $pu = self::PU, string $votes = '300*120*80*20*5'): string
    {
        return "1*{$pu}*{$votes}*1*".self::PIN;
    }
}
