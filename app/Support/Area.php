<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The area the signed-in console user may see. Admins see everything;
 * every other account sees only its LGA, or its ward when one is set
 * (an account with no LGA sees nothing until an admin assigns one).
 *
 * Set for each console request by LimitToArea; empty outside a request
 * (USSD callbacks, jobs, commands), so nothing else is affected.
 */
final class Area
{
    /** @var array{lga: ?string, ward: ?string}|null */
    private static ?array $current = null;

    public static function limitTo(User $user): void
    {
        self::$current = $user->isAdmin() ? null : ['lga' => $user->lga ?: null, 'ward' => $user->lga ? ($user->ward ?: null) : null];
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    public static function restricted(): bool
    {
        return self::$current !== null;
    }

    public static function lga(): ?string
    {
        return self::$current['lga'] ?? null;
    }

    public static function ward(): ?string
    {
        return self::$current['ward'] ?? null;
    }

    /**
     * Restricted, but no LGA assigned yet: sees nothing.
     */
    public static function unassigned(): bool
    {
        return self::restricted() && self::lga() === null;
    }

    /**
     * Limit a query on a table with lga / ward columns.
     */
    public static function onPlaceColumns($query, string $lgaColumn = 'lga', string $wardColumn = 'ward'): void
    {
        if (! self::restricted()) {
            return;
        }
        if (self::unassigned()) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where($lgaColumn, self::lga());
        if (self::ward() !== null) {
            $query->where($wardColumn, self::ward());
        }
    }

    /**
     * The PU codes in the area (a subquery).
     */
    public static function codes(): QueryBuilder
    {
        $query = DB::table('polling_units')->select('code');
        self::onPlaceColumns($query);

        return $query;
    }

    /**
     * Limit a query on a table with a polling unit code column.
     */
    public static function onCodes($query, string $column = 'polling_unit_code'): void
    {
        if (self::restricted()) {
            $query->whereIn($column, self::codes());
        }
    }

    /**
     * For screens: "Abakaliki LGA" or "Abakpa ward, Abakaliki".
     */
    public static function label(): ?string
    {
        return match (true) {
            ! self::restricted() => null,
            self::unassigned() => 'No area assigned',
            self::ward() !== null => self::ward().' ward, '.self::lga(),
            default => self::lga().' LGA',
        };
    }
}
