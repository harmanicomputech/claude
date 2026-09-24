# Election Shield

USSD service (Africa's Talking) for election polling agents to submit results, report incidents and confirm presence. Laravel 13, MySQL in production, SQLite in-memory for tests.

## Commands

- `php artisan test` — run the test suite
- `vendor/bin/pint` — format code (CI runs `pint --test`)
- `php artisan agent:add <phone> "<name>" [--pu=<code>]` — register an agent

## Layout

- `routes/api.php` — `POST /api/ussd`, the Africa's Talking callback
- `app/Http/Controllers/UssdController.php` — phone-number auth, error fallback
- `app/Ussd/UssdMenu.php` — the menu state machine (all screens and texts)
- `app/Services/ElectionRecorder.php` — every database write for the flows
- `app/Services/AfricasTalkingSms.php` + `app/Jobs/SendSms.php` — queued SMS
- `config/ussd.php` — instructions text, input limits, reference length

## How the USSD flow works

Africa's Talking sends the full input history each request (`text=1*02345*120*300*1`). `UssdMenu` replays every input through the state machine from the main menu, so never read inputs by position. Only END (terminal) steps may write to the database — a CON step can be replayed many times in one session.

Screen texts must stay short (USSD screens are ~160–182 characters).
