<?php

namespace App\Models;

use App\Enums\MaterialStatus;
use App\Models\Scopes\InArea;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_id', 'polling_unit_code', 'status', 'personnel_arrived', 'reported_at', 'channel'])]
class MaterialReport extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('area', new InArea('code'));
    }

    protected function casts(): array
    {
        return [
            'status' => MaterialStatus::class,
            'personnel_arrived' => 'boolean',
            'reported_at' => 'datetime',
        ];
    }

    /**
     * The agent who sent it (shown even when the agent's own PU is elsewhere).
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class)->withoutGlobalScope('area');
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_code', 'code');
    }

    public function personnelLabel(): string
    {
        return match ($this->personnel_arrived) {
            true => 'Personnel arrived',
            false => 'No personnel yet',
            null => 'Personnel not reported',
        };
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
            'personnel_arrived' => $this->personnel_arrived,
            'personnel_label' => $this->personnelLabel(),
            'agent' => $this->agent->toSummaryArray(),
            'channel' => $this->channel ?? 'ussd',
            'reported_at' => $this->reported_at->toIso8601String(),
        ];
    }
}
