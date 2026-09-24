<?php

namespace App\Models;

use App\Enums\MaterialStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_id', 'polling_unit_code', 'status', 'reported_at'])]
class MaterialReport extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MaterialStatus::class,
            'reported_at' => 'datetime',
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
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'agent' => $this->agent->toSummaryArray(),
            'reported_at' => $this->reported_at->toIso8601String(),
        ];
    }
}
