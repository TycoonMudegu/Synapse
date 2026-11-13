import { cp, mkdir } from 'node:fs/promises';

await mkdir('Public/Assets/fonts', { recursive: true });
for (const weight of [400, 500, 600, 700, 800]) {
    await cp(
        `node_modules/@fontsource/nunito/files/nunito-latin-${weight}-normal.woff2`,
        `Public/Assets/fonts/nunito-${weight}.woff2`,
    );
}
await mkdir('Public/Assets/licenses', { recursive: true });
await cp('node_modules/@fontsource/nunito/LICENSE', 'Public/Assets/licenses/nunito.txt');
await cp('node_modules/lucide/LICENSE', 'Public/Assets/licenses/lucide.txt');
console.log('Nunito fonts and bundled asset licences copied.');
