const API = 'api/';
const $ = id => document.getElementById(id);

let lastSeenId = 0;
let idleTimer = null;
let currentCard = null;   // { uid, name, balance } after a successful tap
let cart = [];            // [{name, price, emoji}]
let pendingOrder = null;  // { qty, total } waiting for card tap to pay
let activeCat = 'All';

const IMG = id => `https://images.unsplash.com/${id}?w=400&q=80&auto=format&fit=crop`;
const MENU = [
  { name: 'Burger', price: 45, cat: 'Meals', img: IMG('photo-1568901346375-23c9450c58cd') },
  { name: 'Rice Meal', price: 60, cat: 'Meals', img: IMG('photo-1512058564366-18510be2db19') },
  { name: 'Sandwich', price: 35, cat: 'Meals', img: IMG('photo-1528736235302-52922df5c122') },
  { name: 'Fries', price: 25, cat: 'Sides', img: IMG('photo-1573080496219-bb080dd4f877') },
  { name: 'Donut', price: 30, cat: 'Snacks', img: IMG('photo-1551024506-0bccd828d307') },
  { name: 'Ice Cream', price: 25, cat: 'Snacks', img: IMG('photo-1560008581-09826d1de69e') },
  { name: 'Soda', price: 20, cat: 'Drinks', img: IMG('photo-1622483767028-3f66f32aef97') },
  { name: 'Coffee', price: 30, cat: 'Drinks', img: IMG('photo-1495474472284-6f71cb5b0b4e') },
  { name: 'Water', price: 10, cat: 'Drinks', img: IMG('photo-1548839140-29a749e1cf4d') },
];

function renderCats() {
  const cats = ['All', ...new Set(MENU.map(m => m.cat))];
  $('kCats').innerHTML = cats.map(c =>
    `<button class="k-cat ${c === activeCat ? 'on' : ''}" data-cat="${c}">${c}</button>`).join('');
}

function renderMenu() {
  const items = MENU.filter(m => activeCat === 'All' || m.cat === activeCat);
  $('kMenu').innerHTML = items.map(m => `
    <button class="k-item active" data-name="${m.name}">
      <img src="${m.img}" alt="${m.name}" loading="lazy">
      <strong>${m.name}</strong>
      <span class="price">${m.price} pts</span>
    </button>`).join('');
}

function renderCartBar() {
  const total = cart.reduce((s, m) => s + m.price, 0);
  if (!cart.length) {
    $('kCartList').innerHTML = '<p class="k-empty">Nothing added yet</p>';
  } else {
    // group duplicates: { name: {item, qty} }
    const groups = {};
    cart.forEach(m => { (groups[m.name] ||= { item: m, qty: 0 }).qty++; });
    $('kCartList').innerHTML = Object.values(groups).map(({ item, qty }) => `
      <div class="row">
        <span><img class="thumb" src="${item.img}" alt=""> ${item.name} <b>×${qty}</b></span>
        <span class="qtyctl">
          <button data-name="${item.name}" data-a="dec">−</button>
          <b>${item.price * qty}</b> pts
          <button data-name="${item.name}" data-a="inc">+</button>
          <button data-name="${item.name}" data-a="rm" class="rm">✕</button>
        </span>
      </div>`).join('');
  }
  $('kTotal').textContent = `${total} pts`;
}

$('kCartList').addEventListener('click', e => {
  const b = e.target.closest('button');
  if (!b) return;
  const item = MENU.find(m => m.name === b.dataset.name);
  if (b.dataset.a === 'inc') cart.push(item);
  if (b.dataset.a === 'dec') {
    const i = cart.findIndex(m => m.name === item.name);
    if (i > -1) cart.splice(i, 1);
  }
  if (b.dataset.a === 'rm') cart = cart.filter(m => m.name !== item.name);
  renderCartBar();
});

$('kCats').addEventListener('click', e => {
  const b = e.target.closest('.k-cat');
  if (!b) return;
  activeCat = b.dataset.cat;
  renderCats(); renderMenu();
});

$('kMenu').addEventListener('click', e => {
  const btn = e.target.closest('.k-item');
  if (!btn) return;
  const item = MENU.find(m => m.name === btn.dataset.name);
  cart.push(item);
  renderCartBar();
  clearTimeout(idleTimer);
  idleTimer = setTimeout(resetStage, 20000);
});

