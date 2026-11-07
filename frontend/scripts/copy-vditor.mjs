import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const source = path.resolve(scriptDir, '../node_modules/vditor/dist');
const destination = path.resolve(scriptDir, '../../assets/vendor/vditor/dist');

if (!fs.existsSync(source)) {
  console.error('Vditor dist directory not found. Run npm install first.');
  process.exit(1);
}

fs.rmSync(destination, { recursive: true, force: true });
fs.mkdirSync(path.dirname(destination), { recursive: true });
fs.cpSync(source, destination, { recursive: true });
console.log(`Copied Vditor runtime assets to ${destination}`);
