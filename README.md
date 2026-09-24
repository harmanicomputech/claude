# Election Shield

A USSD service built on [Africa's Talking](https://africastalking.com) for the Ebonyi State election on 6 February 2027. It lets registered polling agents, from any phone:

1. **Submit Result**: enter the PU code, candidate votes and total votes, check the confirmation screen, and submit. The agent gets an SMS receipt.
2. **Report Incident**: violence, vote buying, delay or other, with a short note.
3. **Confirm Presence**: records a timestamp and marks the agent as active.
4. **Instructions**
5. **Exit**

Every result, incident and presence check-in is also pushed to an external results dashboard over HTTP. Results and incidents are emailed to the coordinators.

Built with Laravel 13 and MySQL.

## USSD flow

```
*XXX#
CON Election Shield            1 → Enter PU Code → Votes for Candidate → Total Votes Cast
1. Submit Result                   → Confirm (1. Submit / 2. Edit / 3. Cancel) → END Submitted ✔ Ref: RS123456
2. Report Incident             2 → Incident Type → Enter PU Code → Short Note → Confirm → END Incident Logged ✔ Ref: IN123456
3. Confirm Presence            3 → Enter PU Code → END Presence Confirmed ✔
4. Instructions                4 → END Stay at PU. ...
5. Exit                        5 → END Thank you
```

### Rules built into the flow

| Rule | Behaviour |
| --- | --- |
| Authentication | Phone numbers that aren't registered agents get `END Access denied. Contact coordinator.` |
| Invalid input | `CON Invalid input. Enter number only:` The agent can re-enter and carry on. |
| Votes > total | `CON Error: Votes cannot exceed total. Re-enter total:` |
| Duplicate result | `END Result already submitted for this PU.` This is checked when the PU code is entered and again at submission, and backed by a unique index. |
| Edit | Restarts result entry from the PU code. |
| Auto-PU detection | Agents registered with `--pu` are never asked for a PU code. |
| Quick codes | Dialling `*XXX*1*02345*120*300#` goes straight to the confirmation screen. Adding `*1` at the end submits immediately. |
| SMS confirmation | `Result received. Ref: RS123456` is queued after each result. |
| Incident note | 1–30 characters. |

Africa's Talking sends the whole session so far as one string (`text=1*02345*120*300*1`). `app/Ussd/UssdMenu.php` replays these inputs through a state machine on every request, which is how retries after errors and "Edit" work.

## Setup

Requirements: PHP 8.3+, Composer, MySQL 8.

```bash
composer install
cp .env.example .env
php artisan key:generate
# set DB_* and AFRICASTALKING_* in .env, then:
php artisan migrate
```

### Register agents

```bash
php artisan agent:add 08012345678 "Ada Obi"                 # agent enters PU codes
php artisan agent:add 08012345678 "Ada Obi" --pu=02345      # assigned to one PU
```

Numbers are stored in E.164 format (`+2348012345678`), the format Africa's Talking sends. `php artisan db:seed` adds two demo agents: `+2348000000001` (any PU) and `+2348000000002` (assigned to PU 02345).

### Run it

```bash
php artisan serve
php artisan queue:work      # sends SMS, emails and dashboard deliveries
php artisan schedule:work   # resends anything the dashboard missed (use cron in production)
```

SMS, email and dashboard deliveries all run on the queue so the USSD reply is never delayed. A queue worker must be running, and `QUEUE_CONNECTION` must not be `sync` in production.

## Dashboard API

Set `DASHBOARD_WEBHOOK_URL` and every record is sent as it is created:

```http
POST {DASHBOARD_WEBHOOK_URL}
Content-Type: application/json
Authorization: Bearer {DASHBOARD_API_TOKEN}
Idempotency-Key: RS784321
X-Election-Shield-Event: result.submitted
X-Election-Shield-Signature: sha256=<HMAC-SHA256 of the raw body using DASHBOARD_WEBHOOK_SECRET>

{
  "event": "result.submitted",
  "sent_at": "2027-02-06T15:04:11+00:00",
  "data": {
    "reference": "RS784321",
    "polling_unit_code": "02345",
    "candidate_votes": 120,
    "total_votes": 300,
    "agent": { "name": "Ada Obi", "phone_number": "+2348012345678" },
    "submitted_at": "2027-02-06T15:04:10+00:00"
  }
}
```

Other events:

- `incident.reported`: `reference`, `polling_unit_code`, `type` (`violence` / `vote_buying` / `delay` / `other`), `type_label`, `note`, `agent`, `reported_at`
- `presence.confirmed`: `polling_unit_code`, `agent`, `confirmed_at`. The `Idempotency-Key` for this event is `presence-{id}`.

The dashboard should:

- **Reply with any 2xx status** once the record is stored. Any other reply, a timeout or a network error is retried 8 times over about 25 minutes.
- **Treat a repeated `Idempotency-Key` as already received.** The same record can arrive more than once.
- **Verify the signature**, if you set a secret:

  ```php
  hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signatureHeader)
  ```

Every record has a `dashboard_synced_at` column. `php artisan dashboard:sync` requeues anything the dashboard never acknowledged. The scheduler runs it every 15 minutes, so a longer dashboard outage loses nothing.

## Email notifications

Set `NOTIFY_EMAILS` (comma-separated) and configure a real mailer (`MAIL_MAILER`, `MAIL_HOST`, …). Each submitted result and each reported incident then sends one email. Presence check-ins don't send email.

Election day will produce thousands of emails in a few hours. Personal Gmail accounts can only *send* about 500 emails a day, so use a transactional provider such as Resend, Mailgun, Brevo or Amazon SES. The address the emails are sent *to* can still be a Gmail address.

## Connecting Africa's Talking

1. In the Africa's Talking dashboard (use the sandbox first), create a USSD service code.
2. Set the callback URL to `https://your-domain/api/ussd`. For local testing, expose `php artisan serve` with a tunnel such as ngrok.
3. Dial the code in the AT simulator using one of the registered agent numbers.
4. For SMS, set `AFRICASTALKING_USERNAME` and `AFRICASTALKING_API_KEY`. The username `sandbox` uses the sandbox API. Without an API key, SMS messages are written to the log instead of being sent.

You can also test without Africa's Talking:

```bash
curl -X POST http://localhost:8000/api/ussd \
  -d "sessionId=test&serviceCode=*384*1#&phoneNumber=+2348000000001&text=1*02345*120*300"
```

## Configuration

| Env var | Default | Purpose |
| --- | --- | --- |
| `AFRICASTALKING_USERNAME` | `sandbox` | AT app username |
| `AFRICASTALKING_API_KEY` | — | AT API key (SMS) |
| `AFRICASTALKING_SENDER_ID` | — | Optional SMS sender ID or short code |
| `USSD_COUNTRY_CODE` | `234` | Used to normalise local phone numbers |
| `USSD_REFERENCE_DIGITS` | `6` | Digits in `RS`/`IN` references |
| `USSD_SMS_CONFIRMATION` | `true` | Send an SMS after a result is submitted |
| `USSD_INSTRUCTIONS` | see `config/ussd.php` | Text for menu option 4 (`\n` for new lines) |
| `USSD_DISPLAY_TIMEZONE` | `Africa/Lagos` | Timezone for times shown in emails |
| `NOTIFY_EMAILS` | — | Comma-separated addresses to email results and incidents to |
| `DASHBOARD_WEBHOOK_URL` | — | Where to POST records (empty disables it) |
| `DASHBOARD_API_TOKEN` | — | Sent as `Authorization: Bearer …` |
| `DASHBOARD_WEBHOOK_SECRET` | — | HMAC key for `X-Election-Shield-Signature` |
| `DASHBOARD_TIMEOUT` | `10` | Seconds to wait for the dashboard |

## Tests

```bash
php artisan test
vendor/bin/pint --test
```
