<?php
require_once __DIR__ . '/lib/common.php';

$repoRoot = ri_get_repo_root();

function ri_get_request_origin(): string {
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
            ri_send_internal_error('Image base folder not found.');
        }

        $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative);
        $real = realpath($candidate);

        if ($real === false || (strncmp($real, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir . DIRECTORY_SEPARATOR)) !== 0 && $real !== $baseDir)) {
            ri_send_not_found();
        }
        if (!is_file($real)) {
            ri_send_not_found();
        }

        ri_output_image_file($real);
    }
}

try {
    $listKey = ri_get_list_key_for_device((bool) $isMobile);
    $imageData = ri_get_random_image_data($repoRoot, $listKey);
    $imageRelativePath = $imageData['relative_path'];
    $imageFsPath = $imageData['file_path'];

    // 生成图片的 URL（自动使用当前请求的 scheme+host）
    $origin = ri_get_request_origin();
    $imageURL = $origin . '/' . ltrim($imageRelativePath, '/');

    if (isset($_GET['json'])) {
        header('Content-Type: application/json');
        echo json_encode(['url' => $imageURL], JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('X-Image-URL: ' . $imageURL);
    ri_output_image_file($imageFsPath);
} catch (Exception $e) {
    ri_send_internal_error($e->getMessage());
}
?>
