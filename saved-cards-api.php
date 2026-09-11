<?php
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/saved_cards.php';

require_login();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Content-Type: application/json');

$me = current_user();
$role = $me['role'] ?? 'viewer';
$method = $_SERVER['REQUEST_METHOD'];

function require_csrf(): bool
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'], $token);
}

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;

        if ($id !== null) {
            $record = get_saved_card($id);
            if ($record === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Not found.']);
                exit;
            }
            echo json_encode($record);
            exit;
        }

        echo json_encode(all_saved_cards());
        exit;
    }

    if ($method === 'POST') {
        if (!require_csrf()) {
            http_response_code(403);
            echo json_encode(['error' => 'Your session expired. Please reload the page.']);
            exit;
        }

        if ($role === 'viewer') {
            http_response_code(403);
            echo json_encode(['error' => 'View-only access — cannot save records.']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input['frontSnapshot'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing card data.']);
            exit;
        }

        $record = add_saved_card($input, $me['username']);
        http_response_code(201);
        echo json_encode($record);
        exit;
    }

    if ($method === 'DELETE') {
        if (!require_csrf()) {
            http_response_code(403);
            echo json_encode(['error' => 'Your session expired. Please reload the page.']);
            exit;
        }

        if ($role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only admins can delete saved cards.']);
            exit;
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id.']);
            exit;
        }

        delete_saved_card($id);
        echo json_encode(['deleted' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
} catch (PDOException $e) {
    error_log('saved-cards-api failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error. Please try again.']);
}
