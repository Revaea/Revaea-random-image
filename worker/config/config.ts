const MILLISECONDS_PER_MINUTE = 60 * 1000;
const SECONDS_PER_MINUTE = 60;

export const CACHE_CONFIG = {
  /**
   * Worker 内存中图片列表的缓存时长，单位为分钟。
   * 
   * 到期后会重新从 KV 读取最新的图片列表。
   */
  imageListTtlMinutes: 10,
  /**
   * 返回给客户端与 CDN 的图片缓存时长，单位为分钟。
   * 
   * 该值会被换算为 HTTP `Cache-Control: max-age` 所需的秒数。
   */
  imageResponseMaxAgeMinutes: 6 * 60,
} as const;

export function getImageListTtlMs(): number {
  return CACHE_CONFIG.imageListTtlMinutes * MILLISECONDS_PER_MINUTE;
}

export function getImageResponseCacheControl(): string {
  return `public, max-age=${CACHE_CONFIG.imageResponseMaxAgeMinutes * SECONDS_PER_MINUTE}`;
}
