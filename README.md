# Election Shield

A USSD service built on [Africa's Talking](https://africastalking.com) that lets registered polling agents, from any phone:

1. **Submit Result**: enter the PU code, candidate votes and total votes, check the confirmation screen, and submit. The agent gets an SMS receipt.
2. **Report Incident**: violence, vote buying, delay or other, with a short note.
3. **Confirm Presence**: records a timestamp and marks the agent as active.
4. **Instructions**
5. **Exit**

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
php artisan queue:work      # sends the SMS confirmations
```

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

## Tests

```bash
php artisan test
vendor/bin/pint --test
```
