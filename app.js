const API = 'api/';
const $ = id => document.getElementById(id);
const esc = s => String(s).replace(/[&<>"']/g,
  c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let lastSeenId = 0;

async function loadLogs() {
  try {
    const res = await fetch(API + 'logs.php?limit=20');
    const data = await res.json();
    setStatus(true);

    $('statTotal').textContent = data.stats.total;
    $('statGranted').textContent = data.stats.granted;
    $('statDenied').textContent = data.stats.denied;
    $('statUsers').textContent = data.stats.users;

    $('logBody').innerHTML = data.logs.length ? data.logs.map(l => `
      <tr>
        <td>${esc(l.created_at)}</td>
        <td>${esc(l.name)}</td>
        <td>${esc(l.uid)}</td>
        <td><span class="badge ${esc(l.access)}">${esc(l.access.toUpperCase())}</span></td>
        <td>${l.access === 'denied'
          ? `<button data-uid="${esc(l.uid)}" class="reg">Register</button>` : ''}</td>
      </tr>`).join('') : '<tr><td colspan="5" class="empty">No taps yet</td></tr>';

    if (data.logs.length && data.logs[0].id !== lastSeenId) {
      showLastTap(data.logs[0], lastSeenId !== 0);
      lastSeenId = data.logs[0].id;
    }
  } catch (err) {
    setStatus(false);
  }
}

function showLastTap(log, animate) {
  const icon = $('lastIcon');
  icon.className = 'icon ' + log.access;
  icon.textContent = log.access === 'granted' ? '✓' : '✕';
  if (animate) { void icon.offsetWidth; icon.classList.add('pop'); }
  $('lastName').textContent = log.access === 'granted' ? log.name : 'ACCESS DENIED';
  $('lastMeta').textContent = `UID ${log.uid} • ${log.created_at}`;
}

function setStatus(online) {
  const el = $('status');
  el.className = 'status ' + (online ? 'online' : 'offline');
  el.textContent = online ? 'Server Online' : 'Server Offline';
}

async function loadUsers() {
  const res = await fetch(API + 'users.php');
  const users = await res.json();
  $('userBody').innerHTML = users.length ? users.map(u => `
    <tr>
      <td>${esc(u.name)}</td><td>${esc(u.uid)}</td><td><strong>${Number(u.balance).toFixed(2)} pts</strong></td><td>${esc(u.created_at)}</td>
      <td>
        <button data-uid="${esc(u.uid)}" class="load">Load</button>
        <button data-uid="${esc(u.uid)}" class="deduct">Deduct</button>
        <button data-uid="${esc(u.uid)}" class="del">Remove</button>
      </td>
    </tr>`).join('') : '<tr><td colspan="5" class="empty">No cards registered</td></tr>';
}

$('userForm').addEventListener('submit', async e => {
  e.preventDefault();
  const msg = $('formMsg');
  const res = await fetch(API + 'users.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ uid: $('uid').value, name: $('name').value })
  });
  const data = await res.json();
  msg.style.color = res.ok ? 'var(--ok)' : 'var(--bad)';
  msg.textContent = data.message || data.error;
  if (res.ok) { e.target.reset(); loadUsers(); loadLogs(); }
});

// One-click register for a denied card
$('logBody').addEventListener('click', e => {
  if (e.target.classList.contains('reg')) {
    $('uid').value = e.target.dataset.uid;
    $('name').focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
});

$('userBody').addEventListener('click', async e => {
  if (e.target.classList.contains('load')) {
    const amt = prompt('Load how many points?');
    if (!amt || isNaN(amt) || Number(amt) <= 0) return;
    const res = await fetch(API + 'wallet.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ uid: e.target.dataset.uid, amount: Number(amt), type: 'load' })
    });
    const data = await res.json();
    alert(data.message ? `${data.message} — new balance: ${data.balance} pts` : data.error);
    loadUsers(); loadLogs();
  }
  if (e.target.classList.contains('deduct')) {
    const amt = prompt('Deduct how many points?');
    if (!amt || isNaN(amt) || Number(amt) <= 0) return;
    const res = await fetch(API + 'wallet.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ uid: e.target.dataset.uid, amount: Number(amt), type: 'purchase' })
    });
    const data = await res.json();
    alert(data.message ? `${data.message} — new balance: ${data.balance} pts` : data.error);
    loadUsers(); loadLogs();
  }
  if (e.target.classList.contains('del')) {
    if (!confirm('Remove this card?')) return;
    await fetch(API + 'users.php?uid=' + encodeURIComponent(e.target.dataset.uid), { method: 'DELETE' });
    loadUsers(); loadLogs();
  }
});

$('clearLogs').addEventListener('click', async () => {
  if (!confirm('Clear all access logs?')) return;
  await fetch(API + 'logs.php', { method: 'DELETE' });
  lastSeenId = 0;
  loadLogs();
});

loadUsers();
loadLogs();
setInterval(loadLogs, 2000);   // live refresh every 2 seconds
