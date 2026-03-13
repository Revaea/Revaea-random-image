<?php
require_once __DIR__ . '/../lib/common.php';

$repoRoot = ri_get_repo_root();

ri_prepare_request();

try {
    ri_output_random_image_response($repoRoot, 'large_screens');
} catch (Exception $e) {
    ri_send_internal_error($e->getMessage());
}
?>
