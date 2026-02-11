<?php
$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error: Failed to resolve repo root.";
    exit;
}

// JSON 文件路径（位于 data/ 下）
$jsonFilePath = $repoRoot . '/data/image_lists.json';

// 检测用户代理以区分手机和电脑访问
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone)/i', $userAgent);

// 添加 CORS 头部
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

try {
    if (!file_exists($jsonFilePath)) {
        throw new Exception("Image list file not found.");
    }

    $jsonContent = file_get_contents($jsonFilePath);
    $imageLists = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Failed to parse image list JSON.");
    }

    $selectedList = $isMobile ? ($imageLists['small_screens'] ?? []) : ($imageLists['large_screens'] ?? []);
    if (empty($selectedList)) {
        throw new Exception("No images found in the selected list.");
    }

    $randomImage = $selectedList[array_rand($selectedList)];
    if (!is_string($randomImage) || $randomImage === '') {
        throw new Exception("Invalid image path in list.");
    }

    // 兼容列表里可能出现的 ./portrait/xx.webp 或 portrait/xx.webp
    // 列表里的 key 仍然是 portrait/... 或 landscape/...，但文件实际在 data/image/ 下
    $imageRelativePath = ltrim($randomImage, './');
    $imageFsPath = $repoRoot . '/data/image/' . $imageRelativePath;

    if (!file_exists($imageFsPath)) {
        throw new Exception("Image file not found: $randomImage");
    }

    // 生成图片的 URL（移除掉开头的"./"）
    $imageURL = 'https://api.revaea.com/' . $imageRelativePath;

    if (isset($_GET['json'])) {
        header('Content-Type: application/json');
        echo json_encode(['url' => $imageURL], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $imgExtension = pathinfo($imageFsPath, PATHINFO_EXTENSION);
    switch (strtolower($imgExtension)) {
        case 'webp':
            header('Content-Type: image/webp');
            break;
        case 'jpg':
        case 'jpeg':
            header('Content-Type: image/jpeg');
            break;
        case 'png':
            header('Content-Type: image/png');
            break;
        case 'gif':
            header('Content-Type: image/gif');
            break;
        default:
            throw new Exception("Unsupported image format: $imgExtension");
    }

    header('X-Image-URL: ' . $imageURL);
    readfile($imageFsPath);
} catch (Exception $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error: " . $e->getMessage();
    exit;
}
?>
