// Builds the standalone privacy site hosted at https://privacy.anyigaemeya.com.ng/
// Output: release/privacy-site/ (upload its contents to that domain's public_html) and release/privacy-site.zip
// Usage: node scripts/build-privacy-site.mjs
// Each page is self-contained: styles and the logo are inlined; no other files are needed.
import { mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

// ---- Fill these in before publishing ---------------------------------------------
const CONTROLLER = '[Name of campaign organisation]';
const CONTACT_EMAIL = '[campaign contact email]';
// ----------------------------------------------------------------------------------

const BASE = 'https://privacy.anyigaemeya.com.ng';
const UPDATED = '6 November 2026';
const OUT = 'release/privacy-site';
const logo = `data:image/webp;base64,${readFileSync('public/media/img/pdp-logo-96.webp').toString('base64')}`;
const favicon = 'data:image/svg+xml,' + readFileSync('public/favicon.svg', 'utf8').replace(/"/g, "'").replace(/#/g, '%23').replace(/\n/g, '');

const pages = [
  { slug: 'website', name: 'Campaign website', short: 'The campaign website and its volunteer sign-up form.' },
  { slug: 'command-center', name: 'Command Center', short: 'The campaign team’s internal app for managing volunteers and supporters.' },
  { slug: 'anyi-ga-eme-ya', name: 'Anyi Ga Eme Ya', short: 'The internal app for the campaign’s field team.' },
];

const css = `
:root{--green:#009054;--green-700:#006b40;--red:#ee3135;--red-600:#c8232c;--ink:#111814;--soft:#2a332e;--muted:#5a655f;--paper:#fbfaf7;--line:#e4dfd5}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth;scroll-padding-top:16px}
body{margin:0;background:var(--paper);color:var(--ink);font:400 1.0625rem/1.65 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.bar{display:flex;height:4px}.bar i{flex:1}.bar i:nth-child(1){background:var(--green)}.bar i:nth-child(2){background:#fff}.bar i:nth-child(3){background:var(--red)}
.top{background:#fff;border-bottom:1px solid var(--line)}
.top div,.tabs div{max-width:760px;margin:0 auto;padding:0 20px}
.top div{display:flex;align-items:center;gap:12px;padding-block:14px;font:700 1.05rem/1.2 Georgia,"Times New Roman",serif}
.top a{color:var(--ink);text-decoration:none;display:flex;align-items:center;gap:12px}
.top img{width:36px;height:36px}
.tabs{background:#fff;border-bottom:1px solid var(--line)}
.tabs div{display:flex;gap:4px;overflow-x:auto}
.tabs a{flex:none;display:inline-flex;align-items:center;min-height:46px;padding:0 12px;color:var(--soft);text-decoration:none;font-weight:600;font-size:.95rem;border-bottom:3px solid transparent}
.tabs a:hover{color:var(--green-700)}
.tabs a[aria-current=page]{color:var(--green-700);border-bottom-color:var(--green-700)}
main{max-width:760px;margin:0 auto;padding:40px 20px 64px}
h1,h2{font-family:Georgia,"Times New Roman",serif;font-weight:700;line-height:1.15;letter-spacing:-.01em}
h1{font-size:clamp(2rem,7vw,2.8rem);margin:0 0 16px}
h2{font-size:1.4rem;margin:40px 0 12px;padding-top:20px;border-top:1px solid var(--line)}
p,li{color:var(--soft)}ul{padding-left:1.2em}li{margin-bottom:8px}strong{color:var(--ink)}
.eyebrow{display:inline-flex;align-items:center;gap:10px;font:600 .78rem/1 system-ui,sans-serif;letter-spacing:.14em;text-transform:uppercase;color:var(--green-700);margin:0 0 16px}
.eyebrow::before{content:"";width:22px;height:2px;background:var(--red)}
.lede{font-size:1.15rem;color:var(--muted)}
.note{padding:14px 16px;border-radius:12px;background:#eef6f1;border:1px solid #d3e8dc;color:var(--ink)}
a{color:var(--green-700);text-underline-offset:.18em}
a:focus-visible{outline:3px solid var(--red-600);outline-offset:3px;border-radius:3px}
.cards{display:grid;gap:14px;margin-top:28px}
.card{display:block;padding:22px 20px;border-radius:16px;background:#fff;border:1px solid var(--line);text-decoration:none;color:var(--ink)}
.card:hover{border-color:var(--green-700)}
.card b{display:block;font:700 1.3rem/1.2 Georgia,"Times New Roman",serif}
.card span{display:block;margin-top:6px;color:var(--soft)}
.card em{display:block;margin-top:10px;font-style:normal;font-weight:600;color:var(--green-700)}
footer{background:#0b1f16;color:#cfd8d3;font-size:.9rem}
footer div{max-width:760px;margin:0 auto;padding:24px 20px}
footer p{margin:0 0 6px;color:#cfd8d3}footer a{color:#fff}
`;

const contactLine = `contact us at <strong>${CONTACT_EMAIL}</strong>`;

// Sections shared by every policy.
const whoWeAre = `<h2 id="who-we-are">Who we are</h2>
<p>The data controller is ${CONTROLLER}, the campaign organisation supporting Dr. Ifeanyi Chukwuma Odii’s candidacy for Governor of Ebonyi State. For any question about this policy or your data, ${contactLine}.</p>`;

const sensitive = `<h2 id="sensitive-data">Sensitive data</h2>
<p>Information that shows someone supports a political candidate or party is sensitive personal data under the Nigeria Data Protection Act 2023 (NDPA). We collect it only with consent or where the law allows, keep it to what we need, and limit who can see it.</p>`;

const sharing = (providers) => `<h2 id="sharing">Who can see your data</h2>
<p>Only authorised members of the campaign team who need it for their work. We use service providers, such as ${providers}, who process data only on our instructions and under contract. We may disclose data where the law requires it. <strong>We never sell personal data or share it with anyone for marketing.</strong></p>`;

const rights = `<h2 id="your-rights">Your rights</h2>
<p>Under the NDPA you have the right to:</p>
<ul>
<li>be informed about how your data is used (this policy);</li>
<li>get a copy of the data we hold about you;</li>
<li>have inaccurate data corrected;</li>
<li>have your data deleted;</li>
<li>withdraw your consent at any time, without affecting anything done before you withdrew it;</li>
<li>object to, or ask us to restrict, how we use your data;</li>
<li>receive your data in a portable format.</li>
</ul>
<p>To use any of these rights, ${contactLine}. We will respond within 30 days. If you are not satisfied, you can complain to the Nigeria Data Protection Commission (NDPC) at <a href="https://ndpc.gov.ng" rel="noopener">ndpc.gov.ng</a>.</p>`;

const children = (what) => `<h2 id="children">Children</h2>
<p>${what} for people aged 18 and over. We do not knowingly collect data from children.</p>`;

const changes = (extra = '') => `<h2 id="changes">Changes to this policy</h2>
<p>If we change this policy, we will update the date at the top of this page${extra}.</p>`;

const internalNote = (app) => `<p class="note">${app} is part of the campaign’s support platform. It is for internal use only, by people the campaign has given an account, and is not open to the public.</p>`;

const deleteAccount = (app) => `<h2 id="delete-account">Deleting your account</h2>
<p>You can ask us to delete your ${app} account and the personal data linked to it at any time. ${contactLine[0].toUpperCase() + contactLine.slice(1)}, saying that you want your ${app} account deleted and giving the phone number or email address on the account. We will delete the account and its data within 30 days, except anything the law requires us to keep, and confirm when it is done.</p>`;

const permissions = (items) => `<h2 id="permissions">Device permissions</h2>
<ul>${items.map((i) => `<li>${i}</li>`).join('')}</ul>
<p>You can turn any of these off at any time in your phone’s settings; some features may then stop working. The app does not read your contacts, messages or call logs, and does not show advertising.</p>`;

const bodies = {
  website: `
<h2 id="what-we-collect">What we collect</h2>
<p>When you sign up to volunteer we collect:</p>
<ul>
<li>your name and phone number;</li>
<li>your Local Government Area and, if you choose, your ward;</li>
<li>how you would like to help, and anything else you choose to tell us;</li>
<li>a record of your consent: the date and time, and the wording you agreed to;</li>
<li>a one-way scrambled code (hash) of your internet address, used only to stop spam and abuse. We cannot recover your internet address from it.</li>
</ul>
<h2 id="how-we-use-it">How we use it, and our lawful basis</h2>
<p>We use your details to organise volunteers and to contact you about volunteering and the campaign by phone, SMS or WhatsApp. Our lawful basis is your consent. Your sign-up may be added to Command Center, the campaign team’s internal app, so the team in your area can contact you.</p>
<h2 id="cookies">Cookies and analytics</h2>
<p>The website sets no advertising or tracking cookies and uses no third-party analytics. Videos and images are served from the website and are only downloaded when you view them.</p>
${sensitive}
${sharing('our web host and email provider')}
<h2 id="retention">How long we keep it</h2>
<p>We keep volunteer details until the end of the campaign and delete them no later than 31 December 2027, unless you ask us to keep you on as a volunteer, or to delete them sooner.</p>
<h2 id="security">How we protect it</h2>
<p>Sign-ups are sent over an encrypted connection (HTTPS) and stored in a location on our server that cannot be reached from the web. Only authorised team members can access them.</p>
${rights}
${children('Volunteer sign-up is')}
${changes()}`,

  'command-center': `
${internalNote('Command Center')}
<h2 id="what-we-collect">What we collect</h2>
<p>Command Center is used by authorised members of the campaign team to manage volunteers and supporters. It holds:</p>
<ul>
<li><strong>Team accounts:</strong> name, phone number or email address, role, and records of sign-ins and changes made, so we can keep the system secure;</li>
<li><strong>Volunteer and supporter records:</strong> details people give us through the campaign website or directly to the team, such as name, phone number, LGA, ward, how they would like to help, notes from the team, and their consent record;</li>
<li><strong>Photos:</strong> pictures that team members choose to take with the camera or upload, for example of campaign activities;</li>
<li><strong>Notifications:</strong> a device token, so the app can send push notifications to your device.</li>
</ul>
<h2 id="how-we-use-it">How we use it, and our lawful basis</h2>
<p>We use team account details to give team members access, keep the system secure and coordinate the team’s work. Volunteer and supporter records are used to organise volunteers and keep in touch with supporters, on the basis of the consent those people gave us.</p>
${permissions(['<strong>Camera and photos:</strong> used only when you choose to take or upload a picture.', '<strong>Notifications:</strong> used to send you updates from the campaign team.'])}
${sensitive}
${sharing('our hosting and cloud storage providers and the services that deliver SMS, email and push notifications')}
<h2 id="retention">How long we keep it</h2>
<p>Volunteer and supporter records and photos are deleted no later than 31 December 2027, unless the person asks us to keep them on or the law requires us to keep something longer. Team accounts are closed when a person leaves the team.</p>
<h2 id="security">How we protect it</h2>
<p>Data is sent over encrypted connections (HTTPS). Every user must sign in, and what each person can see and do is limited by their role. Changes are logged.</p>
${deleteAccount('Command Center')}
${rights}
${children('Command Center is')}
${changes(', and tell app users about important changes in the app')}`,

  'anyi-ga-eme-ya': `
${internalNote('Anyi Ga Eme Ya')}
<h2 id="what-we-collect">What we collect</h2>
<p>Anyi Ga Eme Ya is used by members of the campaign’s field team who have been given an account. It holds:</p>
<ul>
<li><strong>Account details:</strong> name, phone number, role, and the area you are assigned to;</li>
<li><strong>Location:</strong> your device’s GPS location, only with your permission and only when you use a feature that needs it, for example to record where an activity took place;</li>
<li><strong>Photos:</strong> pictures you choose to take with the camera or upload in the app;</li>
<li><strong>Reports and activity:</strong> the information you submit in the app, and when you submitted it;</li>
<li><strong>Notifications:</strong> a device token, so the app can send you push notifications.</li>
</ul>
<h2 id="how-we-use-it">How we use it, and our lawful basis</h2>
<p>We use this information to give team members access to the app, coordinate the field team’s work, and keep in touch with team members. You use the app as part of your work with the campaign, and you can withdraw location and camera permission at any time.</p>
${permissions(['<strong>Location:</strong> used only with your permission, when you use a feature that needs it. The app does not track your location in the background.', '<strong>Camera and photos:</strong> used only when you choose to take or upload a picture.', '<strong>Notifications:</strong> used to send you updates from the campaign team.'])}
${sensitive}
${sharing('our hosting and cloud storage providers and the services that deliver SMS and push notifications')}
<h2 id="retention">How long we keep it</h2>
<p>Reports, locations and photos submitted in the app are deleted no later than 31 December 2027, unless the law requires us to keep something longer. Accounts are closed when a person leaves the team.</p>
<h2 id="security">How we protect it</h2>
<p>Data is sent over encrypted connections (HTTPS). Every user must sign in, and what each person can see is limited by their role.</p>
${deleteAccount('Anyi Ga Eme Ya')}
${rights}
${children('Anyi Ga Eme Ya is')}
${changes(', and tell app users about important changes in the app')}`,
};

function page({ path, title, description, current, main }) {
  const tabs = [['', 'Overview'], ...pages.map((p) => [`${p.slug}/`, p.name])]
    .map(([href, label]) => `<a href="/${href}"${href === current ? ' aria-current="page"' : ''}>${label}</a>`).join('');
  return `<!doctype html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title}</title>
<meta name="description" content="${description}">
<link rel="canonical" href="${BASE}/${path}">
<meta property="og:title" content="${title}">
<meta property="og:description" content="${description}">
<meta property="og:url" content="${BASE}/${path}">
<meta name="theme-color" content="#0b1f16">
<link rel="icon" href="${favicon}">
<style>${css}</style>
</head>
<body>
<div class="bar" aria-hidden="true"><i></i><i></i><i></i></div>
<header class="top"><div><a href="/"><img src="${logo}" width="36" height="36" alt="Peoples Democratic Party logo">Dr. Ifeanyi Chukwuma Odii · Privacy</a></div></header>
<nav class="tabs" aria-label="Privacy policies"><div>${tabs}</div></nav>
<main>
${main}
</main>
<footer><div><p><strong style="color:#fff">Authorised by the Media team.</strong></p><p>© 2026 Ifeanyi Odii Campaign</p></div></footer>
</body>
</html>
`;
}

rmSync(OUT, { recursive: true, force: true });
mkdirSync(OUT, { recursive: true });

writeFileSync(`${OUT}/index.html`, page({
  path: '', current: '', title: 'Privacy · Dr. Ifeanyi Chukwuma Odii campaign',
  description: 'Privacy policies for the campaign website and the campaign support apps Command Center and Anyi Ga Eme Ya.',
  main: `<p class="eyebrow">Privacy</p>
<h1>Your data, respected.</h1>
<p class="lede">These policies explain what personal data the campaign collects, why, how we protect it, and your rights under the Nigeria Data Protection Act 2023. Choose the service you use.</p>
<div class="cards">${pages.map((p) => `<a class="card" href="/${p.slug}/"><b>${p.name}</b><span>${p.short}</span><em>Read the policy →</em></a>`).join('')}</div>
<p style="margin-top:28px">Command Center and Anyi Ga Eme Ya are part of the campaign’s support platform and are for internal use only. Questions about any of these policies: ${CONTACT_EMAIL}.</p>
<p><small>Last updated: ${UPDATED}</small></p>`,
}));

for (const p of pages) {
  mkdirSync(`${OUT}/${p.slug}`, { recursive: true });
  writeFileSync(`${OUT}/${p.slug}/index.html`, page({
    path: `${p.slug}/`, current: `${p.slug}/`,
    title: `Privacy policy: ${p.name} · Dr. Ifeanyi Chukwuma Odii campaign`,
    description: `How ${p.name === 'Campaign website' ? 'the campaign website' : p.name} collects, uses and protects personal data, under the Nigeria Data Protection Act 2023.`,
    main: `<p class="eyebrow">Privacy policy</p>
<h1>${p.name}</h1>
<p class="lede">${p.short} This policy explains what personal data we collect, why, how we protect it, and your rights.</p>
<p><small>Last updated: ${UPDATED}</small></p>
${whoWeAre}
${bodies[p.slug]}`,
  }));
}

writeFileSync(`${OUT}/robots.txt`, `User-agent: *\nAllow: /\n\nSitemap: ${BASE}/sitemap.xml\n`);
writeFileSync(`${OUT}/sitemap.xml`, `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${['', ...pages.map((p) => `${p.slug}/`)].map((u) => `  <url><loc>${BASE}/${u}</loc></url>`).join('\n')}\n</urlset>\n`);
writeFileSync(`${OUT}/.htaccess`, `Options -Indexes\nDirectoryIndex index.html\n<IfModule mod_rewrite.c>\n  RewriteEngine On\n  # Uncomment once SSL is active to force HTTPS.\n  # RewriteCond %{HTTPS} !=on\n  # RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n</IfModule>\n`);

rmSync('release/privacy-site.zip', { force: true });
execFileSync('zip', ['-r', '-X', '-q', '../privacy-site.zip', '.'], { cwd: OUT });
const left = [CONTROLLER, CONTACT_EMAIL].filter((v) => v.startsWith('['));
if (left.length) console.warn(`⚠  Placeholders still in scripts/build-privacy-site.mjs: ${left.join(', ')}`);
console.log(`Wrote ${OUT}/ and release/privacy-site.zip`);
