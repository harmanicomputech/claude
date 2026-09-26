// Builds public/media/img/og.jpg (1200x630), the image WhatsApp, Facebook and X show
// when the site is shared. Needs the Fraunces and Inter fonts installed for fontconfig
// (copy the .woff files from node_modules/@fontsource/*/files into ~/.fonts).
// Usage: node scripts/og-image.mjs
import sharp from 'sharp';
import { site } from '../src/config.ts';

const W = 1200, H = 630, PHOTO_W = 540;
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');

const photo = await sharp('media-src/photos/10.webp')
  .resize(PHOTO_W, H, { fit: 'cover', position: 'centre' })
  .toBuffer();
const logo = await sharp('media-src/photos/1.png').resize(56).png().toBuffer();

const fade = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${PHOTO_W}" height="${H}">
  <defs><linearGradient id="g" x1="0" x2="1"><stop offset="0" stop-color="#0e1712"/><stop offset=".35" stop-color="#0e1712" stop-opacity="0"/></linearGradient></defs>
  <rect width="100%" height="100%" fill="url(#g)"/></svg>`);

const text = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}">
  <rect width="${W}" height="${H}" fill="#0e1712"/>
  <rect x="64" y="560" width="30" height="4" fill="#009054"/><rect x="94" y="560" width="30" height="4" fill="#ffffff"/><rect x="124" y="560" width="30" height="4" fill="#ee3135"/>
  <text x="136" y="104" font-family="Inter" font-weight="600" font-size="20" letter-spacing="3" fill="#cfe9dc">PDP · EBONYI STATE 2027</text>
  <text x="64" y="206" font-family="Fraunces" font-weight="600" font-size="34" fill="#cfe9dc">Dr.</text>
  <text x="64" y="270" font-family="Fraunces" font-weight="600" font-size="58" fill="#ffffff" letter-spacing="-1">Ifeanyi Chukwuma</text>
  <text x="64" y="334" font-family="Fraunces" font-weight="600" font-size="58" fill="#ffffff" letter-spacing="-1">Odii</text>
  <text x="64" y="398" font-family="Inter" font-size="28" fill="#b9c4bd">${esc(site.office)}</text>
  <text x="64" y="478" font-family="Fraunces" font-weight="600" font-size="42" fill="#8fe0b8">${esc(site.slogan)}</text>
  <text x="64" y="522" font-family="Inter" font-weight="600" font-size="22" fill="#b9c4bd">${esc(site.hashtags.join('  '))}</text>
</svg>`);

await sharp(text)
  .composite([
    { input: photo, left: W - PHOTO_W, top: 0 },
    { input: fade, left: W - PHOTO_W, top: 0 },
    { input: logo, left: 64, top: 72 },
  ])
  .jpeg({ quality: 82, mozjpeg: true })
  .toFile('public/media/img/og.jpg');
console.log('Wrote public/media/img/og.jpg');
