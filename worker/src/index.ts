type ImageLists = {
  small_screens: string[];
  large_screens: string[];
};

type Env = {
  IMAGE_KV: KVNamespace;
  IMAGES_BUCKET: R2Bucket;
  LIST_KEY?: string;
  BASE_URL?: string;
};

let cachedLists: ImageLists | null = null;
let cachedListsLoadedAt = 0;

function corsHeaders(): Record<string, string> {
  return {
    "Access-Control-Allow-Origin": "*",
    "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, Authorization",
  };
}

function isMobileUA(userAgent: string | null): boolean {
  if (!userAgent) return false;
  return /(android|iphone|ipad|ipod|blackberry|windows phone)/i.test(userAgent);
}

function normalizeListPath(p: string): string {
  // 兼容现有 JSON 里带 ./ 的路径
  return p.replace(/^\.\//, "");
}

function pickRandom<T>(arr: T[]): T {
  return arr[Math.floor(Math.random() * arr.length)];
}

function guessContentTypeFromKey(key: string): string {
  const lower = key.toLowerCase();
  if (lower.endsWith(".webp")) return "image/webp";
  if (lower.endsWith(".jpg") || lower.endsWith(".jpeg")) return "image/jpeg";
  if (lower.endsWith(".png")) return "image/png";
  if (lower.endsWith(".gif")) return "image/gif";
  return "application/octet-stream";
}

async function loadImageLists(env: Env): Promise<ImageLists> {
  const now = Date.now();
  // 简单内存缓存：10 分钟
  if (cachedLists && now - cachedListsLoadedAt < 10 * 60 * 1000) {
    return cachedLists;
  }

  const listKey = (env.LIST_KEY && env.LIST_KEY.trim()) || "image_lists.json";
  const raw = await env.IMAGE_KV.get(listKey);
  if (!raw) {
    throw new Error(
      `KV missing image list: key="${listKey}". Put image_lists.json into KV first.`
    );
  }

  const parsed = JSON.parse(raw) as ImageLists;
  if (!parsed?.small_screens?.length || !parsed?.large_screens?.length) {
    throw new Error("Invalid image list JSON: expected small_screens & large_screens arrays");
  }

  cachedLists = parsed;
  cachedListsLoadedAt = now;
  return parsed;
}

function buildBaseUrl(env: Env, requestUrl: URL): string {
  const configured = (env.BASE_URL || "").trim();
  if (configured) return configured.replace(/\/$/, "");
  return requestUrl.origin;
}

async function serveR2Object(env: Env, key: string, request: Request): Promise<Response> {
  const object = await env.IMAGES_BUCKET.get(key);
  if (!object) {
    return new Response("Not Found", { status: 404, headers: corsHeaders() });
  }

  const headers = new Headers(corsHeaders());
  headers.set("Content-Type", object.httpMetadata?.contentType || guessContentTypeFromKey(key));
  // 交给 CDN 缓存；内容变更通常靠换文件名/ETag
  headers.set("Cache-Control", "public, max-age=86400");

  if (object.etag) headers.set("ETag", object.etag);

  // 支持 304
  const ifNoneMatch = request.headers.get("If-None-Match");
  if (ifNoneMatch && object.etag && ifNoneMatch === object.etag) {
    return new Response(null, { status: 304, headers });
  }

  return new Response(object.body, { status: 200, headers });
}

async function handleRandom(env: Env, request: Request, mode: "auto" | "mobile" | "pc"): Promise<Response> {
  const url = new URL(request.url);
  const lists = await loadImageLists(env);

  const userAgent = request.headers.get("User-Agent");
  const useMobile = mode === "mobile" || (mode === "auto" && isMobileUA(userAgent));
  const selected = useMobile ? lists.small_screens : lists.large_screens;
  if (!selected.length) {
    return new Response("No images found", { status: 500, headers: corsHeaders() });
  }

  const rawPath = pickRandom(selected);
  const key = normalizeListPath(rawPath);
  const baseUrl = buildBaseUrl(env, url);
  const imageUrl = `${baseUrl}/${encodeURI(key)}`;

  if (url.searchParams.has("json")) {
    const headers = new Headers(corsHeaders());
    headers.set("Content-Type", "application/json");
    return new Response(JSON.stringify({ url: imageUrl }), { status: 200, headers });
  }

  const response = await serveR2Object(env, key, request);
  // 跟 PHP 版一致：附带 X-Image-URL
  const headers = new Headers(response.headers);
  headers.set("X-Image-URL", imageUrl);
  return new Response(response.body, { status: response.status, headers });
}

export default {
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    // 兼容：如果用户没用 module worker 的 env 注入，这里也不影响（但 wrangler 会注入 env）
    void ctx;

    if (request.method === "OPTIONS") {
      return new Response(null, { status: 204, headers: corsHeaders() });
    }

    const url = new URL(request.url);
    const pathname = url.pathname.replace(/\/+$/, "") || "/";

    try {
      if (pathname === "/") {
        return await handleRandom(env, request, "auto");
      }
      if (pathname === "/mobile") {
        return await handleRandom(env, request, "mobile");
      }
      if (pathname === "/pc") {
        return await handleRandom(env, request, "pc");
      }

      // 兼容静态路径访问：/portrait/xxx.webp 或 /landscape/xxx.webp
      if (pathname.startsWith("/portrait/") || pathname.startsWith("/landscape/")) {
        const key = decodeURIComponent(pathname.slice(1)); // remove leading '/' and decode URL encoding
        return await serveR2Object(env, key, request);
      }

      return new Response("Not Found", { status: 404, headers: corsHeaders() });
    } catch (err) {
      const message = err instanceof Error ? err.message : "Unknown error";
      return new Response(`Error: ${message}`, { status: 500, headers: corsHeaders() });
    }
  },
};
