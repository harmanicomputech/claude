# Deploying on cPanel / DirectAdmin (no terminal)

Everything is done through the hosting control panel and the Election Shield admin console. You don't need SSH.

## What you need

- **PHP 8.3 or 8.4.** Choose it in the control panel's PHP version selector. These extensions must be enabled: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `xml`, `ctype`, `bcmath`.
- **A MySQL database:** MySQL 5.7+ or MariaDB 10.3+.
- **HTTPS** on the domain or subdomain (Let's Encrypt in the control panel). Africa's Talking will only call an HTTPS address.
- **The zip:** `election-shield-shared-hosting.zip`. It already includes every PHP library.

A dedicated subdomain such as `ussd.yourdomain.com` keeps it separate from your main website.

## 1. Create the database

In the control panel, open **MySQL Databases**. Create a database and a user, give the user **all privileges** on the database, and note the database name, username and password.

## 2. Upload and extract

The zip contains two folders:

```
election-shield/   ← the application: must NOT be inside public_html
public_html/       ← the web root files (index.php, .htaccess, robots.txt)
```

- **DirectAdmin:** in **File Manager**, open `domains/yourdomain.com/`, upload the zip there, and extract it. `election-shield/` ends up next to `public_html/`.
- **cPanel:** in **File Manager**, open your home folder (the one that contains `public_html`), upload the zip there, and extract it.
- **For a subdomain,** move the contents of the extracted `public_html/` into the subdomain's document root instead (for example `public_html/ussd/`). `index.php` finds `election-shield/` up to three folders above itself.

Never put `election-shield/` inside `public_html`: that would expose your settings file to the internet.

## 3. Edit the settings (`election-shield/.env`)

Open `election-shield/.env` in File Manager's editor. It's a hidden file, so turn on "show hidden files" if you can't see it. Then fill in every `CHANGE-ME`:

| Setting | Value |
| --- | --- |
| `APP_URL` | `https://ussd.yourdomain.com` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from step 1 |
| `AFRICASTALKING_API_KEY` | from the Africa's Talking sandbox (step 6) |
| `USSD_SERVICE_CODE` | your sandbox USSD code, e.g. `*384*12345#` |

`APP_KEY`, `ADMIN_PASSWORD`, `USSD_CALLBACK_SECRET` and `ELECTION_API_TOKEN` are already filled with random values generated for you. **`ADMIN_PASSWORD` is the one-time setup key** for creating the first admin account (step 4).

## 4. Set up from the admin console

Open `https://ussd.yourdomain.com/admin`. The first time, it asks you to **create the first admin account**: enter `ADMIN_PASSWORD` from `.env` as the setup key, then your name, email and a new password. This also sets up the database. After that, you log in with your email and password.

Then, on the **Overview** page:

1. Press **Set up / update database**.
2. Press **Import polling units**. With no file chosen, it loads the bundled Ebonyi register (3,308 PUs).
3. Press **Send test email** and check your inbox.
4. Go to **Agents** and add yourself with your phone number and a PIN, for testing.
5. Go to **Coordinators** and add at least one state-wide coordinator.

The **Set-up checklist** on the Overview page shows what is still missing.

## 5. Keep background work running (no per-minute cron needed)

SMS receipts, violence alerts, emails, reminders and the hourly summary are sent in the background. Many shared hosts don't allow per-minute cron jobs, so Election Shield doesn't depend on one. It runs background work in three ways, and you should set up the first two:

1. **Automatically after every visit.** After answering a USSD request, an admin page or a web-app API call, the app sends whatever is waiting. On election day the USSD traffic keeps everything moving within seconds. This needs nothing from you.
2. **A free pinger, every minute. Set this up.** It covers quiet periods, for example a violence alert when nobody else is dialling, or the 8:00 AM reminder.
   - Create a free account at **cron-job.org** (or UptimeRobot, EasyCron, …).
   - Add a job that opens the **Background work URL** shown at the bottom of the admin **Overview** checklist (`https://…/cron/<secret>`) **every minute**.
   - This is an ordinary web visit, not a cron job on your server, so shared-hosting rules allow it. Keep the URL secret.
3. **Your host's cron, hourly, as a backup.** In **Cron Jobs**, add:

   ```
   0 * * * *   /usr/local/bin/php /home/USERNAME/domains/yourdomain.com/election-shield/artisan election:tick
   ```

   Adjust the PHP path and folder path as needed. It must be PHP 8.3 or newer.

Tasks never run twice, whichever of the three triggers them. Reminders still go out if the run after 8:00 comes a little late.

**Check it:** the **Background work** row on the Overview checklist turns **OK** and shows how it last ran: *after a web request*, *by the pinger* or *by cron*. If it stays red for more than a few minutes, the pinger isn't reaching the URL.

**On election day,** consider moving to a VPS for the week, with a permanent queue worker (`php artisan queue:work --queue=high,default,bulk,mail`) and a per-minute cron running `election:tick`. The pinger setup works, but a VPS gives more headroom.

## 6. Connect the Africa's Talking sandbox

1. Log in at account.africastalking.com and open the **Sandbox** app.
2. **Settings → API Key**: generate a key and put it in `AFRICASTALKING_API_KEY` in `.env`.
3. **USSD → Create Channel**: pick a channel number and set the **callback URL** to the one shown at the bottom of the admin **Overview** checklist (`https://…/api/ussd/<secret>`).
4. Open the simulator (developers.africastalking.com/simulator). Enter the phone number you registered as an agent in step 4, and dial your sandbox code.

In the sandbox, SMS receipts and alerts appear in the simulator's message inbox, not on real phones.

## Registering agents in bulk

Go to **Agents → Import agents from CSV** and upload a file like `database/data/agents.example.csv`:

```
name,phone,pu_code,pin
Ada Obi,08012345678,EB/212/02633/007,
Chidi Eze,08023456789,21202633002,4821
```

- `pu_code` is optional. When it's given, the agent is never asked for a PU code.
- `pin` is optional. Agents without one get a random PIN. The PINs appear once after the import; download them from that page, or tick "Text each new PIN to its agent".

## Updating to a new version

You'll receive `election-shield-shared-hosting-update.zip`. It contains no `.env`, so your settings are kept.

1. Upload it to the same folder as before and extract it, overwriting files.
2. In the admin console, press **Set up / update database**.

## Accounts and roles

Under **Users** (admins only), give each coordinator their own account:
- **Admins** can do everything.
- **Coordinators** can see all data, export it and review corrections, but can't change agents, users or settings.

A temporary password is shown once after an account is created. Each person can change their password on the Overview page. Every sensitive action (logins, correction decisions, PIN resets, exports, clearing data) is recorded under **Audit log**.

## Rehearsals

1. **Settings → Switch rehearsal mode on.** Submissions are open at any time, the USSD menu reads *Election Shield REHEARSAL*, and every SMS and email starts with *[REHEARSAL]*.
2. Run the practice with your agents.
3. **Settings → Clear test data.** Type `CLEAR` and your password. This removes results, incidents and check-ins, and keeps the polling units, agents (unless you tick the box), coordinators, accounts and audit log.
4. Switch rehearsal mode off.

Clearing is blocked on election day itself, unless rehearsal mode is on.

## Agent cards

**Agents → Print agent cards** prints one card per agent with their PU code, the dial code and simple steps. PINs are stored scrambled, so existing ones can't be printed. An admin can instead set new PINs for the agents on the page and print them onto the cards; their old PINs stop working.

## Before election day

- Set `ELECTION_ENFORCE_WINDOWS=true`.
- Switch to live Africa's Talking: set `AFRICASTALKING_USERNAME` to your app's username, use the live API key and your live service code, and set the callback URL on the live channel.
- Clear the test data (Settings), switch rehearsal mode off, and remove the test agents.
- Change the mail password, and update `MAIL_PASSWORD`.

## Updating an existing install: urgent incident types

Installs set up before 26 Sep 2026 have `ELECTION_URGENT_INCIDENT_TYPES=violence` in `.env`. Change it to:

```
ELECTION_URGENT_INCIDENT_TYPES=violence,vote_suppression,malpractice
```

so the new vote-suppression and malpractice reports also text coordinators.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| **419 Page Expired** when logging in or pressing a button | The browser didn't send the session cookie back. Turn on SSL and open the console with `https://`. Packages built before 24 Sep 2026 also need `SESSION_SECURE_COOKIE=true` removed from `.env` (or apply the update zip, which handles this automatically). Then reload the login page. |
| Blank page or **500 error** | Check that the PHP version is 8.3+ and that `election-shield/.env` has no `CHANGE-ME` left in the `DB_*` lines. The error details are in `election-shield/storage/logs/`. |
| "the election-shield folder was not found" | `election-shield/` must sit next to `public_html/` (or up to two folders higher), not inside it. |
| Background work check stays red | Set up the pinger (step 5) and check that it's calling the exact URL from the Overview page. The hourly cron alone is too slow for alerts. |
| The simulator shows an error or nothing | The callback URL must be `https://` and match the one on the Overview page exactly. Some hosts' firewall (ModSecurity) blocks automated POSTs: ask them to allow `/api/ussd`. |
