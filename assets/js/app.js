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
    const defaults = {
      headers: { 'Content-Type': 'application/json' },
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

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      window.location.href = '/api/logout.php';
    });
  }

  /* ---------- Task Fetching & Rendering ---------- */

  var CANCELLED_STATUSES = ['post cancelado', 'cancelado', 'cancelled'];
  var HIDDEN_STATUSES = ['linha editorial cancelada', 'published', 'pending'];
  var READY_STATUSES = ['design', 'approval design'];

  function classifyTask(task) {
    var status = (task.status_name || '').toLowerCase().trim();
    if (HIDDEN_STATUSES.indexOf(status) !== -1) return 'hidden';
    if (CANCELLED_STATUSES.indexOf(status) !== -1) return 'cancelled';
    if (READY_STATUSES.indexOf(status) !== -1 && task.copy_ready) return 'ready';
    return 'future';
  }

  async function fetchTasks() {
    var readyContainer = document.getElementById('ready-list');
    var futureContainer = document.getElementById('future-list');
    var cancelledContainer = document.getElementById('cancelled-list');
    if (!readyContainer) return;

    readyContainer.innerHTML =
      '<div class="loading-container">' +
      '<span class="loading-spinner"></span>' +
      '<span>A carregar tarefas...</span>' +
      '</div>';

    try {
      var data = await apiFetch('/api/tasks.php');
      var all = (data.tasks || []).filter(function(t) { return classifyTask(t) !== 'hidden'; });

      _allTasks.ready = all.filter(function(t) { return classifyTask(t) === 'ready'; });
      _allTasks.future = all.filter(function(t) { return classifyTask(t) === 'future'; });
      _allTasks.cancelled = all.filter(function(t) { return classifyTask(t) === 'cancelled'; });

      renderTasks(readyContainer, _allTasks.ready, false);
      if (futureContainer) renderTasks(futureContainer, _allTasks.future, false);
      if (cancelledContainer) renderTasks(cancelledContainer, _allTasks.cancelled, true);

      updateTabCount('ready-count', _allTasks.ready.length);
      updateTabCount('future-count', _allTasks.future.length);
      updateTabCount('cancelled-count', _allTasks.cancelled.length);
      updateTaskCount(_allTasks.ready.length);
      updateLastSync(data.last_sync);
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

  var _allTasks = { ready: [], future: [], cancelled: [] };

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
      var future = filterByQuery(_allTasks.future, q);
      var cancelled = filterByQuery(_allTasks.cancelled, q);

      renderTasks(document.getElementById('ready-list'), ready, false);
      renderTasks(document.getElementById('future-list'), future, false);
      renderTasks(document.getElementById('cancelled-list'), cancelled, true);

      updateTabCount('ready-count', ready.length);
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

    // Combine ready + future tasks (not cancelled)
    var allTasks = _allTasks.ready.concat(_allTasks.future);

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

    // Also collect overdue and no-date tasks for the current week (offset 0)
    var overdueHtml = '';
    if (_calWeekOffset === 0) {
      var overdue = allTasks.filter(function(t) {
        if (!t.due_date) return false;
        return parseInt(t.due_date) < weekStart.getTime();
      });
      if (overdue.length > 0) {
        overdueHtml = '<div class="cal-overdue">' +
          '<div class="cal-day-header cal-overdue-header">Atrasadas (' + overdue.length + ')</div>' +
          overdue.map(function(t) { return buildTaskCardHtml(t, false); }).join('') +
          '</div>';
      }
    }

    grid.innerHTML = overdueHtml + '<div class="cal-week">' + days.map(function(d) {
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
            ? d.tasks.map(function(t) { return buildTaskCardHtml(t, false); }).join('')
            : '<div class="cal-empty"></div>') +
        '</div>' +
      '</div>';
    }).join('') + '</div>';
  }

  function buildTaskCardHtml(task, isCancelled) {
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
    var priorityBadge = '';
    if (task.priority_id) {
      var labels = { 1: 'Urgente', 2: 'Alta', 3: 'Normal', 4: 'Baixa' };
      priorityBadge = '<span class="priority-badge priority-' + task.priority_id + '">' +
        (labels[task.priority_id] || '') + '</span>';
    }
    var dueDate = formatDueDate(task.due_date);
    var urgencyBadge = '';
    if (task.urgency_score != null) {
      var urgencyClass = 'urgency-low';
      if (task.urgency_score >= 70) urgencyClass = 'urgency-critical';
      else if (task.urgency_score >= 50) urgencyClass = 'urgency-high';
      else if (task.urgency_score >= 30) urgencyClass = 'urgency-medium';
      urgencyBadge = '<span class="urgency-badge ' + urgencyClass + '">' + task.urgency_score + '</span>';
    }

    var classes = 'task-card';
    if (isCancelled) classes += ' cancelled';
    if (task.priority_id) classes += ' priority-' + task.priority_id;

    return '<div class="' + classes + '">' +
      '<div class="card-top-row">' + leTag + copyIndicator + urgencyBadge + '</div>' +
      '<div class="task-header">' +
        '<div class="task-name">' + escapeHtml(task.post_name || task.name) + '</div>' +
      '</div>' +
      '<div class="task-meta">' + priorityBadge + dueDate + '</div>' +
      '<a href="' + escapeHtml(task.post_url || task.url || '#') + '" target="_blank" class="task-link" title="Abrir no ClickUp">' +
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>' +
          '<polyline points="15 3 21 3 21 9"/>' +
          '<line x1="10" y1="14" x2="21" y2="3"/>' +
        '</svg>' +
      '</a>' +
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
      });
    });
  }

  /* ---------- Sync ---------- */

  function bindSync() {
    var btn = document.getElementById('btn-sync');
    if (!btn) return;

    btn.addEventListener('click', async function() {
      btn.disabled = true;
      btn.classList.add('syncing');
      var originalText = btn.innerHTML;
      btn.innerHTML = '<span class="loading-spinner" style="width:16px;height:16px;border-width:2px;"></span> A sincronizar...';

      try {
        // Start background sync
        await apiFetch('/api/sync.php', {
          method: 'POST',
          body: JSON.stringify({}),
        });
        Toast.show('Sync iniciado...');

        // Poll for completion
        await pollSyncStatus(btn, originalText);
      } catch (err) {
        Toast.error('Erro no sync: ' + err.message);
        btn.disabled = false;
        btn.classList.remove('syncing');
        btn.innerHTML = originalText;
      }
    });
  }

  async function pollSyncStatus(btn, originalText) {
    var maxAttempts = 60; // max 2 minutes (2s intervals)
    for (var i = 0; i < maxAttempts; i++) {
      await new Promise(function(r) { setTimeout(r, 2000); });

      try {
        var status = await apiFetch('/api/sync.php'); // GET = check status
        if (!status.running) {
          Toast.success('Sync completo: ' + (status.last_count || 0) + ' tarefas');
          fetchTasks();
          btn.disabled = false;
          btn.classList.remove('syncing');
          btn.innerHTML = originalText;
          return;
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

  /* ---------- Helpers ---------- */

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
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
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose toast globally so PHP-rendered pages can use it
  window.Sonar = { Toast: Toast };
})();
