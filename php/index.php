<?php
require_once __DIR__ . '/lib/common.php';

$repoRoot = ri_get_repo_root();

ri_send_cors_headers();

if (ri_is_options_request()) {
    exit;
}

$isMobile = ri_is_mobile_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');

ri_try_serve_static_image_route($repoRoot, ri_get_request_path());

try {
    $listKey = ri_get_list_key_for_device((bool) $isMobile);
    $imageData = ri_get_random_image_data($repoRoot, $listKey);
    $imageRelativePath = $imageData['relative_path'];
    $imageFsPath = $imageData['file_path'];

    $imageURL = ri_build_image_url(ri_get_request_origin(), $imageRelativePath);

    if (isset($_GET['json'])) {
        ri_output_json(['url' => $imageURL]);
    }

    header('X-Image-URL: ' . $imageURL);
    ri_output_image_file($imageFsPath);
} catch (Exception $e) {
    ri_send_internal_error($e->getMessage());
}
?>
