<?php
require_once __DIR__ . '/lib/session.php';
require_login();

$me = current_user();
$role = $me['role'] ?? 'viewer';
$displayName = $me['name'] ?? $me['username'] ?? '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Saved Cards — Office Card Print</title>
<script src="assets/theme.js"></script>
<link rel="stylesheet" href="assets/theme.css">
<style>
  .wrap{max-width:1100px;margin:0 auto;padding:32px 24px 60px;}
  .page-header{
    display:flex;
    align-items:baseline;
    justify-content:space-between;
    gap:16px;
    margin-bottom:18px;
    flex-wrap:wrap;
  }
  .page-header h1{font-size:17px;margin:0 0 2px;color:var(--navy);}
  .page-header .sub{font-size:12.5px;color:var(--gray);margin:0;}
  .saved-search{width:260px;max-width:100%;}
  .saved-count{font-size:12px;color:var(--gray);white-space:nowrap;margin-bottom:14px;}
  .saved-empty{font-size:13px;color:var(--gray);padding:32px;text-align:center;}
  .saved-list{
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:var(--radius-lg);
    overflow-x:auto;
  }
  .saved-table{width:100%;border-collapse:collapse;font-size:13px;}
  .saved-table th,
  .saved-table td{
    text-align:left;
    padding:10px 14px;
    border-bottom:1px solid var(--border);
    vertical-align:middle;
  }
  .saved-table th{
    background:var(--paper);
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.05em;
    color:var(--gray);
    font-weight:600;
    white-space:nowrap;
  }
  .saved-table tbody tr:hover{background:var(--row-hover);}
  .saved-table tbody tr:last-child td{border-bottom:none;}
  .saved-thumb{
    display:block;
    width:44px;
    aspect-ratio:53.98/85.6;
    object-fit:cover;
    border-radius:4px;
    border:1px solid var(--border);
    background:var(--gray-soft);
  }
  .saved-name{font-weight:700;color:var(--text);text-transform:uppercase;}
  .saved-upper{text-transform:uppercase;}
  .saved-muted{color:var(--gray);}
  .saved-date{color:var(--gray);font-size:12px;white-space:nowrap;}
  .saved-actions{display:flex;gap:6px;white-space:nowrap;}
  .saved-actions .btn-small{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    text-decoration:none;
    font-family:inherit;
    cursor:pointer;
    border:none;
  }
  .saved-last-line{white-space:nowrap;}
  .action-tag{
    display:inline-block;
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.05em;
    padding:2px 8px;
    border-radius:999px;
    line-height:1.5;
    background:var(--gray-soft);
    color:var(--gray);
  }
  .action-tag.printed{background:var(--blue-soft);color:var(--blue);}
  .action-tag.saved{background:var(--green-soft);color:var(--green);}
  .saved-history-row > td{background:var(--paper);padding:0 14px 14px 72px;}
  .history-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--gray);padding:12px 0 8px;}
  .history-table{width:100%;border-collapse:collapse;font-size:12.5px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);}
  .history-table th,
  .history-table td{text-align:left;padding:7px 12px;border-bottom:1px solid var(--border);}
  .history-table th{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--gray);font-weight:600;}
  .history-table tbody tr:last-child td{border-bottom:none;}
  .history-msg{font-size:12.5px;color:var(--gray);padding:12px 0;}
</style>
</head>
<body>
<script>
window.APP_ROLE = <?= json_encode($role) ?>;
window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
</script>

<div class="topbar">
  <div class="topbar-brand">
    <div class="topbar-mark">OC</div>
    <div class="topbar-title">Office Card Print</div>
  </div>
  <div class="topbar-right">
    <div class="user-chip">
      <span class="name"><?= htmlspecialchars($displayName, ENT_QUOTES) ?></span>
      <span class="role-badge <?= htmlspecialchars($role, ENT_QUOTES) ?>"><?= htmlspecialchars($role, ENT_QUOTES) ?></span>
    </div>
    <div class="topbar-divider"></div>
    <a href="index.php" class="topbar-link">&larr; Back to app</a>
    <div class="topbar-divider"></div>
    <?php if ($role === 'admin'): ?>
      <a href="users.php" class="topbar-link">Manage users</a>
      <div class="topbar-divider"></div>
    <?php endif; ?>
    <a href="logout.php" class="topbar-link">Log out</a>
    <button id="themeToggle" class="theme-toggle" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode"></button>
  </div>
