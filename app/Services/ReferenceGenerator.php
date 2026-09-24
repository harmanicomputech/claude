<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ReferenceGenerator
{
    private const MAX_ATTEMPTS = 20;

    /**
     * Generate an unused reference such as "RS784321" for the given model.
     *
     * @param  class-string<Model>  $model
     */
    public function generate(string $prefix, string $model): string
    {
        $digits = config('ussd.reference_digits');

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $reference = $prefix.str_pad((string) random_int(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);

            if (! $model::where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException("Could not generate a unique {$prefix} reference; increase ussd.reference_digits.");
    }
}
