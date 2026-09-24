# Election Shield: web app handoff brief

Paste or attach this file at the start of the new chat. It describes the existing **Election Shield USSD service** and how the new **Election Shield web app** receives its data.

## The project

- **Election:** Ebonyi State governorship election, **Saturday 6 February 2027**. Timezone Africa/Lagos.
- **Ballot as entered by agents:** APC (Francis Ogbonna Nwifuru), PDP (Ifeanyi Chukwuma Odii), LP (Splendor Oko Eze), and OTHERS (all other parties combined).
- **Polling units:** 3,308 PUs in 13 LGAs and 169 wards, from the INEC-verified register. INEC codes like `EB/212/02633/007` are stored as digits: `21202633007`.
- **Owner:** Kehinde Amusan (amusankehinde@gmail.com).

## Product scope: Software 3, Election Shield

**"Election Day Control & Protection System"**: making sure votes are protected, the process is transparent, and problems get a fast response.

| Feature | Already in the USSD service | Still to build (mostly in the web app) |
| --- | --- | --- |
| **1. Polling unit monitoring.** Agents report materials arriving, delays and irregularities. | Presence check-in per PU. Incidents of type *delay*, *vote buying*, *violence* and *other*. Missing-PU lists and SMS reminders. | Live PU status board and map by LGA and ward. **USSD additions** (small changes in the USSD repo): a "materials arrived / not arrived" report with a timestamp, and more incident types (see 3). |
| **2. Parallel Vote Tabulation (PVT).** Collect results from every PU and compare them with the official results. | EC8A figures from every PU (accredited, per party, rejected), a correction workflow, and collation by LGA and ward. | **Official results intake:** INEC's IReV result per PU and the declared ward and LGA collations (EC8B/EC8C), entered by hand or imported. **Comparison:** our figures against the official ones per PU, ward and LGA, flagging differences above a threshold and PUs where IReV shows no upload. Evidence export for petitions. EC8A **photo upload** tied to the result reference, since USSD can't carry images. |
| **3. Incident alert system.** Real-time alerts for violence, vote suppression and malpractice. | Violence sends an instant SMS to the coordinators for that LGA plus state-wide coordinators, with email, dashboard events and an audit trail. Which types count as urgent is configurable. | A live incident feed and map, and acknowledge/resolve tracking for coordinators. **USSD addition:** *vote suppression* and *malpractice* as their own incident types (today they fall under *other* or *vote buying*), marked urgent. |
| **4. Digital town hall.** The candidate engages voters through live sessions and Q&A. | Not covered: USSD can't carry it. | Embedded live stream (YouTube/Facebook), a question submission and moderation queue, and a schedule of sessions. Could share the broadcast list for reminders. |
| **5. WhatsApp/SMS broadcast system.** Election updates and mobilisation reminders. | SMS to *agents* through Africa's Talking (PINs, receipts, alerts, election-day reminders). | Audience lists (supporters by LGA and ward, agents, coordinators), opt-in and opt-out, scheduled campaigns, and delivery reports. **SMS:** Africa's Talking bulk SMS; watch the sender ID and do-not-disturb (DND) rules. **WhatsApp:** the WhatsApp Business Platform (Meta Cloud API or a provider) needs a verified business, pre-approved message templates, and recipients' opt-in. |

### Ebonyi election insight: the win condition

Under section 179(2) of the 1999 Constitution, a governorship candidate is declared elected with:
1. **the highest number of votes** (a plurality, not necessarily a majority), and
2. **at least 25% of the votes in at least two-thirds of the LGAs**. Ebonyi has **13 LGAs**, so this means **at least 9 LGAs** (two-thirds is 8.67). Confirm the rounding with the legal team.

Otherwise, a run-off is held. The web app should track this live from the PVT figures:
- the **25% tracker:** each candidate's share in each of the 13 LGAs, and how many LGAs they have reached 25% in (target 9)
- the **overall lead**
- **weak links:** LGAs where the candidate is under or near 25%, and LGAs with low result coverage (few PUs reported)

This is where Election Shield delivers "coverage across all LGAs, no weak links on election day". The USSD data already arrives with LGA and ward on every result.

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
- **Features:** build in order of election-day value.
  1. PVT dashboard with collation and the 25% tracker
  2. PU monitoring board and incident feed
  3. Official-results intake and comparison
  4. EC8A photo upload
  5. Broadcast system
  6. Digital town hall
- **The EC8A photo:** USSD can't send images. A web or WhatsApp upload flow tied to the result reference would let coordinators check figures against the photographed result sheet.
