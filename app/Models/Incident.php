<?php

namespace App\Models;

use App\Contracts\DashboardRecord;
use App\Enums\IncidentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reference', 'agent_id', 'polling_unit_code', 'type', 'note', 'dashboard_synced_at'])]
class Incident extends Model implements DashboardRecord
{
    protected function casts(): array
    {
        return [
            'dashboard_synced_at' => 'datetime',
            'type' => IncidentType::class,
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function dashboardEvent(): string
    {
        return 'incident.reported';
    }

    public function dashboardIdempotencyKey(): string
    {
        return $this->reference;
    }

    public function dashboardPayload(): array
    {
        return [
            'reference' => $this->reference,
            'polling_unit_code' => $this->polling_unit_code,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'note' => $this->note,
            'agent' => [
                'name' => $this->agent->name,
                'phone_number' => $this->agent->phone_number,
            ],
            'reported_at' => $this->created_at->toIso8601String(),
        ];
    }
}
