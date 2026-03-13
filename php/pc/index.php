<?php
require_once __DIR__ . '/../lib/common.php';

$repoRoot = ri_get_repo_root();

try {
    $imageData = ri_get_random_image_data($repoRoot, 'large_screens');
    ri_output_image_file($imageData['file_path']);
} catch (Exception $e) {
    ri_send_internal_error($e->getMessage());
}
?>
