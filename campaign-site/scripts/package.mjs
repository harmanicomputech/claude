// Zips dist/ into release/odii-campaign-public_html.zip. The files sit at the root of
// the zip, so extracting it inside public_html puts index.html directly in public_html.
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, statSync } from 'node:fs';

if (!existsSync('dist/index.html')) throw new Error('Run npm run build first.');
mkdirSync('release', { recursive: true });
const out = 'release/odii-campaign-public_html.zip';
rmSync(out, { force: true });
execFileSync('zip', ['-r', '-X', '-q', `../${out}`, '.', '-x', '_data/*'], { cwd: 'dist' });
console.log(`${out}: ${(statSync(out).size / 1048576).toFixed(1)} MB`);
