<?php
/**
 * Dashboard — workspace selector & main dashboard view.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/security.php';

init_session();
send_security_headers();

// Must be authenticated
if (!is_authenticated()) {
    header('Location: /login.php');
    exit;
}

$user = $_SESSION['clickup_user'] ?? [];
$userName = htmlspecialchars($user['username'] ?? $user['name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$userAvatar = $user['profilePicture'] ?? $user['avatar'] ?? null;
$userInitial = strtoupper(mb_substr($userName, 0, 1));

// Check if current user is Diego Ferreira (admin features)
$rawName = $user['username'] ?? $user['name'] ?? '';
$isAdmin = stripos($rawName, 'diego ferreira') !== false || stripos($rawName, 'diego') !== false;

// F01 — department-head gate for the Colaboradores view
$isDepartmentHead = is_department_head();

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
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
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
            <div class="notif-wrapper">
                <button id="btn-notifications" class="btn-icon-header" aria-label="Notificacoes" title="Notificacoes">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    <span id="notif-badge" class="notif-badge" style="display:none;">0</span>
                </button>
                <div id="notif-panel" class="notif-panel" style="display:none;">
                    <div class="notif-panel-header">
                        <span class="notif-panel-title">Notificacoes</span>
                        <button id="btn-mark-all-read" class="notif-mark-all">Marcar todas como lidas</button>
                    </div>
                    <div id="notif-list" class="notif-list">
                        <div class="notif-empty">Sem notificacoes</div>
                    </div>
                </div>
            </div>
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

    <!-- Navbar -->
    <nav class="app-navbar" aria-label="Vistas">
        <a href="#editorial" class="nav-link active" data-nav="editorial">Linha Editorial</a>
        <?php if ($isDepartmentHead): ?>
        <a href="#collaborators" class="nav-link" data-nav="collaborators">Colaboradores</a>
        <?php endif; ?>
    </nav>

    <!-- Main -->
    <main class="main-content">
        <section class="view" data-view="editorial">
            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Linha Editorial</h2>
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
                <button class="tab" data-tab="approval">Em Aprovação <span id="approval-count" class="tab-count"></span></button>
                <button class="tab" data-tab="future">Futuro <span id="future-count" class="tab-count"></span></button>
                <button class="tab" data-tab="cancelled">Canceladas <span id="cancelled-count" class="tab-count"></span></button>
                <button class="tab" data-tab="calendar">Calendario</button>
                <?php if ($isAdmin): ?>
                <button class="tab" data-tab="report">Relatório</button>
                <?php endif; ?>
            </div>

            <!-- Tasks -->
            <div id="ready-list" data-tab-content="ready">
                <div class="loading-container">
                    <span class="loading-spinner"></span>
                    <span>A carregar tarefas...</span>
                </div>
            </div>
            <div id="approval-list" data-tab-content="approval" style="display:none;"></div>
            <div id="future-list" data-tab-content="future" style="display:none;"></div>
            <div id="cancelled-list" data-tab-content="cancelled" style="display:none;"></div>

            <!-- Report (admin only) -->
            <?php if ($isAdmin): ?>
            <div id="report-view" data-tab-content="report" style="display:none;">
                <div class="report-filters">
                    <label class="report-filter-label">Linhas Editoriais:</label>
                    <div id="report-le-filters" class="report-le-filters">
                        <span class="text-secondary">A carregar...</span>
                    </div>
                </div>
                <div id="report-list" class="report-list">
                    <div class="empty-state">
                        <h3>Seleciona uma linha editorial</h3>
                        <p>Escolhe as linhas editoriais para ver os posts em atraso de todos os utilizadores.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Calendar -->
            <div id="calendar-view" data-tab-content="calendar" style="display:none;">
                <div class="calendar-nav">
                    <button id="cal-prev" class="btn btn-secondary btn-sm">&larr;</button>
                    <span id="cal-week-label"></span>
                    <button id="cal-next" class="btn btn-secondary btn-sm">&rarr;</button>
                </div>
                <div id="calendar-grid" class="calendar-grid"></div>
            </div>
        </section>

        <?php if ($isDepartmentHead): ?>
        <section class="view" data-view="collaborators" style="display:none;">
            <!-- F01 — Colaboradores view (preenchida na Tarefa 9) -->
        </section>
        <?php endif; ?>
    </main>

    <script src="/assets/js/app.js"></script>
</body>
</html>
