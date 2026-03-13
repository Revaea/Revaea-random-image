<?php
require_once __DIR__ . '/lib/common.php';

$repoRoot = ri_get_repo_root();

ri_prepare_request();

$isMobile = ri_is_mobile_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');

ri_try_serve_static_image_route($repoRoot, ri_get_request_path());

try {
    ri_output_random_image_response($repoRoot, ri_get_list_key_for_device((bool) $isMobile));
} catch (Exception $e) {
    ri_send_internal_error($e->getMessage());
}
?>
