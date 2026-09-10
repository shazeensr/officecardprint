<?php
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/hr_directory.php';

require_login();

header('Content-Type: application/json');

$rc = trim($_GET['rc'] ?? '');
if ($rc === '' || !ctype_digit($rc)) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a numeric RC number.']);
    exit;
}

try {
    $result = lookup_hr_by_rc($rc);
} catch (HrDirectoryException $e) {
    error_log('HR directory lookup failed: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Could not reach the HR directory. Try again shortly.']);
    exit;
}

if ($result === null) {
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode(['found' => true] + $result);
