import { spawn } from "node:child_process";
import { resolve } from "node:path";

const filePath = resolve("..", "image_lists.json");
const listKey = process.env.LIST_KEY || "image_lists.json";

// 用 wrangler CLI 写入 KV（需要你先在 worker/wrangler.toml 里填好 kv namespace id）
// Windows 下直接 spawn `npx.cmd` 在某些环境会 EINVAL，这里改为 shell 模式更稳。
const child = spawn(
  "npx",
  [
    "wrangler",
    "kv",
    "key",
    "put",
    listKey,
    `--path=${filePath}`,
    "--binding=IMAGE_KV",
    "--remote",
  ],
  { stdio: "inherit", shell: true }
);

child.on("exit", (code) => {
  process.exit(code ?? 1);
});
