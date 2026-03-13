<?php
/**
 * Logout -- destroy the session and redirect to login.
 */

require_once __DIR__ . '/../includes/session.php';

clear_session();

header('Location: ../login.php');
exit;
