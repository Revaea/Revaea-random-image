<?php
$repoRoot = realpath(__DIR__ . '/../..');
if ($repoRoot === false) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error: Failed to resolve repo root.";
    exit;
}

$path = $repoRoot . '/data/image/landscape';

function getImagesFromDir($path) {
    $images = array();
    if ($img_dir = @opendir($path)) {
        while (false !== ($img_file = readdir($img_dir))) {
            if (preg_match("/\.(webp|jpg|jpeg|png|gif)$/i", $img_file)) {
                $images[] = $img_file;
            }
        }
        closedir($img_dir);
    }
    return $images;
}

function generateImagePath($path, $img) {
    return $path . '/' . $img;
}

$imgList = getImagesFromDir($path);
shuffle($imgList);
$img = reset($imgList);

$img_extension = pathinfo($img, PATHINFO_EXTENSION);
switch (strtolower($img_extension)) {
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
}

$img_path = generateImagePath($path, $img);
readfile($img_path);
?>
