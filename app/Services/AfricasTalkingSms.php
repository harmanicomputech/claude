<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal client for the Africa's Talking SMS API.
 *
 * The official PHP SDK pins Guzzle 6/7, which conflicts with Laravel 13, and
 * sending an SMS is a single form POST, so we call the API directly.
 */
class AfricasTalkingSms
{
    private const LIVE_URL = 'https://api.africastalking.com/version1/messaging';

    private const SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

    public function send(string $to, string $message): void
    {
        $config = config('services.africastalking');

        if (blank($config['api_key'])) {
            Log::info('Africa\'s Talking API key not set; SMS not sent.', ['to' => $to, 'message' => $message]);

            return;
        }

        $url = $config['username'] === 'sandbox' ? self::SANDBOX_URL : self::LIVE_URL;

        Http::asForm()
            ->acceptJson()
            ->withHeaders(['apiKey' => $config['api_key']])
            ->timeout(15)
            ->post($url, array_filter([
                'username' => $config['username'],
                'to' => $to,
                'message' => $message,
                'from' => $config['sender_id'],
            ]))
            ->throw();
    }
}
