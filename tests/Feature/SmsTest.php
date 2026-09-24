<?php

namespace Tests\Feature;

use App\Services\AfricasTalkingSms;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsTest extends TestCase
{
    public function test_it_posts_to_africas_talking(): void
    {
        config(['services.africastalking' => ['username' => 'electionshield', 'api_key' => 'secret', 'sender_id' => 'ESHIELD']]);
        Http::fake(['*' => Http::response(['SMSMessageData' => ['Recipients' => []]], 201)]);

        app(AfricasTalkingSms::class)->send('+2348011111111', 'Result received. Ref: RS123456');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.africastalking.com/version1/messaging'
            && $request->hasHeader('apiKey', 'secret')
            && $request['username'] === 'electionshield'
            && $request['to'] === '+2348011111111'
            && $request['message'] === 'Result received. Ref: RS123456'
            && $request['from'] === 'ESHIELD');
    }

    public function test_sandbox_username_uses_sandbox_endpoint(): void
    {
        config(['services.africastalking' => ['username' => 'sandbox', 'api_key' => 'secret', 'sender_id' => null]]);
        Http::fake();

        app(AfricasTalkingSms::class)->send('+2348011111111', 'Hi');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.sandbox.africastalking.com/')
            && ! isset($request['from']));
    }

    public function test_it_skips_sending_without_an_api_key(): void
    {
        config(['services.africastalking.api_key' => null]);
        Http::fake();

        app(AfricasTalkingSms::class)->send('+2348011111111', 'Hi');

        Http::assertNothingSent();
    }
}
