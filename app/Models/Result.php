<?php

namespace App\Models;

use App\Contracts\DashboardRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reference', 'agent_id', 'polling_unit_code', 'candidate_votes', 'total_votes', 'dashboard_synced_at'])]
class Result extends Model implements DashboardRecord
{
    protected function casts(): array
    {
        return [
            'dashboard_synced_at' => 'datetime',
            'candidate_votes' => 'integer',
            'total_votes' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function dashboardEvent(): string
    {
        return 'result.submitted';
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
            'candidate_votes' => $this->candidate_votes,
            'total_votes' => $this->total_votes,
            'agent' => [
                'name' => $this->agent->name,
                'phone_number' => $this->agent->phone_number,
            ],
            'submitted_at' => $this->created_at->toIso8601String(),
        ];
    }
}