</div>

<div class="wrap">
  <div class="page-header">
    <div>
      <h1>Saved Cards</h1>
      <p class="sub">One record per RC number — every save, print and reprint is kept in its history. Load a card back into the form, reprint it as-is, or open its history.</p>
    </div>
    <input type="text" id="savedSearch" class="saved-search" placeholder="Search by name or RC number…" oninput="applySavedFilter()">
  </div>

  <div class="saved-count" id="savedCount"></div>
  <div class="saved-list" id="savedList"></div>
</div>

<script>
async function apiGetAllSavedCards(){
  const res = await fetch('saved-cards-api.php');
  if(!res.ok) throw new Error('Could not load saved cards.');
  return res.json();
}

async function apiGetSavedCardsSummary(){
  const res = await fetch('saved-cards-api.php?summary=1');
  if(!res.ok) throw new Error('summary failed');
  return res.json();
}

async function apiDeleteSavedCard(id){
  const res = await fetch('saved-cards-api.php?id=' + encodeURIComponent(id), {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': window.CSRF_TOKEN }
  });
  const data = await res.json();
  if(!res.ok){
    throw new Error(data.error || 'Could not delete record.');
  }
  return data;
}

function escapeHtml(s){
  return (s || '').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

let allRecords = [];
let lastFingerprint = null;
let refreshing = false;

// Expanded history rows survive re-renders and auto-refreshes.
const expanded = new Set();
const historyCache = {};
const historyLoading = new Set();

const ACTION_LABELS = { saved:'Saved', printed:'Printed', reprinted:'Reprinted' };

function fingerprint(s){
  return s.count + ':' + s.latestEventId;
}

async function refreshSavedList(){
  const list = document.getElementById('savedList');
  refreshing = true;
  try{
    const [records, summary] = await Promise.all([apiGetAllSavedCards(), apiGetSavedCardsSummary()]);
    allRecords = records;
    lastFingerprint = fingerprint(summary);
    // Something changed — any open history may be stale.
    expanded.forEach(id => delete historyCache[id]);
  } catch(err){
    console.error(err);
    allRecords = [];
    list.innerHTML = '<div class="saved-empty">Could not load saved cards.</div>';
    refreshing = false;
    return;
  }
  refreshing = false;
  applySavedFilter();
}

/* Changes from other computers (new cards, reprints, deletions) show up
   without a manual reload: poll a tiny fingerprint and only re-download the
   (image-heavy) list when it changed. Paused while the tab is hidden. */
async function checkForChanges(){
  if(refreshing || document.hidden || lastFingerprint === null) return;
  try{
    const s = await apiGetSavedCardsSummary();
    if(fingerprint(s) !== lastFingerprint){
      await refreshSavedList();
    }
  } catch(err){
    // transient network/session hiccup — try again on the next tick
  }
}
setInterval(checkForChanges, 15000);
document.addEventListener('visibilitychange', checkForChanges);

async function loadHistory(id){
  if(historyLoading.has(id)) return;
  historyLoading.add(id);
  try{
    const res = await fetch('saved-cards-api.php?id=' + encodeURIComponent(id) + '&history=1');
    if(!res.ok) throw new Error('history failed');
    historyCache[id] = await res.json();
  } catch(err){
    console.error(err);
    historyCache[id] = null;
  } finally {
    historyLoading.delete(id);
  }
  if(expanded.has(id)) applySavedFilter();
}

function toggleHistory(id){
  if(expanded.has(id)){
    expanded.delete(id);
  } else {
    expanded.add(id);
  }
  applySavedFilter();
}

function historyHtml(id){
  const events = historyCache[id];
  if(events === undefined) return '<div class="history-msg">Loading history…</div>';
  if(events === null) return '<div class="history-msg">Could not load the history.</div>';
  if(events.length === 0) return '<div class="history-msg">No history recorded.</div>';

  const rows = events.map(e=>`
    <tr>
      <td class="saved-date">${new Date(e.performedAt).toLocaleString()}</td>
      <td><span class="action-tag ${escapeHtml(e.action)}">${escapeHtml(ACTION_LABELS[e.action] || e.action)}</span></td>
      <td>${escapeHtml(e.performedByName || e.performedBy)}</td>
      <td class="saved-upper">${escapeHtml(e.fullName || '—')}</td>
      <td class="saved-upper saved-muted">${escapeHtml(e.designation || '—')}</td>
    </tr>
  `).join('');

  return `
    <div class="history-title">History — newest first</div>
    <table class="history-table">
      <thead><tr><th>When</th><th>Action</th><th>By</th><th>Name at the time</th><th>Designation at the time</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
  `;
}

function applySavedFilter(){
  const list = document.getElementById('savedList');
  const countEl = document.getElementById('savedCount');
  const query = document.getElementById('savedSearch').value.trim().toLowerCase();

  const records = query
    ? allRecords.filter(r=>
        (r.fullName || '').toLowerCase().includes(query) ||
        (r.rcNumber || '').toLowerCase().includes(query))
    : allRecords;

  countEl.textContent = allRecords.length
    ? (query ? records.length + ' of ' + allRecords.length : allRecords.length) + ' saved'
    : '';

  if(allRecords.length === 0){
    list.innerHTML = '<div class="saved-empty">No saved cards yet — go to the card printer, fill in the form, and click "Save to records" or "Print card".</div>';
    return;
  }
  if(records.length === 0){
    list.innerHTML = '<div class="saved-empty">No saved cards match "' + escapeHtml(document.getElementById('savedSearch').value) + '".</div>';
    return;
  }

  const rows = records.map(r=>{
    const id = Number(r.id);
    const open = expanded.has(id);
    if(open && historyCache[id] === undefined) loadHistory(id);

    return `
    <tr>
      <td><img class="saved-thumb" src="${escapeHtml(r.frontSnapshot)}" alt=""></td>
      <td class="saved-name">${escapeHtml(r.fullName || '(no name)')}</td>
      <td class="saved-upper">${escapeHtml(r.rcNumber || '—')}</td>
      <td class="saved-upper saved-muted">${escapeHtml(r.designation || '—')}</td>
      <td>${Number(r.printCount)}</td>
      <td>
        <div class="saved-last-line">${escapeHtml(ACTION_LABELS[r.lastAction] || r.lastAction)} by ${escapeHtml(r.lastByName || 'Unknown')}</div>
        <div class="saved-date">${new Date(r.lastAt).toLocaleString()}</div>
      </td>
      <td>
        <div class="saved-actions">
          <a class="btn-secondary btn-small" href="index.php?load=${id}">Load</a>
          <a class="btn-secondary btn-small" href="index.php?print=${id}">Print</a>
          <button class="btn-info btn-small" onclick="toggleHistory(${id})" aria-expanded="${open}">${open ? 'Hide history' : 'History (' + Number(r.eventCount) + ')'}</button>
          ${window.APP_ROLE === 'admin' ? `<button class="btn-secondary btn-small btn-danger" onclick="deleteRecord(${id})">Delete</button>` : ''}
        </div>
      </td>
    </tr>
    ${open ? `<tr class="saved-history-row"><td colspan="7">${historyHtml(id)}</td></tr>` : ''}
  `;
  }).join('');

  list.innerHTML = `
    <table class="saved-table">
      <thead>
        <tr>
          <th>Card</th>
          <th>Name</th>
          <th>RC number</th>
          <th>Designation</th>
          <th>Prints</th>
          <th>Last activity</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `;
}

async function deleteRecord(id){
  if(!confirm('Delete this saved card and its history? This cannot be undone.')) return;
  try{
    await apiDeleteSavedCard(id);
    expanded.delete(id);
    delete historyCache[id];
    await refreshSavedList();
  } catch(err){
    alert(err.message);
  }
}

refreshSavedList();
</script>
</body>
</html>
