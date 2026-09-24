<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone_number', 'polling_unit_code', 'is_active', 'last_seen_at'])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    protected function phoneNumber(): Attribute
    {
        return Attribute::set(fn (string $value) => PhoneNumber::normalize($value));
    }

    public static function findByPhone(string $phoneNumber): ?self
    {
        return static::where('phone_number', PhoneNumber::normalize($phoneNumber))->first();
    }

    public function hasAssignedPollingUnit(): bool
    {
        return filled($this->polling_unit_code);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function presences(): HasMany
    {
        return $this->hasMany(Presence::class);
    }
}
