<?php

namespace App\Enums;

enum MaterialStatus: string
{
    case Arrived = 'arrived';
    case Incomplete = 'incomplete';
    case NotArrived = 'not_arrived';

    /**
     * @return list<self>
     */
    public static function menu(): array
    {
        return [self::Arrived, self::Incomplete, self::NotArrived];
    }

    public static function fromMenuOption(string $option): ?self
    {
        return ctype_digit($option) ? (self::menu()[(int) $option - 1] ?? null) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Arrived => 'Arrived (complete)',
            self::Incomplete => 'Arrived (incomplete)',
            self::NotArrived => 'Not arrived yet',
        };
    }
}
