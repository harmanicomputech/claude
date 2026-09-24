<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Country Code
    |--------------------------------------------------------------------------
    |
    | Used to turn local numbers (e.g. 08012345678) into E.164 format when
    | agents are registered, so they match what Africa's Talking sends.
    |
    */

    'default_country_code' => env('USSD_COUNTRY_CODE', '234'),

    /*
    |--------------------------------------------------------------------------
    | Instructions
    |--------------------------------------------------------------------------
    |
    | Shown for menu option 4. Use "\n" for line breaks and keep the whole
    | screen under ~160 characters.
    |
    */

    'instructions' => env(
        'USSD_INSTRUCTIONS',
        "Stay at PU.\nSubmit results after counting.\nReport any issue immediately."
    ),

    /*
    |--------------------------------------------------------------------------
    | Input Limits
    |--------------------------------------------------------------------------
    */

    'polling_unit_pattern' => '/^\d{2,12}$/',

    'max_vote_digits' => 7,

    'max_note_length' => 30,

    /*
    |--------------------------------------------------------------------------
    | Reference Numbers
    |--------------------------------------------------------------------------
    |
    | References look like RS784321 / IN452190. Six digits leaves room for
    | every polling unit in the country without collisions piling up.
    |
    */

    'reference_digits' => (int) env('USSD_REFERENCE_DIGITS', 6),

    /*
    |--------------------------------------------------------------------------
    | SMS Confirmation
    |--------------------------------------------------------------------------
    */

    'sms_confirmation' => (bool) env('USSD_SMS_CONFIRMATION', true),

];
