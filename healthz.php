<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Dhaka');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

http_response_code(200);
echo json_encode([
    'status'    => 'ok',
    'timestamp' => date(DATE_ATOM)
], JSON_UNESCAPED_SLASHES);
exit;
