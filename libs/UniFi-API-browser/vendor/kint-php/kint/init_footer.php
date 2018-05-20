<?php

Kint::$file_link_format = ini_get('xdebug.file_link_format');
if (isset($_SERVER['DOCUMENT_ROOT'])) {
    Kint::$app_root_dirs = [
        $_SERVER['DOCUMENT_ROOT'] => '<ROOT>',
        realpath($_SERVER['DOCUMENT_ROOT']) => '<ROOT>',
    ];
}
