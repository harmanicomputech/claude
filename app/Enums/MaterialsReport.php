<?php

namespace App\Enums;

/**
 * The one "Material Status" answer agents give on USSD, covering the
 * materials and the INEC officials (personnel). Stored as two fields: the
 * materials status and whether the personnel had arrived (null: not said).
 */
enum MaterialsReport: string
{
    case Both = 'both';
    case MaterialsOnly = 'materials_only';
    case PersonnelOnly = 'personnel_only';
    case Incomplete = 'incomplete';
    case Neither = 'neither';

    /**
     * @return list<self>
     */
    public static function menu(): array
    {
        return self::cases();
    }

    public static function fromMenuOption(string $option): ?self
    {
        return ctype_digit($option) ? (self::menu()[(int) $option - 1] ?? null) : null;
    }

    /**
     * Short, for the USSD screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::Both => 'Materials & personnel arrived',
            self::MaterialsOnly => 'Materials only',
            self::PersonnelOnly => 'Personnel only',
            self::Incomplete => 'Materials incomplete',
            self::Neither => 'Neither has arrived',
        };
    }

    public function materials(): MaterialStatus
    {
        return match ($this) {
            self::Both, self::MaterialsOnly => MaterialStatus::Arrived,
            self::Incomplete => MaterialStatus::Incomplete,
            self::PersonnelOnly, self::Neither => MaterialStatus::NotArrived,
        };
    }

    public function personnelArrived(): ?bool
    {
        return match ($this) {
            self::Both, self::PersonnelOnly => true,
            self::MaterialsOnly, self::Neither => false,
            self::Incomplete => null,
        };
    }
}
