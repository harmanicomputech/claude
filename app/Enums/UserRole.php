<?php

namespace App\Enums;

enum UserRole: string
{
    /** Everything, including set-up, agents, users, settings and clearing data. */
    case Admin = 'admin';

    /** Sees all data and exports, and reviews corrections. */
    case Coordinator = 'coordinator';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Coordinator => 'Coordinator',
        };
    }
}
