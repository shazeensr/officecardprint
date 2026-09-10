<?php
require_once __DIR__ . '/lib/session.php';
require_login();

$me = current_user();
$role = $me['role'] ?? 'viewer';
$displayName = $me['name'] ?? $me['username'] ?? '';
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
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
    gap:16px;
  }
  .saved-item{
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:10px;
    padding:10px;
    display:flex;
    flex-direction:column;
    gap:8px;
    transition:box-shadow .15s ease, border-color .15s ease;
  }
  .saved-item:hover{
    box-shadow:var(--shadow-md);
    border-color:var(--navy-2);
  }
  .saved-thumb{
    width:100%;
    aspect-ratio:53.98/85.6;
    object-fit:cover;
    border-radius:6px;
    border:1px solid var(--border);
    background:var(--gray-soft);
  }
  .saved-name{
    font-size:13px;
    font-weight:700;
    color:var(--text);
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    text-transform:uppercase;
  }
  .saved-meta{
    font-size:12px;
    color:var(--gray);
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    text-transform:uppercase;
  }
  .saved-date{font-size:11px;color:var(--muted);}
  .saved-actions{display:flex;gap:6px;margin-top:4px;}
  .saved-actions .btn-small{
    flex:1;
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
<script>window.APP_ROLE = <?= json_encode($role) ?>;</script>

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
const DB_NAME = 'officeCardDB';
const DB_STORE = 'cards';

function openCardDB(){
  return new Promise((resolve,reject)=>{
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = ()=>{
      const db = req.result;
      if(!db.objectStoreNames.contains(DB_STORE)){
        const store = db.createObjectStore(DB_STORE, { keyPath:'id', autoIncrement:true });
        store.createIndex('createdAt','createdAt',{unique:false});
      }
    };
    req.onsuccess = ()=>resolve(req.result);
    req.onerror = ()=>reject(req.error);
  });
}

async function dbGetAll(){
  const db = await openCardDB();
  return new Promise((resolve,reject)=>{
    const tx = db.transaction(DB_STORE,'readonly');
    const req = tx.objectStore(DB_STORE).getAll();
    req.onsuccess = ()=>resolve(req.result);
    req.onerror = ()=>reject(req.error);
  });
}

async function dbDelete(id){
  const db = await openCardDB();
  return new Promise((resolve,reject)=>{
    const tx = db.transaction(DB_STORE,'readwrite');
    const req = tx.objectStore(DB_STORE).delete(id);
    req.onsuccess = ()=>resolve();
    req.onerror = ()=>reject(req.error);
  });
}

function escapeHtml(s){
  return (s || '').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

let allRecords = [];

async function refreshSavedList(){
  const list = document.getElementById('savedList');
  try{
    allRecords = await dbGetAll();
  } catch(err){
    console.error(err);
    allRecords = [];
    list.innerHTML = '<div class="saved-empty">Could not load saved cards.</div>';
    return;
  }
  allRecords.sort((a,b)=> b.createdAt - a.createdAt);
  applySavedFilter();
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

  list.innerHTML = records.map(r=>{
    const meta = [r.rcNumber, r.designation].filter(Boolean).join(' · ');
    return `
      <div class="saved-item">
        <img class="saved-thumb" src="${r.frontSnapshot}" alt="">
        <div class="saved-name">${escapeHtml(r.fullName || '(no name)')}</div>
        <div class="saved-meta">${escapeHtml(meta)}</div>
        <div class="saved-date">${new Date(r.createdAt).toLocaleString()}</div>
        <div class="saved-actions">
          <a class="btn-secondary btn-small" href="index.php?load=${r.id}">Load</a>
          <a class="btn-secondary btn-small" href="index.php?print=${r.id}">Print</a>
          ${window.APP_ROLE === 'viewer' ? '' : `<button class="btn-secondary btn-small btn-danger" onclick="deleteRecord(${r.id})">Delete</button>`}
        </div>
      </div>
    `;
  }).join('');
}

async function deleteRecord(id){
  if(!confirm('Delete this saved card? This cannot be undone.')) return;
  await dbDelete(id);
  await refreshSavedList();
}

refreshSavedList();
</script>
</body>
</html>
