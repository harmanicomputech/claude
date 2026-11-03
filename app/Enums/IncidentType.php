<?php

namespace App\Enums;

enum IncidentType: string
{
    case BallotSnatching = 'ballot_snatching';
    case Malpractice = 'malpractice';
    case Intimidation = 'intimidation';
    case Violence = 'violence';

    // No longer offered on the menu; kept so earlier reports still read.
    case VoteSuppression = 'vote_suppression';
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
        return [self::BallotSnatching, self::Malpractice, self::Intimidation, self::Violence];
    }

    public static function fromMenuOption(string $option): ?self
    {
        return ctype_digit($option) ? (self::menu()[(int) $option - 1] ?? null) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::BallotSnatching => 'Ballot box/Result snatching',
            self::Malpractice => 'Malpractice',
            self::Intimidation => 'Intimidation',
            self::Violence => 'Violence',
            self::VoteSuppression => 'Vote Suppression',
            self::VoteBuying => 'Vote Buying',
            self::Delay => 'Delay',
            self::Other => 'Other',
        };
    }
}
