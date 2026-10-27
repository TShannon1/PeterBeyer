(() => {
'use strict';
const app = document.getElementById('app');
let csrf = '', me = null, content = null, version = '', dirty = false, tab = 'edit', sel = 'quick';

/* ---------- helpers ---------- */
function el(tag, attrs = {}, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') n.className = v;
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
    else if (k === 'style') n.setAttribute('style', v);
    else if (k in n && k !== 'list') { try { n[k] = v; } catch { n.setAttribute(k, v); } }
    else n.setAttribute(k, v === true ? '' : v);
  }
  for (const k of kids.flat()) if (k != null && k !== false) n.append(k instanceof Node ? k : document.createTextNode(String(k)));
  return n;
}
const clear = n => { while (n.firstChild) n.firstChild.remove(); return n; };
const fill = (n, ...kids) => { clear(n); for (const k of kids.flat(3)) if (k != null && k !== false) n.append(k); return n; };
let toastT;
function toast(msg, bad = false, ms) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'show' + (bad ? ' bad' : '');
  clearTimeout(toastT); toastT = setTimeout(() => t.className = bad ? 'bad' : '', ms || (bad ? 6000 : 3000));
}
async function api(action, body, isForm) {
  const opt = { method: body ? 'POST' : 'GET', headers: { 'X-CSRF': csrf }, credentials: 'same-origin' };
  if (body) { if (isForm) opt.body = body; else { opt.body = JSON.stringify(body); opt.headers['Content-Type'] = 'application/json'; } }
  let r, j;
  try { r = await fetch('api.php?action=' + action, opt); j = await r.json(); }
  catch { throw new Error('Could not reach the server. Check your connection, and that this is hosted with PHP.'); }
  if (r.status === 401 && action !== 'login') { me = null; render(); }
  if (!j.ok) throw new Error(j.error || 'Something went wrong.');
  return j;
}
const fmtDate = s => s ? new Date(s).toLocaleString('en-AU', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
const uid = p => p + '-' + Math.random().toString(36).slice(2, 7);
function markDirty() { dirty = true; updateSaveBar(); }
function move(arr, i, d) { const j = i + d; if (j < 0 || j >= arr.length) return false; [arr[i], arr[j]] = [arr[j], arr[i]]; return true; }
function genPassword() {
  const words = 'river,stone,maple,harbour,cedar,window,garden,timber,ocean,meadow,copper,lantern,granite,willow,summit,canyon'.split(',');
  const a = new Uint32Array(4); crypto.getRandomValues(a);
  return `${words[a[0] % words.length]}-${words[a[1] % words.length]}-${words[a[2] % words.length]}-${10 + a[3] % 90}`;
}
addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

/* ---------- boot ---------- */
async function boot() {
  try {
    const s = await api('state');
    csrf = s.csrf; me = s.user;
    if (s.setup) return renderSetup();
    if (me) await loadContent();
    render();
  } catch (e) { fill(app, el('p', { class: 'loading' }, e.message)); }
}
async function loadContent() { const j = await api('content'); content = j.content; version = j.version; dirty = false; }

function render() {
  if (!me) return renderLogin();
  fill(app, topBar(), el('main', { class: 'view', id: 'view' }));
  renderView();
}

/* ---------- login / first-time setup ---------- */
function renderLogin() {
  const err = el('p', { class: 'err' });
  const f = el('form', { onsubmit: async e => {
      e.preventDefault(); err.textContent = '';
      try { await api('login', { username: f.username.value, password: f.password.value }); const s = await api('state'); csrf = s.csrf; me = s.user; await loadContent(); render(); }
      catch (x) { err.textContent = x.message; }
    } },
    el('h1', {}, 'Website editor'), el('p', {}, 'Sign in to update the website.'),
    el('label', { class: 'field' }, el('span', {}, 'Username'), el('input', { type: 'text', name: 'username', autocomplete: 'username', required: true, autofocus: true })),
    el('label', { class: 'field' }, el('span', {}, 'Password'), el('input', { type: 'password', name: 'password', autocomplete: 'current-password', required: true })),
    err, el('button', { class: 'btn primary', type: 'submit' }, 'Sign in'),
    el('p', { class: 'hint', style: 'margin-top:16px;text-align:center' }, el('a', { href: '../' }, '← Back to the website')));
  fill(app, el('div', { class: 'auth' }, f));
}
function renderSetup() {
  const err = el('p', { class: 'err' });
  const f = el('form', { onsubmit: async e => {
      e.preventDefault(); err.textContent = '';
      if (f.password.value !== f.password2.value) { err.textContent = 'The two passwords don’t match.'; return; }
      try { await api('setup', { name: f.fullname.value, username: f.username.value, password: f.password.value }); boot(); }
      catch (x) { err.textContent = x.message; }
    } },
    el('h1', {}, 'Create the first admin'), el('p', {}, 'This only appears once. You can add more admins after signing in.'),
    el('label', { class: 'field' }, el('span', {}, 'Your name'), el('input', { type: 'text', name: 'fullname', autocomplete: 'name' })),
    el('label', { class: 'field' }, el('span', {}, 'Username'), el('input', { type: 'text', name: 'username', autocomplete: 'username', required: true }), el('small', {}, 'Letters, numbers, dots or dashes. An email address works too.')),
    el('label', { class: 'field' }, el('span', {}, 'Password'), el('input', { type: 'password', name: 'password', autocomplete: 'new-password', required: true, minLength: 10 }), el('small', {}, 'At least 10 characters.')),
    el('label', { class: 'field' }, el('span', {}, 'Password again'), el('input', { type: 'password', name: 'password2', autocomplete: 'new-password', required: true })),
    err, el('button', { class: 'btn primary', type: 'submit' }, 'Create admin and sign in'));
  fill(app, el('div', { class: 'auth' }, f));
}

/* ---------- top bar ---------- */
let saveBtn, statusEl;
function topBar() {
  const tabBtn = (id, label) => el('button', { 'aria-current': String(tab === id), onclick: () => { tab = id; render(); } }, label);
  statusEl = el('span', { class: 'status' });
  saveBtn = el('button', { class: 'btn primary', onclick: save }, 'Publish changes');
  const bar = el('header', { class: 'bar' }, el('div', { class: 'bar-in' },
    el('h1', {}, 'Website editor'),
    el('nav', { class: 'tabs' }, tabBtn('edit', 'Edit website'), tabBtn('admins', 'Admins'), tabBtn('history', 'History')),
    el('span', { class: 'who' }, 'Signed in as ' + (me.name || me.username)),
    el('a', { class: 'btn small', href: '../', target: '_blank', rel: 'noopener' }, 'View site ↗'),
    el('button', { class: 'btn small', onclick: logout }, 'Sign out'),
    el('div', { class: 'savebar' }, statusEl, saveBtn)));
  updateSaveBar();
  return bar;
}
function updateSaveBar() {
  if (!saveBtn) return;
  saveBtn.disabled = !dirty;
  document.querySelectorAll('.quick-pub .btn').forEach(b => b.disabled = !dirty);
  statusEl.className = 'status' + (dirty ? ' dirty' : '');
  statusEl.textContent = dirty ? 'Unsaved changes' : (content?.updated ? 'Published ' + fmtDate(content.updated.at) : 'All changes published');
}
async function save() {
  saveBtn.disabled = true; saveBtn.textContent = 'Publishing…';
  try {
    const j = await api('save', { content, version });
    content = j.content; version = j.version; dirty = false;
    toast('Published. The website is updated.');
    renderView();
  } catch (e) { toast(e.message, true); }
  finally { saveBtn.textContent = 'Publish changes'; updateSaveBar(); }
}
async function logout() {
  if (dirty && !confirm('You have unpublished changes. Sign out and lose them?')) return;
  dirty = false;
  await api('logout', {}).catch(() => {});
  me = null; const s = await api('state'); csrf = s.csrf; render();
}

function renderView() {
  const v = document.getElementById('view'); if (!v) return;
  clear(v);
  if (tab === 'edit') v.append(editView());
  if (tab === 'admins') adminsView(v);
  if (tab === 'history') historyView(v);
  updateSaveBar();
}

/* ---------- edit website ---------- */
const typeName = { gallery: 'Gallery', page: 'Text & photos', contact: 'Contact' };
function editView() {
  const secs = content.sections;
  if (sel !== 'site' && sel !== 'quick' && !secs.some(s => s.id === sel)) sel = 'quick';
  const side = el('aside', { class: 'side' },
    el('div', { class: 'side-item quick' + (sel === 'quick' ? ' on' : '') }, el('button', { class: 'pick', onclick: () => { sel = 'quick'; renderView(); } }, '＋ Add new work')),
    el('h2', {}, 'Settings'),
    el('div', { class: 'side-item' + (sel === 'site' ? ' on' : '') }, el('button', { class: 'pick', onclick: () => { sel = 'site'; renderView(); } }, 'Business details')),
    el('h2', {}, 'Page sections (top to bottom)'),
    secs.map((s, i) => el('div', { class: 'side-item' + (sel === s.id ? ' on' : '') },
      el('button', { class: 'pick', onclick: () => { sel = s.id; renderView(); } }, s.title || '(untitled)', !s.visible && el('span', { class: 'hiddenTag' }, 'Hidden')),
      el('button', { class: 'mini', title: 'Move up', 'aria-label': 'Move ' + s.title + ' up', onclick: () => { if (move(secs, i, -1)) { markDirty(); renderView(); } } }, '↑'),
      el('button', { class: 'mini', title: 'Move down', 'aria-label': 'Move ' + s.title + ' down', onclick: () => { if (move(secs, i, 1)) { markDirty(); renderView(); } } }, '↓'))),
    el('div', { class: 'side-add' }, el('button', { class: 'btn small', onclick: addSection }, '+ Add a section')));
  const jump = el('label', { class: 'side-select' }, el('span', {}, 'Editing'),
    el('select', { onchange: e => { sel = e.target.value; renderView(); scrollTo(0, 0); } },
      el('option', { value: 'quick', selected: sel === 'quick' }, '＋ Add new work'),
      el('option', { value: 'site', selected: sel === 'site' }, 'Business details'),
      secs.map(x => el('option', { value: x.id, selected: sel === x.id }, (x.title || '(untitled)') + (x.visible ? '' : ' (hidden)')))));
  const main = el('div', {}, jump, sel === 'quick' ? quickView() : sel === 'site' ? siteEditor() : sectionEditor(secs.find(s => s.id === sel)));
  return el('div', { class: 'editor' }, side, main);
}
function quickView() {
  const g = content.sections.find(s => s.type === 'gallery');
  const p = g && (g.projects.find(x => x.id === g.quickAdd) || g.projects[0]);
  if (!p) return el('section', { class: 'card' }, el('h2', {}, 'Add new work'), el('p', { class: 'empty' }, 'Add a project to the Gallery first.'));
  const pub = el('button', { class: 'btn primary', onclick: save }, 'Publish to the website');
  return el('div', {},
    el('section', { class: 'card quick-card' },
      el('h2', {}, `Add new work to “${p.title}”`),
      el('p', { class: 'lead' }, 'Upload photos of a new job. They go to the front of the gallery, so they’re the first thing visitors see.'),
      el('ol', { class: 'steps' },
        el('li', {}, el('strong', {}, 'Add photos'), ' — click the button below, or drag photos onto this box.'),
        el('li', {}, el('strong', {}, 'Type a caption'), ' under each photo (for example “New kitchen, Mundaring”).'),
        el('li', {}, el('strong', {}, 'Publish'), ' — the website updates straight away.')),
      photosEditor(p.images, null, { prepend: true, big: true }),
      el('div', { class: 'quick-pub' }, pub)),
    el('p', { class: 'hint' }, `To reorder or remove older photos in “${p.title}”, use the arrows and ✕ above. Other projects are under Gallery in the list on the left.`));
}
function addSection() {
  const title = prompt('Name of the new section (for example “Testimonials”):');
  if (!title) return;
  const s = { id: uid(title.toLowerCase().replace(/[^a-z0-9]+/g, '-').slice(0, 24) || 'section'), type: 'page', title, menu: '', visible: true, layout: 'grid', body: '', images: [], videos: [] };
  const ci = content.sections.findIndex(x => x.type === 'contact');
  content.sections.splice(ci < 0 ? content.sections.length : ci, 0, s);
  sel = s.id; markDirty(); renderView();
}

function input(obj, key, label, opts = {}) {
  const tag = opts.multiline ? 'textarea' : 'input';
  const i = el(tag, { type: opts.type || 'text', value: obj[key] ?? '', placeholder: opts.ph || '', rows: opts.rows || 3,
    oninput: e => { obj[key] = e.target.value; markDirty(); opts.onchange?.(); } });
  if (opts.multiline) i.value = obj[key] ?? '';
  return el('label', { class: 'field' }, el('span', {}, label), i, opts.hint && el('small', {}, opts.hint));
}

function siteEditor() {
  const s = content.site;
  return el('div', {},
    el('section', { class: 'card' }, el('h2', {}, 'Business details'), el('p', { class: 'lead' }, 'Shown in the header, the contact section and the footer.'),
      el('div', { class: 'row' }, input(s, 'name', 'Business name'), input(s, 'credentials', 'Credentials', { hint: 'Small line under the name, e.g. AIA A+ Member' })),
      el('div', { class: 'row' }, input(s, 'mobile', 'Mobile', { type: 'tel', hint: 'Used for the “Call” buttons.' }), input(s, 'phone', 'Office phone', { type: 'tel', hint: 'Leave blank to hide.' })),
      el('div', { class: 'row' }, input(s, 'email', 'Email for enquiries', { type: 'email', hint: 'When filled in, a contact form appears on the website and sends enquiries here.' }), input(s, 'location', 'Location')),
      input(s, 'footer', 'Footer line')),
    el('section', { class: 'card' }, el('h2', {}, 'Welcome message'), el('p', { class: 'lead' }, 'The big headline and text at the top of the home page, above the gallery.'),
      input(s, 'tagline', 'Headline', { multiline: true, rows: 2 }),
      el('div', { class: 'field' }, el('span', {}, 'Welcome text'), rte(s, 'intro'))));
}

function sectionEditor(sec) {
  const settings = el('section', { class: 'card' },
    el('div', { class: 'card-head' }, el('h2', {}, sec.title || 'Section'), el('span', { class: 'hint' }, typeName[sec.type] + ' section')),
    el('label', { class: 'switch', style: 'margin-bottom:18px' }, el('input', { type: 'checkbox', checked: sec.visible, onchange: e => { sec.visible = e.target.checked; markDirty(); renderView(); } }), sec.visible ? 'Shown on the website' : 'Hidden from the website'),
    el('div', { class: 'row' },
      input(sec, 'title', 'Heading', { onchange: () => { const b = document.querySelector('.side-item.on .pick'); if (b) b.firstChild.textContent = sec.title || '(untitled)'; } }),
      input(sec, 'menu', 'Top menu label', { hint: 'Short word for the menu bar. Leave blank to keep it out of the menu.' })),
    sec.type === 'page' && el('label', { class: 'field' }, el('span', {}, 'Photo layout'),
      el('select', { onchange: e => { sec.layout = e.target.value; markDirty(); } },
        el('option', { value: 'grid', selected: sec.layout !== 'side' }, 'Photos in a grid under the text'),
        el('option', { value: 'side', selected: sec.layout === 'side' }, 'One photo beside the text (good for a portrait)'))),
    !['gallery', 'contact'].includes(sec.type) && el('button', { class: 'btn danger small', onclick: () => {
      if (!confirm(`Delete the “${sec.title}” section? (Tip: you can hide it instead.)`)) return;
      content.sections = content.sections.filter(x => x !== sec); sel = 'site'; markDirty(); renderView();
    } }, 'Delete this section'));

  const text = el('section', { class: 'card' }, el('h2', {}, sec.type === 'gallery' ? 'Extra text (optional)' : 'Text'),
    sec.type === 'gallery' && el('p', { class: 'lead' }, 'Appears under the welcome message. The welcome message itself is in Business details.'),
    sec.type === 'contact' && el('p', { class: 'lead' }, 'Phone numbers and email come from Business details.'),
    rte(sec, 'body'));

  const out = [settings, text];
  if (sec.type === 'gallery') {
    out.push(el('section', { class: 'card' }, el('h2', {}, 'Gallery buttons'),
      el('div', { class: 'row' },
        input(sec, 'allLabel', 'First button (shows every photo)', { hint: 'For example “Highlights”.' }),
        el('label', { class: 'field' }, el('span', {}, 'Photos across (computers and tablets)'),
          el('select', { onchange: e => { sec.across = +e.target.value; markDirty(); } },
            el('option', { value: '3', selected: sec.across !== 2 && sec.across !== 0 }, '3 across'),
            el('option', { value: '2', selected: sec.across === 2 }, '2 across (bigger photos)'),
            el('option', { value: '0', selected: sec.across === 0 }, 'Let visitors choose (best size picked automatically)')),
          el('small', {}, 'Phones show 2 across unless visitors can choose.')),
        el('label', { class: 'field' }, el('span', {}, '“Add new work” puts photos into'),
          el('select', { onchange: e => { sec.quickAdd = e.target.value; markDirty(); } },
            sec.projects.map(p => el('option', { value: p.id, selected: p.id === sec.quickAdd }, p.title || '(untitled)'))),
          el('small', {}, 'Usually “Recent work”.')))));
    out.push(projectsEditor(sec));
  }
  if (sec.type === 'page') {
    out.push(el('section', { class: 'card' }, el('h2', {}, 'Photos'), el('p', { class: 'lead' }, 'Click a photo’s caption to edit it. Visitors can click photos to enlarge them.'), photosEditor(sec.images)));
    out.push(videosEditor(sec));
  }
  return el('div', {}, out);
}

function projectsEditor(sec) {
  const wrap = el('section', { class: 'card' });
  const draw = (openId) => {
    fill(wrap, 
      el('div', { class: 'card-head' }, el('div', {}, el('h2', {}, 'Projects'), el('p', { class: 'lead', style: 'margin:4px 0 0' }, 'Each project becomes a filter button above the gallery.')),
        el('button', { class: 'btn small', onclick: () => {
          const p = { id: uid('project'), title: 'New project', text: '', chip: true, images: [] };
          sec.projects.unshift(p); markDirty(); draw(p.id);
        } }, '+ Add a project')),
      sec.projects.length ? null : el('p', { class: 'empty' }, 'No projects yet.'),
      sec.projects.map((p, i) => {
        const count = el('span', { class: 'count' }, p.images.length + ' photo' + (p.images.length === 1 ? '' : 's'));
        const sumTitle = el('span', {}, p.title || '(untitled)');
        return el('details', { class: 'project', open: p.id === openId },
          el('summary', {}, sumTitle, count),
          el('div', { class: 'body' },
            el('div', { class: 'ptools' },
              el('button', { class: 'btn small', onclick: () => { if (move(sec.projects, i, -1)) { markDirty(); draw(p.id); } } }, '↑ Move up'),
              el('button', { class: 'btn small', onclick: () => { if (move(sec.projects, i, 1)) { markDirty(); draw(p.id); } } }, '↓ Move down'),
              el('button', { class: 'btn small danger', onclick: () => { if (confirm(`Delete the project “${p.title}”? Its photos stay in the photo library.`)) { sec.projects.splice(i, 1); markDirty(); draw(); } } }, 'Delete project')),
            input(p, 'title', 'Project name', { onchange: () => sumTitle.textContent = p.title || '(untitled)' }),
            el('label', { class: 'switch', style: 'margin:-4px 0 16px' }, el('input', { type: 'checkbox', checked: p.chip !== false, onchange: e => { p.chip = e.target.checked; markDirty(); } }), 'Show a button for this project above the gallery'),
            input(p, 'text', 'Short description', { multiline: true, rows: 3, hint: 'Shown when visitors pick this project, and in the enlarged photo view.' }),
            el('div', { class: 'field' }, el('span', {}, 'Photos'), photosEditor(p.images, () => count.textContent = p.images.length + ' photo' + (p.images.length === 1 ? '' : 's')))));
      }));
  };
  draw(sec.projects[0]?.id);
  return wrap;
}

function photosEditor(list, onChange, opts = {}) {
  const box = el('div', { class: 'drop' + (opts.big ? ' big' : '') });
  const addSrc = src => opts.prepend ? list.unshift({ src, caption: '' }) : list.push({ src, caption: '' });
  const draw = () => {
    onChange?.();
    const grid = el('div', { class: 'photos' }, list.map((im, i) => el('div', { class: 'photo' },
      el('div', { class: 'thumb', role: 'img', 'aria-label': im.caption || 'Photo', style: `background-image:url("../${im.src.replace(/"/g, '')}")` }),
      el('input', { type: 'text', value: im.caption, placeholder: 'Add a caption…', 'aria-label': 'Caption', oninput: e => { im.caption = e.target.value; markDirty(); } }),
      el('div', { class: 'tools' },
        el('button', { title: 'Move left', 'aria-label': 'Move left', onclick: () => { if (move(list, i, -1)) { markDirty(); draw(); } } }, '←'),
        el('button', { title: 'Move right', 'aria-label': 'Move right', onclick: () => { if (move(list, i, 1)) { markDirty(); draw(); } } }, '→'),
        el('button', { class: 'del', title: 'Remove from here', 'aria-label': 'Remove photo', onclick: () => { list.splice(i, 1); markDirty(); draw(); } }, '✕')))));
    const status = el('span', { class: 'upl' });
    const upload = async files => {
      files = files.filter(f => /^image\//.test(f.type));
      if (!files.length) return;
      let n = 0;
      const added = [];
      for (const f of files) {
        status.textContent = `Uploading ${++n} of ${files.length}…`;
        const fd = new FormData(); fd.append('photo', f);
        try { const j = await api('upload', fd, true); added.push(j.src); markDirty(); }
        catch (e) { toast(`${f.name}: ${e.message}`, true); }
      }
      (opts.prepend ? added.reverse() : added).forEach(addSrc);
      status.textContent = ''; draw();
      if (added.length) { const c = box.querySelector('.photo input'); if (opts.prepend && c) c.focus(); }
    };
    box.ondragover = e => { e.preventDefault(); box.classList.add('over'); };
    box.ondragleave = () => box.classList.remove('over');
    box.ondrop = e => { e.preventDefault(); box.classList.remove('over'); upload([...e.dataTransfer.files]); };
    const file = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', multiple: true, hidden: true, onchange: () => { const f = [...file.files]; file.value = ''; upload(f); } });
    const actions = el('div', { class: 'photo-actions' },
        el('button', { class: opts.big ? 'btn primary' : 'btn small', onclick: () => file.click() }, opts.big ? '⬆ Add photos' : '⬆ Upload photos'),
        el('button', { class: 'btn small', onclick: () => pickFromLibrary(srcs => { (opts.prepend ? [...srcs].reverse() : srcs).forEach(addSrc); markDirty(); draw(); }) }, 'Choose from photo library'),
        status, file);
    if (opts.big) fill(box, actions, list.length ? grid : el('p', { class: 'empty' }, 'No photos yet.'));
    else fill(box, list.length ? grid : el('p', { class: 'empty' }, 'No photos yet.'), actions);
  };
  draw();
  return box;
}

async function pickFromLibrary(done) {
  let imgs;
  try { imgs = (await api('library')).images; } catch (e) { return toast(e.message, true); }
  const chosen = new Set();
  const add = el('button', { class: 'btn primary', disabled: true }, 'Add photos');
  const d = el('dialog', { class: 'modal' }, el('div', { class: 'modal-in' },
    el('h2', {}, 'Photo library'), el('p', { class: 'hint', style: 'margin:-6px 0 12px' }, 'Every photo on the website, newest uploads first. Click to select.'),
    el('div', { class: 'lib' }, imgs.map(src => el('button', { 'aria-pressed': 'false', 'aria-label': src, style: `background-image:url("../${src}")`, onclick: e => {
      const b = e.currentTarget; chosen.has(src) ? chosen.delete(src) : chosen.add(src);
      b.setAttribute('aria-pressed', String(chosen.has(src)));
      add.disabled = !chosen.size; add.textContent = chosen.size ? `Add ${chosen.size} photo${chosen.size > 1 ? 's' : ''}` : 'Add photos';
    } }))),
    el('div', { class: 'modal-actions' }, el('button', { class: 'btn', onclick: () => d.close() }, 'Cancel'), add)));
  add.onclick = () => { done([...chosen]); d.close(); };
  d.addEventListener('close', () => d.remove());
  document.body.append(d); d.showModal();
}

function videosEditor(sec) {
  sec.videos = sec.videos || [];
  const card = el('section', { class: 'card' });
  const draw = () => fill(card, 
    el('h2', {}, 'YouTube videos'), el('p', { class: 'lead' }, 'Paste a YouTube link. The video plays right on the page.'),
    sec.videos.map((v, i) => el('div', { class: 'video-row' },
      el('input', { type: 'text', value: v.title, placeholder: 'Title', 'aria-label': 'Video title', oninput: e => { v.title = e.target.value; markDirty(); } }),
      el('input', { type: 'url', value: v.url, placeholder: 'https://www.youtube.com/watch?v=…', 'aria-label': 'YouTube link', oninput: e => { v.url = e.target.value; markDirty(); } }),
      el('button', { class: 'btn small danger', onclick: () => { sec.videos.splice(i, 1); markDirty(); draw(); } }, 'Remove'))),
    el('button', { class: 'btn small', onclick: () => { sec.videos.push({ title: '', url: '' }); markDirty(); draw(); } }, '+ Add a video'));
  draw();
  return card;
}

/* ---------- simple rich-text editor ---------- */
function rte(obj, key) {
  const area = el('div', { class: 'rte-area', contentEditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'data-ph': 'Start typing…' });
  area.innerHTML = obj[key] || ''; // server-sanitised HTML from our own content file
  const sync = () => { obj[key] = area.innerHTML.replace(/<p><br><\/p>/g, '').trim(); markDirty(); };
  const cmd = (c, v) => { area.focus(); document.execCommand(c, false, v); sync(); };
  const b = (label, title, fn) => el('button', { type: 'button', title, 'aria-label': title, onmousedown: e => e.preventDefault(), onclick: fn }, label);
  const tools = el('div', { class: 'rte-tools' },
    b(el('b', {}, 'B'), 'Bold', () => cmd('bold')),
    b(el('i', {}, 'I'), 'Italic', () => cmd('italic')),
    el('span', { class: 'sep' }),
    b('Heading', 'Small heading', () => cmd('formatBlock', '<h3>')),
    b('Normal', 'Normal text', () => cmd('formatBlock', '<p>')),
    b('“ Quote', 'Quote', () => cmd('formatBlock', '<blockquote>')),
    el('span', { class: 'sep' }),
    b('• List', 'Bulleted list', () => cmd('insertUnorderedList')),
    b('1. List', 'Numbered list', () => cmd('insertOrderedList')),
    el('span', { class: 'sep' }),
    b('Link', 'Add a link', () => {
      const sel = getSelection();
      if (!sel || sel.isCollapsed) return toast('Select some text first, then click Link.', true);
      let url = prompt('Link to (web address, email or phone):', 'https://');
      if (!url) return;
      url = url.trim();
      if (/^[^@\s]+@[^@\s]+$/.test(url)) url = 'mailto:' + url;
      else if (/^[0-9 +()]+$/.test(url)) url = 'tel:' + url.replace(/\s/g, '');
      else if (!/^(https?:|mailto:|tel:)/i.test(url)) url = 'https://' + url;
      cmd('createLink', url);
    }),
    b('Unlink', 'Remove link', () => cmd('unlink')),
    b('Clear', 'Clear formatting', () => { cmd('removeFormat'); cmd('formatBlock', '<p>'); }));
  area.addEventListener('input', sync);
  area.addEventListener('paste', e => {
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, text);
  });
  area.addEventListener('focus', () => document.execCommand('defaultParagraphSeparator', false, 'p'));
  return el('div', { class: 'rte' }, tools, area);
}

/* ---------- admins ---------- */
async function adminsView(v) {
  let data;
  try { data = await api('users'); } catch (e) { return v.append(el('p', { class: 'err' }, e.message)); }
  const tbl = el('table', {}, el('thead', {}, el('tr', {}, el('th', {}, 'Name'), el('th', {}, 'Username'), el('th', {}, 'Last signed in'), el('th', {}, 'Added by'), el('th', {}, ''))),
    el('tbody', {}, data.users.map(u => el('tr', {},
      el('td', {}, u.name || '—', u.username === data.me && el('span', { class: 'you' }, 'you')),
      el('td', {}, u.username), el('td', {}, fmtDate(u.lastLogin)), el('td', {}, u.createdBy || '—'),
      el('td', { style: 'text-align:right' },
        u.username !== data.me && el('button', { class: 'btn small', onclick: async () => {
          const p = genPassword();
          const np = prompt(`New password for ${u.username} (at least 10 characters). A suggestion is filled in — copy it before clicking OK:`, p);
          if (!np) return;
          try { await api('set_password', { username: u.username, password: np }); toast('Password changed. Let ' + (u.name || u.username) + ' know the new one.', false, 8000); } catch (e) { toast(e.message, true); }
        } }, 'Reset password'),
        u.username !== data.me && el('button', { class: 'btn small danger', onclick: async () => {
          if (!confirm(`Remove ${u.name || u.username} as an admin? They will no longer be able to sign in.`)) return;
          try { await api('delete_user', { username: u.username }); toast('Admin removed.'); renderView(); } catch (e) { toast(e.message, true); }
        } }, 'Remove'))))));

  const err = el('p', { class: 'err' });
  const pw = el('input', { type: 'text', name: 'password', required: true, minLength: 10, autocomplete: 'new-password', value: genPassword() });
  const addForm = el('form', { onsubmit: async e => {
      e.preventDefault(); err.textContent = '';
      const f = e.target;
      try {
        await api('add_user', { name: f.fullname.value, username: f.username.value, password: f.password.value });
        toast(`Admin added. Give ${f.username.value} their password: ${f.password.value}`, false, 15000);
        renderView();
      } catch (x) { err.textContent = x.message; }
    } },
    el('div', { class: 'row' },
      el('label', { class: 'field' }, el('span', {}, 'Their name'), el('input', { type: 'text', name: 'fullname', required: true })),
      el('label', { class: 'field' }, el('span', {}, 'Username'), el('input', { type: 'text', name: 'username', required: true, autocomplete: 'off' }), el('small', {}, 'They’ll use this to sign in. An email address works.'))),
    el('label', { class: 'field' }, el('span', {}, 'Starting password'), el('div', { style: 'display:flex;gap:8px' }, pw, el('button', { type: 'button', class: 'btn small', onclick: () => pw.value = genPassword() }, 'New suggestion')),
      el('small', {}, 'Copy this and send it to them. They can change it after signing in.')),
    err, el('button', { class: 'btn primary', type: 'submit' }, 'Add admin'));

  const myErr = el('p', { class: 'err' });
  const myForm = el('form', { onsubmit: async e => {
      e.preventDefault(); myErr.textContent = '';
      const f = e.target;
      if (f.password.value !== f.password2.value) { myErr.textContent = 'The new passwords don’t match.'; return; }
      try { await api('set_password', { username: data.me, current: f.current.value, password: f.password.value }); f.reset(); toast('Your password has been changed.'); }
      catch (x) { myErr.textContent = x.message; }
    } },
    el('label', { class: 'field' }, el('span', {}, 'Current password'), el('input', { type: 'password', name: 'current', required: true, autocomplete: 'current-password' })),
    el('div', { class: 'row' },
      el('label', { class: 'field' }, el('span', {}, 'New password'), el('input', { type: 'password', name: 'password', required: true, minLength: 10, autocomplete: 'new-password' }), el('small', {}, 'At least 10 characters.')),
      el('label', { class: 'field' }, el('span', {}, 'New password again'), el('input', { type: 'password', name: 'password2', required: true, autocomplete: 'new-password' }))),
    myErr, el('button', { class: 'btn', type: 'submit' }, 'Change my password'));

  v.append(
    el('section', { class: 'card' }, el('h2', {}, 'Admins'), el('p', { class: 'lead' }, 'Everyone here can edit the website and manage admins.'), tbl),
    el('section', { class: 'card' }, el('h2', {}, 'Add an admin'), addForm),
    el('section', { class: 'card' }, el('h2', {}, 'My password'), myForm));
}

/* ---------- history ---------- */
async function historyView(v) {
  let list;
  try { list = (await api('backups')).backups; } catch (e) { return v.append(el('p', { class: 'err' }, e.message)); }
  v.append(el('section', { class: 'card' }, el('h2', {}, 'History'),
    el('p', { class: 'lead' }, 'A copy is kept every time someone publishes (the last 40). Restoring puts the website back exactly as it was at that time.'),
    list.length ? el('table', {}, el('thead', {}, el('tr', {}, el('th', {}, 'Version from'), el('th', {}, 'Replaced by'), el('th', {}, ''))),
      el('tbody', {}, list.map(b => el('tr', {}, el('td', {}, fmtDate(b.at)), el('td', {}, b.by || '—'),
        el('td', { style: 'text-align:right' }, el('button', { class: 'btn small', onclick: async () => {
          if (dirty && !confirm('This will discard your unpublished changes. Continue?')) return;
          if (!confirm('Restore the website to the version from ' + fmtDate(b.at) + '?')) return;
          try { const j = await api('restore', { file: b.file }); content = j.content; version = j.version; dirty = false; toast('Restored and published.'); tab = 'edit'; render(); }
          catch (e) { toast(e.message, true); }
        } }, 'Restore')))))) : el('p', { class: 'empty' }, 'No earlier versions yet. They appear after the first publish.')));
}

boot();
})();
