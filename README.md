# Election Shield

A USSD service built on [Africa's Talking](https://africastalking.com) for the Ebonyi State election on 6 February 2027. Registered polling agents can use it from any phone to:

1. **Submit Result**: the EC8A figures (accredited voters, votes for APC, PDP, LP and all other parties, and rejected votes), protected by a PIN. Wrong results can be corrected with a coordinator's approval.
2. **Report Incident**: violence, vote buying, delay or other. Violence immediately texts the coordinators for that area.
3. **Confirm Presence** at the polling unit.
4. **Instructions**
5. **Exit**

Every result, incident and presence check-in is pushed to an external dashboard. Coordinators receive emails, an hourly summary, and SMS alerts for urgent incidents. Agents who haven't reported get SMS reminders on election day.

Built with Laravel 13 and MySQL.

## USSD flow

```
*XXX#
CON Election Shield
1. Submit Result    → PU code → Accredited Voters → Votes for APC → PDP → LP → OTHERS → Rejected Votes
                      → Confirm (1.Submit 2.Edit 3.Cancel) → Enter PIN → END Submitted ✔ Ref: RS123456
2. Report Incident  → Type → PU code → Short Note → Confirm → END Incident Logged ✔ Ref: IN123456
3. Confirm Presence → PU code → END Presence Confirmed ✔
4. Instructions     → END Stay at PU. …
5. Exit             → END Thank you
```

Example confirmation screen, for a real PU from the register (it fits the 182-character USSD limit even with large numbers):

```
Confirm:
PU:21202633007
Police Station Area 007
Acc:1200 Rej:21
APC:610 PDP:402
LP:95 OTHERS:18
Valid:1125
1.Submit 2.Edit 3.Cancel
```

### Governorship ballot

| Party | Candidate |
| --- | --- |
| APC | Francis Ogbonna Nwifuru |
| PDP | Ifeanyi Chukwuma Odii |
| LP | Splendor Oko Eze |
| OTHERS | Combined votes of every other party on the ballot |

Parties are set with `ELECTION_PARTIES`; candidate names live in `config/election.php` and appear in the summary email and reports API.

### Rules built into the flow

| Rule | Behaviour |
| --- | --- |
| Authentication | Phone numbers that aren't registered agents get `END Access denied. Contact coordinator.` |
| PU register | Only PU codes from the imported INEC register are accepted (`PU code not found`). The PU name is shown before voting figures are entered, so a mistyped code is easy to spot. |
| Invalid input | `Invalid input. Enter number only:` The agent can re-enter and carry on. |
| Accredited > registered | `Error: Accredited cannot exceed registered voters (N). Re-enter accredited:` |
| Votes cast > accredited | Valid votes plus rejected votes can't exceed accredited voters. The agent chooses *Re-enter accredited* or *Start again*. |
| PIN | Required to submit a result. After 3 wrong PINs the agent is locked out for 30 minutes. Only the latest input counts toward the limit, so replayed inputs aren't counted twice. |
| Duplicate result | The agent is offered *Request correction*. The correction is stored as **pending** and doesn't count until a coordinator approves it. |
| Time windows | Presence opens at 07:00 on election day and results open at 14:30. Both close at `ELECTION_RESULTS_CLOSE_AT`. Incidents can be reported at any time. |
| Auto-PU detection | Agents registered with `--pu` are never asked for a PU code. |
| Quick codes | `*XXX*1*21202633007*1200*610*402*95*18*21#` goes straight to the confirmation screen, which still asks for the PIN. |
| SMS | The agent gets a receipt for each result and correction request, and a message when a correction is approved or rejected. |

Africa's Talking sends the whole session so far as one string (`text=1*21202633007*1200*…`). `app/Ussd/UssdMenu.php` replays these inputs through a state machine on every request, which is how retries, "Edit" and corrections work.

## Setup

**Shared hosting (cPanel / DirectAdmin, no terminal):** follow [docs/DEPLOY-SHARED-HOSTING.md](docs/DEPLOY-SHARED-HOSTING.md).

### Admin console

Open `https://your-domain/admin`. The first visit creates the first admin account, using `ADMIN_PASSWORD` as a one-time setup key; after that, people log in with their own accounts (admin or coordinator). The console has:

- a set-up checklist and buttons to set up the database, import the PU register and send a test email
- agents: add them one at a time or by CSV upload, reset PINs and unlock accounts
- coordinators, correction review, and the missing-PU lists

Build the upload package with `scripts/build-shared-hosting.sh`, or `--update` for an update without `.env`. On shared hosting, `SCHEDULER_RUNS_QUEUE=true` lets the single every-minute cron job also process the queue.

### Server with a terminal


Requirements: PHP 8.3+, Composer, MySQL 8.

```bash
composer install
cp .env.example .env
php artisan key:generate
# set DB_*, AFRICASTALKING_*, MAIL_*, NOTIFY_EMAILS, DASHBOARD_*, USSD_CALLBACK_SECRET in .env
php artisan migrate
```

### 1. Import the polling unit register

The Ebonyi register is in `database/data/ebonyi_polling_units.csv`: 3,308 PUs in 13 LGAs and 169 wards. The columns are `code,name,ward,lga,registered_voters`, and `registered_voters` is optional.

```bash
php artisan pu:import database/data/ebonyi_polling_units.csv
```

Codes are stored as digits only, which is what agents type: `EB/212/02633/007` becomes **`21202633007`**. Print a list of each agent's code for them. Re-running the import (for example with an updated file) updates existing polling units and adds new ones.

### 2. Register agents and coordinators

```bash
php artisan agent:add 08012345678 "Ada Obi" --pu=EB/212/02633/007 --sms-pin   # random PIN, texted to the agent
php artisan agent:add 08012345679 "Chidi Eze" --pin=4821                   # any PU, chosen PIN
php artisan agent:pin 08012345678 --sms                                    # reset a PIN / unlock
php artisan agent:import agents.csv --sms-pins                             # bulk: name,phone,pu_code,pin

php artisan coordinator:add 08020000001 "Abakaliki Lead" --lga=Abakaliki --email=lead@example.com
php artisan coordinator:add 08020000009 "State Lead"                      # state-wide
```

Coordinators receive SMS alerts for urgent incidents in their LGA. State-wide coordinators receive alerts for every LGA.

### 3. Run it

```bash
php artisan serve
php artisan queue:work --queue=high,default,bulk,mail   # SMS and alerts first, emails last
php artisan schedule:work   # reminders, hourly summary, dashboard resend (use cron in production)
```

Every notification runs on the queue, so the USSD reply is never delayed. In production, keep a queue worker running under a process manager such as Supervisor, and add the Laravel scheduler to cron:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan db:seed` imports the Ebonyi register and adds demo agents `+2348000000001` (any PU) and `+2348000000002` (assigned to PU 21202633002), both with PIN `1234`, plus a state-wide coordinator. Remove the demo people before election day.

## Election day tools

| Command | What it does |
| --- | --- |
| `php artisan election:missing presence` | PUs with no agent checked in today, grouped by LGA and ward, with the agent's name and phone number. Add `--lga=Ikwo` to filter. |
| `php artisan election:missing results` | PUs with no accepted result. |
| `php artisan election:remind presence` | Texts every agent who hasn't checked in. Runs automatically at 08:00 on election day. |
| `php artisan election:remind results` | Texts every agent whose PU has no result. Runs automatically at 17:00 on election day. |
| `php artisan election:summary` | Emails the summary now. It's sent automatically every hour from 07:00 until results close. `--print` shows the figures instead. |
| `php artisan result:review list` | Shows pending corrections next to the current result. |
| `php artisan result:review approve RS123456 --by="Name" --note="…"` | Approves a correction. It becomes the PU's result and the old one is kept as *superseded*. |
| `php artisan result:review reject RS123456 --note="…"` | Rejects a correction. |
| `php artisan dashboard:sync` | Resends dashboard events that never got through. Runs every 15 minutes. |

## Coordinator API

For your dashboard. Set `ELECTION_API_TOKEN` and send `Authorization: Bearer {token}`.

| Endpoint | |
| --- | --- |
| `GET /api/corrections?status=pending` | Corrections (`pending`, `accepted` or `rejected`) |
| `POST /api/corrections/{reference}/approve` | Body: `reviewed_by`, `note` (both optional). Returns 409 if the correction has already been reviewed. |
| `POST /api/corrections/{reference}/reject` | Same body as approve |
| `GET /api/reports/summary` | The same figures as the hourly email: turnout, party totals, incidents, and a breakdown by LGA |
| `GET /api/reports/missing?type=presence\|results&lga=…` | PUs missing presence or a result, with their assigned agents |

## Dashboard webhook

Set `DASHBOARD_WEBHOOK_URL`. Every event is saved in an outbox table and then posted:

```http
POST {DASHBOARD_WEBHOOK_URL}
Content-Type: application/json
Authorization: Bearer {DASHBOARD_API_TOKEN}
Idempotency-Key: result.submitted:RS784321
X-Election-Shield-Event: result.submitted
X-Election-Shield-Signature: sha256=<HMAC-SHA256 of the raw body using DASHBOARD_WEBHOOK_SECRET>

{
  "event": "result.submitted",
  "sent_at": "2027-02-06T15:04:11+00:00",
  "data": {
    "reference": "RS784321",
    "status": "accepted",
    "polling_unit": { "code": "21202633007", "name": "Police Station Area 007", "ward": "Abakaliki Ward 01", "lga": "Abakaliki", "registered_voters": 1507 },
    "accredited_voters": 1200,
    "votes": { "APC": 610, "PDP": 402, "LP": 95, "OTHERS": 18 },
    "total_valid_votes": 1125,
    "rejected_votes": 21,
    "total_votes_cast": 1146,
    "corrects_reference": null,
    "agent": { "name": "Ada Obi", "phone_number": "+2348012345678" },
    "submitted_at": "2027-02-06T15:04:10+00:00",
    "reviewed_at": null, "reviewed_by": null, "review_note": null
  }
}
```

| Event | When | `data` |
| --- | --- | --- |
| `result.submitted` | A PU's first result | result (as above) |
| `result.correction_requested` | An agent asks to correct a result | result with `status: "pending"` and `corrects_reference` |
| `result.corrected` | A coordinator approves a correction | result with `status: "accepted"`, plus `superseded_reference`. **Replace** the PU's figures with these. |
| `result.correction_rejected` | A coordinator rejects a correction | result with `status: "rejected"` |
| `incident.reported` | Incident | `reference`, `polling_unit`, `type`, `type_label`, `urgent`, `note`, `agent`, `reported_at` |
| `presence.confirmed` | Check-in | `id`, `polling_unit`, `agent`, `confirmed_at` |

The dashboard should:

- **Reply with any 2xx status** once it has stored the event. Anything else is retried 8 times over about 25 minutes, and after that `dashboard:sync` keeps resending.
- **Ignore repeats:** treat a repeated `Idempotency-Key` as already received.
- **Verify the signature**, if you set a secret:

  ```php
  hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signatureHeader)
  ```

To total results correctly, count only results with `status: "accepted"`. When `result.corrected` arrives, replace the PU's figures.

Every event's `data` also carries `"rehearsal": true|false`. Keep rehearsal data apart from real results, or discard it.

**Connecting a dashboard after data exists:** press **Settings → Send all existing data to the dashboard**, or run `php artisan dashboard:backfill`. This re-sends every result, incident and check-in using the same idempotency keys, so repeats are harmless.

## Email notifications

Set `NOTIFY_EMAILS` (comma-separated) and configure a real mailer (`MAIL_MAILER`, `MAIL_HOST`, …). Coordinators then receive:

- one email per accepted result (turn off with `NOTIFY_EMAIL_EACH_RESULT=false`)
- one email per incident, marked **URGENT** for violence
- one email per correction request, showing the old and proposed figures side by side
- the hourly summary: turnout, votes per party, incidents, and results received per LGA (turn off with `NOTIFY_HOURLY_SUMMARY=false`)

Check the settings with `php artisan election:test-email` (or `election:test-email you@example.com`). It sends one email immediately and prints the SMTP error if it fails.

Election day will produce thousands of emails. Personal Gmail accounts can only *send* about 500 a day, so use a transactional provider such as Resend, Mailgun, Brevo or Amazon SES. The address the emails are sent *to* can still be a Gmail address.

## Securing the USSD callback

Anyone who finds `/api/ussd` could otherwise post fake results under an agent's phone number. Before going live:

1. Set `USSD_CALLBACK_SECRET` to a long random string, for example the output of `openssl rand -hex 24`.
2. In Africa's Talking, set the callback URL to `https://your-domain/api/ussd/{that-secret}`. Without the secret, the endpoint returns 404.
3. Optionally, set `USSD_ALLOWED_IPS` to Africa's Talking's IP ranges. If the app is behind a load balancer or Cloudflare, configure Laravel's trusted proxies so the real client IP is seen.

