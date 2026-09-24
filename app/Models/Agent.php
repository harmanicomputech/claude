<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

#[Fillable(['name', 'phone_number', 'polling_unit_code', 'pin', 'is_active', 'last_seen_at'])]
#[Hidden(['pin'])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'pin' => 'hashed',
            'failed_pin_attempts' => 'integer',
            'locked_until' => 'datetime',
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

    public function hasPin(): bool
    {
        return filled($this->pin);
    }

    public function isLocked(): bool
    {
        return $this->locked_until?->isFuture() ?? false;
    }

    public function pinMatches(string $pin): bool
    {
        return $this->hasPin() && Hash::check($pin, $this->pin);
    }

    /**
     * Count a wrong PIN, locking the agent out once the limit is reached.
     * Returns the number of attempts left (0 when now locked).
     */
    public function recordFailedPin(): int
    {
        $max = config('election.pin_max_attempts');
        $attempts = $this->failed_pin_attempts + 1;

        if ($attempts >= $max) {
            $this->forceFill([
                'failed_pin_attempts' => 0,
                'locked_until' => now()->addMinutes(config('election.pin_lock_minutes')),
            ])->save();

            return 0;
        }

        $this->forceFill(['failed_pin_attempts' => $attempts])->save();

        return $max - $attempts;
    }

    public function clearFailedPins(): void
    {
        if ($this->failed_pin_attempts > 0 || $this->locked_until !== null) {
            $this->forceFill(['failed_pin_attempts' => 0, 'locked_until' => null])->save();
        }
    }

    /**
     * @return array<string, string>
     */
    public function toSummaryArray(): array
    {
        return [
            'name' => $this->name,
            'phone_number' => $this->phone_number,
        ];
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_code', 'code');
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
