<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['result_id', 'party', 'votes'])]
class ResultVote extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'votes' => 'integer',
        ];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }
}
