// Static validation of the frontend: every module parses, every referenced asset exists, element ids used by
// app.js exist in index.html, and nothing loads from third-party hosts (privacy: no CDNs, no trackers).
import { readFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'frontend');
const html = readFileSync(join(root, 'index.html'), 'utf8');
const errors = [];

for (const f of ['app.js', 'api.js', 'format.js']) {
  try { execFileSync(process.execPath, ['--check', join(root, 'assets/js', f)], { stdio: 'pipe' }); }
  catch (e) { errors.push(`syntax error in ${f}: ${e.stderr}`); }
}
for (const m of html.matchAll(/(?:src|href)="(assets\/[^"]+)"/g)) {
  if (!existsSync(join(root, m[1]))) errors.push(`missing asset ${m[1]}`);
}
if (/(?:src|href)="https?:\/\//.test(html)) errors.push('index.html references an external host');
const ids = new Set([...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]));
const app = readFileSync(join(root, 'assets/js/app.js'), 'utf8');
for (const m of app.matchAll(/\$\('([A-Za-z0-9_-]+)'\)/g)) if (!ids.has(m[1])) errors.push(`app.js uses #${m[1]} which is not in index.html`);
for (const f of ['app.js', 'api.js', 'format.js']) {
  const src = readFileSync(join(root, 'assets/js', f), 'utf8');
  if (/https?:\/\/(?!www\.w3\.org)/.test(src.replace(/\/\/.*$/gm, ''))) errors.push(`${f} contains an external URL`);
  if (/innerHTML\s*=/.test(src)) errors.push(`${f} assigns innerHTML (XSS risk)`);
}
if (!/<html lang=/.test(html)) errors.push('missing lang attribute');
for (const label of ['urlInput', 'qualitySelect', 'bitrateSelect']) if (!new RegExp(`for="${label}"`).test(html)) errors.push(`no <label for="${label}">`);
if (!/aria-live=/.test(html)) errors.push('no aria-live region for progress announcements');

if (errors.length) { console.error(errors.join('\n')); process.exit(1); }
console.log(`frontend OK (${ids.size} element ids, 3 modules checked)`);
