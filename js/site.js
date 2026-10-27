(() => {
  const $ = (s, el = document) => el.querySelector(s);

  /* Home: always start at the top with the full gallery */
  if ('scrollRestoration' in history && !location.hash) { history.scrollRestoration = 'manual'; scrollTo(0, 0); }
  document.querySelectorAll('[data-home]').forEach(a => a.addEventListener('click', e => {
    e.preventDefault();
    location.href = location.href.split('#')[0];
  }));
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));

  /* header shadow + active menu link */
  const top = $('.top');
  const onScroll = () => top && top.classList.toggle('scrolled', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true }); onScroll();
  const links = $$('.menu a');
  if ('IntersectionObserver' in window && links.length) {
    const io = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (!e.isIntersecting) return;
        links.forEach(a => a.classList.toggle('active', a.hasAttribute('data-home') ? e.target.classList.contains('gallery') : a.getAttribute('href') === '#' + e.target.id));
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    links.forEach(a => { const t = a.hasAttribute('data-home') ? $('.gallery') : document.getElementById(a.getAttribute('href').slice(1)); t && io.observe(t); });
  }

  /* gallery filter */
  const chips = $$('.chip');
  chips.forEach(chip => chip.addEventListener('click', () => {
    const f = chip.dataset.filter;
    chips.forEach(c => c.setAttribute('aria-pressed', String(c === chip)));
    $$('.tile').forEach(t => { t.hidden = f !== 'all' && t.dataset.project !== f; });
    $$('.note').forEach(n => { n.hidden = n.dataset.note !== f; });
    chip.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    const g = $('.chips'); if (g && g.getBoundingClientRect().top < 0) g.scrollIntoView({ block: 'start', behavior: 'smooth' });
  }));

  /* photos per row: best size picked for the screen, visitors can change it */
  const grid = $('.masonry.auto'), sizer = $('.sizer');
  if (grid && sizer) {
    const best = () => innerWidth <= 720 ? 2 : 3;
    const max = () => innerWidth <= 720 ? 3 : 4;
    let saved = null;
    try { saved = +localStorage.getItem('pb-cols') || null; } catch {}
    const apply = () => {
      const n = Math.min(saved || best(), max());
      grid.dataset.cols = n;
      $$('button', sizer).forEach(b => b.setAttribute('aria-pressed', String(+b.dataset.cols === n)));
    };
    $$('button', sizer).forEach(b => b.addEventListener('click', () => {
      saved = +b.dataset.cols;
      try { localStorage.setItem('pb-cols', saved); } catch {}
      apply();
    }));
    addEventListener('resize', apply);
    apply();
  }

  /* lightbox */
  const box = $('#lightbox');
  if (box && box.showModal) {
    const img = $('img', box), title = $('figcaption strong', box), cap = $('figcaption span', box);
    let group = [], idx = 0;
    const show = i => {
      idx = (i + group.length) % group.length;
      const a = group[idx];
      img.src = a.getAttribute('href');
      img.alt = a.dataset.caption || a.dataset.title || '';
      title.textContent = a.dataset.title || '';
      const bits = [a.dataset.caption, a.dataset.caption ? '' : a.dataset.text].filter(Boolean);
      cap.textContent = bits.join(' ');
      $('.lb-prev', box).hidden = $('.lb-next', box).hidden = group.length < 2;
    };
    document.addEventListener('click', e => {
      const a = e.target.closest('.tile a, .pic a');
      if (!a) return;
      e.preventDefault();
      const scope = a.closest('.masonry, .media');
      group = $$('a', scope).filter(x => !x.closest('[hidden]') && x.closest('.tile, .pic'));
      show(group.indexOf(a));
      box.showModal();
    });
    $('.lb-close', box).onclick = () => box.close();
    $('.lb-prev', box).onclick = () => show(idx - 1);
    $('.lb-next', box).onclick = () => show(idx + 1);
    box.addEventListener('click', e => { if (e.target === box || e.target.tagName === 'FIGURE') box.close(); });
    box.addEventListener('keydown', e => {
      if (e.key === 'ArrowLeft') show(idx - 1);
      if (e.key === 'ArrowRight') show(idx + 1);
    });
    let x0 = null;
    box.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', e => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 50) show(idx + (dx < 0 ? 1 : -1));
    });
  }

  /* YouTube: load the player only when clicked */
  $$('.yt').forEach(btn => btn.addEventListener('click', () => {
    const f = document.createElement('iframe');
    f.src = `https://www.youtube-nocookie.com/embed/${btn.dataset.yt}?autoplay=1`;
    f.allow = 'autoplay; encrypted-media; picture-in-picture'; f.allowFullscreen = true;
    f.title = btn.getAttribute('aria-label') || 'Video';
    btn.replaceWith(f);
  }));

  /* enquiry form */
  const form = $('.enquiry');
  if (form) {
    const started = Date.now();
    form.addEventListener('submit', async e => {
      e.preventDefault();
      if (!form.reportValidity()) return;
      const status = $('.form-status', form), btn = $('button', form);
      form.elements.t.value = String(Math.round((Date.now() - started) / 1000));
      btn.disabled = true; status.textContent = 'Sending…';
      try {
        const r = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || 'failed');
        form.reset(); status.textContent = 'Thanks, your enquiry has been sent. Peter will be in touch.';
      } catch (err) {
        status.textContent = 'Sorry, the message could not be sent. Please call ' + ($('.big-call')?.textContent || 'Peter') + '.';
      } finally { btn.disabled = false; }
    });
  }
})();
