<?php
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in()) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = db()->query("SELECT id,name,event_type,event_date,event_time,venue,status,priority FROM events ORDER BY event_date,event_time")->fetchAll();
    echo json_encode(['data'=>$rows], JSON_UNESCAPED_UNICODE);
    exit;
}
http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
