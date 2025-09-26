<?php
// JSON 文件路径
$jsonFilePath = './image_lists.json';

// 检测用户代理以区分手机和电脑访问
$userAgent = $_SERVER['HTTP_USER_AGENT'];
$isMobile = preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone)/i', $userAgent);

// 添加 CORS 头部
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

    // 检查图片文件是否存在
    if (!file_exists($randomImage)) {
        throw new Exception("Image file not found: $randomImage");
    }

    // 生成图片的 URL（移除掉开头的"./"）
    $imageURL = 'https://api.wenturc.com/' . ltrim($randomImage, './');

    // 判断是否要求返回 JSON 格式
    if (isset($_GET['json'])) {
        header('Content-Type: application/json');
        echo json_encode(['url' => $imageURL], JSON_UNESCAPED_SLASHES);
        exit;
    }

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

    // 添加图片 URL 到自定义头
    header('X-Image-URL: ' . $imageURL);

    // 输出图片内容，建议使用绝对路径
    readfile(__DIR__ . '/' . $randomImage);

} catch (Exception $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error: " . $e->getMessage();
    exit;
}
?>
