<?php

namespace App\Models;

use App\Enums\IncidentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reference', 'agent_id', 'polling_unit_code', 'type', 'note'])]
class Incident extends Model
{
    protected function casts(): array
    {
        return [
            'type' => IncidentType::class,
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

    public function isUrgent(): bool
    {
        return in_array($this->type->value, config('election.urgent_incident_types'), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        $this->loadMissing('agent', 'pollingUnit');

        return [
            'reference' => $this->reference,
            'polling_unit' => $this->pollingUnit?->toSummaryArray() ?? ['code' => $this->polling_unit_code],
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'urgent' => $this->isUrgent(),
            'note' => $this->note,
            'agent' => $this->agent->toSummaryArray(),
            'reported_at' => $this->created_at->toIso8601String(),
        ];
    }
}
