import { spawn } from "node:child_process";
import { resolve } from "node:path";

const filePath = resolve("..", "image_lists.json");
const listKey = process.env.LIST_KEY || "image_lists.json";

// 用 wrangler CLI 写入 KV（需要你先在 worker/wrangler.toml 里填好 kv namespace id）
const child = spawn(
  process.platform === "win32" ? "npx.cmd" : "npx",
  [
    "wrangler",
    "kv",
    "key",
    "put",
    listKey,
    `--path=${filePath}`,
    "--binding=IMAGE_KV",
  ],
  { stdio: "inherit" }
);

child.on("exit", (code) => {
  process.exit(code ?? 1);
});
