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
  .saved-by{white-space:nowrap;}
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
      <p class="sub">Cards saved from the printer — load one back into the form, reprint it as-is, or delete it.</p>
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

function fingerprint(count, latestId){
  return count + ':' + latestId;
}

async function refreshSavedList(){
  const list = document.getElementById('savedList');
  refreshing = true;
  try{
    allRecords = await apiGetAllSavedCards();
    lastFingerprint = fingerprint(allRecords.length, allRecords.reduce((m,r)=>Math.max(m,r.id),0));
  } catch(err){
    console.error(err);
    allRecords = [];
    list.innerHTML = '<div class="saved-empty">Could not load saved cards.</div>';
    refreshing = false;
    return;
  }
  refreshing = false;
  allRecords.sort((a,b)=> b.createdAt - a.createdAt);
  applySavedFilter();
}

/* Cards saved from other computers show up without a manual reload: poll a
   tiny count/newest-id fingerprint and only re-download the (image-heavy)
   list when it actually changed. Paused while the tab is hidden. */
async function checkForChanges(){
  if(refreshing || document.hidden || lastFingerprint === null) return;
  try{
    const s = await apiGetSavedCardsSummary();
    if(fingerprint(s.count, s.latestId) !== lastFingerprint){
      await refreshSavedList();
    }
  } catch(err){
    // transient network/session hiccup — try again on the next tick
  }
}
setInterval(checkForChanges, 15000);
document.addEventListener('visibilitychange', checkForChanges);

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

  const rows = records.map(r=>`
    <tr>
      <td><img class="saved-thumb" src="${escapeHtml(r.frontSnapshot)}" alt=""></td>
      <td class="saved-name">${escapeHtml(r.fullName || '(no name)')}</td>
      <td class="saved-upper">${escapeHtml(r.rcNumber || '—')}</td>
      <td class="saved-upper saved-muted">${escapeHtml(r.designation || '—')}</td>
      <td class="saved-by">${escapeHtml(r.createdByName || 'Unknown')}</td>
      <td class="saved-date">${new Date(r.createdAt).toLocaleString()}</td>
      <td>
        <div class="saved-actions">
          <a class="btn-secondary btn-small" href="index.php?load=${r.id}">Load</a>
          <a class="btn-secondary btn-small" href="index.php?print=${r.id}">Print</a>
          ${window.APP_ROLE === 'admin' ? `<button class="btn-secondary btn-small btn-danger" onclick="deleteRecord(${r.id})">Delete</button>` : ''}
        </div>
      </td>
    </tr>
  `).join('');

  list.innerHTML = `
    <table class="saved-table">
      <thead>
        <tr>
          <th>Card</th>
          <th>Name</th>
          <th>RC number</th>
          <th>Designation</th>
          <th>Printed by</th>
          <th>Printed date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `;
}

async function deleteRecord(id){
  if(!confirm('Delete this saved card? This cannot be undone.')) return;
  try{
    await apiDeleteSavedCard(id);
    await refreshSavedList();
  } catch(err){
    alert(err.message);
  }
}

refreshSavedList();
</script>
</body>
</html>
