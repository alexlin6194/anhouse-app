<?php
declare(strict_types=1);

session_start();
$_SESSION = [];
session_destroy();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'message' => '已登出', 'redirect' => 'index.html'], JSON_UNESCAPED_UNICODE);
