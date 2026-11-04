<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/mongo.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cursor = mcoll('events')->find([], [
        'sort' => ['event_date' => 1, 'event_time' => 1],
        'projection' => [
            'id' => 1,
            'name' => 1,
            'event_type' => 1,
            'event_date' => 1,
            'event_time' => 1,
            'venue' => 1,
            'status' => 1,
            'priority' => 1
        ]
    ]);
    $rows = array_values(array_map('mongo_doc_to_array', iterator_to_array($cursor)));
    echo json_encode(['data' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
