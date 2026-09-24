<?php

namespace App\Enums;

enum IncidentType: string
{
    case Violence = 'violence';
    case VoteSuppression = 'vote_suppression';
    case Malpractice = 'malpractice';
    case VoteBuying = 'vote_buying';
    case Delay = 'delay';
    case Other = 'other';

    /**
     * USSD menu options in order: the case at index 0 is option 1.
     *
     * @return list<self>
     */
    public static function menu(): array
    {
        return [self::Violence, self::VoteSuppression, self::Malpractice, self::VoteBuying, self::Delay, self::Other];
    }

    public static function fromMenuOption(string $option): ?self
    {
        return ctype_digit($option) ? (self::menu()[(int) $option - 1] ?? null) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Violence => 'Violence',
            self::VoteSuppression => 'Vote Suppression',
            self::Malpractice => 'Malpractice',
            self::VoteBuying => 'Vote Buying',
            self::Delay => 'Delay',
            self::Other => 'Other',
        };
    }
}
