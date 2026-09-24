<?php

namespace App\Models;

use App\Enums\ResultStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'agent_id', 'polling_unit_code', 'status', 'accepted_polling_unit_code',
    'corrects_result_id', 'accredited_voters', 'rejected_votes', 'total_valid_votes',
    'total_votes_cast', 'reviewed_at', 'reviewed_by', 'review_note',
])]
class Result extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ResultStatus::class,
            'accredited_voters' => 'integer',
            'rejected_votes' => 'integer',
            'total_valid_votes' => 'integer',
            'total_votes_cast' => 'integer',
            'reviewed_at' => 'datetime',
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

    public function votes(): HasMany
    {
        // Inserted in ballot order.
        return $this->hasMany(ResultVote::class)->orderBy('id');
    }

    /**
     * The result this correction replaces.
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'corrects_result_id');
    }

    public function isCorrection(): bool
    {
        return $this->corrects_result_id !== null;
    }

    /**
     * Party votes in configured ballot order, e.g. ['APC' => 120, 'PDP' => 80].
     *
     * @return array<string, int>
     */
    public function votesByParty(): array
    {
        return $this->votes->pluck('votes', 'party')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        $this->loadMissing('agent', 'pollingUnit', 'votes', 'corrects');

        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'polling_unit' => $this->pollingUnit?->toSummaryArray() ?? ['code' => $this->polling_unit_code],
            'accredited_voters' => $this->accredited_voters,
            'votes' => $this->votesByParty(),
            'total_valid_votes' => $this->total_valid_votes,
            'rejected_votes' => $this->rejected_votes,
            'total_votes_cast' => $this->total_votes_cast,
            'corrects_reference' => $this->corrects?->reference,
            'agent' => $this->agent->toSummaryArray(),
            'submitted_at' => $this->created_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'review_note' => $this->review_note,
        ];
    }
}
