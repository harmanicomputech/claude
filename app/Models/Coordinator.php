<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'phone_number', 'email', 'lga'])]
class Coordinator extends Model
{
    protected function phoneNumber(): Attribute
    {
        return Attribute::set(fn (string $value) => PhoneNumber::normalize($value));
    }

    /**
     * Coordinators responsible for an LGA, plus state-wide coordinators.
     */
    public function scopeCovering(Builder $query, ?string $lga): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('lga')
            ->when($lga !== null, fn (Builder $query) => $query->orWhere('lga', $lga)));
    }
}
