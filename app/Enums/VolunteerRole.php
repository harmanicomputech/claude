<?php

namespace App\Enums;

/**
 * "How can you help?" answers. The USSD menu shows the short labels; the
 * web app and CSVs use the full ones.
 */
enum VolunteerRole: string
{
    case Canvass = 'canvass';
    case PollingUnitAgent = 'pu_agent';
    case Share = 'share';
    case MobiliseWomen = 'women';
    case MobiliseYouth = 'youth';
    case Transport = 'transport';
    case Professional = 'professional';
    case Other = 'other';

    /**
     * Menu order: the case at index 0 is option 1.
     *
     * @return list<self>
     */
    public static function menu(): array
    {
        return self::cases();
    }

    public function label(): string
    {
        return match ($this) {
            self::Canvass => 'Canvass in my ward',
            self::PollingUnitAgent => 'Serve as a polling unit agent',
            self::Share => 'Share on WhatsApp and social media',
            self::MobiliseWomen => 'Mobilise women',
            self::MobiliseYouth => 'Mobilise youth',
            self::Transport => 'Transport and logistics',
            self::Professional => 'Professional skills (legal, media, medical, IT)',
            self::Other => 'Anything else',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Canvass => 'Canvass my ward',
            self::PollingUnitAgent => 'PU agent',
            self::Share => 'Share on WhatsApp',
            self::MobiliseWomen => 'Mobilise women',
            self::MobiliseYouth => 'Mobilise youth',
            self::Transport => 'Transport/logistics',
            self::Professional => 'Legal/media/medical/IT',
            self::Other => 'Other',
        };
    }

    /** Professional skills, asked when Professional is chosen. */
    public const SKILLS = ['legal' => 'Legal', 'media' => 'Media', 'medical' => 'Medical', 'it' => 'IT', 'other' => 'Other'];
}
