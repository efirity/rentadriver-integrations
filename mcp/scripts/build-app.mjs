// Bundle each MCP App (src/app/*.ts) with esbuild and inline it into a single HTML file under dist/ui/. Runs under plain
// node in the Docker build (node:22-alpine, no Bun) and locally via `npm run build`.
import { build } from "esbuild";
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
const root = join(dirname(fileURLToPath(import.meta.url)), "..");
mkdirSync(join(root, "dist/ui"), { recursive: true });
for (const name of ["delivery", "quote"]) {
  const out = await build({ entryPoints: [join(root, "src/app", `${name}.ts`)], bundle: true, write: false, format: "iife", platform: "browser", target: "es2022", minify: true, logLevel: "error" });
  const js = out.outputFiles[0].text;
  const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RentADriver ${name}</title></head><body><script>${js.replace(/<\/script/gi, "<\\/script")}</script></body></html>`;
  writeFileSync(join(root, "dist/ui", `${name}.html`), html);
  console.log(`dist/ui/${name}.html ${(html.length / 1024).toFixed(0)} kB`);
}
