# Election Shield

USSD service (Africa's Talking) for the Ebonyi State election (6 Feb 2027) that lets polling agents submit EC8A results, report incidents and confirm presence. Laravel 13, MySQL in production, SQLite in-memory for tests.

## Commands

- `php artisan test` — run the test suite
- `vendor/bin/pint` — format code (CI runs `pint --test`)
- `php artisan pu:import <csv>`, `agent:add`, `agent:pin`, `coordinator:add` — set-up
- `php artisan result:review list|approve|reject`, `election:missing`, `election:remind`, `election:summary`, `dashboard:sync` — election day

## Layout

- `routes/api.php` — `POST /api/ussd/{secret?}` (AT callback) and the coordinator API
- `app/Http/Controllers/UssdController.php` — phone-number auth, error fallback
- `app/Http/Middleware/VerifyUssdRequest.php` — callback secret + IP allowlist
- `app/Ussd/UssdMenu.php` — the menu state machine (all screens and texts)
- `app/Services/ElectionRecorder.php` — every write the USSD flows make, plus the SMS / email / dashboard / alert side effects
- `app/Services/CorrectionReviewer.php` — approving / rejecting corrections
- `app/Services/DashboardOutbox.php`, `DashboardClient.php`, `app/Jobs/PushToDashboard.php` — outbox + signed webhook (contract documented in README; keep them in sync)
- `app/Services/ElectionStats.php` — summary and missing-PU figures (summary email, reports API, `election:missing`)
- `app/Support/ElectionCalendar.php` — election date, submission windows
- `app/Http/Controllers/Admin/`, `resources/views/admin/` — web admin console at `/admin`; the production host is shared hosting with no terminal, so every operator task must be doable there. Accounts are `users` with `role` admin|coordinator (admin-only routes use `RequireAdminRole`); the first admin is created with ADMIN_PASSWORD as a setup key. Record sensitive actions with `App\Support\Audit::record()`.
- `app/Support/Queues.php` — queue names; workers must run `--queue=high,default,bulk,mail` so SMS/alerts never wait behind emails
- `app/Support/Rehearsal.php`, `Settings.php`, `app/Services/TestDataCleaner.php` — rehearsal mode (console setting) and clearing test data
- `app/Services/AgentRegistrar.php`, `AgentImporter.php` — agent registration shared by CLI and console
- `docs/WEB-APP-HANDOFF.md` — integration brief for the separate Election Shield web app (webhook events, API); keep it in sync with DashboardClient/README
- `scripts/build-shared-hosting.sh`, `deploy/shared-hosting/`, `docs/DEPLOY-SHARED-HOSTING.md` — upload package for cPanel/DirectAdmin
- `config/election.php` — date, windows, parties + candidates, PIN, alerts, reminders; `config/ussd.php` — USSD limits and callback protection
- `database/data/ebonyi_polling_units.csv` — the PU register (3,308 PUs; codes like `EB/212/02633/007`, typed by agents as `21202633007`). `db:seed` imports it.

## How the USSD flow works

Africa's Talking sends the full input history each request (`text=1*110101001*300*120*...`). `UssdMenu` replays every input through the state machine from the main menu, so never read inputs by position. Only END (terminal) steps may write to the database — a CON step can be replayed many times in one session. The one exception, counting wrong PINs, only happens for the latest input (`$isLatestInput`).

Screen texts must stay under 182 characters (`test_screens_fit_on_a_ussd_display`).

Results: one `accepted` result per PU, enforced by the unique `accepted_polling_unit_code` column (null for every other status). Corrections are `pending` until reviewed; approval marks the old result `superseded`.

Tests use parties `APC,PDP,LP` and disable submission windows (see `phpunit.xml`); `tests/Concerns/InteractsWithUssd.php` sets up a PU and an agent with PIN 1234.
