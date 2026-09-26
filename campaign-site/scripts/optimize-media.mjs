// Turns the originals in media-src/ (not committed) into the optimised copies
// in public/media/ (committed), plus src/data/media.json describing them.
// Usage: npm run media   (needs ffmpeg on PATH or FFMPEG=/path/to/ffmpeg for videos)
import sharp from 'sharp';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync, statSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const SRC = 'media-src';
const OUT = 'public/media';
const FFMPEG = process.env.FFMPEG || 'ffmpeg';
mkdirSync(`${OUT}/img`, { recursive: true });
mkdirSync(`${OUT}/video`, { recursive: true });

// name: output base name; src: original; widths: responsive widths;
// crop: optional {left, top, width, height} as fractions of the original.
// ratio: optional output aspect (w/h) for a centred/positioned cover crop.
const images = [
  { name: 'hero-tall', src: 'photos/2.jpg', widths: [480, 720, 960], ratio: 4 / 5, position: 'top', quality: { avif: 50, webp: 70, jpg: 72 } },
  { name: 'hero-wide', src: 'photos/10.webp', widths: [720, 1080, 1440], crop: { left: 0.27, top: 0, width: 0.73, height: 1 } },
  { name: 'about', src: 'photos/9.jpg', widths: [480, 720, 1080], ratio: 4 / 5, position: 'attention' },
  { name: 'portrait-hat', src: 'photos/11.webp', widths: [400, 700] },
  { name: 'join', src: 'photos/4.jpg', widths: [480, 720, 1000], ratio: 4 / 5, position: 'top' },
  { name: 'leadership', src: 'photos/6.jpg', widths: [480, 720, 1000], ratio: 4 / 5, position: 'top' },
  { name: 'office', src: 'photos/3.jpg', widths: [480, 720, 1000], ratio: 4 / 5, position: 'top' },
  { name: 'meeting', src: 'photos/8.jpg', widths: [480, 720], crop: { left: 0, top: 0, width: 1, height: 0.93 } },
  { name: 'agbada', src: 'photos/2.jpg', widths: [480, 900] },
  { name: 'white-cap', src: 'photos/4.jpg', widths: [480, 900] },
  { name: 'handshake-1', src: 'photos/5.jpg', widths: [480, 900] },
  { name: 'blue-suit', src: 'photos/6.jpg', widths: [480, 900] },
  { name: 'evening-event', src: 'photos/7.jpg', widths: [480, 900, 1280] },
  { name: 'black-cap', src: 'photos/9.jpg', widths: [480, 900, 1280] },
  { name: 'ceremony', src: 'photos/10.webp', widths: [480, 900, 1280] },
  { name: 'with-supporters', src: 'photos/12.jpg', widths: [480, 900, 1280] },
  { name: 'fedora', src: 'photos/11.webp', widths: [480, 853] },
  { name: 'grey-suit', src: 'photos/3.jpg', widths: [480, 900] },
  // Ebele and Anyichuks Foundation (from ifeanyiodii.com/philanthropy)
  { name: 'fdn-students', src: 'foundation/p1_02.png', widths: [480, 960] },
  { name: 'fdn-books', src: 'foundation/p1_03.png', widths: [480, 960] },
  { name: 'fdn-school-visit', src: 'foundation/p1_05.png', widths: [480, 960] },
  { name: 'fdn-presentation', src: 'foundation/p2_11.png', widths: [480, 960] },
  { name: 'fdn-community', src: 'foundation/p2_10.png', widths: [480, 960] },
  { name: 'fdn-machines', src: 'foundation/p3_17.png', widths: [480, 960, 1440] },
  { name: 'fdn-sewing', src: 'foundation/p3_18.png', widths: [480, 960, 1440] },
  { name: 'fdn-women', src: 'foundation/p3_19.png', widths: [480, 960, 1440] },
  { name: 'fdn-rice', src: 'foundation/p3_21.png', widths: [480, 960, 1440] },
  { name: 'fdn-celebration', src: 'foundation/p3_22.png', widths: [480, 960, 1440] },
  { name: 'fdn-aerial', src: 'foundation/p4_27.png', widths: [480, 960, 1440] },
];

// ONLY=name1,name2 re-processes just those images and keeps everything else.
const only = process.env.ONLY ? process.env.ONLY.split(',') : null;
const manifest = only && existsSync('src/data/media.json') ? JSON.parse(readFileSync('src/data/media.json', 'utf8')) : { images: {}, videos: {} };

async function prepare(img) {
  let pipeline = sharp(join(SRC, img.src)).rotate();
  const meta = await sharp(join(SRC, img.src)).metadata();
  let w = meta.width, h = meta.height;
  if (img.crop) {
    const c = img.crop;
    const box = { left: Math.round(c.left * w), top: Math.round(c.top * h), width: Math.round(c.width * w), height: Math.round(c.height * h) };
    pipeline = pipeline.extract(box);
    w = box.width; h = box.height;
  }
  if (img.ratio) {
    const current = w / h;
    let cw = w, ch = h;
    if (current > img.ratio) cw = Math.round(h * img.ratio); else ch = Math.round(w / img.ratio);
    const buf = await pipeline.toBuffer();
    const pos = img.position === 'attention' ? sharp.strategy.attention : img.position || 'centre';
    pipeline = sharp(buf).resize(cw, ch, { fit: 'cover', position: pos });
    w = cw; h = ch;
  }
  return { buffer: await pipeline.toBuffer(), width: w, height: h };
}

