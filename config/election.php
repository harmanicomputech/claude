<?php

$list = fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

return [

    'name' => env('ELECTION_NAME', 'Ebonyi State Governorship Election'),

    /*
    |--------------------------------------------------------------------------
    | Date, Timezone and Submission Windows
    |--------------------------------------------------------------------------
    |
    | Times are in the election timezone. Presence check-in opens at
    | presence_opens_at on election day, results open at results_open_at
    | (after polls close) and stay open until results_close_at (a full
    | "Y-m-d H:i" datetime, or empty for no closing time). Incidents can be
    | reported at any time.
    |
    */

    'date' => env('ELECTION_DATE', '2027-02-06'),

    'timezone' => env('ELECTION_TIMEZONE', 'Africa/Lagos'),

    'enforce_windows' => (bool) env('ELECTION_ENFORCE_WINDOWS', true),

    'presence_opens_at' => env('ELECTION_PRESENCE_OPENS_AT', '07:00'),

    'results_open_at' => env('ELECTION_RESULTS_OPEN_AT', '14:30'),

    'results_close_at' => env('ELECTION_RESULTS_CLOSE_AT', '2027-02-08 23:59'),

    /*
    |--------------------------------------------------------------------------
    | Parties (EC8A)
    |--------------------------------------------------------------------------
    |
    | Agents enter votes for each party in this order. Keep the list short:
    | every party is one more USSD screen. "OTHERS" captures the combined
    | votes of every other party on the ballot.
    |
    | Candidates are shown next to their party in the summary email and the
    | reports API. Update them here if the field changes.
    |
    */

    'parties' => $list(env('ELECTION_PARTIES', 'APC,PDP,LP,OTHERS')),

    'candidates' => [
        'APC' => 'Francis Ogbonna Nwifuru',
        'PDP' => 'Ifeanyi Chukwuma Odii',
        'LP' => 'Splendor Oko Eze',
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling Unit Register
    |--------------------------------------------------------------------------
    |
    | When true, only PU codes imported with `pu:import` are accepted.
    |
    */

    'require_known_polling_unit' => (bool) env('ELECTION_REQUIRE_KNOWN_PU', true),

    /*
    |--------------------------------------------------------------------------
    | Agent PIN
    |--------------------------------------------------------------------------
    */

    'pin_max_attempts' => (int) env('ELECTION_PIN_MAX_ATTEMPTS', 3),

    'pin_lock_minutes' => (int) env('ELECTION_PIN_LOCK_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Urgent Incident Alerts
    |--------------------------------------------------------------------------
    |
    | Incident types that immediately SMS the coordinators for the PU's LGA
    | (and state-wide coordinators).
    |
    */

    'urgent_incident_types' => $list(env('ELECTION_URGENT_INCIDENT_TYPES', 'violence')),

    /*
    |--------------------------------------------------------------------------
    | Election-Day Reminders
    |--------------------------------------------------------------------------
    |
    | SMS reminders sent on election day to agents who have not confirmed
    | presence / submitted a result by these times. Empty disables one.
    |
    */

    'presence_reminder_at' => env('ELECTION_PRESENCE_REMINDER_AT', '08:00'),

    'results_reminder_at' => env('ELECTION_RESULTS_REMINDER_AT', '17:00'),

    /*
    |--------------------------------------------------------------------------
    | Coordinator Email
    |--------------------------------------------------------------------------
    |
    | email_each_result: one email per accepted result (incidents and
    | correction requests are always emailed). hourly_summary: an hourly
    | summary email from presence opening until results close.
    |
    */

    'email_each_result' => (bool) env('NOTIFY_EMAIL_EACH_RESULT', true),

    'hourly_summary' => (bool) env('NOTIFY_HOURLY_SUMMARY', true),

    /*
    |--------------------------------------------------------------------------
    | Coordinator API
    |--------------------------------------------------------------------------
    |
    | Bearer token for /api/corrections and /api/reports. Empty disables them.
    |
    */

    'api_token' => env('ELECTION_API_TOKEN'),

];
