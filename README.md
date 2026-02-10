### 搭建一个简单的随机图片API，支持Docker部署


#### 简介

随机图片 API 是一种允许开发者从一个图片库或者指定的目录中获取随机图片的接口。这种 API 通常用于网站、移动应用程序或其他软件中，以便动态地展示随机图片，例如用作背景图片、占位图、或者其他需要随机化内容的场景。

### 特性

- 图片随机展示
- 设备适配：通过检测用户代理字符串，判断访问设备是手机还是电脑，并根据设备类型选择对应的图片文件夹路径。
- 图片格式支持：web,jpg,jpeg,png,gif

### 部署

#### PHP

直接丢到有PHP和Nginx的环境中就行

#### Docker

```yml
version: '3.9'
services:
    random-api:
        image: 'neixin/random-pic-api'
        volumes:
# 竖屏图片
            - './portrait:/var/www/html/portrait'
# 横屏图片
            - './landscape:/var/www/html/landscape'
        ports:
            - '8080:80'
```

### 图片处理

#### 代码

```py
from PIL import Image
import os

# 检查图片方向
def get_image_orientation(image_path):
    with Image.open(image_path) as img:
        width, height = img.size
        return "landscape" if width > height else "portrait"

# 转换图片为 WebP 格式
def convert_to_webp(image_path, output_folder, max_pixels=178956970):
    try:
        with Image.open(image_path) as img:
            # Check image size
            width, height = img.size
            if width * height > max_pixels:
                print(f"Skipping {image_path} because it exceeds the size limit.")
                return
            
            # Save the image as WebP
            output_path = os.path.join(output_folder, os.path.splitext(os.path.basename(image_path))[0] + ".webp")
            img.save(output_path, "webp")
    except Exception as e:
        print(f"Failed to convert {image_path}: {e}")

# 遍历文件夹中的图片
def process_images(input_folder, output_folder_landscape, output_folder_portrait):
    for filename in os.listdir(input_folder):
        if filename.endswith(('.jpg', '.jpeg', '.png')):
            image_path = os.path.join(input_folder, filename)
            orientation = get_image_orientation(image_path)
            try:
                if orientation == "landscape":
                    convert_to_webp(image_path, output_folder_landscape)
                else:
                    convert_to_webp(image_path, output_folder_portrait)
            except Exception as e:
                print(f"Error processing {image_path}: {e}. Skipping this image.")

# 指定输入和输出文件夹
input_folder = "/root/photos"
output_folder_landscape = "/root/landscape"
output_folder_portrait = "/root/portrait"

# 执行转换
process_images(input_folder, output_folder_landscape, output_folder_portrait)
```

#### 作用

将横屏和竖屏的图片分开，并转化为webp格式，使用时注意修改文件路径

---

## Worker 版（Cloudflare Workers + R2 + KV）

> 目标：保留当前 PHP 版的行为（UA 判断、随机图、`?json=1`、`X-Image-URL`、CORS），但把运行环境迁到边缘 Worker。

### 为什么需要 R2/KV

- Workers 不能像 PHP 那样直接读你仓库里的本地图片文件。
- 图片建议放到 R2；超大的 `image_lists.json` 不建议打进 Worker bundle，所以放到 KV。

### 路由兼容

- `GET /`：按 UA 自动选择竖屏/横屏列表并随机返回图片（默认直接输出图片字节）
- `GET /?json=1`：返回 `{"url": "..."}`
- `GET /mobile`：强制竖屏随机
- `GET /pc`：强制横屏随机
- `GET /portrait/<file>`、`GET /landscape/<file>`：按路径直接取对应图片（用于 JSON 返回的 URL 可直接访问）

### 部署步骤（概览）

1) 创建 KV（用于存 `image_lists.json`）

- Cloudflare Dashboard → Workers & Pages → KV
- 创建后把 namespace id 填到 `worker/wrangler.toml` 的 `kv_namespaces[0].id`

2) 创建 R2 Bucket（用于存图片）

- Cloudflare Dashboard → R2
- 创建后把 bucket name 填到 `worker/wrangler.toml` 的 `r2_buckets[0].bucket_name`

3) 把 `image_lists.json` 写入 KV

在 `worker/` 目录下运行：

- `npm install`
- `npm run kv:put:image-lists`

4) 上传图片到 R2

- R2 对象 key 建议为：`portrait/<文件名>` 和 `landscape/<文件名>`
- 也就是说，把仓库里的 `portrait/`、`landscape/` 目录内容分别上传到 R2 同名前缀下

5) 部署 Worker

在 `worker/` 目录下运行：

- `npm run deploy`

提示：本仓库的 Worker 版使用 `@cloudflare/workers-types` 提供 runtime 全局类型；`worker/worker-configuration.d.ts` 只保留 Env 绑定类型，避免重复声明导致的 TS 报错。需要重新生成时在 `worker/` 目录运行 `npm run types`。

### 可选配置

- `worker/wrangler.toml` 里的 `BASE_URL`：用于 JSON 返回的 URL 前缀（不填则用请求的 `origin`）
- `LIST_KEY`：KV 中存放图片列表 JSON 的 key（默认 `image_lists.json`）
