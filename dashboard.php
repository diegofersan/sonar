<?php
/**
 * Dashboard — workspace selector & main dashboard view.
 */

require_once __DIR__ . '/includes/session.php';

init_session();

// Must be authenticated
if (!is_authenticated()) {
    header('Location: /login.php');
    exit;
}

$user = $_SESSION['clickup_user'] ?? [];
$userName = htmlspecialchars($user['username'] ?? $user['name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$userAvatar = $user['profilePicture'] ?? $user['avatar'] ?? null;
$userInitial = strtoupper(mb_substr($userName, 0, 1));

// Must have workspace selected
if (empty($_SESSION['clickup_workspace'])) {
    header('Location: /workspace.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sonar — Dashboard</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="app-header">
        <div class="header-left">
            <span class="brand-name">Sonar</span>
        </div>
        <div class="header-right">
            <div class="user-info">
                <?php if ($userAvatar): ?>
                    <img class="user-avatar" src="<?= htmlspecialchars($userAvatar, ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= $userName ?>">
                <?php else: ?>
                    <span class="user-avatar-placeholder"><?= $userInitial ?></span>
                <?php endif; ?>
                <span class="user-name"><?= $userName ?></span>
            </div>
            <button id="btn-logout" class="btn btn-secondary btn-sm">Logout</button>
        </div>
    </header>

    <!-- Main -->
    <main class="main-content">
            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Minhas Tarefas</h2>
                    <span id="task-count" class="badge"></span>
                </div>
                <div class="toolbar-right">
                    <span id="last-sync-info" class="text-secondary"></span>
                    <button id="btn-sync" class="btn btn-primary btn-sm">
                        <svg class="sync-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                        Sync
                    </button>
                </div>
            </div>

            <!-- Search -->
            <div class="search-box">
                <input type="text" id="search-input" placeholder="Buscar por titulo..." autocomplete="off">
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="ready">Fila <span id="ready-count" class="tab-count"></span></button>
                <button class="tab" data-tab="future">Futuro <span id="future-count" class="tab-count"></span></button>
                <button class="tab" data-tab="cancelled">Canceladas <span id="cancelled-count" class="tab-count"></span></button>
                <button class="tab" data-tab="calendar">Calendario</button>
            </div>

            <!-- Tasks -->
            <div id="ready-list" data-tab-content="ready">
                <div class="loading-container">
                    <span class="loading-spinner"></span>
                    <span>A carregar tarefas...</span>
                </div>
            </div>
            <div id="future-list" data-tab-content="future" style="display:none;"></div>
            <div id="cancelled-list" data-tab-content="cancelled" style="display:none;"></div>

            <!-- Calendar -->
            <div id="calendar-view" data-tab-content="calendar" style="display:none;">
                <div class="calendar-nav">
                    <button id="cal-prev" class="btn btn-secondary btn-sm">&larr;</button>
                    <span id="cal-week-label"></span>
                    <button id="cal-next" class="btn btn-secondary btn-sm">&rarr;</button>
                </div>
                <div id="calendar-grid" class="calendar-grid"></div>
            </div>
    </main>

    <script src="/assets/js/app.js"></script>
</body>
</html>
