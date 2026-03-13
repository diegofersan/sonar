<?php
/**
 * Logout -- destroy the session and return a JSON response.
 * Only accepts POST requests with a valid CSRF token.
 */

require_once __DIR__ . '/../includes/session.php';

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Validate CSRF token before processing logout
require_csrf();

clear_session();

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'redirect' => '../login.php']);
exit;
