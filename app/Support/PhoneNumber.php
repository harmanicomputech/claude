<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Normalise a phone number to E.164 (e.g. "+2348012345678") so numbers
     * typed by coordinators match the format Africa's Talking sends.
     */
    public static function normalize(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);
        $countryCode = (string) config('ussd.default_country_code');

        if (str_starts_with($number, '+') || str_starts_with($number, '00')) {
            return '+'.ltrim($digits, '0');
        }

        if (str_starts_with($digits, '0')) {
            return '+'.$countryCode.substr($digits, 1);
        }

        if (str_starts_with($digits, $countryCode)) {
            return '+'.$digits;
        }

        return '+'.$countryCode.$digits;
    }
}
