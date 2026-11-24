// Copies JavaScript and self-hosted vendor assets into public/assets.
// CSS is compiled separately by the Tailwind CLI (npm run build:css).
// Everything is served from our own origin: the CSP allows no CDNs.
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'public', 'assets');

function copy(from, to) {
  const source = join(root, from);
  if (!existsSync(source)) {
    throw new Error(`Missing ${from}. Run npm ci first.`);
  }
  mkdirSync(dirname(join(out, to)), { recursive: true });
  cpSync(source, join(out, to), { recursive: true });
}

copy('resources/js', 'js');

// Inter (variable, Latin subset). The @font-face lives in resources/css/app.css.
const interFiles = join(root, 'node_modules/@fontsource-variable/inter/files');
mkdirSync(join(out, 'fonts'), { recursive: true });
for (const file of readdirSync(interFiles)) {
  if (/^inter-latin-wght-normal\.woff2$/.test(file)) {
    cpSync(join(interFiles, file), join(out, 'fonts', file));
  }
}

// Font Awesome Free: CSS references ../webfonts, so keep that layout.
copy('node_modules/@fortawesome/fontawesome-free/css/all.min.css', 'vendor/fontawesome/css/all.min.css');
copy('node_modules/@fortawesome/fontawesome-free/webfonts', 'vendor/fontawesome/webfonts');

// Version manifest: content hash per asset, read once per request by
// App\Core\View\AssetVersions (no per-request file hashing).
function walk(dir, base = '') {
  const files = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const rel = base ? `${base}/${entry.name}` : entry.name;
    if (entry.isDirectory()) files.push(...walk(join(dir, entry.name), rel));
    else if (rel !== 'manifest.json') files.push(rel);
  }
  return files;
}
const manifest = {};
for (const rel of walk(out).sort()) {
  manifest[rel] = createHash('sha256').update(readFileSync(join(out, rel))).digest('hex').slice(0, 12);
}
writeFileSync(join(out, 'manifest.json'), JSON.stringify(manifest, null, 0) + '\n');

console.log(`Assets copied to public/assets (${Object.keys(manifest).length} versioned)`);
