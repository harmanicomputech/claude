<?php

namespace App\Support;

/**
 * Rehearsal mode (switched on in the admin console): submission windows are
 * open, and everything agents and coordinators receive is labelled as a
 * rehearsal so nobody mistakes practice figures for real ones.
 */
class Rehearsal
{
    public const SETTING = 'rehearsal_mode';

    public static function active(): bool
    {
        return Settings::bool(self::SETTING);
    }

    /**
     * "[REHEARSAL] " while rehearsal mode is on, otherwise "".
     */
    public static function prefix(): string
    {
        return self::active() ? '[REHEARSAL] ' : '';
    }
}
