<?php
/**
 * Main entry point — routes to login or dashboard.
 */

require_once __DIR__ . '/includes/session.php';

init_session();

if (is_authenticated()) {
    if (!empty($_SESSION['clickup_workspace'])) {
        header('Location: /dashboard.php');
    } else {
        header('Location: /workspace.php');
    }
} else {
    header('Location: /login.php');
}
exit;
