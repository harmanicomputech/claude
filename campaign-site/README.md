# Campaign website: Dr. Ifeanyi Chukwuma Odii for Governor, Ebonyi 2027

A static one-page campaign site (Astro), compiled to plain HTML/CSS/JS, plus one small PHP file for the volunteer form. It runs on ordinary shared hosting (DirectAdmin or cPanel) with no terminal and no database.

## Uploading to DirectAdmin (no terminal needed)

1. Log in to DirectAdmin and open **File Manager**, then go to `domains/YOUR-DOMAIN/public_html`.
2. Delete or move the placeholder `index.html` that the host put there, if any.
3. Click **Upload files** and upload `odii-campaign-public_html.zip`.
4. Tick the zip, choose **Extract**, and extract it into `public_html` (not into a subfolder). You should now see `index.html`, `volunteer.php`, `.htaccess` and the folders `_astro`, `media`, `privacy` and others directly inside `public_html`. Delete the zip afterwards.
5. **Set up the volunteer form.** Go **up one level**, to `domains/YOUR-DOMAIN/` (next to `public_html`, not inside it). Create a file called `volunteer-config.php` and paste in the contents of `deploy/volunteer-config.sample.php`. Fill in:
   - `team_email`: where new sign-ups should be emailed;
   - `from_email`: an address on your own domain, e.g. `no-reply@yourdomain.com`;
   - `ip_salt`: any long random string.
6. **Turn on SSL.** In DirectAdmin, go to **SSL Certificates** and issue a free Let's Encrypt certificate. Then, in `public_html/.htaccess`, remove the `#` from the two lines under "Uncomment the next two lines" to force HTTPS.
7. Open the site on your phone. Submit a test sign-up, check the email arrives, then delete the test row (see below).

Sign-ups are saved to `domains/YOUR-DOMAIN/volunteer-data/volunteers.csv`, outside the website, so nobody can download it from the web. Download it with File Manager and open it in Excel or Google Sheets. It is personal data covered by the NDPA, so share it only within the team. If the host doesn't allow that folder, the form falls back to `public_html/_data/`, which `.htaccess` blocks from the web.

## Everyday updates without a rebuild

- **News and events:** edit `public_html/news.json` in File Manager. Copy an existing item, change the text, keep the commas between items, and save. Dates are `YYYY-MM-DD`; the newest shows first.

## Changes that need a rebuild

Contact details, "Authorised by", the domain, the slogan and the text all live in `src/config.ts` and `src/data/content.ts`. After editing them, someone with Node.js 22 (or a Claude Code session) runs:

```
npm install
npm run package     # builds dist/ and writes release/odii-campaign-public_html.zip
```

and you re-upload the zip. The build prints a warning while any `[placeholder]` remains.

### Adding or replacing photos and videos

Put originals in `media-src/` (not committed), list them in `scripts/optimize-media.mjs`, and run `npm run media` (needs ffmpeg). It writes AVIF, WebP and JPEG copies at several widths into `public/media/img/`, and 720p H.264 videos with posters into `public/media/video/`. `ONLY=name npm run media` re-processes one image. `node scripts/og-image.mjs` rebuilds the WhatsApp/Facebook share image.

## Checks

```
php -S 127.0.0.1:8080 -t dist &     # serves the built site, including volunteer.php
node scripts/check.mjs              # screenshots at 360/768/1280px, fails on horizontal scroll or console errors
```

Last run (26 Sept 2026, Lighthouse mobile, home page): Performance 98–99, Accessibility 100, Best Practices 100, SEO 100. The page is about 217 KB on first load; videos download only when tapped.

## What is where

| Path | What |
|---|---|
| `src/config.ts` | Domain, slogan, hashtags, socials, contact, "Authorised by", LGAs, volunteer options |
| `src/data/content.ts` | Approved agenda pillars, track-record facts, quotes, companies, gallery |
| `src/pages/index.astro` | The one-page home (hero, about, record, agenda, watch, foundation, leadership, gallery, news, join) |
| `src/pages/privacy.astro` | Privacy notice (NDPA 2023). Have it reviewed by a lawyer before launch |
| `public/volunteer.php` | Form handler: honeypot, time trap, rate limit, consent record, CSV and email |
| `public/.htaccess` | Caching, compression, security headers, blocks config and data files |
| `public/news.json` | News list, editable on the server |
| `public/media/` | Optimised images and videos only |

## Sources and permissions

- Agenda pillars were approved by the campaign on 26 Sept 2026. Points marked as coming from the videos come from the BBC News Igbo interview and the 2022 interview.
- BBC News Igbo interview: used with BBC permission (confirmed by the campaign, 26 Sept 2026). English captions in `public/media/video/bbc-igbo-interview.en.vtt` are our translation and should be checked by a native Igbo speaker.
- Foundation text and photos: from ifeanyiodii.com/philanthropy.
