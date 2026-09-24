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

`APP_KEY`, `ADMIN_PASSWORD`, `USSD_CALLBACK_SECRET` and `ELECTION_API_TOKEN` are already filled with random values generated for you. **`ADMIN_PASSWORD` is your admin console password.**

## 4. Set up from the admin console

Open `https://ussd.yourdomain.com/admin` and log in with `ADMIN_PASSWORD`. Then, on the **Overview** page:

1. Press **Set up / update database**.
2. Press **Import polling units**. With no file chosen, it loads the bundled Ebonyi register (3,308 PUs).
3. Press **Send test email** and check your inbox.
4. Go to **Agents** and add yourself with your phone number and a PIN, for testing.
5. Go to **Coordinators** and add at least one state-wide coordinator.

The **Set-up checklist** on the Overview page shows what is still missing.

## 5. Add the cron job

In the control panel, open **Cron Jobs** and add one job that runs **every minute** (`* * * * *`):

```
/usr/local/bin/php /home/USERNAME/domains/yourdomain.com/election-shield/artisan schedule:run >> /dev/null 2>&1
```

- Replace the folder path with the real location of `election-shield`. cPanel is usually `/home/USERNAME/election-shield`. The File Manager shows the full path.
- The PHP path varies by host. DirectAdmin often uses `/usr/local/bin/php` or `/usr/local/php84/bin/php`; cPanel often uses `/usr/local/bin/php` or `/opt/cpanel/ea-php84/root/usr/bin/php`. It must be PHP 8.3 or newer. Ask your host if unsure.

This single job sends SMS and emails, delivers to the dashboard, sends reminders and the hourly summary. Within two minutes, **Cron (background jobs)** on the Overview page should turn **OK**. If it doesn't, the PHP path or folder path is wrong.

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

## Before election day

- Set `ELECTION_ENFORCE_WINDOWS=true`.
- Switch to live Africa's Talking: set `AFRICASTALKING_USERNAME` to your app's username, use the live API key and your live service code, and set the callback URL on the live channel.
- Remove the test agents.
- Change the mail password, and update `MAIL_PASSWORD`.
