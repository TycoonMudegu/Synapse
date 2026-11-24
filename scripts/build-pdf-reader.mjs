import { cp, mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const root = new URL('../', import.meta.url);
const source = new URL('node_modules/pdfjs-dist/', root);
const { version } = JSON.parse(await readFile(new URL('package.json', source), 'utf8'));
const destination = new URL(`Public/Assets/pdfjs/${version}/`, root);
await mkdir(destination, { recursive: true });
for (const file of ['pdf.min.mjs', 'pdf.worker.min.mjs']) {
    await cp(new URL(`build/${file}`, source), new URL(file, destination));
}
for (const directory of ['cmaps', 'standard_fonts', 'wasm']) {
    await cp(new URL(directory, source), new URL(directory, destination), { recursive: true });
}
await cp(new URL('LICENSE', source), new URL('Public/Assets/licenses/pdfjs.txt', root));
console.log(`PDF.js ${version} assets copied to ${fileURLToPath(destination)}`);