## Connecting Africa's Talking

1. In the Africa's Talking dashboard (use the sandbox first), create a USSD service code and set `USSD_SERVICE_CODE` to it. The code is quoted in reminder SMS messages.
2. Set the callback URL as described above. For local testing, expose `php artisan serve` with a tunnel such as ngrok.
3. Dial the code in the AT simulator using a registered agent's number.
4. For SMS, set `AFRICASTALKING_USERNAME` and `AFRICASTALKING_API_KEY`. The username `sandbox` uses the sandbox API. Without an API key, SMS messages are written to the log instead of being sent.

To test without Africa's Talking (set `ELECTION_ENFORCE_WINDOWS=false` to try it before election day):

```bash
curl -X POST http://localhost:8000/api/ussd \
  -d "sessionId=test&serviceCode=*384*1#&phoneNumber=+2348000000001&text=1*21202633007*1200*610*402*95*18*21"
```

## Configuration

| Env var | Default | Purpose |
| --- | --- | --- |
| `ELECTION_NAME` | `Ebonyi State Governorship Election` | Shown in the summary email |
| `ELECTION_DATE` | `2027-02-06` | Election day |
| `ELECTION_TIMEZONE` | `Africa/Lagos` | Timezone for all windows, reminders and email times |
| `ELECTION_ENFORCE_WINDOWS` | `true` | Turn off for testing and rehearsals |
| `ELECTION_PRESENCE_OPENS_AT` | `07:00` | Presence check-in opens (election day) |
| `ELECTION_RESULTS_OPEN_AT` | `14:30` | Result submission opens (election day) |
| `ELECTION_RESULTS_CLOSE_AT` | `2027-02-08 23:59` | Result submission closes (empty = never) |
| `ELECTION_PARTIES` | `APC,PDP,LP,OTHERS` | Parties agents enter votes for, in this order. Every party adds one USSD screen, so keep the main contenders plus `OTHERS`. |
| `ELECTION_REQUIRE_KNOWN_PU` | `true` | Only accept imported PU codes |
| `ELECTION_PIN_MAX_ATTEMPTS` | `3` | Wrong PINs before lockout |
| `ELECTION_PIN_LOCK_MINUTES` | `30` | Lockout length |
| `ELECTION_URGENT_INCIDENT_TYPES` | `violence` | Types that text coordinators (`violence,vote_buying,delay,other`) |
| `ELECTION_PRESENCE_REMINDER_AT` | `08:00` | Presence reminder SMS time (empty disables) |
| `ELECTION_RESULTS_REMINDER_AT` | `17:00` | Result reminder SMS time (empty disables) |
| `ELECTION_API_TOKEN` | — | Coordinator API token |
| `NOTIFY_EMAILS` | — | Comma-separated coordinator email addresses |
| `NOTIFY_EMAIL_EACH_RESULT` | `true` | Send one email per accepted result |
| `NOTIFY_HOURLY_SUMMARY` | `true` | Send the hourly summary email |
| `USSD_SERVICE_CODE` | `*384*123#` | Quoted in reminder SMS |
| `USSD_CALLBACK_SECRET` | — | Secret path segment for the callback URL |
| `USSD_ALLOWED_IPS` | — | IPs or CIDR ranges allowed to call the callback |
| `USSD_COUNTRY_CODE` | `234` | Used to normalise local phone numbers |
| `USSD_REFERENCE_DIGITS` | `6` | Digits in `RS`/`IN` references |
| `USSD_SMS_CONFIRMATION` | `true` | Send SMS receipts to agents |
| `USSD_INSTRUCTIONS` | see `config/ussd.php` | Text for menu option 4 (`\n` for new lines) |
| `AFRICASTALKING_USERNAME` | `sandbox` | AT app username |
| `AFRICASTALKING_API_KEY` | — | AT API key (SMS) |
| `AFRICASTALKING_SENDER_ID` | — | Optional SMS sender ID or short code |
| `DASHBOARD_WEBHOOK_URL` | — | Where to POST events (empty disables it) |
| `DASHBOARD_API_TOKEN` | — | Sent as `Authorization: Bearer …` |
| `DASHBOARD_WEBHOOK_SECRET` | — | HMAC key for `X-Election-Shield-Signature` |
| `DASHBOARD_TIMEOUT` | `10` | Seconds to wait for the dashboard |

## Tests

```bash
php artisan test
vendor/bin/pint --test
```
