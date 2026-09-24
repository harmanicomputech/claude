<?php

namespace App\Services;

use App\Models\DashboardDelivery;
use Illuminate\Support\Facades\Http;

/**
 * Pushes outbox events to the external results dashboard as signed JSON.
 *
 * Request: POST {DASHBOARD_WEBHOOK_URL}
 *   Authorization: Bearer {DASHBOARD_API_TOKEN}          (when set)
 *   Idempotency-Key: result.submitted:RS784321
 *   X-Election-Shield-Event: result.submitted
 *   X-Election-Shield-Signature: sha256=<hex HMAC of the raw body>  (when a secret is set)
 *   Body: {"event": "...", "sent_at": "...", "data": {...}}
 */
class DashboardClient
{
    public function enabled(): bool
    {
        return filled(config('services.dashboard.url'));
    }

    /**
     * Deliver the event, throwing if the dashboard does not answer with 2xx.
     */
    public function push(DashboardDelivery $delivery): void
    {
        $config = config('services.dashboard');

        $body = json_encode([
            'event' => $delivery->event,
            'sent_at' => now()->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Idempotency-Key' => $delivery->idempotency_key,
            'X-Election-Shield-Event' => $delivery->event,
        ];

        if (filled($config['secret'])) {
            $headers['X-Election-Shield-Signature'] = 'sha256='.hash_hmac('sha256', $body, $config['secret']);
        }

        $request = Http::acceptJson()
            ->withHeaders($headers)
            ->timeout($config['timeout']);

        if (filled($config['token'])) {
            $request->withToken($config['token']);
        }

        $request->withBody($body, 'application/json')
            ->post($config['url'])
            ->throw();
    }
}
