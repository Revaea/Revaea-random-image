from PIL import Image
import os
import hashlib
import json


# 计算文件的哈希值
def calculate_file_hash(image_path):
    hash_md5 = hashlib.md5()
    with open(image_path, "rb") as f:
        for chunk in iter(lambda: f.read(4096), b""):
            hash_md5.update(chunk)
    return hash_md5.hexdigest()


# 检查图片方向
def get_image_orientation(image_path):
    with Image.open(image_path) as img:
        width, height = img.size
        return "landscape" if width > height else "portrait"


# 转换图片为 WebP 格式，增加哈希检查
def convert_to_webp(image_path, output_folder, max_pixels=178956970, processed_hashes=set()):
    # 计算文件哈希
    file_hash = calculate_file_hash(image_path)
    if file_hash in processed_hashes:
        print(f"Skipping {image_path} because it is a duplicate.")
        return
    processed_hashes.add(file_hash)

    try:
        with Image.open(image_path) as img:
            width, height = img.size
            if width * height > max_pixels:
                print(f"Skipping {image_path} because it exceeds the size limit.")
                return

            # 构建输出路径
            output_path = os.path.join(output_folder, os.path.splitext(os.path.basename(image_path))[0] + ".webp")
            if os.path.exists(output_path):
                print(f"Skipping {image_path} because {output_path} already exists.")
                return

            # 保存图片为 WebP 格式
            img.save(output_path, "webp")
            print(f"Converted {image_path} to {output_path}")
    except Exception as e:
        print(f"Failed to convert {image_path}: {e}")


# 遍历文件夹中的图片
def process_images(input_folder, output_folder_landscape, output_folder_portrait):
    processed_hashes = set()  # 用于记录已处理图片的哈希值

    for filename in os.listdir(input_folder):
        if filename.endswith((".jpg", ".jpeg", ".png")):
            image_path = os.path.join(input_folder, filename)
            orientation = get_image_orientation(image_path)
            try:
                if orientation == "landscape":
                    convert_to_webp(image_path, output_folder_landscape, processed_hashes=processed_hashes)
                else:
                    convert_to_webp(image_path, output_folder_portrait, processed_hashes=processed_hashes)
            except Exception as e:
                print(f"Error processing {image_path}: {e}. Skipping this image.")


def generate_image_lists(output_folder_landscape, output_folder_portrait, list_output_path):
    image_lists = {"small_screens": [], "large_screens": []}

    # 列表里的 key 使用 API/R2 的对象 key：portrait/<file>、landscape/<file>
    for filename in sorted(os.listdir(output_folder_landscape)):
        if filename.lower().endswith(".webp"):
            image_lists["large_screens"].append("landscape/" + filename)

    for filename in sorted(os.listdir(output_folder_portrait)):
        if filename.lower().endswith(".webp"):
            image_lists["small_screens"].append("portrait/" + filename)

    # 保存列表为 JSON 文件
    with open(list_output_path, "w", encoding="utf-8") as json_file:
        json.dump(image_lists, json_file, ensure_ascii=False)
    print(f"Image lists saved to {list_output_path}")


repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

# 指定输入和输出文件夹（统一在 data/image 下）
input_folder = os.path.join(repo_root, "data", "image", "photos")
output_folder_landscape = os.path.join(repo_root, "data", "image", "landscape")
output_folder_portrait = os.path.join(repo_root, "data", "image", "portrait")

# 确保输出文件夹存在
os.makedirs(output_folder_landscape, exist_ok=True)
os.makedirs(output_folder_portrait, exist_ok=True)

# 执行转换
process_images(input_folder, output_folder_landscape, output_folder_portrait)

# 生成图片路径列表
list_output_path = os.path.join(repo_root, "data", "image_lists.json")
generate_image_lists(output_folder_landscape, output_folder_portrait, list_output_path)
