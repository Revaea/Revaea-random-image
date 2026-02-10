import { readdir, writeFile } from "node:fs/promises";
import { join, resolve } from "node:path";

const root = resolve("..");
const portraitDir = join(root, "portrait");
const landscapeDir = join(root, "landscape");
const outPath = join(root, "image_lists.json");

const exts = new Set([".webp", ".jpg", ".jpeg", ".png", ".gif"]);

function hasAllowedExt(name) {
  const lower = name.toLowerCase();
  for (const ext of exts) {
    if (lower.endsWith(ext)) return true;
  }
  return false;
}

async function listKeys(dir, prefix) {
  const names = await readdir(dir, { withFileTypes: true });
  return names
    .filter((d) => d.isFile() && hasAllowedExt(d.name))
    .map((d) => `${prefix}/${d.name}`)
    .sort((a, b) => a.localeCompare(b));
}

const small = await listKeys(portraitDir, "portrait");
const large = await listKeys(landscapeDir, "landscape");

if (small.length === 0) {
  throw new Error(`No images found in ${portraitDir}`);
}
if (large.length === 0) {
  throw new Error(`No images found in ${landscapeDir}`);
}

const payload = {
  small_screens: small,
  large_screens: large,
};

await writeFile(outPath, JSON.stringify(payload), "utf8");
console.log(`Wrote ${outPath}`);
console.log(`small_screens: ${small.length}`);
console.log(`large_screens: ${large.length}`);
