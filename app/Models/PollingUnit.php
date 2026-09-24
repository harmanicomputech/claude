<?php

namespace App\Models;

use Database\Factories\PollingUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['code', 'name', 'ward', 'lga', 'registered_voters'])]
class PollingUnit extends Model
{
    /** @use HasFactory<PollingUnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'registered_voters' => 'integer',
        ];
    }

    /**
     * Turn an INEC code such as "11-05-03-004" or "11/05/03/004" into the
     * digits-only form agents type on USSD ("110503004").
     */
    public static function normalizeCode(string $code): string
    {
        return preg_replace('/\D/', '', $code);
    }

    /**
     * Whether a (normalised) code may be assigned to an agent: it must match
     * the USSD format and, when the register is enforced, be in it.
     */
    public static function isAssignable(string $code): bool
    {
        if (! preg_match(config('ussd.polling_unit_pattern'), $code)) {
            return false;
        }

        return ! config('election.require_known_polling_unit') || static::where('code', $code)->exists();
    }

    public static function findByCode(string $code): ?self
    {
        return static::where('code', static::normalizeCode($code))->first();
    }

    /**
     * Short name for USSD screens.
     */
    public function shortName(int $length = 20): string
    {
        return Str::limit($this->name, $length, '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'ward' => $this->ward,
            'lga' => $this->lga,
            'registered_voters' => $this->registered_voters,
        ];
    }
}
