<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_id', 'polling_unit_code', 'confirmed_at', 'channel'])]
class Presence extends Model
{
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_code', 'code');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        $this->loadMissing('agent', 'pollingUnit');

        return [
            'id' => $this->id,
            'polling_unit' => $this->pollingUnit?->toSummaryArray() ?? ['code' => $this->polling_unit_code],
            'agent' => $this->agent->toSummaryArray(),
            'channel' => $this->channel ?? 'ussd',
            'confirmed_at' => $this->confirmed_at->toIso8601String(),
        ];
    }
}
