const reduceMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

export function initCountdown() {
  const root = document.querySelector<HTMLElement>('[data-countdown]');
  if (!root) return;
  const target = Date.parse(root.dataset.countdown!);
  const d = root.querySelector('[data-d]')!, h = root.querySelector('[data-h]')!, m = root.querySelector('[data-m]')!;
  const tick = () => {
    const left = Math.max(0, target - Date.now());
    d.textContent = String(Math.floor(left / 86400000));
    h.textContent = String(Math.floor((left % 86400000) / 3600000));
    m.textContent = String(Math.floor((left % 3600000) / 60000));
    if (left === 0) {
      root.querySelector('.countdown-label')!.textContent = 'It’s election day. Go out and vote!';
      return;
    }
    setTimeout(tick, 60000 - (Date.now() % 60000) + 50);
  };
  tick();
}

export function initCounters() {
  const els = document.querySelectorAll<HTMLElement>('[data-count]');
  if (!els.length || reduceMotion() || !('IntersectionObserver' in window)) return;
  const fmt = new Intl.NumberFormat('en-NG');
  const run = (el: HTMLElement) => {
    const end = Number(el.dataset.count);
    const start = performance.now();
    const dur = 1400;
    const step = (now: number) => {
      const p = Math.min(1, (now - start) / dur);
      el.textContent = fmt.format(Math.round(end * (1 - Math.pow(1 - p, 3))));
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };
  const io = new IntersectionObserver((entries) => {
    for (const e of entries) if (e.isIntersecting) { run(e.target as HTMLElement); io.unobserve(e.target); }
  }, { threshold: 0.4 });
  els.forEach((el) => { el.textContent = '0'; io.observe(el); });
}

function makeVideo(src: string, poster?: string, track?: string, label = 'Video') {
  const v = document.createElement('video');
  v.controls = true;
  v.playsInline = true;
  v.preload = 'metadata';
  v.setAttribute('aria-label', label);
  if (poster) v.poster = poster;
  const s = document.createElement('source');
  s.src = src; s.type = 'video/mp4';
  v.append(s);
  if (track) {
    const t = document.createElement('track');
    t.kind = 'captions'; t.srclang = 'en'; t.label = 'English (translation)'; t.src = track; t.default = true;
    v.append(t);
  }
  return v;
}

// Videos are never downloaded until someone taps play.
export function initVideos() {
  document.querySelectorAll<HTMLElement>('[data-video][data-src]').forEach((frame) => {
    frame.querySelector('.video-play')?.addEventListener('click', () => {
      const v = makeVideo(frame.dataset.src!, frame.dataset.poster, frame.dataset.track, frame.dataset.title);
      frame.replaceChildren(v);
      v.play().catch(() => {});
      v.focus();
    });
  });
}

const videoSources: Record<string, { src: string; poster: string; track?: string }> = {
  'bbc-igbo-interview': { src: '/media/video/bbc-igbo-interview.mp4', poster: '/media/video/bbc-igbo-interview-poster.jpg', track: '/media/video/bbc-igbo-interview.en.vtt' },
  'interview-2022': { src: '/media/video/interview-2022.mp4', poster: '/media/video/interview-2022-poster.jpg' },
};

export function initGallery() {
  const dialog = document.querySelector<HTMLDialogElement>('[data-lightbox]');
  const items = [...document.querySelectorAll<HTMLButtonElement>('[data-gallery] .g-item')];
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const stage = dialog.querySelector<HTMLElement>('[data-stage]')!;
  const caption = dialog.querySelector<HTMLElement>('[data-caption]')!;
  let index = 0;
  let opener: HTMLElement | null = null;

  const show = (i: number) => {
    index = (i + items.length) % items.length;
    const it = items[index];
    stage.replaceChildren();
    if (it.dataset.video) {
      const v = videoSources[it.dataset.video];
      const el = makeVideo(v.src, v.poster, v.track, it.dataset.caption);
      stage.append(el);
      el.play().catch(() => {});
    } else {
      const pic = document.createElement('picture');
      const s = document.createElement('source');
      s.type = 'image/webp'; s.srcset = it.dataset.fullWebp!;
      const img = document.createElement('img');
      img.src = it.dataset.full!;
      img.alt = it.querySelector('img')?.alt || '';
      pic.append(s, img);
      stage.append(pic);
    }
    caption.textContent = `${it.dataset.caption}  ·  ${index + 1} of ${items.length}`;
  };
  const open = (i: number, from: HTMLElement | null) => { opener = from; show(i); dialog.showModal(); };
  const close = () => dialog.close();

  items.forEach((it, i) => it.addEventListener('click', () => open(i, it)));
  dialog.querySelector('[data-close]')!.addEventListener('click', close);
  dialog.querySelector('[data-prev]')!.addEventListener('click', () => show(index - 1));
  dialog.querySelector('[data-next]')!.addEventListener('click', () => show(index + 1));
  dialog.addEventListener('click', (e) => { if (e.target === dialog || e.target === stage) close(); });
  dialog.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') show(index - 1);
    if (e.key === 'ArrowRight') show(index + 1);
  });
  dialog.addEventListener('close', () => { stage.replaceChildren(); opener?.focus(); });

  // "Play" buttons elsewhere on the page open the matching gallery video.
  document.querySelectorAll<HTMLElement>('[data-open-video]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const i = items.findIndex((it) => it.dataset.video === btn.dataset.openVideo);
      if (i >= 0) open(i, btn);
    });
  });

  let x0: number | null = null;
  stage.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
  stage.addEventListener('touchend', (e) => {
    if (x0 === null) return;
    const dx = e.changedTouches[0].clientX - x0;
    if (Math.abs(dx) > 50 && !items[index].dataset.video) show(index + (dx < 0 ? 1 : -1));
    x0 = null;
  });
}

