import { copyFile, mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const dashboardDirectory = dirname(dirname(fileURLToPath(import.meta.url)));
const vendorDirectory = join(dashboardDirectory, 'assets', 'vendor');

const files = [
  ['chart.js/dist/chart.umd.min.js', 'chart.umd.min.js'],
  ['jspdf/dist/jspdf.umd.min.js', 'jspdf.umd.min.js'],
  ['jspdf-autotable/dist/jspdf.plugin.autotable.min.js', 'jspdf.plugin.autotable.min.js'],
];

await mkdir(vendorDirectory, { recursive: true });
for (const [source, destination] of files) {
  await copyFile(join(dashboardDirectory, 'node_modules', source), join(vendorDirectory, destination));
}

console.log(`Copied ${files.length} dashboard libraries to ${vendorDirectory}`);