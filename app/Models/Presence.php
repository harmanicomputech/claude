<?php

namespace App\Models;

use App\Contracts\DashboardRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_id', 'polling_unit_code', 'confirmed_at', 'dashboard_synced_at'])]
class Presence extends Model implements DashboardRecord
{
    protected function casts(): array
    {
        return [
            'dashboard_synced_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function dashboardEvent(): string
    {
        return 'presence.confirmed';
    }

    public function dashboardIdempotencyKey(): string
    {
        return 'presence-'.$this->id;
    }

    public function dashboardPayload(): array
    {
        return [
            'polling_unit_code' => $this->polling_unit_code,
            'agent' => [
                'name' => $this->agent->name,
                'phone_number' => $this->agent->phone_number,
            ],
            'confirmed_at' => $this->confirmed_at->toIso8601String(),
        ];
    }
}
