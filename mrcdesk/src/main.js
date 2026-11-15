/* MRC Masaüstü pilotu — önyüz mantığı.
 *
 * Akış (GeneralEcu ile aynı felsefe: ince istemci, iş sunucuda):
 *  1. POST {api}/auth/login → token (2FA varsa 202 + challenge → kodla tamamla)
 *  2. GET {api}/parties → iskelet modül (cari listesi)
 *  3. Rust köprüsü: get_device_info + list_printers (tarayıcının yapamadıkları)
 *
 * Token localStorage'da durur (pilot; üretimde sistem anahtarlığı — keyring —
 * kullanılmalı, TODO olarak işaretli).
 *
 * Köprü yoksa (dosya/önizleme): API işleri çalışır, cihaz/yazıcı kutuları
 * „köprü yok" notu düşer — sayfa çökmez.
 */
const invoke = window.__TAURI__?.core?.invoke
  ?? (async () => { throw new Error('Tauri köprüsü yok (tarayıcı önizlemesi)'); });

const $ = (id) => document.getElementById(id);
const API_KEY = 'mrcdesk.api_base';
const TOKEN_KEY = 'mrcdesk.token'; // TODO(üretim): keyring (tauri-plugin-stronghold)

const apiBase = () => ($('api-base').value || '').replace(/\/+$/, '');

function showError(el, err, fallback) {
  const bag = err?.response?.errors || err?.errors;
  el.textContent = (bag && Object.values(bag).flat()[0])
    || err?.response?.message
    || err?.message
    || fallback;
}

async function api(path, { method = 'GET', body, token } = {}) {
  const res = await fetch(`${apiBase()}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(data.message || `HTTP ${res.status}`);
    err.response = data;
    err.status = res.status;
    throw err;
  }
  return { status: res.status, data };
}

let challengeToken = null;

async function handleLogin() {
  $('login-error').textContent = '';
  try {
    const { status, data } = await api('/auth/login', {
      method: 'POST',
      body: { email: $('email').value.trim(), password: $('password').value },
    });
    if (status === 202 && data.requires_two_factor) {
      // 2FA: sınama anahtarı bellekte, kod istenir (localStorage'a yazılmaz).
      challengeToken = data.challenge_token;
      $('challenge-box').classList.remove('hidden');
      $('login-error').textContent = '';
      $('code').focus();
      return;
    }
    enterApp(data.token);
  } catch (err) {
    showError($('login-error'), err, 'Giriş yapılamadı.');
  }
}

async function handleChallenge() {
  $('login-error').textContent = '';
  try {
    const code = $('code').value.trim();
    const { data } = await fetch(`${apiBase()}/auth/2fa/challenge`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${challengeToken}`,
      },
      body: JSON.stringify(code ? { code } : { recovery_code: '' }),
    }).then(async (res) => {
      const d = await res.json().catch(() => ({}));
      if (!res.ok) {
        const err = new Error(d.message || `HTTP ${res.status}`);
        err.response = d;
        err.status = res.status;
        throw err;
      }
      return { data: d };
    });
    challengeToken = null;
    enterApp(data.token);
  } catch (err) {
    showError($('login-error'), err, 'Kod doğrulanamadı.');
  }
}

function enterApp(token) {
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(API_KEY, apiBase());
  $('view-login').classList.add('hidden');
  $('view-app').classList.remove('hidden');
  bootApp(token);
}

async function bootApp(token) {
  try {
    const { data } = await api('/me', { token });
    $('company-name').textContent = data.company?.name || 'MRC';
    $('user-line').textContent = `${data.user?.name || ''} · ${data.user?.email || ''}`;
  } catch {
    $('company-name').textContent = 'MRC';
  }
  loadParties(token);
  loadDevice();
  loadPrinters();
}

async function loadParties(token) {
  $('parties-error').textContent = '';
  try {
    const { data } = await api('/parties?per_page=20', { token });
    const rows = data.data || [];
    $('parties-body').innerHTML = rows.map((p) => {
      // Liste ucu bakiye taşımaz (ayrı `/parties/{id}/balance`); satırda açılış görünür.
      const acilis = p.opening_balance ?? null;
      return `<tr><td class="mono">${p.code || ''}</td><td>${escapeHtml(p.name)}</td>`
        + `<td>${p.type || ''}</td><td class="num">${acilis === null ? '—' : acilis}</td></tr>`;
    }).join('') || '<tr><td colspan="4" class="muted">Kayıt yok.</td></tr>';
  } catch (err) {
    showError($('parties-error'), err, 'Cariler yüklenemedi.');
  }
}

async function loadDevice() {
  try {
    const info = await invoke('get_device_info');
    $('device-info').textContent = JSON.stringify(info, null, 2);
  } catch (err) {
    $('device-info').textContent = `köprü hatası: ${err}`;
  }
}

async function loadPrinters() {
  $('printers-error').textContent = '';
  try {
    const list = await invoke('list_printers');
    $('printers-list').innerHTML = list.length
      ? list.map((p) => `<li><b>${escapeHtml(p.Name || p.name || '?')}</b>`
        + (p.PortName ? ` <span class="muted">${escapeHtml(String(p.PortName))}</span>` : '')
        + '</li>').join('')
      : '<li class="muted">Yazıcı bulunamadı.</li>';
  } catch (err) {
    $('printers-error').textContent = `Yazıcılar okunamadı: ${err}`;
  }
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

async function handleLogout() {
  const token = localStorage.getItem(TOKEN_KEY);
  try {
    if (token) await api('/auth/logout', { method: 'POST', token });
  } catch { /* ağ yoksa da yerelde düş */ }
  localStorage.removeItem(TOKEN_KEY);
  challengeToken = null;
  $('view-app').classList.add('hidden');
  $('view-login').classList.remove('hidden');
  $('challenge-box').classList.add('hidden');
  $('password').value = '';
  const code = $('code');
  if (code) code.value = '';
}

// Varsayılan sunucu: yerel geliştirme. Kullanıcı değiştirebilir (kalıcı).
$('api-base').value = localStorage.getItem(API_KEY) || 'http://127.0.0.1:8000/api/v1';
$('btn-login').addEventListener('click', () => {
  if ($('challenge-box').classList.contains('hidden')) {
    handleLogin();
  } else {
    handleChallenge();
  }
});
$('btn-logout').addEventListener('click', handleLogout);
$('btn-refresh-parties').addEventListener('click', () => loadParties(localStorage.getItem(TOKEN_KEY)));
for (const id of ['email', 'password', 'code']) {
  $(id).addEventListener('keydown', (e) => { if (e.key === 'Enter') $('btn-login').click(); });
}
