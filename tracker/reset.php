<?php
require_once __DIR__ . '/config.php';

if (file_exists(PROGRESS_FILE)) {
    file_put_contents(PROGRESS_FILE, json_encode([]));
}
header('Location: index.php');
exit;
