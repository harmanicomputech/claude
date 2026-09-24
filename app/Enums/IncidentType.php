<?php

namespace App\Enums;

enum IncidentType: string
{
    case Violence = 'violence';
    case VoteBuying = 'vote_buying';
    case Delay = 'delay';
    case Other = 'other';

    /**
     * The incident type for a USSD menu option (1-4), in menu order.
     */
    public static function fromMenuOption(string $option): ?self
    {
        return match ($option) {
            '1' => self::Violence,
            '2' => self::VoteBuying,
            '3' => self::Delay,
            '4' => self::Other,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Violence => 'Violence',
            self::VoteBuying => 'Vote Buying',
            self::Delay => 'Delay',
            self::Other => 'Other',
        };
    }
}
