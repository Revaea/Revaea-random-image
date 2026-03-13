<?php

function ri_respond_with_error(int $statusCode, string $message): void {
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $message;
    exit;
}

function ri_send_internal_error(string $message): void {
    ri_respond_with_error(500, 'Error: ' . $message);
}

function ri_send_not_found(string $message = 'Not found.'): void {
    ri_respond_with_error(404, $message);
}

function ri_get_repo_root(): string {
    $repoRoot = realpath(__DIR__ . '/../..');
    if ($repoRoot === false) {
        ri_send_internal_error('Failed to resolve repo root.');
    }

    return $repoRoot;
}

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

function ri_is_mobile_user_agent(?string $userAgent): bool {
    if (!$userAgent) {
        return false;
    }

    return preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone)/i', $userAgent) === 1;
}

function ri_send_cors_headers(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

function ri_is_options_request(): bool {
    return (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS');
}

function ri_prepare_request(): void {
    ri_send_cors_headers();

    if (ri_is_options_request()) {
        exit;
    }
}

function ri_get_request_path(): string {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($requestPath) || $requestPath === '') {
        return '/';
    }

    return $requestPath;
}

function ri_get_mime_type(string $ext): string {
    switch (strtolower($ext)) {
        case 'webp':
            return 'image/webp';
        case 'jpg':
        case 'jpeg':
            return 'image/jpeg';
        case 'png':
            return 'image/png';
        case 'gif':
            return 'image/gif';
        default:
            return 'application/octet-stream';
    }
}

function ri_set_content_type_from_path(string $filePath): void {
    $imgExtension = pathinfo($filePath, PATHINFO_EXTENSION);
    header('Content-Type: ' . ri_get_mime_type($imgExtension));
}

function ri_set_image_cache_headers(string $imgPath, int $maxAge = 3600): void {
    header('Cache-Control: public, max-age=' . (int)$maxAge);

    $mtime = @filemtime($imgPath);
    $size = @filesize($imgPath);
    if ($mtime !== false) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    }

    if ($mtime !== false && $size !== false) {
        $etag = '"' . md5($imgPath . '|' . $size . '|' . $mtime) . '"';
        header('ETag: ' . $etag);

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch === $etag) {
            http_response_code(304);
            exit;
        }

        $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if ($ifModifiedSince !== '') {
            $clientTime = strtotime($ifModifiedSince);
            if ($clientTime !== false && $mtime <= $clientTime) {
                http_response_code(304);
                exit;
            }
        }
    }
}

function ri_load_image_lists(string $repoRoot): array {
    $jsonFilePath = $repoRoot . '/data/image_lists.json';
    if (!file_exists($jsonFilePath)) {
        throw new RuntimeException('Image list file not found.');
    }

    $jsonContent = file_get_contents($jsonFilePath);
    if ($jsonContent === false) {
        throw new RuntimeException('Failed to read image list JSON.');
    }

    $imageLists = json_decode($jsonContent, true);
    if (!is_array($imageLists) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Failed to parse image list JSON.');
    }

    return $imageLists;
}

function ri_get_list_key_for_device(bool $isMobile): string {
    return $isMobile ? 'small_screens' : 'large_screens';
}

function ri_normalize_image_relative_path(string $imagePath): string {
    return ltrim(str_replace('\\', '/', $imagePath), './');
}

function ri_get_random_image_relative_path(array $imageLists, string $listKey): string {
    $selectedList = $imageLists[$listKey] ?? [];
    if (!is_array($selectedList) || empty($selectedList)) {
        throw new RuntimeException('No images found in the selected list.');
    }

    $randomImage = $selectedList[array_rand($selectedList)];
    if (!is_string($randomImage) || $randomImage === '') {
        throw new RuntimeException('Invalid image path in list.');
    }

    return ri_normalize_image_relative_path($randomImage);
}

function ri_get_image_file_path(string $repoRoot, string $imageRelativePath): string {
    $normalizedRelativePath = ltrim($imageRelativePath, '/');
    $imageFsPath = $repoRoot . '/data/image/' . $normalizedRelativePath;
    if (!is_file($imageFsPath) || !is_readable($imageFsPath)) {
        throw new RuntimeException('Image file not found: ' . $imageRelativePath);
    }

    return $imageFsPath;
}

function ri_get_random_image_data(string $repoRoot, string $listKey): array {
    $imageLists = ri_load_image_lists($repoRoot);
    $imageRelativePath = ri_get_random_image_relative_path($imageLists, $listKey);
    $imageFsPath = ri_get_image_file_path($repoRoot, $imageRelativePath);

    return [
        'relative_path' => $imageRelativePath,
        'file_path' => $imageFsPath,
    ];
}

function ri_output_image_file(string $filePath): void {
    if (!is_file($filePath) || !is_readable($filePath)) {
        ri_send_not_found('Image not found.');
    }

    ri_set_content_type_from_path($filePath);
    ri_set_image_cache_headers($filePath);
    readfile($filePath);
    exit;
}

function ri_try_serve_static_image_route(string $repoRoot, string $requestPath): void {
    foreach (['/portrait/' => 'portrait', '/landscape/' => 'landscape'] as $prefixPath => $folder) {
        if (strncmp($requestPath, $prefixPath, strlen($prefixPath)) !== 0) {
            continue;
        }

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

function ri_build_image_url(string $origin, string $imageRelativePath): string {
    return rtrim($origin, '/') . '/' . ltrim($imageRelativePath, '/');
}

function ri_output_json(array $payload): void {
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function ri_output_random_image_response(string $repoRoot, string $listKey): void {
    $imageData = ri_get_random_image_data($repoRoot, $listKey);
    $imageRelativePath = $imageData['relative_path'];
    $imageFsPath = $imageData['file_path'];
    $imageURL = ri_build_image_url(ri_get_request_origin(), $imageRelativePath);

    if (isset($_GET['json'])) {
        ri_output_json(['url' => $imageURL]);
    }

    header('X-Image-URL: ' . $imageURL);
    ri_output_image_file($imageFsPath);
}