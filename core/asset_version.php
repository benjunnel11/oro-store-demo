<?php
function assetV($path) {
    $file = $_SERVER['DOCUMENT_ROOT'] . $path;
    return file_exists($file) ? filemtime($file) : '1';
}
