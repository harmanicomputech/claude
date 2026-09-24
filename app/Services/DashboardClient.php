<?php

namespace App\Services;

use App\Contracts\DashboardRecord;
use Illuminate\Support\Facades\Http;

/**
 * Pushes records to the external results dashboard as signed JSON.
 *
 * Request: POST {DASHBOARD_WEBHOOK_URL}
 *   Authorization: Bearer {DASHBOARD_API_TOKEN}          (when set)
 *   Idempotency-Key: RS784321 | IN452190 | presence-12
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
     * Deliver the record, throwing if the dashboard does not answer with 2xx.
     */
    public function push(DashboardRecord $record): void
    {
        $config = config('services.dashboard');

        $body = json_encode([
            'event' => $record->dashboardEvent(),
            'sent_at' => now()->toIso8601String(),
            'data' => $record->dashboardPayload(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Idempotency-Key' => $record->dashboardIdempotencyKey(),
            'X-Election-Shield-Event' => $record->dashboardEvent(),
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
