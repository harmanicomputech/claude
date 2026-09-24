# Election Shield: web app handoff brief

Paste or attach this file at the start of the new chat. It describes the existing **Election Shield USSD service** and how the new **Election Shield web app** receives its data.

## The project

- **Election:** Ebonyi State governorship election, **Saturday 6 February 2027**. Timezone Africa/Lagos.
- **Ballot as entered by agents:** APC (Francis Ogbonna Nwifuru), PDP (Ifeanyi Chukwuma Odii), LP (Splendor Oko Eze), and OTHERS (all other parties combined).
- **Polling units:** 3,308 PUs in 13 LGAs and 169 wards, from the INEC-verified register. INEC codes like `EB/212/02633/007` are stored as digits: `21202633007`.
- **Owner:** Kehinde Amusan (amusankehinde@gmail.com).

## What already exists: Election Shield USSD

- **Repository:** `harmanicomputech/claude`, branch `claude/hello-i876f8`. Laravel 13, PHP 8.4, MySQL.
- **Live at** `https://ussd.techatronagency.com`, on DirectAdmin shared hosting with no terminal. Everything is managed through its admin console at `/admin`, and one cron job runs its background work.
- **Africa's Talking USSD:** sandbox channel `*384*92342#` for now; a live code has been applied for.
- **What agents do by USSD** (registered phone numbers only, with a 4-digit PIN for results):
  - confirm presence at their PU
  - submit the EC8A result: accredited voters, votes for each party, rejected votes
  - request a correction, which a coordinator must approve
  - report incidents: violence, vote buying, delay, other; violence sends an SMS alert to coordinators
- **Admin console:**
  - results with collation by LGA and ward, incidents, polling units, agents
  - correction review, coordinators, users (admin and coordinator roles), audit log
  - rehearsal mode, CSV exports, printable agent cards

**The USSD service stays the source of truth for submissions.** The web app receives its data.

## How the web app gets the data

### 1. Push: a signed webhook (main path)

The USSD service POSTs every event to one URL on the web app. Configure it in the USSD server's `election-shield/.env`:

```
DASHBOARD_WEBHOOK_URL=https://<web-app>/api/ussd-events
DASHBOARD_API_TOKEN=<token the web app expects as a Bearer token>
DASHBOARD_WEBHOOK_SECRET=<shared secret for the HMAC signature>
```

Each request looks like this:

```http
POST {DASHBOARD_WEBHOOK_URL}
Content-Type: application/json
Authorization: Bearer {DASHBOARD_API_TOKEN}
Idempotency-Key: result.submitted:RS784321
X-Election-Shield-Event: result.submitted
X-Election-Shield-Signature: sha256=<hex HMAC-SHA256 of the raw body with DASHBOARD_WEBHOOK_SECRET>

{"event": "result.submitted", "sent_at": "2027-02-06T15:04:11+00:00", "data": { ... }}
```

The web app must:
- **Verify the signature:** `hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $header)`. Also check the Bearer token.
- **Reply 2xx only after storing the event.** Anything else is retried 8 times over about 25 minutes, then every 15 minutes by a safety job.
- **Treat `Idempotency-Key` as unique.** The same event can arrive more than once.
- **Not assume events arrive in order.** Apply them by reference and status.

#### Events

| Event | `data` |
| --- | --- |
| `result.submitted` | A PU's first result (see the result payload below) |
| `result.correction_requested` | A result with `status: "pending"` and `corrects_reference` |
| `result.corrected` | A result with `status: "accepted"` plus `superseded_reference`. **Replace** that PU's figures. |
| `result.correction_rejected` | A result with `status: "rejected"` |
| `incident.reported` | `reference`, `polling_unit`, `type` (`violence`/`vote_buying`/`delay`/`other`), `type_label`, `urgent`, `note`, `agent`, `reported_at` |
| `presence.confirmed` | `id`, `polling_unit`, `agent`, `confirmed_at` |

Every `data` object also has **`rehearsal: true|false`**. Keep rehearsal data apart from real results, or drop it.

#### The result payload

```json
{
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
  "reviewed_at": null,
  "reviewed_by": null,
  "review_note": null,
  "rehearsal": false
}
```

**Counting rule:** each PU has exactly one result with `status: "accepted"` at any time. Totals must count only accepted results. Statuses are `accepted`, `pending` (a correction awaiting review), `rejected` and `superseded` (an old result replaced by an approved correction).

#### Catching up on existing data

After connecting the webhook, press **Settings → Send all existing data to the dashboard** in the USSD admin console, or run `php artisan dashboard:backfill`. It re-sends everything with the same idempotency keys, so it's safe to repeat.

### 2. Pull: the coordinator API (optional)

`Authorization: Bearer {ELECTION_API_TOKEN}`. The token is in the USSD server's `.env`.

| Endpoint | Returns |
| --- | --- |
| `GET https://ussd.techatronagency.com/api/reports/summary` | Totals, turnout, party votes, incidents, and a breakdown by LGA |
| `GET /api/reports/missing?type=presence\|results&lga=…` | PUs with no check-in or no result, with their assigned agents |
| `GET /api/corrections?status=pending\|accepted\|rejected` | Corrections |
| `POST /api/corrections/{reference}/approve` and `/reject` | Body: `reviewed_by`, `note`. Lets the web app review corrections. |

## Decisions for the new chat

- **What the web app is for:** an internal situation room for coordinators, public or partner results, or both. This decides the authentication and what is shown.
- **Stack and hosting:** the same shared host (Laravel suits it), or somewhere else. If it's a separate app, the webhook above is the integration. Don't share the USSD database directly.
- **Features to consider:**
  - a live results map and collation by LGA and ward
  - a turnout and incident map
  - correction review (through the API)
  - agent presence tracking
  - result-sheet (EC8A) photo uploads, which USSD can't carry
  - exports and printable collation sheets
- **The EC8A photo:** USSD can't send images. A web or WhatsApp upload flow tied to the result reference would let coordinators check figures against the photographed result sheet.
