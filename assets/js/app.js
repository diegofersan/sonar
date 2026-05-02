/* ==========================================================================
   Sonar - ClickUp Dashboard
   Main Application JavaScript
   ========================================================================== */

(function () {
  'use strict';

  /* ---------- Toast Notification System ---------- */

  const Toast = {
    _container: null,

    _getContainer() {
      if (!this._container) {
        this._container = document.createElement('div');
        this._container.className = 'toast-container';
        document.body.appendChild(this._container);
      }
      return this._container;
    },

    show(message, type = 'info', duration = 3000) {
      const container = this._getContainer();
      const toast = document.createElement('div');
      toast.className = `toast toast-${type}`;
      toast.textContent = message;
      container.appendChild(toast);

      setTimeout(() => {
        toast.classList.add('toast-out');
        toast.addEventListener('animationend', () => toast.remove());
      }, duration);
    },

    success(message) {
      this.show(message, 'success');
    },

    error(message) {
      this.show(message, 'error', 5000);
    },
  };

  /* ---------- API Helpers ---------- */

  async function apiFetch(url, options = {}) {
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    const defaults = {
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken ? csrfToken.getAttribute('content') : '',
      },
      credentials: 'same-origin',
    };
    const config = { ...defaults, ...options };

    const response = await fetch(url, config);

    if (!response.ok) {
      const body = await response.json().catch(() => null);
      const msg = (body && body.error) || `Request failed (${response.status})`;
      throw new Error(msg);
    }

    return response.json();
  }

  /* ---------- Workspace Loader ---------- */

  async function fetchWorkspaces() {
    const container = document.getElementById('workspace-list');
    if (!container) return;

    // Show loading state
    container.innerHTML =
      '<div class="loading-container">' +
      '<span class="loading-spinner"></span>' +
      '<span>Loading workspaces…</span>' +
      '</div>';

    try {
      const data = await apiFetch('/api/workspaces.php');
      renderWorkspaces(container, data.workspaces || data);
    } catch (err) {
      container.innerHTML =
        '<div class="error-message">Failed to load workspaces. ' +
        escapeHtml(err.message) +
        '</div>';
      Toast.error('Could not load workspaces');
    }
  }

  function renderWorkspaces(container, workspaces) {
    if (!workspaces || workspaces.length === 0) {
      container.innerHTML =
        '<div class="error-message">No workspaces found. Make sure your ClickUp account has at least one workspace.</div>';
      return;
    }

    const grid = document.createElement('div');
    grid.className = 'workspace-grid';

    workspaces.forEach(function (ws) {
      const card = document.createElement('div');
      card.className = 'workspace-card';
      card.setAttribute('role', 'button');
      card.setAttribute('tabindex', '0');

      const name = document.createElement('div');
      name.className = 'ws-name';
      name.textContent = ws.name;

      const meta = document.createElement('div');
      meta.className = 'ws-meta';
      meta.textContent = ws.members
        ? ws.members.length + ' member' + (ws.members.length !== 1 ? 's' : '')
        : '';

      card.appendChild(name);
      card.appendChild(meta);

      card.addEventListener('click', function () {
        selectWorkspace(ws.id, ws.name);
      });
      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          selectWorkspace(ws.id, ws.name);
        }
      });

      grid.appendChild(card);
    });

    container.innerHTML = '';
    container.appendChild(grid);
  }

  /* ---------- Workspace Selection ---------- */

  async function selectWorkspace(id, name) {
    try {
      Toast.show('Selecting workspace…');
      await apiFetch('/api/select-workspace.php', {
        method: 'POST',
        body: JSON.stringify({ workspace_id: id, workspace_name: name }),
      });
      Toast.success('Workspace "' + name + '" selected');
      // Short delay so the user sees the toast, then reload
      setTimeout(function () {
        window.location.reload();
      }, 400);
    } catch (err) {
      Toast.error('Failed to select workspace: ' + err.message);
    }
  }

  /* ---------- Change Workspace ---------- */

  function bindChangeWorkspace() {
    var btn = document.getElementById('btn-change-workspace');
    if (!btn) return;

    btn.addEventListener('click', async function () {
      try {
        await apiFetch('/api/select-workspace.php', {
          method: 'POST',
          body: JSON.stringify({ workspace_id: null }),
        });
        window.location.reload();
      } catch (err) {
        Toast.error('Could not clear workspace: ' + err.message);
      }
    });
  }

  /* ---------- Logout ---------- */

  function bindLogout() {
    var btn = document.getElementById('btn-logout');
    if (!btn) return;

    btn.addEventListener('click', async function (e) {
      e.preventDefault();
      try {
        await apiFetch('/api/logout.php', { method: 'POST' });
      } catch (err) {
        // Ignore errors on logout
      }
      window.location.href = '/login.php';
    });
  }

  /* ---------- Task Fetching & Rendering ---------- */

  var CANCELLED_STATUSES = ['post cancelado', 'cancelado', 'cancelled'];
  var HIDDEN_STATUSES = ['linha editorial cancelada', 'published', 'pending', 'scheduled'];
  var APPROVAL_STATUSES = ['approval design'];
  var READY_STATUSES = ['design'];

  function classifyTask(task) {
    var status = (task.status_name || '').toLowerCase().trim();
    if (HIDDEN_STATUSES.indexOf(status) !== -1) return 'hidden';
    if (CANCELLED_STATUSES.indexOf(status) !== -1) return 'cancelled';
    if (APPROVAL_STATUSES.indexOf(status) !== -1) return 'approval';
    if (READY_STATUSES.indexOf(status) !== -1 && task.copy_ready) return 'ready';
    return 'future';
  }

  async function fetchTasks() {
    var readyContainer = document.getElementById('ready-list');
    var approvalContainer = document.getElementById('approval-list');
    var futureContainer = document.getElementById('future-list');
    var cancelledContainer = document.getElementById('cancelled-list');
    if (!readyContainer) return 0;

    readyContainer.innerHTML =
      '<div class="loading-container">' +
      '<span class="loading-spinner"></span>' +
      '<span>A carregar tarefas...</span>' +
      '</div>';

    try {
      var data = await apiFetch('/api/tasks.php');
      var all = (data.tasks || []).filter(function(t) { return classifyTask(t) !== 'hidden'; });

      _allTasks.ready = all.filter(function(t) { return classifyTask(t) === 'ready'; });
      _allTasks.approval = all.filter(function(t) { return classifyTask(t) === 'approval'; });
      _allTasks.future = all.filter(function(t) { return classifyTask(t) === 'future'; });
      _allTasks.cancelled = all.filter(function(t) { return classifyTask(t) === 'cancelled'; });

      renderTasks(readyContainer, _allTasks.ready, false);
      if (approvalContainer) renderTasks(approvalContainer, _allTasks.approval, false);
      if (futureContainer) renderTasks(futureContainer, _allTasks.future, false);
      if (cancelledContainer) renderTasks(cancelledContainer, _allTasks.cancelled, true);

      updateTabCount('ready-count', _allTasks.ready.length);
      updateTabCount('approval-count', _allTasks.approval.length);
      updateTabCount('future-count', _allTasks.future.length);
      updateTabCount('cancelled-count', _allTasks.cancelled.length);
      updateTaskCount(_allTasks.ready.length);
      updateLastSync(data.last_sync);

      return all.length;
    } catch (err) {
      readyContainer.innerHTML =
        '<div class="error-message">Erro ao carregar tarefas. ' + escapeHtml(err.message) + '</div>';
      if (err.message.includes('no such table') || err.message.includes('SQLSTATE')) {
        readyContainer.innerHTML =
          '<div class="empty-state">' +
          '<h3>Sem dados</h3>' +
          '<p>Clica em "Sync" para importar as tuas tarefas do ClickUp.</p>' +
          '</div>';
      }
      return 0;
    }
  }

  function formatDueDate(dueDateMs) {
    if (!dueDateMs) return '';
    var date = new Date(parseInt(dueDateMs));
    var now = new Date();
    now.setHours(0, 0, 0, 0);
    var target = new Date(date);
    target.setHours(0, 0, 0, 0);
    var diffDays = Math.round((target - now) / (1000 * 60 * 60 * 24));
    var dateClass = 'due-date';
    if (diffDays < 0) dateClass += ' overdue';
    else if (diffDays <= 2) dateClass += ' due-soon';

    var dateStr;
    if (diffDays < -7) {
      var weeks = Math.floor(Math.abs(diffDays) / 7);
      dateStr = 'Atrasada ' + weeks + ' semana' + (weeks > 1 ? 's' : '');
    } else if (diffDays < -1) {
      dateStr = 'Atrasada ' + Math.abs(diffDays) + ' dias';
    } else if (diffDays === -1) {
      dateStr = 'Atrasada 1 dia';
    } else if (diffDays === 0) {
      dateStr = 'Hoje';
    } else if (diffDays === 1) {
      dateStr = 'Amanha';
    } else if (diffDays === 2) {
      dateStr = 'Depois de amanha';
    } else if (diffDays <= 7) {
      dateStr = 'Daqui a ' + diffDays + ' dias';
    } else if (diffDays <= 14) {
      dateStr = 'Proxima semana';
    } else if (diffDays <= 30) {
      var weeks = Math.floor(diffDays / 7);
      dateStr = 'Daqui a ' + weeks + ' semana' + (weeks > 1 ? 's' : '');
    } else {
      dateStr = date.toLocaleDateString('pt-PT', { day: '2-digit', month: 'short' });
    }

    return '<span class="' + dateClass + '">' + dateStr + '</span>';
  }

  function renderTasks(container, tasks, isCancelledList) {
    if (!tasks || tasks.length === 0) {
      container.innerHTML =
        '<div class="empty-state">' +
        '<h3>' + (isCancelledList ? 'Nenhuma tarefa cancelada' : 'Nenhuma tarefa encontrada') + '</h3>' +
        '<p>' + (isCancelledList ? '' : 'Nao tens tarefas atribuidas ou precisas de fazer sync primeiro.') + '</p>' +
        '</div>';
      return;
    }

    container.innerHTML = '';

    container.innerHTML += tasks.map(function(task) {
      return buildTaskCardHtml(task, isCancelledList);
    }).join('');
  }

  function updateTaskCount(count) {
    var el = document.getElementById('task-count');
    if (el) {
      el.textContent = count + ' tarefa' + (count !== 1 ? 's' : '');
    }
  }

  function updateLastSync(timestamp) {
    var el = document.getElementById('last-sync-info');
    if (!el) return;
    if (!timestamp) {
      el.textContent = 'Nunca sincronizado';
      return;
    }
    var date = new Date(timestamp * 1000);
    el.textContent = 'Último sync: ' + date.toLocaleString('pt-PT');
  }

  /* ---------- Search ---------- */

  var _allTasks = { ready: [], approval: [], future: [], cancelled: [] };

  function filterByQuery(tasks, q) {
    if (!q) return tasks;
    return tasks.filter(function(t) { return (t.post_name || t.name || '').toLowerCase().indexOf(q) !== -1; });
  }

  function bindSearch() {
    var input = document.getElementById('search-input');
    if (!input) return;

    input.addEventListener('input', function() {
      var q = input.value.toLowerCase().trim();

      var ready = filterByQuery(_allTasks.ready, q);
      var approval = filterByQuery(_allTasks.approval, q);
      var future = filterByQuery(_allTasks.future, q);
      var cancelled = filterByQuery(_allTasks.cancelled, q);

      renderTasks(document.getElementById('ready-list'), ready, false);
      renderTasks(document.getElementById('approval-list'), approval, false);
      renderTasks(document.getElementById('future-list'), future, false);
      renderTasks(document.getElementById('cancelled-list'), cancelled, true);

      updateTabCount('ready-count', ready.length);
      updateTabCount('approval-count', approval.length);
      updateTabCount('future-count', future.length);
      updateTabCount('cancelled-count', cancelled.length);
      updateTaskCount(ready.length);
    });
  }

  /* ---------- Tab Counts ---------- */

  function updateTabCount(id, count) {
    var el = document.getElementById(id);
    if (el) {
      el.textContent = count > 0 ? count : '';
    }
  }

  /* ---------- Calendar ---------- */

  var _calWeekOffset = 0;
  var DAY_NAMES = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
  var MONTH_NAMES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

  function getWeekStart(offset) {
    var now = new Date();
    now.setHours(0, 0, 0, 0);
    // Sunday = start of week
    now.setDate(now.getDate() - now.getDay() + (offset * 7));
    return now;
  }

  function renderCalendar() {
    var grid = document.getElementById('calendar-grid');
    var label = document.getElementById('cal-week-label');
    if (!grid) return;

    var weekStart = getWeekStart(_calWeekOffset);
    var weekEnd = new Date(weekStart);
    weekEnd.setDate(weekEnd.getDate() + 6);

    label.textContent = weekStart.getDate() + ' ' + MONTH_NAMES[weekStart.getMonth()] +
      ' — ' + weekEnd.getDate() + ' ' + MONTH_NAMES[weekEnd.getMonth()] + ' ' + weekEnd.getFullYear();

    // Combine ready + approval + future tasks (not cancelled)
    var allTasks = _allTasks.ready.concat(_allTasks.approval, _allTasks.future);

    // Group tasks by day
    var days = [];
    var today = new Date();
    today.setHours(0, 0, 0, 0);

    for (var i = 0; i < 7; i++) {
      var dayDate = new Date(weekStart);
      dayDate.setDate(dayDate.getDate() + i);
      var dayStart = dayDate.getTime();
      var dayEnd = dayStart + 86400000;

      var dayTasks = allTasks.filter(function(t) {
        var due = parseInt(t.due_date);
        return due >= dayStart && due < dayEnd;
      });

      days.push({
        date: dayDate,
        dayNum: dayDate.getDate(),
        dayName: DAY_NAMES[i],
        isToday: dayDate.getTime() === today.getTime(),
        isWeekend: i === 0 || i === 6,
        tasks: dayTasks
      });
    }

    grid.innerHTML = '<div class="cal-week">' + days.map(function(d) {
      var classes = 'cal-day';
      if (d.isToday) classes += ' cal-today';
      if (d.isWeekend) classes += ' cal-weekend';

      return '<div class="' + classes + '">' +
        '<div class="cal-day-header">' +
          '<span class="cal-day-name">' + d.dayName + '</span>' +
          '<span class="cal-day-num">' + d.dayNum + '</span>' +
        '</div>' +
        '<div class="cal-day-tasks">' +
          (d.tasks.length > 0
            ? d.tasks.map(function(t) { return buildTaskCardHtml(t, false, { hideDueDate: true }); }).join('')
            : '<div class="cal-empty"></div>') +
        '</div>' +
      '</div>';
    }).join('') + '</div>';
  }

  function buildTaskCardHtml(task, isCancelled, opts) {
    opts = opts || {};
    var leTag = '';
    if (task.linha_editorial) {
      leTag = '<span class="le-tag">' + escapeHtml(task.linha_editorial) + '</span>';
    }
    var copyIndicator = '';
    if (task.copy_ready) {
      copyIndicator = '<span class="copy-ready">Copy pronto</span>';
    } else if (task.copy_ready === false) {
      copyIndicator = '<span class="copy-pending">Sem copy</span>';
    }
    var rejectedBadge = '';
    if (task.approval_rejected) {
      rejectedBadge = '<span class="rejected-badge">Recusada</span>';
    }
    var priorityBadge = '';
    if (task.priority_id) {
      var safePriority = parseInt(task.priority_id) || 0;
      var labels = { 1: 'Urgente', 2: 'Alta', 3: 'Normal', 4: 'Baixa' };
      priorityBadge = '<span class="priority-badge priority-' + safePriority + '">' +
        escapeHtml(labels[safePriority] || '') + '</span>';
    }
    var dueDate = opts.hideDueDate ? '' : formatDueDate(task.due_date);
    var urgencyBadge = '';
    if (task.urgency_score != null) {
      var urgencyClass = 'urgency-low';
      if (task.urgency_score >= 70) urgencyClass = 'urgency-critical';
      else if (task.urgency_score >= 50) urgencyClass = 'urgency-high';
      else if (task.urgency_score >= 30) urgencyClass = 'urgency-medium';
      var dScore = task.urgency_design || 0;
      var pScore = task.urgency_priority || 0;
      var postScore = task.urgency_post || 0;
      var rejScore = task.urgency_rejected || 0;
      var barColor = urgencyClass === 'urgency-critical' ? '#f44336' : urgencyClass === 'urgency-high' ? '#ff9800' : urgencyClass === 'urgency-medium' ? '#ffc107' : '#a0a0b0';
      urgencyBadge = '<span class="urgency-badge ' + urgencyClass + '">'
        + escapeHtml(String(task.urgency_score || 0))
        + '<span class="urgency-tooltip">'
        + '<div class="tooltip-title">Pontua\u00e7\u00e3o de urg\u00eancia</div>'
        + '<div class="tooltip-row"><span class="tooltip-row-label">Data design</span>'
        + '<span class="tooltip-row-bar"><span class="tooltip-row-bar-fill" style="width:' + (dScore * 2) + '%;background:' + barColor + '"></span></span>'
        + '<span class="tooltip-row-value">' + dScore + '/50</span></div>'
        + '<div class="tooltip-row"><span class="tooltip-row-label">Prioridade</span>'
        + '<span class="tooltip-row-bar"><span class="tooltip-row-bar-fill" style="width:' + Math.round(pScore / 30 * 100) + '%;background:' + barColor + '"></span></span>'
        + '<span class="tooltip-row-value">' + pScore + '/30</span></div>'
        + '<div class="tooltip-row"><span class="tooltip-row-label">Data post</span>'
        + '<span class="tooltip-row-bar"><span class="tooltip-row-bar-fill" style="width:' + (postScore * 5) + '%;background:' + barColor + '"></span></span>'
        + '<span class="tooltip-row-value">' + postScore + '/20</span></div>'
        + (rejScore ? '<div class="tooltip-row"><span class="tooltip-row-label">Recusada</span>'
        + '<span class="tooltip-row-bar"><span class="tooltip-row-bar-fill" style="width:100%;background:#f44336"></span></span>'
        + '<span class="tooltip-row-value">+' + rejScore + '</span></div>' : '')
        + '<hr class="tooltip-divider">'
        + '<div class="tooltip-total"><span>Total</span><span>' + (task.urgency_score || 0) + '/100</span></div>'
        + '</span></span>';
    }

    var taskId = task.post_id || task.id || '';
    var isWatched = task.watched ? 'true' : 'false';
    var watchTitle = task.watched ? 'Deixar de seguir' : 'Seguir tarefa';
    var watchLabel = task.watched ? 'A seguir' : 'Seguir';
    var watchBtn = '<button class="task-action task-watch" data-task-id="' + escapeHtml(String(taskId)) + '" data-watched="' + isWatched + '" title="' + watchTitle + '">' +
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>' +
        '<circle cx="12" cy="12" r="3"/>' +
      '</svg>' +
      '<span>' + watchLabel + '</span>' +
    '</button>';

    var classes = 'task-card';
    if (isCancelled) classes += ' cancelled';
    if (task.priority_id) classes += ' priority-' + (parseInt(task.priority_id) || 0);

    var clickupLink = '<a href="' + escapeHtml(task.post_url || task.url || '#') + '" target="_blank" class="task-action" title="Abrir no ClickUp">' +
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>' +
          '<polyline points="15 3 21 3 21 9"/>' +
          '<line x1="10" y1="14" x2="21" y2="3"/>' +
        '</svg>' +
        '<span>ClickUp</span>' +
      '</a>';

    return '<div class="' + classes + '">' +
      '<div class="card-top-row">' + leTag + rejectedBadge + copyIndicator + urgencyBadge + '</div>' +
      '<div class="task-header">' +
        '<div class="task-name">' + escapeHtml(task.post_name || task.name) + '</div>' +
      '</div>' +
      '<div class="task-meta">' + priorityBadge + dueDate + '</div>' +
      '<div class="card-actions">' + watchBtn + clickupLink + '</div>' +
    '</div>';
  }

  function bindCalendar() {
    var prev = document.getElementById('cal-prev');
    var next = document.getElementById('cal-next');
    if (!prev) return;

    prev.addEventListener('click', function() {
      _calWeekOffset--;
      renderCalendar();
    });
    next.addEventListener('click', function() {
      _calWeekOffset++;
      renderCalendar();
    });
  }

  /* ---------- Tabs ---------- */

  function bindTabs() {
    var tabs = document.querySelectorAll('.tab[data-tab]');
    if (!tabs.length) return;

    tabs.forEach(function(tab) {
      tab.addEventListener('click', function() {
        var target = tab.getAttribute('data-tab');

        // Update active tab
        tabs.forEach(function(t) { t.classList.remove('active'); });
        tab.classList.add('active');

        // Show/hide content
        document.querySelectorAll('[data-tab-content]').forEach(function(el) {
          el.style.display = el.getAttribute('data-tab-content') === target ? '' : 'none';
        });

        // Render calendar when switching to it
        if (target === 'calendar') renderCalendar();
        // Load report when switching to it
        if (target === 'report') fetchReport();
      });
    });
  }

  /* ---------- Sync ---------- */

  function bindSync() {
    var btn = document.getElementById('btn-sync');
    if (!btn) return;

    btn.addEventListener('click', function() {
      startSync(btn, false);
    });
  }

  async function startSync(btn, force) {
    btn.disabled = true;
    btn.classList.add('syncing');
    var originalText = btn.innerHTML;
    btn.innerHTML = '<span class="loading-spinner" style="width:16px;height:16px;border-width:2px;"></span> A sincronizar...';

    try {
      await apiFetch('/api/sync.php', {
        method: 'POST',
        body: JSON.stringify(force ? { force: true } : {}),
      });
      Toast.show(force ? 'Sync forcado iniciado...' : 'Sync iniciado...');
      await pollSyncStatus(btn, originalText);
    } catch (err) {
      // If 429 (already running), show force button
      if (err.message && err.message.indexOf('already running') !== -1) {
        btn.disabled = false;
        btn.classList.remove('syncing');
        btn.innerHTML = originalText;
        showForceSync(btn);
      } else {
        Toast.error('Erro no sync: ' + err.message);
        btn.disabled = false;
        btn.classList.remove('syncing');
        btn.innerHTML = originalText;
      }
    }
  }

  function showForceSync(btn) {
    var existing = document.getElementById('force-sync-bar');
    if (existing) existing.remove();

    var bar = document.createElement('div');
    bar.id = 'force-sync-bar';
    bar.className = 'force-sync-bar';
    bar.innerHTML = '<span>Ja existe um sync a correr.</span>' +
      '<button class="btn btn-sm btn-force-sync" id="btn-force-sync">Forcar novo sync</button>';
    btn.parentNode.appendChild(bar);

    document.getElementById('btn-force-sync').addEventListener('click', function() {
      bar.remove();
      startSync(btn, true);
    });
  }

  async function pollSyncStatus(btn, originalText) {
    var maxAttempts = 120; // max 4 minutes (2s intervals)
    for (var i = 0; i < maxAttempts; i++) {
      await new Promise(function(r) { setTimeout(r, 2000); });

      try {
        var status = await apiFetch('/api/sync.php'); // GET = check status
        if (!status.running) {
          var visibleCount = await fetchTasks();
          Toast.success('Sync completo: ' + visibleCount + ' tarefas');
          fetchUnreadCount();
          btn.disabled = false;
          btn.classList.remove('syncing');
          btn.innerHTML = originalText;
          return;
        }
        // Update button with progress
        if (status.progress) {
          btn.innerHTML = '<span class="loading-spinner" style="width:16px;height:16px;border-width:2px;"></span> ' + status.progress;
        }
      } catch (err) {
        // Ignore polling errors, keep trying
      }
    }

    Toast.error('Sync demorou demasiado. Verifica mais tarde.');
    btn.disabled = false;
    btn.classList.remove('syncing');
    btn.innerHTML = originalText;
  }

  /* ---------- Watch Task ---------- */

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.task-watch');
    if (!btn) return;
    e.preventDefault();
    var taskId = btn.getAttribute('data-task-id');
    var isWatched = btn.getAttribute('data-watched') === 'true';
    var newState = !isWatched;

    // Optimistic UI update
    btn.setAttribute('data-watched', String(newState));
    btn.title = newState ? 'Deixar de seguir' : 'Seguir tarefa';

    apiFetch('/api/watch.php', {
      method: 'POST',
      body: JSON.stringify({ task_id: taskId, action: newState ? 'watch' : 'unwatch' })
    }).catch(function(err) {
      // Revert on error
      btn.setAttribute('data-watched', String(isWatched));
      btn.title = isWatched ? 'Deixar de seguir' : 'Seguir tarefa';
      Toast.error('Erro ao atualizar: ' + err.message);
    });
  });

  /* ---------- Notifications ---------- */

  var _notifPollTimer = null;

  function relativeTime(ts) {
    var now = Date.now();
    var then = typeof ts === 'number' ? ts * 1000 : new Date(ts).getTime();
    var diffMs = now - then;
    if (diffMs < 0) return 'agora';
    var diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1) return 'agora';
    if (diffMin < 60) return 'ha ' + diffMin + ' min';
    var diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return 'ha ' + diffH + ' h';
    var diffD = Math.floor(diffH / 24);
    return 'ha ' + diffD + ' dia' + (diffD > 1 ? 's' : '');
  }

  function formatChangeDescription(n) {
    var field = n.change_type || n.field || '';
    var labels = {
      status: 'Status',
      priority: 'Prioridade',
      due_date: 'Prazo alterado',
      assignee: 'Assignees alterados',
      name: 'Nome'
    };

    if (field === 'due_date' || field === 'assignee') {
      return '<span class="notif-change">' + escapeHtml(labels[field] || field) + '</span>';
    }

    var label = labels[field] || field;
    var oldVal = n.old_value || '';
    var newVal = n.new_value || '';

    return '<span class="notif-change">' + escapeHtml(label) + ': ' +
      '<span class="notif-old">' + escapeHtml(oldVal) + '</span>' +
      ' → ' +
      '<span class="notif-new">' + escapeHtml(newVal) + '</span>' +
    '</span>';
  }

  function renderNotification(n) {
    var unreadClass = (n.seen || n.read) ? '' : ' unread';
    var taskName = n.task_name || 'Tarefa';
    var taskUrl = n.task_url || '';

    var nameHtml = taskUrl
      ? '<a href="' + escapeHtml(taskUrl) + '" target="_blank" class="notif-task-name">' + escapeHtml(taskName) + '</a>'
      : '<span class="notif-task-name">' + escapeHtml(taskName) + '</span>';

    return '<div class="notif-item' + unreadClass + '" data-notif-id="' + (n.id || '') + '">' +
      '<div class="notif-content">' +
        nameHtml +
        formatChangeDescription(n) +
        '<span class="notif-time">' + relativeTime(n.created_at) + '</span>' +
      '</div>' +
      '<button class="notif-dismiss" title="Marcar como lida">&times;</button>' +
    '</div>';
  }

  function fetchUnreadCount() {
    apiFetch('/api/notifications.php?unread_count=1').then(function(data) {
      var badge = document.getElementById('notif-badge');
      if (!badge) return;
      var count = data.count || data.unread_count || 0;
      if (count > 0) {
        badge.style.display = '';
        badge.textContent = count > 9 ? '9+' : String(count);
      } else {
        badge.style.display = 'none';
        badge.textContent = '0';
      }
    }).catch(function() {
      // Silently ignore fetch errors for badge
    });
  }

  function fetchNotifications() {
    var list = document.getElementById('notif-list');
    if (!list) return;

    list.innerHTML = '<div class="loading-container"><span class="loading-spinner"></span></div>';

    apiFetch('/api/notifications.php').then(function(data) {
      var notifications = data.notifications || [];
      if (notifications.length === 0) {
        list.innerHTML = '<div class="notif-empty">Sem notificacoes</div>';
        return;
      }
      list.innerHTML = notifications.map(renderNotification).join('');
    }).catch(function(err) {
      list.innerHTML = '<div class="notif-empty">Erro ao carregar notificacoes</div>';
    });
  }

  function bindNotifications() {
    var bellBtn = document.getElementById('btn-notifications');
    var panel = document.getElementById('notif-panel');
    var markAllBtn = document.getElementById('btn-mark-all-read');
    if (!bellBtn || !panel) return;

    // Toggle panel on bell click
    bellBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      var isVisible = panel.style.display !== 'none';
      if (isVisible) {
        panel.style.display = 'none';
      } else {
        panel.style.display = '';
        fetchNotifications();
      }
    });

    // Close panel on click outside
    document.addEventListener('click', function(e) {
      if (!panel.contains(e.target) && e.target !== bellBtn && !bellBtn.contains(e.target)) {
        panel.style.display = 'none';
      }
    });

    // Close panel on Escape
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        panel.style.display = 'none';
      }
    });

    // Dismiss single notification
    panel.addEventListener('click', function(e) {
      var dismissBtn = e.target.closest('.notif-dismiss');
      if (!dismissBtn) return;
      var item = dismissBtn.closest('.notif-item');
      if (!item) return;
      var notifId = item.getAttribute('data-notif-id');

      item.classList.remove('unread');
      apiFetch('/api/notifications.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'mark_read', id: parseInt(notifId) })
      }).then(function() {
        fetchUnreadCount();
      }).catch(function() {
        // Silently ignore
      });
    });

    // Mark all as read
    if (markAllBtn) {
      markAllBtn.addEventListener('click', function() {
        apiFetch('/api/notifications.php', {
          method: 'POST',
          body: JSON.stringify({ action: 'mark_all_read' })
        }).then(function() {
          var items = panel.querySelectorAll('.notif-item.unread');
          for (var i = 0; i < items.length; i++) {
            items[i].classList.remove('unread');
          }
          var badge = document.getElementById('notif-badge');
          if (badge) {
            badge.style.display = 'none';
            badge.textContent = '0';
          }
        }).catch(function(err) {
          Toast.error('Erro ao marcar notificacoes: ' + err.message);
        });
      });
    }

    // Fetch unread count on load and every 60s
    fetchUnreadCount();
    _notifPollTimer = setInterval(fetchUnreadCount, 60000);
  }

  /* ---------- Report ---------- */

  var _reportData = { tasks: [], linhas: [] };
  var _reportSelectedLinhas = {};
  var _reportLoaded = false;

  async function fetchReport() {
    if (_reportLoaded) return;
    var filtersEl = document.getElementById('report-le-filters');
    var listEl = document.getElementById('report-list');
    if (!filtersEl || !listEl) return;

    filtersEl.innerHTML = '<span class="text-secondary">A carregar...</span>';
    listEl.innerHTML = '<div class="loading-container"><span class="loading-spinner"></span><span>A carregar relatório...</span></div>';

    try {
      var data = await apiFetch('/api/report.php');
      _reportData.tasks = data.tasks || [];
      _reportData.linhas = data.linhas_editoriais || [];
      _reportLoaded = true;

      renderReportFilters();
      // Select all by default
      _reportData.linhas.forEach(function(le) { _reportSelectedLinhas[le] = true; });
      renderReportTasks();
    } catch (err) {
      filtersEl.innerHTML = '';
      listEl.innerHTML = '<div class="error-message">Erro ao carregar relatório. ' + escapeHtml(err.message) + '</div>';
    }
  }

  function renderReportFilters() {
    var filtersEl = document.getElementById('report-le-filters');
    if (!filtersEl) return;

    if (_reportData.linhas.length === 0) {
      filtersEl.innerHTML = '<span class="text-secondary">Nenhuma linha editorial encontrada.</span>';
      return;
    }

    var selectAllChecked = _reportData.linhas.every(function(le) { return _reportSelectedLinhas[le]; });

    var html = '<label class="report-le-chip">' +
      '<input type="checkbox" ' + (selectAllChecked ? 'checked' : '') + ' data-le-all="true"> Todas' +
      '</label>';

    html += _reportData.linhas.map(function(le) {
      var checked = _reportSelectedLinhas[le] ? 'checked' : '';
      return '<label class="report-le-chip">' +
        '<input type="checkbox" ' + checked + ' data-le="' + escapeHtml(le) + '"> ' +
        escapeHtml(le.replace(/linha editorial\s*/i, '').trim() || le) +
        '</label>';
    }).join('');

    filtersEl.innerHTML = html;

    // Bind filter events
    filtersEl.querySelectorAll('input[data-le]').forEach(function(cb) {
      cb.addEventListener('change', function() {
        _reportSelectedLinhas[cb.getAttribute('data-le')] = cb.checked;
        renderReportFilters();
        renderReportTasks();
      });
    });

    var allCb = filtersEl.querySelector('input[data-le-all]');
    if (allCb) {
      allCb.addEventListener('change', function() {
        _reportData.linhas.forEach(function(le) {
          _reportSelectedLinhas[le] = allCb.checked;
        });
        renderReportFilters();
        renderReportTasks();
      });
    }
  }

  function renderReportTasks() {
    var listEl = document.getElementById('report-list');
    if (!listEl) return;

    var filtered = _reportData.tasks.filter(function(t) {
      return t.linha_editorial && _reportSelectedLinhas[t.linha_editorial];
    });

    if (filtered.length === 0) {
      listEl.innerHTML = '<div class="empty-state"><h3>Sem posts em atraso</h3><p>Nenhum post em atraso para as linhas editoriais selecionadas.</p></div>';
      return;
    }

    // Group by linha editorial
    var groups = {};
    filtered.forEach(function(t) {
      var le = t.linha_editorial;
      if (!groups[le]) groups[le] = [];
      groups[le].push(t);
    });

    var html = '';
    var sortedKeys = Object.keys(groups).sort();

    sortedKeys.forEach(function(le) {
      var tasks = groups[le];
      var leName = le.replace(/linha editorial\s*/i, '').trim() || le;

      html += '<div class="report-group">';
      html += '<div class="report-group-header">' +
        '<span class="report-group-name">' + escapeHtml(leName) + '</span>' +
        '<span class="report-group-count">' + tasks.length + ' post' + (tasks.length !== 1 ? 's' : '') + ' em atraso</span>' +
        '</div>';

      html += '<div class="report-group-tasks">';
      tasks.forEach(function(task) {
        var assigneeHtml = '';
        if (task.all_assignees && task.all_assignees.length > 0) {
          assigneeHtml = '<div class="report-assignees">' +
            task.all_assignees.map(function(a) {
              var name = a.username || a.name || 'Unknown';
              var avatar = a.profilePicture || a.avatar || null;
              if (avatar) {
                return '<span class="report-assignee" title="' + escapeHtml(name) + '">' +
                  '<img class="report-assignee-avatar" src="' + escapeHtml(avatar) + '" alt="' + escapeHtml(name) + '">' +
                  '<span>' + escapeHtml(name) + '</span></span>';
              }
              return '<span class="report-assignee" title="' + escapeHtml(name) + '">' +
                '<span class="report-assignee-initial">' + escapeHtml(name.charAt(0).toUpperCase()) + '</span>' +
                '<span>' + escapeHtml(name) + '</span></span>';
            }).join('') +
            '</div>';
        }

        var daysText = task.days_overdue === 1 ? '1 dia' : task.days_overdue + ' dias';

        var clickupLink = task.post_url || task.url
          ? '<a href="' + escapeHtml(task.post_url || task.url) + '" target="_blank" class="report-clickup-link" title="Abrir no ClickUp">' +
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">' +
              '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>' +
              '<polyline points="15 3 21 3 21 9"/>' +
              '<line x1="10" y1="14" x2="21" y2="3"/>' +
            '</svg></a>'
          : '';

        html += '<div class="report-task-row">' +
          '<div class="report-task-info">' +
            '<span class="report-task-name">' + escapeHtml(task.post_name || task.name) + '</span>' +
            clickupLink +
          '</div>' +
          '<div class="report-task-meta">' +
            '<span class="report-overdue-badge">' + daysText + ' em atraso</span>' +
            assigneeHtml +
          '</div>' +
        '</div>';
      });

      html += '</div></div>';
    });

    listEl.innerHTML = html;
  }

  /* ---------- Helpers ---------- */

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  /* ---------- Colaboradores (F01) ---------- */

  var _collabLoaded = false;
  var _collabData = null; // full API payload, used by the day-click popup
  var _collabMonth = null; // selected month as 'YYYY-MM' or null = current
  var DAYS_PT = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
  var DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
  var STATUS_LABEL = { under: 'Sub', ok: 'Ok', over: 'Sobre' };

  // Day-of-month (1..31) for a given DAY_KEYS entry within an ISO week whose
  // Monday is `weekStart` ('YYYY-MM-DD'). Returns null if inputs are invalid.
  function collabDayOfMonth(weekStart, dayKey) {
    if (!weekStart) return null;
    var offset = DAY_KEYS.indexOf(dayKey);
    if (offset < 0) return null;
    var base = new Date(weekStart + 'T00:00:00');
    if (isNaN(base.getTime())) return null;
    base.setDate(base.getDate() + offset);
    return base.getDate();
  }
  var MONTH_PT = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                  'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

  function formatHours(h) {
    if (!h || h <= 0) return '—';
    // Strip a trailing .0 so whole numbers read cleanly ("8h" not "8.0h").
    var s = (Math.round(h * 10) / 10).toString();
    if (s.indexOf('.') === -1) return s + 'h';
    return s + 'h';
  }

  function formatLastSync(ts) {
    if (!ts) return 'Ainda sem dados';
    var then = Number(ts) * 1000;
    var d = new Date(then);
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    return 'Última sync: ' + pad(d.getDate()) + '/' + pad(d.getMonth() + 1) +
           ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  // Pick the label from the API response's `month_start` (first Monday of the
  // window) but derive the calendar month from the last day of that window —
  // `month_start` can legitimately sit in the previous calendar month when
  // the 1st falls on Tue/Wed/etc. Cheap approximation: add 7 days, which
  // always lands inside the target month.
  function formatMonthLabel(monthStartISO) {
    if (!monthStartISO) return '';
    var d = new Date(monthStartISO + 'T00:00:00');
    d.setDate(d.getDate() + 7);
    return MONTH_PT[d.getMonth()] + ' ' + d.getFullYear();
  }

  function currentYearMonth() {
    var n = new Date();
    var pad = function (x) { return x < 10 ? '0' + x : '' + x; };
    return n.getFullYear() + '-' + pad(n.getMonth() + 1);
  }

  // Parse '#collaborators/YYYY-MM' → 'YYYY-MM'. Anything else (no sub-segment,
  // different view, malformed) → null = current month.
  function parseCollabHashMonth() {
    var raw = (location.hash || '').replace(/^#/, '');
    var parts = raw.split('/');
    if (parts[0] !== 'collaborators' || !parts[1]) return null;
    return /^\d{4}-(0[1-9]|1[0-2])$/.test(parts[1]) ? parts[1] : null;
  }

  function setCollabHash(month) {
    var base = '#collaborators';
    var next = month ? base + '/' + month : base;
    // Avoid firing a hashchange loop if it already matches.
    if (location.hash !== next) {
      history.replaceState(null, '', next);
    }
  }

  // Shift `_collabMonth` by `delta` months. `null` = current month; shifting
  // from null means "last/next from today". Clamp to current month on top.
  function navCollabMonth(delta) {
    var base = _collabMonth || currentYearMonth();
    var parts = base.split('-');
    var y = Number(parts[0]);
    var m = Number(parts[1]);
    m += delta;
    while (m < 1)  { m += 12; y -= 1; }
    while (m > 12) { m -= 12; y += 1; }
    var pad = function (x) { return x < 10 ? '0' + x : '' + x; };
    var next = y + '-' + pad(m);
    // If user navigates back to current month, clear the hash sub-segment.
    _collabMonth = (next === currentYearMonth()) ? null : next;
    // Never go into the future.
    if (_collabMonth && _collabMonth > currentYearMonth()) return;
    setCollabHash(_collabMonth);
    fetchCollaborators(_collabMonth);
  }

  async function fetchCollaborators(month) {
    var listEl = document.getElementById('collab-list');
    if (!listEl) return;

    listEl.innerHTML =
      '<div class="loading-container">' +
      '<span class="loading-spinner"></span>' +
      '<span>A carregar colaboradores...</span>' +
      '</div>';

    var url = '/api/collaborators.php' + (month ? '?month=' + encodeURIComponent(month) : '');
    try {
      var data = await apiFetch(url);
      renderCollaborators(data);
    } catch (err) {
      listEl.innerHTML = '<div class="error-message">Erro: ' + escapeHtml(err.message) + '</div>';
      Toast.error('Erro ao carregar colaboradores: ' + err.message);
    }
  }

  function updateCollabNavButtons() {
    var nextBtn = document.getElementById('collab-next');
    if (nextBtn) nextBtn.disabled = (_collabMonth === null);
  }

  function bindCollabMonthNav() {
    var prev = document.getElementById('collab-prev');
    var next = document.getElementById('collab-next');
    if (prev) prev.addEventListener('click', function () { navCollabMonth(-1); });
    if (next) next.addEventListener('click', function () { navCollabMonth(+1); });
  }

  function renderCollaborators(data) {
    var listEl = document.getElementById('collab-list');
    var lastSyncEl = document.getElementById('collab-last-sync');
    var monthEl = document.getElementById('collab-month-label');
    if (!listEl) return;

    if (monthEl) monthEl.textContent = formatMonthLabel(data.month_start);
    if (lastSyncEl) lastSyncEl.textContent = formatLastSync(data.last_sync);
    updateCollabNavButtons();

    if (!data.last_sync) {
      listEl.innerHTML =
        '<div class="empty-state">' +
        '<h3>Ainda sem dados</h3>' +
        '<p>Clica em <strong>Sync</strong> para importar os time entries do mês.</p>' +
        '</div>';
      return;
    }

    _collabData = data;

    var collabs = data.collaborators || [];
    if (!collabs.length) {
      listEl.innerHTML =
        '<div class="empty-state">' +
        '<h3>Sem colaboradores no grupo Design</h3>' +
        '<p>' + escapeHtml(data.warning || 'O grupo não tem membros.') + '</p>' +
        '</div>';
      return;
    }

    renderCollabBurnup(data);

    var html = '';
    collabs.forEach(function (c, idx) {
      html += renderCollabCard(c, idx);
    });
    listEl.innerHTML = html;
    bindCollabDayClicks();
  }

  /* ---------- Burn-up chart (F05) ---------- */

  // Compute the aggregate burn-up series from the collaborators payload.
  // Returns { weeks: [{label, meta_h, real_h|null}], meta_total, real_total }.
  // `real_h` is null for ISO weeks whose Monday is after today — the polyline
  // stops there instead of dropping to zero for the unlived future.
  function computeCollabBurnup(data) {
    var weeksMeta = (data && data.weeks_meta) || [];
    var collabs   = (data && data.collaborators) || [];

    var teamWeekly = collabs.reduce(function (acc, c) {
      return acc + (Number(c.weekly_hours) || 0);
    }, 0);

    // Sum total_hours across all collabs for week index k.
    function realForWeek(k) {
      var sum = 0;
      for (var i = 0; i < collabs.length; i++) {
        var wk = (collabs[i].weeks || [])[k];
        sum += wk && typeof wk.total_hours === 'number' ? wk.total_hours : 0;
      }
      return sum;
    }

    var now = new Date();
    now.setHours(0, 0, 0, 0);

    var out = [];
    var cumReal = 0;
    for (var k = 0; k < weeksMeta.length; k++) {
      var meta_h = teamWeekly * (k + 1);
      var weekMondayISO = weeksMeta[k].week_start; // 'YYYY-MM-DD'
      var weekMonday = new Date(weekMondayISO + 'T00:00:00');
      var isFuture = weekMonday > now;
      var real_h = null;
      if (!isFuture) {
        cumReal += realForWeek(k);
        real_h = Math.round(cumReal * 100) / 100;
      }
      out.push({
        label:  'W' + weeksMeta[k].week_number,
        meta_h: Math.round(meta_h * 100) / 100,
        real_h: real_h,
      });
    }

    var metaTotal = teamWeekly * weeksMeta.length;
    // Last non-null real is the current "realized" total
    var realTotal = 0;
    for (var j = out.length - 1; j >= 0; j--) {
      if (out[j].real_h !== null) { realTotal = out[j].real_h; break; }
    }

    return {
      weeks: out,
      meta_total: Math.round(metaTotal * 100) / 100,
      real_total: realTotal,
    };
  }

  function renderCollabBurnup(data) {
    var host = document.getElementById('collab-burnup');
    if (!host) return;

    // Hide when there's nothing to show (pre-sync or no members).
    var collabs = (data && data.collaborators) || [];
    if (!data || !data.last_sync || !collabs.length) {
      host.style.display = 'none';
      host.innerHTML = '';
      return;
    }

    var series = computeCollabBurnup(data);
    var weeks  = series.weeks;
    if (!weeks.length) {
      host.style.display = 'none';
      host.innerHTML = '';
      return;
    }

    // Layout in viewBox units — CSS scales to container width.
    var W = 720, H = 200;
    var pad = { top: 20, right: 24, bottom: 32, left: 44 };
    var innerW = W - pad.left - pad.right;
    var innerH = H - pad.top - pad.bottom;

    // Y scale: top at max(meta_total, max_real) rounded up to the next 40h.
    var maxReal = 0;
    weeks.forEach(function (w) {
      if (w.real_h !== null && w.real_h > maxReal) maxReal = w.real_h;
    });
    var yMax = Math.max(series.meta_total, maxReal);
    yMax = Math.max(40, Math.ceil(yMax / 40) * 40);

    // X positions: one per week, evenly spaced.
    var n = weeks.length;
    function xFor(k) {
      if (n === 1) return pad.left + innerW / 2;
      return pad.left + (innerW * k) / (n - 1);
    }
    function yFor(h) {
      return pad.top + innerH - (innerH * h) / yMax;
    }

    // Build polylines.
    var metaPts = weeks.map(function (w, k) {
      return xFor(k).toFixed(1) + ',' + yFor(w.meta_h).toFixed(1);
    }).join(' ');

    // Real line: break at nulls — just take the prefix up to the last point.
    var realPtsArr = [];
    for (var k = 0; k < weeks.length; k++) {
      if (weeks[k].real_h === null) break;
      realPtsArr.push(xFor(k).toFixed(1) + ',' + yFor(weeks[k].real_h).toFixed(1));
    }
    var realPts = realPtsArr.join(' ');

    // Y axis gridlines at 0, yMax/4, yMax/2, 3*yMax/4, yMax.
    var gridLines = '';
    var labels    = '';
    for (var gi = 0; gi <= 4; gi++) {
      var val = (yMax * gi) / 4;
      var yy  = yFor(val).toFixed(1);
      gridLines += '<line class="grid" x1="' + pad.left + '" y1="' + yy +
                   '" x2="' + (pad.left + innerW) + '" y2="' + yy + '"/>';
      labels   += '<text class="axis-label" x="' + (pad.left - 6) +
                  '" y="' + (parseFloat(yy) + 3.5) + '" text-anchor="end">' +
                  Math.round(val) + 'h</text>';
    }

    // X labels (week numbers).
    var xLabels = '';
    weeks.forEach(function (w, k) {
      xLabels += '<text class="axis-label" x="' + xFor(k).toFixed(1) +
                 '" y="' + (pad.top + innerH + 16) + '" text-anchor="middle">' +
                 escapeHtml(w.label) + '</text>';
    });

    // Dots + tooltips at each week intersection.
    var metaDots = '';
    var realDots = '';
    weeks.forEach(function (w, k) {
      var cx = xFor(k).toFixed(1);
      var delta = (w.real_h !== null) ? (w.real_h - w.meta_h) : null;
      var tipMeta = w.label + ' · Meta: ' + w.meta_h + 'h';
      var tipReal = w.label + ' · Meta: ' + w.meta_h + 'h · Real: ' +
        (w.real_h === null ? '—' : w.real_h + 'h') +
        (delta !== null ? ' · Δ ' + (delta >= 0 ? '+' : '') + delta.toFixed(1) + 'h' : '');
      metaDots += '<circle class="meta-dot" cx="' + cx + '" cy="' + yFor(w.meta_h).toFixed(1) +
                  '" r="3"><title>' + escapeHtml(tipMeta) + '</title></circle>';
      if (w.real_h !== null) {
        realDots += '<circle class="real-dot" cx="' + cx + '" cy="' + yFor(w.real_h).toFixed(1) +
                    '" r="3"><title>' + escapeHtml(tipReal) + '</title></circle>';
      }
    });

    // Legend text: "Abril 2026 · Meta: Xh · Trabalhado: Yh · ±Zh"
    var monthLabel = formatMonthLabel(data.month_start);
    var delta = series.real_total - series.meta_total;
    // Only meaningful to show delta when we have at least one real point.
    var hasReal = weeks.some(function (w) { return w.real_h !== null; });
    var deltaStr = hasReal
      ? ' · ' + (delta >= 0 ? '+' : '') + delta.toFixed(1) + 'h'
      : '';

    var legend =
      '<div class="collab-burnup-legend">' +
        '<span class="collab-burnup-month">' + escapeHtml(monthLabel) + '</span>' +
        '<span class="collab-burnup-sep">·</span>' +
        '<span><span class="collab-burnup-swatch meta"></span>Meta: <strong>' +
          series.meta_total + 'h</strong></span>' +
        '<span class="collab-burnup-sep">·</span>' +
        '<span><span class="collab-burnup-swatch real"></span>Trabalhado: <strong>' +
          (hasReal ? series.real_total + 'h' : '—') + '</strong></span>' +
        (deltaStr ? '<span class="collab-burnup-delta ' + (delta >= 0 ? 'pos' : 'neg') +
          '">' + deltaStr + '</span>' : '') +
      '</div>';

    var svg =
      '<svg class="collab-burnup-svg" viewBox="0 0 ' + W + ' ' + H +
        '" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Burn-up mensal">' +
        gridLines +
        '<polyline class="meta-line" points="' + metaPts + '"/>' +
        (realPts ? '<polyline class="real-line" points="' + realPts + '"/>' : '') +
        metaDots +
        realDots +
        labels +
        xLabels +
      '</svg>';

    host.innerHTML = legend + svg;
    host.style.display = '';
  }

  function renderCollabCard(c, collabIdx) {
    var user = c.user || {};
    var displayName = user.username || user.email || user.id || '—';
    var weekly = Number(c.weekly_hours) || 0;

    var avatar;
    if (user.profilePicture) {
      avatar = '<img class="user-avatar" src="' + escapeHtml(user.profilePicture) + '" alt="">';
    } else {
      var initials = user.initials || displayName.charAt(0).toUpperCase();
      var bg = user.color ? ' style="background:' + escapeHtml(user.color) + '"' : '';
      avatar = '<span class="user-avatar-placeholder"' + bg + '>' + escapeHtml(initials) + '</span>';
    }

    var head =
      '<header class="collab-header">' +
      avatar +
      '<div class="collab-meta">' +
      '<h3 class="collab-name">' + escapeHtml(displayName) + '</h3>' +
      '<span class="collab-weekly">' + weekly + 'h / semana</span>' +
      '</div>' +
      '</header>';

    // Month summary: Expectável / Trabalhado + status badge. `month_totals`
    // is filled in by api/collaborators.php via collab_month_totals().
    var summary = '';
    if (c.month_totals) {
      var mt = c.month_totals;
      summary = '<div class="collab-month-summary">' +
        '<span class="collab-month-item">Expectável: <strong>' + formatHours(mt.expected_hours) + '</strong></span>' +
        '<span class="collab-month-sep">·</span>' +
        '<span class="collab-month-item">Trabalhado: <strong>' + formatHours(mt.worked_hours) + '</strong></span>' +
        '<span class="collab-badge ' + escapeHtml(mt.status || 'under') + '">' +
        (STATUS_LABEL[mt.status] || mt.status || '—') +
        '</span>' +
        '</div>';
    }

    // Week table: header row + one row per ISO week
    var headRow = '<div class="collab-week-row collab-week-head">' +
      '<div class="collab-week-label">Sem</div>';
    DAYS_PT.forEach(function (d) { headRow += '<div class="collab-day">' + d + '</div>'; });
    headRow += '<div class="collab-total">Total</div>' +
      '<div class="collab-status-cell">Carga</div>' +
      '</div>';

    var rows = '';
    (c.weeks || []).forEach(function (w, weekIdx) {
      var row = '<div class="collab-week-row">' +
        '<div class="collab-week-label" title="' + escapeHtml(w.week_start || '') + '">W' +
        (w.week_number || '') + '</div>';
      DAY_KEYS.forEach(function (k) {
        var v = (w.days && typeof w.days[k] === 'number') ? w.days[k] : 0;
        var dom = collabDayOfMonth(w.week_start, k);
        var domHtml = dom != null ? '<span class="collab-day-num">' + dom + '</span>' : '';
        if (v > 0) {
          // Clickable — opens popup with the tasks worked on that day.
          row += '<button type="button" class="collab-day has-val" ' +
            'data-collab-day="' + collabIdx + ':' + weekIdx + ':' + k + '">' +
            '<span class="collab-day-hours">' + formatHours(v) + '</span>' +
            domHtml + '</button>';
        } else {
          row += '<div class="collab-day">' +
            '<span class="collab-day-hours">' + formatHours(v) + '</span>' +
            domHtml + '</div>';
        }
      });
      row += '<div class="collab-total">' + formatHours(w.total_hours) + '</div>' +
        '<div class="collab-status-cell">' +
        '<span class="collab-badge ' + escapeHtml(w.status || 'under') + '">' +
        (STATUS_LABEL[w.status] || w.status || '—') +
        '</span></div>' +
        '</div>';
      rows += row;
    });

    return '<article class="collab-card">' + head + summary +
      '<div class="collab-weeks">' + headRow + rows + '</div>' +
      '</article>';
  }

  function bindCollabDayClicks() {
    document.querySelectorAll('[data-collab-day]').forEach(function (el) {
      el.addEventListener('click', function () {
        var parts = el.getAttribute('data-collab-day').split(':');
        openCollabDayModal(Number(parts[0]), Number(parts[1]), parts[2]);
      });
    });
  }

  // PT weekday names keyed by the server's day key (mon..sun).
  var COLLAB_DAY_PT = {
    mon: 'Segunda', tue: 'Terça', wed: 'Quarta', thu: 'Quinta',
    fri: 'Sexta', sat: 'Sábado', sun: 'Domingo',
  };

  function openCollabDayModal(collabIdx, weekIdx, dayKey) {
    if (!_collabData) return;
    var c = (_collabData.collaborators || [])[collabIdx];
    if (!c) return;
    var w = (c.weeks || [])[weekIdx];
    if (!w) return;

    var tasks = (w.days_posts && w.days_posts[dayKey]) || [];
    var totalHours = (w.days && typeof w.days[dayKey] === 'number') ? w.days[dayKey] : 0;

    // Derive the concrete date for that day (week_start is the Monday).
    var dateSuffix = '';
    if (w.week_start) {
      var base = new Date(w.week_start + 'T00:00:00');
      var offset = DAY_KEYS.indexOf(dayKey);
      if (offset >= 0) {
        base.setDate(base.getDate() + offset);
        var dd = base.getDate(), mm = base.getMonth() + 1;
        dateSuffix = (dd < 10 ? '0' : '') + dd + '/' + (mm < 10 ? '0' : '') + mm;
      }
    }

    var displayName = (c.user && c.user.username) ? c.user.username : 'Colaborador';
    var title = displayName + ' — ' + (COLLAB_DAY_PT[dayKey] || dayKey) +
      (dateSuffix ? ' ' + dateSuffix : '');

    var rows = tasks.length
      ? tasks.map(renderCollabDayRow).join('')
      : '<div class="empty-state"><p>Sem tarefas registadas neste dia.</p></div>';

    var header =
      '<div class="day-modal-header">' +
      '<h3>' + escapeHtml(title) + '</h3>' +
      '<span class="day-modal-count">' + formatHours(totalHours) + '</span>' +
      '<button type="button" class="day-modal-close" aria-label="Fechar">&times;</button>' +
      '</div>';

    var backdrop = document.createElement('div');
    backdrop.className = 'day-modal-backdrop';
    backdrop.innerHTML =
      '<div class="day-modal" role="dialog" aria-modal="true">' +
      header +
      '<div class="day-modal-body">' + rows + '</div>' +
      '</div>';

    function close() {
      document.removeEventListener('keydown', onKey);
      if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }

    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) close();
    });
    backdrop.querySelector('.day-modal-close').addEventListener('click', close);
    document.addEventListener('keydown', onKey);

    document.body.appendChild(backdrop);
  }

  function renderCollabDayRow(t) {
    // After F03 the aggregator groups by post (parent ?? task), so rows carry
    // post_name/post_url directly. If name is missing (post outside cache
    // and sync hasn't re-resolved yet) fall back to "Post {id}".
    var hours = typeof t.hours === 'number' ? t.hours : 0;
    var name  = t.post_name
      ? t.post_name
      : (t.post_id ? 'Post ' + t.post_id : '(entry sem post)');
    var url   = t.post_url || (t.post_id ? 'https://app.clickup.com/t/' + t.post_id : '');

    var titleCell = url
      ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' + escapeHtml(name) + '</a>'
      : escapeHtml(name);

    return '<div class="day-modal-row">' +
      '<div class="day-modal-title">' + titleCell + '</div>' +
      '<div class="day-modal-meta">' +
      '<span class="day-modal-status">' + escapeHtml(formatHours(hours)) + '</span>' +
      '</div>' +
      '</div>';
  }

  function bindTimeEntriesSync() {
    var btn = document.getElementById('btn-sync-time-entries');
    if (!btn) return;
    btn.addEventListener('click', function () { startTimeEntriesSync(btn, false); });
  }

  async function startTimeEntriesSync(btn, force) {
    btn.disabled = true;
    btn.classList.add('syncing');
    var originalText = btn.innerHTML;
    btn.innerHTML = '<span class="loading-spinner" style="width:16px;height:16px;border-width:2px;"></span> A sincronizar...';

    try {
      var payload = {};
      if (force) payload.force = true;
      if (_collabMonth) payload.month = _collabMonth;
      var syncUrl = '/api/sync_time_entries.php' +
        (_collabMonth ? '?month=' + encodeURIComponent(_collabMonth) : '');
      await apiFetch(syncUrl, {
        method: 'POST',
        body: JSON.stringify(payload),
      });
      Toast.show(force ? 'Sync forçado iniciado...' : 'Sync iniciado...');
      await pollTimeEntriesSync(btn, originalText);
    } catch (err) {
      if (err.message && err.message.indexOf('already running') !== -1) {
        btn.disabled = false;
        btn.classList.remove('syncing');
        btn.innerHTML = originalText;
        // Simple confirm-based force path (no full force bar UI to keep this tight).
        if (window.confirm('Já existe um sync a correr. Forçar novo sync?')) {
          startTimeEntriesSync(btn, true);
        }
      } else {
        Toast.error('Erro no sync: ' + err.message);
        btn.disabled = false;
        btn.classList.remove('syncing');
        btn.innerHTML = originalText;
      }
    }
  }

  async function pollTimeEntriesSync(btn, originalText) {
    var maxAttempts = 120; // ~4 min
    for (var i = 0; i < maxAttempts; i++) {
      await new Promise(function (r) { setTimeout(r, 2000); });
      try {
        var status = await apiFetch('/api/sync_time_entries.php');
        if (!status.running) {
          await fetchCollaborators(_collabMonth);
          Toast.success('Sync de colaboradores concluído');
          btn.disabled = false;
          btn.classList.remove('syncing');
          btn.innerHTML = originalText;
          return;
        }
        if (status.progress) {
          btn.innerHTML = '<span class="loading-spinner" style="width:16px;height:16px;border-width:2px;"></span> ' +
            escapeHtml(status.progress);
        }
      } catch (err) { /* keep polling */ }
    }
    Toast.error('Sync demorou demasiado. Verifica mais tarde.');
    btn.disabled = false;
    btn.classList.remove('syncing');
    btn.innerHTML = originalText;
  }

  /* ---------- View router (F01) ---------- */

  function showView(name) {
    var sections = document.querySelectorAll('section.view[data-view]');
    if (!sections.length) return;
    var matched = false;
    sections.forEach(function (sec) {
      var match = sec.getAttribute('data-view') === name;
      sec.style.display = match ? '' : 'none';
      if (match) matched = true;
    });
    // Fallback: if the requested view doesn't exist (e.g. non-heads hitting
    // #collaborators), show the editorial view.
    if (!matched) {
      sections.forEach(function (sec) {
        sec.style.display = sec.getAttribute('data-view') === 'editorial' ? '' : 'none';
      });
      name = 'editorial';
    }
    document.querySelectorAll('.app-navbar .nav-link[data-nav]').forEach(function (a) {
      a.classList.toggle('active', a.getAttribute('data-nav') === name);
    });
    // Lazy-load the collaborators view the first time it's shown, and on
    // subsequent shows refresh if the hash month has changed.
    if (name === 'collaborators') {
      var hashMonth = parseCollabHashMonth();
      if (!_collabLoaded || hashMonth !== _collabMonth) {
        _collabLoaded = true;
        _collabMonth  = hashMonth;
        fetchCollaborators(_collabMonth);
      }
    }
  }

  function bindNavbar() {
    var links = document.querySelectorAll('.app-navbar .nav-link[data-nav]');
    if (!links.length) return;
    links.forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var target = a.getAttribute('data-nav');
        // Keep the URL hash in sync so refresh + deep-link keep working.
        // For collaborators, preserve any month sub-segment already set.
        if (target === 'collaborators' && _collabMonth) {
          setCollabHash(_collabMonth);
        } else {
          history.replaceState(null, '', '#' + target);
        }
        showView(target);
      });
    });
    // Pick the initial view from the URL hash (so deep links / refresh work).
    // Format: '#editorial' | '#collaborators' | '#collaborators/YYYY-MM'.
    var raw = (location.hash || '').replace(/^#/, '');
    var top = raw.split('/')[0];
    var initial = (top === 'editorial' || top === 'collaborators') ? top : 'editorial';
    showView(initial);
  }

  /* ---------- Init ---------- */

  function init() {
    // Workspace list (only present when no workspace is selected)
    if (document.getElementById('workspace-list')) {
      fetchWorkspaces();
    }

    // Tasks view
    if (document.getElementById('ready-list')) {
      fetchTasks();
    }

    bindSearch();
    bindTabs();
    bindCalendar();
    bindSync();
    bindChangeWorkspace();
    bindLogout();
    bindNotifications();
    bindTimeEntriesSync();
    bindCollabMonthNav();
    bindNavbar();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose toast globally so PHP-rendered pages can use it
  window.Sonar = { Toast: Toast };
})();
