<?php
$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error: Failed to resolve repo root.";
    exit;
}

function get_request_origin(): string {
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if ($proto !== '') {
        $proto = strtolower(trim(explode(',', $proto)[0]));
    } else {
        $proto = $_SERVER['REQUEST_SCHEME'] ?? '';
        if ($proto === '') {
            $https = $_SERVER['HTTPS'] ?? '';
            $proto = ($https && strtolower($https) !== 'off') ? 'https' : 'http';
        }
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $host = trim(explode(',', $host)[0]);
    return $proto . '://' . $host;
}

function set_content_type_from_path(string $filePath): void {
    $imgExtension = pathinfo($filePath, PATHINFO_EXTENSION);
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
            header('Content-Type: application/octet-stream');
            break;
    }
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    exit;
}

// 作为 router 使用：支持 /portrait/<file>、/landscape/<file> 直取
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($requestPath) || $requestPath === '') {
    $requestPath = '/';
}

foreach (['/portrait/' => 'portrait', '/landscape/' => 'landscape'] as $prefixPath => $folder) {
    if (strncmp($requestPath, $prefixPath, strlen($prefixPath)) === 0) {
        $relative = substr($requestPath, strlen($prefixPath));
        $relative = rawurldecode($relative);

        $baseDir = realpath($repoRoot . '/data/image/' . $folder);
        if ($baseDir === false) {
            header("HTTP/1.1 500 Internal Server Error");
            echo "Error: Image base folder not found.";
            exit;
        }

        $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative);
        $real = realpath($candidate);

        if ($real === false || (strncmp($real, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir . DIRECTORY_SEPARATOR)) !== 0 && $real !== $baseDir)) {
            header("HTTP/1.1 404 Not Found");
            echo "Not found.";
            exit;
        }
        if (!is_file($real)) {
            header("HTTP/1.1 404 Not Found");
            echo "Not found.";
            exit;
        }

        set_content_type_from_path($real);
        readfile($real);
        exit;
    }
}

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

    // 生成图片的 URL（自动使用当前请求的 scheme+host）
    $origin = get_request_origin();
    $imageURL = $origin . '/' . ltrim($imageRelativePath, '/');

    if (isset($_GET['json'])) {
        header('Content-Type: application/json');
        echo json_encode(['url' => $imageURL], JSON_UNESCAPED_SLASHES);
        exit;
    }

    set_content_type_from_path($imageFsPath);

    header('X-Image-URL: ' . $imageURL);
    readfile($imageFsPath);
} catch (Exception $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error: " . $e->getMessage();
    exit;
}
?>
