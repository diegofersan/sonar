<?php
/**
 * Workspace selector — shown after login when user has multiple workspaces.
 */

require_once __DIR__ . '/includes/session.php';

init_session();

if (!is_authenticated()) {
    header('Location: /login.php');
    exit;
}

// Already has workspace — go to dashboard
if (!empty($_SESSION['clickup_workspace'])) {
    header('Location: /dashboard.php');
    exit;
}

$user = $_SESSION['clickup_user'] ?? [];
$userName = htmlspecialchars($user['username'] ?? $user['name'] ?? 'User', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sonar — Workspace</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <main class="main-content" style="max-width: 600px; margin: 4rem auto;">
        <div class="section-header">
            <h2>Ola, <?= $userName ?>!</h2>
            <p>Escolhe o workspace com que queres trabalhar.</p>
        </div>
        <div id="workspace-list"></div>
    </main>
    <script src="/assets/js/app.js"></script>
</body>
</html>