type NewsItem = { date?: string; title: string; summary?: string; link?: string };

// News lives in /news.json so it can be edited in the hosting file manager without a rebuild.
export async function initNews() {
  const list = document.querySelector<HTMLElement>('[data-news]');
  if (!list) return;
  const render = (items: NewsItem[]) => {
    list.replaceChildren();
    if (!items.length) {
      const li = document.createElement('li');
      li.innerHTML = '<p>News and events will appear here soon.</p>';
      list.append(li);
      return;
    }
    const fmt = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    for (const n of items) {
      const li = document.createElement('li');
      if (n.date) {
        const t = document.createElement('time');
        t.dateTime = n.date;
        const d = new Date(n.date + 'T12:00:00');
        t.textContent = isNaN(d.valueOf()) ? n.date : fmt.format(d);
        li.append(t);
      }
      const div = document.createElement('div');
      const h = document.createElement('h3');
      if (n.link) {
        const a = document.createElement('a');
        a.href = n.link; a.textContent = n.title;
        if (/^https?:/.test(n.link)) { a.target = '_blank'; a.rel = 'noopener'; }
        h.append(a);
      } else h.textContent = n.title;
      div.append(h);
      if (n.summary) { const p = document.createElement('p'); p.textContent = n.summary; div.append(p); }
      li.append(div);
      list.append(li);
    }
  };
  try {
    const res = await fetch('/news.json', { cache: 'no-cache' });
    const data = await res.json();
    const items: NewsItem[] = (Array.isArray(data) ? data : data.items || []).filter((n: NewsItem) => n && n.title);
    items.sort((a, b) => (b.date || '').localeCompare(a.date || ''));
    render(items);
  } catch {
    render([]);
  }
}

export function initForm() {
  const form = document.querySelector<HTMLFormElement>('[data-form]');
  if (!form) return;
  const status = form.querySelector<HTMLElement>('[data-status]')!;
  const started = form.querySelector<HTMLInputElement>('[data-started]')!;
  started.value = String(Date.now());
  const submit = form.querySelector<HTMLButtonElement>('button[type=submit]')!;

  const say = (msg: string, ok: boolean) => {
    status.hidden = false;
    status.className = `form-status ${ok ? 'ok' : 'err'}`;
    status.textContent = msg;
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const invalid = [...form.querySelectorAll<HTMLInputElement | HTMLSelectElement>('[required]')].find((el) =>
      el instanceof HTMLInputElement && el.type === 'checkbox' ? !el.checked : !el.value.trim());
    if (invalid) {
      const label = invalid.id === 'f-consent' ? 'Please tick the consent box to continue.' : `Please fill in: ${form.querySelector(`label[for="${invalid.id}"]`)?.firstChild?.textContent?.trim()}.`;
      say(label, false);
      invalid.focus();
      return;
    }
    const phone = (form.elements.namedItem('phone') as HTMLInputElement).value.replace(/[\s-]/g, '');
    if (!/^(\+?234|0)[789][01]\d{8}$/.test(phone)) {
      say('Please enter a valid Nigerian phone number, for example 0803 000 0000.', false);
      (form.elements.namedItem('phone') as HTMLInputElement).focus();
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Sending…';
    try {
      const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.ok) {
        form.reset();
        started.value = String(Date.now());
        say('Thank you! You’re in. The campaign team in your LGA will contact you soon.', true);
      } else {
        say(data.message || 'Sorry, something went wrong. Please try again in a few minutes.', false);
      }
    } catch {
      say('We could not reach the server. Please check your connection and try again.', false);
    } finally {
      submit.disabled = false;
      submit.textContent = 'Sign me up';
    }
  });
}
