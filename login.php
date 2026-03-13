<?php
/**
 * Login page — "Login with ClickUp" button.
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/security.php';

init_session();
send_security_headers();

// Already logged in? Go to dashboard.
if (is_authenticated()) {
    header('Location: /dashboard.php');
    exit;
}

$error = isset($_GET['error']) ? htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sonar — Login</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="brand">
                <div class="brand-name">Sonar</div>
                <div class="brand-subtitle">ClickUp Dashboard</div>
            </div>

            <?php if ($error): ?>
                <div class="error-message" style="margin-bottom: 1.5rem;"><?= $error ?></div>
            <?php endif; ?>

            <a href="/oauth/authorize.php" class="btn btn-primary btn-block">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Login with ClickUp
            </a>
        </div>
    </div>
</body>
</html>