for (const img of images.filter((i) => !only || only.includes(i.name))) {
  const { buffer, width, height } = await prepare(img);
  const q = { avif: 48, webp: 68, jpg: 74, ...(img.quality || {}) };
  const widths = img.widths.filter((x) => x <= width);
  if (!widths.length || widths.at(-1) < Math.min(width, img.widths.at(-1))) widths.push(Math.min(width, img.widths.at(-1)));
  const unique = [...new Set(widths)];
  for (const wd of unique) {
    const base = sharp(buffer).resize({ width: wd, withoutEnlargement: true });
    await base.clone().avif({ quality: q.avif, effort: 6 }).toFile(`${OUT}/img/${img.name}-${wd}.avif`);
    await base.clone().webp({ quality: q.webp, effort: 6 }).toFile(`${OUT}/img/${img.name}-${wd}.webp`);
    await base.clone().jpeg({ quality: q.jpg, mozjpeg: true, progressive: true }).toFile(`${OUT}/img/${img.name}-${wd}.jpg`);
  }
  manifest.images[img.name] = { widths: unique, width, height };
  const sizes = unique.map((wd) => `${wd}:${Math.round(statSync(`${OUT}/img/${img.name}-${wd}.avif`).size / 1024)}K`).join(' ');
  console.log(`${img.name.padEnd(18)} ${width}x${height}  avif ${sizes}`);
}

if (only) {
  writeFileSync('src/data/media.json', JSON.stringify(manifest, null, 2) + '\n');
  process.exit(0);
}

// Party logo (small, used in header/footer)
await sharp(join(SRC, 'photos/1.png')).resize(96).webp({ quality: 85 }).toFile(`${OUT}/img/pdp-logo-96.webp`);
await sharp(join(SRC, 'photos/1.png')).resize(96).png({ compressionLevel: 9, palette: true }).toFile(`${OUT}/img/pdp-logo-96.png`);

// The Open Graph / WhatsApp preview image is built by scripts/og-image.mjs.

// Favicons from the SVG mark
const mark = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0b5e3b"/><circle cx="32" cy="32" r="15" fill="none" stroke="#fff" stroke-width="7"/><circle cx="47" cy="47" r="6" fill="#ee3135"/></svg>`);
writeFileSync('public/favicon.svg', mark);
await sharp(mark).resize(180).png().toFile('public/apple-touch-icon.png');
await sharp(mark).resize(32).png().toFile('public/favicon-32.png');
await sharp(mark).resize(192).png().toFile(`${OUT}/img/icon-192.png`);
await sharp(mark).resize(512).png().toFile(`${OUT}/img/icon-512.png`);

// Videos: H.264 720p, faststart, with poster images. Only loaded when tapped.
const videos = [
  { name: 'bbc-igbo-interview', src: 'video/bbc-igbo.mp4', posterAt: 27, crf: 30, maxrate: '520k' },
  { name: 'interview-2022', src: 'video/interview-2022.mp4', posterAt: 12, crf: 28, maxrate: '600k' },
];
for (const v of videos) {
  const out = `${OUT}/video/${v.name}.mp4`;
  if (!existsSync(out) || process.env.REENCODE) {
    execFileSync(FFMPEG, ['-y', '-loglevel', 'error', '-i', join(SRC, v.src), '-c:v', 'libx264', '-preset', 'slow', '-crf', String(v.crf),
      '-maxrate', v.maxrate, '-bufsize', '1200k', '-vf', "scale='min(720,iw)':-2", '-profile:v', 'high', '-pix_fmt', 'yuv420p',
      '-c:a', 'aac', '-b:a', '64k', '-ac', '1', '-movflags', '+faststart', out]);
  }
  const frame = `${OUT}/video/${v.name}-poster-src.png`;
  execFileSync(FFMPEG, ['-y', '-loglevel', 'error', '-ss', String(v.posterAt), '-i', join(SRC, v.src), '-frames:v', '1', frame]);
  await sharp(frame).resize(540).webp({ quality: 70 }).toFile(`${OUT}/video/${v.name}-poster.webp`);
  await sharp(frame).resize(540).jpeg({ quality: 72, mozjpeg: true }).toFile(`${OUT}/video/${v.name}-poster.jpg`);
  execFileSync('rm', [frame]);
  manifest.videos[v.name] = { mb: +(statSync(out).size / 1048576).toFixed(1) };
  console.log(`${v.name}: ${manifest.videos[v.name].mb} MB`);
}

writeFileSync('src/data/media.json', JSON.stringify(manifest, null, 2) + '\n');
console.log('Wrote src/data/media.json');
