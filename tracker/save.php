<?php
/**
 * FDE Tracker — Save endpoint
 * POST JSON: { milestone, index, checked } OR { milestone, bulk, checked, total }
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['milestone'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$milestone_id = preg_replace('/[^0-9]/', '', $data['milestone']);
$milestone_id = str_pad($milestone_id, 2, '0', STR_PAD_LEFT);

// Validate milestone ID exists
$valid_ids = array_column($MILESTONES, 'id');
if (!in_array($milestone_id, $valid_ids, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown milestone']);
    exit;
}

$progress = load_progress();

if (!empty($data['bulk'])) {
    // Mark all tasks in milestone as checked/unchecked
    $total   = (int)($data['total'] ?? 50);
    $checked = (bool)$data['checked'];
    $progress[$milestone_id] = [];
    for ($i = 0; $i < $total; $i++) {
        $progress[$milestone_id][(string)$i] = $checked;
    }
} else {
    // Single task toggle
    $index   = (int)($data['index'] ?? 0);
    $checked = (bool)$data['checked'];
    if (!isset($progress[$milestone_id])) {
        $progress[$milestone_id] = [];
    }
    $progress[$milestone_id][(string)$index] = $checked;
}

$ok = save_progress($progress);
echo json_encode(['ok' => $ok]);