$('kCheckout').addEventListener('click', () => {
  if (!cart.length) return;
  const total = cart.reduce((s, m) => s + m.price, 0);
  pendingOrder = { qty: cart.length, total };
  fetch(API + 'pending.php', { method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(pendingOrder) });
  currentCard = null;
  renderMenu();
  const stage = $('kStage');
  stage.className = 'k-stage idle';
  $('kIcon').textContent = '💳';
  $('kTitle').textContent = 'TAP YOUR CARD TO PAY';
  $('kSub').textContent = `Total: ${total} pts for ${pendingOrder.qty} item(s) — waiting for payment tap`;
  clearTimeout(idleTimer);
  idleTimer = setTimeout(() => { pendingOrder = null; cart = []; renderCartBar(); resetStage(); }, 30000);
});

$('kClear').addEventListener('click', () => {
  cart = []; pendingOrder = null;
  fetch(API + 'pending.php', { method: 'DELETE' });
  renderCartBar(); resetStage();
});

async function payOrder(log) {
  const stage = $('kStage');
  const icon = $('kIcon');
  if (log.access === 'granted') {
    stage.className = 'k-stage granted';
    icon.textContent = '✓';
    $('kTitle').textContent = `Paid! Thanks, ${log.name}`;
    $('kSub').textContent = `Balance: ${Number(log.balance || 0).toFixed(2)} pts`;
    cart = []; renderCartBar();
  } else {
    stage.className = 'k-stage denied';
    icon.textContent = '✕';
    $('kTitle').textContent = 'PAYMENT FAILED';
    $('kSub').textContent = `UID ${log.uid} • Insufficient balance or unregistered card`;
  }
  pendingOrder = null;
  fetch(API + 'pending.php', { method: 'DELETE' });
  void icon.offsetWidth; icon.classList.add('pop');
  clearTimeout(idleTimer);
  idleTimer = setTimeout(resetStage, 6000);
}

async function poll() {
  try {
    const res = await fetch(API + 'logs.php?limit=10');
    const data = await res.json();
    setStatus(true);
    if (data.logs.length && data.logs[0].id !== lastSeenId) {
      if (lastSeenId === 0) {
        // first poll: just remember the latest tap, don't display stale history
        lastSeenId = data.logs[0].id;
      } else if (pendingOrder) {
        lastSeenId = data.logs[0].id;
        await payOrder(data.logs[0]);   // a tap while waiting = payment
      } else {
        lastSeenId = data.logs[0].id;
        showTap(data.logs[0], true);
      }
    }
    renderMenu();
  } catch (err) {
    setStatus(false);
  }
}

function showTap(log, animate) {
  const stage = $('kStage');
  const icon = $('kIcon');
  stage.className = 'k-stage ' + log.access;
  icon.textContent = log.access === 'granted' ? '✓' : '✕';
  if (animate) { void icon.offsetWidth; icon.classList.add('pop'); }
  $('kTitle').textContent = log.access === 'granted' ? `Welcome, ${log.name}` : 'ACCESS DENIED';
  $('kSub').textContent = log.access === 'granted'
    ? `Balance: ${Number(log.balance || 0).toFixed(2)} pts • Pick your items below`
    : `Unregistered card • ${log.uid}`;

  if (log.access === 'granted') {
    if (currentCard && currentCard.uid !== log.uid && cart.length) {
      cart = [];   // different customer tapped — don't carry the old order over
      renderCartBar();
    }
    currentCard = { uid: log.uid, name: log.name, balance: Number(log.balance || 0) };
  } else {
    currentCard = null;
  }
  renderMenu();

  clearTimeout(idleTimer);
  idleTimer = setTimeout(resetStage, 15000);
}

function resetStage() {
  currentCard = null;
  cart = [];
  pendingOrder = null;
  renderMenu(); renderCartBar();
  $('kStage').className = 'k-stage idle';
  $('kIcon').textContent = '💳';
  $('kTitle').textContent = 'WELCOME!';
  $('kSub').textContent = 'Pick your items, then tap your card to pay';
}

function setStatus(online) {
  const el = $('kStatus');
  el.className = 'k-status ' + (online ? 'online' : 'offline');
  el.textContent = online ? 'Online' : 'Offline';
}

renderCats(); renderMenu(); renderCartBar();
poll();
setInterval(poll, 2000);
