// Copies JavaScript and self-hosted vendor assets into public/assets.
// CSS is compiled separately by the Tailwind CLI (npm run build:css).
// Everything is served from our own origin: the CSP allows no CDNs.
import { cpSync, existsSync, mkdirSync, readdirSync } from 'node:fs';
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

console.log('Assets copied to public/assets');
