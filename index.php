<?php
// JSON 文件路径
$jsonFilePath = './image_lists.json';

// 检测用户代理以区分手机和电脑访问
$userAgent = $_SERVER['HTTP_USER_AGENT'];
$isMobile = preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone)/i', $userAgent);

// 添加 CORS 头部
header('Access-Control-Allow-Origin: *');  // 允许所有域访问，如果需要只允许特定域，请修改为 'http://localhost:8080'
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');  // 允许的 HTTP 方法
header('Access-Control-Allow-Headers: Content-Type, Authorization');  // 允许的请求头

try {
    // 检查 JSON 文件是否存在
    if (!file_exists($jsonFilePath)) {
        throw new Exception("Image list file not found.");
    }

    // 读取 JSON 文件
    $jsonContent = file_get_contents($jsonFilePath);
    $imageLists = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Failed to parse image list JSON.");
    }

    // 根据设备类型选择图片列表
    $selectedList = $isMobile ? $imageLists['small_screens'] : $imageLists['large_screens'];

    if (empty($selectedList)) {
        throw new Exception("No images found in the selected list.");
    }

    // 随机选择一张图片
    $randomImage = $selectedList[array_rand($selectedList)];

    $randomImage = $selectedList[array_rand($selectedList)];
    $randomImage .= '?t=' . time(); // 或 '?v=' . uniqid();


    // 禁用浏览器缓存
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // 获取图片的格式
    $imgExtension = pathinfo($randomImage, PATHINFO_EXTENSION);

    // 根据图片的格式设置 Content-Type
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

    // 输出随机图片
    readfile($randomImage);

} catch (Exception $e) {
    // 错误处理
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error: " . $e->getMessage();
    exit;
}
?>
