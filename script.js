(function(){
  const root = document.documentElement;
  const emitLangChange = (value) => {
    document.dispatchEvent(new CustomEvent('site:lang-change', { detail: value }));
  };
  const lang = localStorage.getItem('lang') || 'de';
  root.setAttribute('data-lang', lang);
  emitLangChange(lang);
  document.querySelectorAll('[data-lang-btn]').forEach(btn=>{
    btn.classList.toggle('active', btn.getAttribute('data-lang-btn')===lang);
    btn.addEventListener('click', ()=>{
      const to = btn.getAttribute('data-lang-btn');
      root.setAttribute('data-lang', to);
      localStorage.setItem('lang', to);
      document.querySelectorAll('[data-lang-btn]').forEach(b=>b.classList.toggle('active', b===btn));
      emitLangChange(to);
    });
  });
  // smooth anchors
  document.addEventListener('click', (e)=>{
    const a = e.target.closest('a[href^="#"]'); if(!a) return;
    const id = a.getAttribute('href').slice(1); const el = document.getElementById(id);
    if (el){ e.preventDefault(); el.scrollIntoView({behavior:'smooth', block:'start'}); }
  });
  document.getElementById('year').textContent = new Date().getFullYear();
})();

// Hero slider
(function(){
  const track = document.querySelector('[data-hero-track]');
  if (!track) return;
  const slides = Array.from(track.children);
  if (!slides.length) return;
  const prefersReduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (prefersReduced) return;

  const firstClone = slides[0].cloneNode(true);
  track.appendChild(firstClone);

  let index = 0;
  const intervalMs = 20000;

  const setTransform = (value, withTransition = true) => {
    track.style.transition = withTransition ? 'transform 1s ease' : 'none';
    track.style.transform = `translateX(-${value}%)`;
  };

  const advance = () => {
    index += 1;
    setTransform(index * 100, true);
  };

  track.addEventListener('transitionend', () => {
    if (index === slides.length) {
      index = 0;
      requestAnimationFrame(() => {
        setTransform(0, false);
      });
    }
  });

  setInterval(advance, intervalMs);
})();

// Enhanced navigation interactions
(function(){
  const header = document.querySelector('.header');
  const navToggle = document.querySelector('.nav-toggle');
  const root = document.documentElement;
  const navLinks = Array.from(document.querySelectorAll('.main-nav a[href^="#"]'));
  const scrollbar = document.getElementById('scrollbar');
  const indicator = document.querySelector('[data-section-indicator]');
  const indicatorDefault = indicator?.dataset.defaultLabel || indicator?.textContent.trim() || '';
  let currentSectionId = navLinks[0]?.getAttribute('href') || '';

  const updateIndicator = () => {
    if (!indicator) return;
    if (!currentSectionId) {
      indicator.textContent = indicatorDefault;
      return;
    }
    const lang = root.getAttribute('data-lang') || 'de';
    const matches = navLinks.filter(link => link.getAttribute('href') === currentSectionId);
    const target = matches.find(link => link.classList.contains(`i18n-${lang}`)) || matches[0];
    indicator.textContent = target?.textContent.trim() || indicatorDefault;
  };

  // Mobile toggle
  if(navToggle){
    navToggle.addEventListener('click', ()=>{
      root.classList.toggle('nav-open');
      const open = root.classList.contains('nav-open');
      navToggle.setAttribute('aria-expanded', open);
    });
    navLinks.forEach(l=>l.addEventListener('click', ()=>{ root.classList.remove('nav-open'); navToggle.setAttribute('aria-expanded','false'); }));
  }

  // Scroll progress + header state
  const onScroll = () => {
    const sc = window.scrollY;
    const max = document.documentElement.scrollHeight - innerHeight;
    if(scrollbar) scrollbar.style.width = (sc / max * 100) + '%';
    header.classList.toggle('scrolled', sc>10);
  };
  addEventListener('scroll', onScroll, {passive:true});
  onScroll();

  // Active section highlighting
  const sections = document.querySelectorAll('section[id]');
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if(entry.isIntersecting){
        const id = '#' + entry.target.id;
        navLinks.forEach(a=>a.classList.toggle('is-active', a.getAttribute('href')===id));
        currentSectionId = id;
        updateIndicator();
      }
    });
  }, { rootMargin: '-50% 0px -45% 0px', threshold: 0 });
  sections.forEach(s=>observer.observe(s));

  updateIndicator();
  document.addEventListener('site:lang-change', updateIndicator);
})();

// Menu & Hofladen gallery carousels
(function(){
  const initCarousel = ({
    carouselSelector,
    trackSelector,
    prevSelector,
    nextSelector,
    paginationSelector,
    emptySelector,
    manifestUrl,
    basePath = '',
    slideClass,
    altPrefix,
    loadingText,
    emptyText,
    errorText
  }) => {
    const track = document.querySelector(trackSelector);
    const carousel = document.querySelector(carouselSelector);
    if (!track || !carousel) return;
    const prevButton = document.querySelector(prevSelector);
    const nextButton = document.querySelector(nextSelector);
    const pagination = document.querySelector(paginationSelector);
    const emptyState = document.querySelector(emptySelector);
    let slides = [];
    let index = 0;
    let pointerActive = false;
    let pointerStartX = 0;
    let pointerDeltaX = 0;
    const pointerThreshold = 35;

    const normalizeX = (event) => {
      if (typeof event.clientX === 'number') return event.clientX;
      if (event.touches && event.touches[0]) return event.touches[0].clientX;
      return 0;
    };

    const showEmpty = (visible, text) => {
      if (!emptyState) return;
      if (typeof text === 'string' && text.length) emptyState.textContent = text;
      emptyState.hidden = !visible;
    };

    const updateControls = () => {
      const multiple = slides.length > 1;
      [prevButton, nextButton].forEach(btn => {
        if (!btn) return;
        btn.disabled = !multiple;
        btn.tabIndex = multiple ? 0 : -1;
        btn.setAttribute('aria-hidden', multiple ? 'false' : 'true');
        btn.style.visibility = multiple ? '' : 'hidden';
      });
      if (pagination) {
        pagination.hidden = slides.length <= 1;
      }
    };

    const updatePagination = () => {
      if (!pagination) return;
      pagination.querySelectorAll('button').forEach((dot, idx) => {
        dot.classList.toggle('is-active', idx === index);
      });
    };

    const setActive = (nextIndex) => {
      if (!slides.length) return;
      index = (nextIndex + slides.length) % slides.length;
      track.style.transform = `translateX(-${index * 100}%)`;
      updatePagination();
    };

    const toSrc = (file) => {
      if (!file) return '';
      if (/^(https?:)?\/\//.test(file) || file.startsWith('/')) return file;
      if (file.startsWith('img/')) return file;
      return `${basePath}${file}`;
    };

    const createSlide = (src, idx) => {
      const slide = document.createElement('figure');
      slide.className = slideClass;
      slide.innerHTML = `<img src="${src}" alt="${altPrefix} ${idx + 1}" loading="lazy" />`;
      return slide;
    };

    const renderSlides = () => {
      track.querySelectorAll(`.${slideClass}`).forEach(el => el.remove());
      slides.forEach((src, idx) => {
        track.appendChild(createSlide(src, idx));
      });
    };

    const renderPagination = () => {
      if (!pagination) return;
      pagination.innerHTML = '';
      slides.forEach((_, idx) => {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.setAttribute('aria-label', `${altPrefix} ${idx + 1}`);
        dot.addEventListener('click', () => setActive(idx));
        pagination.appendChild(dot);
      });
      updatePagination();
    };

    const applyData = (list) => {
      const files = Array.isArray(list) ? list.filter(item => typeof item === 'string' && item.trim()) : [];
      slides = files.map(toSrc).filter(Boolean);
      renderSlides();
      renderPagination();
      updateControls();
      if (slides.length) {
        showEmpty(false);
        setActive(0);
      } else {
        track.style.transform = 'translateX(0)';
        showEmpty(true, emptyText);
      }
    };

    const onPrev = () => setActive(index - 1);
    const onNext = () => setActive(index + 1);

    if (prevButton) prevButton.addEventListener('click', onPrev);
    if (nextButton) nextButton.addEventListener('click', onNext);

    const endPointer = () => {
      if (!pointerActive) return;
      pointerActive = false;
      if (Math.abs(pointerDeltaX) > pointerThreshold) {
        if (pointerDeltaX < 0) {
          onNext();
        } else {
          onPrev();
        }
      }
      pointerDeltaX = 0;
    };

    carousel.addEventListener('pointerdown', (event) => {
      if (!slides.length) return;
      pointerActive = true;
      pointerStartX = normalizeX(event);
      pointerDeltaX = 0;
    });

    carousel.addEventListener('pointermove', (event) => {
      if (!pointerActive) return;
      pointerDeltaX = normalizeX(event) - pointerStartX;
    });

    ['pointerup', 'pointerleave', 'pointercancel'].forEach(evt => {
      carousel.addEventListener(evt, endPointer);
    });

    showEmpty(true, loadingText);
    updateControls();

    fetch(manifestUrl)
      .then(res => (res.ok ? res.json() : Promise.reject(new Error('Manifest not found'))))
      .then(applyData)
      .catch(() => {
        slides = [];
        renderSlides();
        if (pagination) pagination.innerHTML = '';
        updateControls();
        track.style.transform = 'translateX(0)';
        showEmpty(true, errorText);
      });
  };

  initCarousel({
    carouselSelector: '[data-menu-carousel]',
    trackSelector: '[data-menu-track]',
    prevSelector: '[data-menu-prev]',
    nextSelector: '[data-menu-next]',
    paginationSelector: '[data-menu-pagination]',
    emptySelector: '[data-menu-empty]',
    manifestUrl: 'img/menu/manifest.json',
    basePath: 'img/menu/',
    slideClass: 'menu-slide',
    altPrefix: 'Speisekarte Foto',
    loadingText: 'Speisekarten-Fotos werden geladen...',
    emptyText: 'Speisekarten-Fotos folgen in Kürze.',
    errorText: 'Speisekarten-Fotos konnten nicht geladen werden.'
  });

  initCarousel({
    carouselSelector: '[data-hof-carousel]',
    trackSelector: '[data-hof-track]',
    prevSelector: '[data-hof-prev]',
    nextSelector: '[data-hof-next]',
    paginationSelector: '[data-hof-pagination]',
    emptySelector: '[data-hof-empty]',
    manifestUrl: 'img/hofladen/manifest.json',
    basePath: 'img/hofladen/',
    slideClass: 'hof-slide',
    altPrefix: 'Hofladen Foto',
    loadingText: 'Hofladen-Fotos werden geladen...',
    emptyText: 'Hofladen-Fotos folgen in Kürze.',
    errorText: 'Hofladen-Fotos konnten nicht geladen werden.'
  });
})();

// Events carousel + notification badge
(function(){
  const track = document.querySelector('[data-events-track]');
  if(!track) return;

  const manifestUrl = 'img/news/manifest.json';
  const pagination = document.querySelector('[data-events-pagination]');
  const badgeEls = document.querySelectorAll('[data-events-badge]');
  const paginationBadge = document.querySelector('[data-events-pagination-badge]');
  const emptyState = document.querySelector('[data-events-empty]');
  const carousel = document.querySelector('[data-events-carousel]');
  const prevButton = document.querySelector('[data-events-prev]');
  const nextButton = document.querySelector('[data-events-next]');
  const storageKey = 'events_history';
  const baseIntroSlide = {
    isIntro: true,
    titleDe: 'Events & Termine',
    titleEn: 'Events & Happenings',
    textDe: 'Aktuelle Impressionen vom Hof: neue Termine, Märkte und besondere Frühstücke.',
    textEn: 'Fresh impressions from the farm: new dates, markets, and special brunch moments.'
  };
  let slides = [];
  let current = 0;
  let history = {};
  let carouselVisible = false;

  const makeIntroSlide = (overrides = {}) => ({ ...baseIntroSlide, ...overrides });
  const isTrackable = (slide) => Boolean(slide && !slide.isIntro && slide.name);
  const showEmptyState = (text = '', visible = false) => {
    if (!emptyState) return;
    emptyState.textContent = text;
    emptyState.hidden = !visible;
  };

  try {
    history = JSON.parse(localStorage.getItem(storageKey)) || {};
  } catch (err) {
    history = {};
  }

  const updateBadge = () => {
    const unseen = slides.filter(isTrackable).filter(item => !history[item.name]).length;
    badgeEls.forEach(badge => {
      badge.setAttribute('data-count', unseen);
      badge.textContent = unseen;
    });
    if (paginationBadge) {
      paginationBadge.setAttribute('data-count', unseen);
      paginationBadge.textContent = unseen ? `+${unseen}` : '';
    }
  };

  updateBadge();

  const persistHistory = () => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(history));
    } catch (err) {
      // ignore storage failures
    }
  };

  const markSeen = (slide) => {
    if (!isTrackable(slide) || history[slide.name]) return;
    history[slide.name] = Date.now();
    persistHistory();
    updateBadge();
  };

  const pruneHistory = (validNames) => {
    const next = {};
    let changed = false;
    Object.keys(history).forEach(key => {
      if (validNames.includes(key)) {
        next[key] = history[key];
      } else {
        changed = true;
      }
    });
    if (changed) {
      history = next;
      persistHistory();
    }
  };

  const updatePagination = () => {
    if (!pagination) return;
    pagination.querySelectorAll('button').forEach((btn, index) => {
      btn.classList.toggle('is-active', index === current);
    });
  };

  const setActive = (index) => {
    if (!slides.length) return;
    current = (index + slides.length) % slides.length;
    track.style.transform = `translateX(-${current * 100}%)`;
    updatePagination();
    markCurrentIfVisible();
  };

  const markCurrentIfVisible = () => {
    if (!carouselVisible || !slides[current]) return;
    markSeen(slides[current]);
  };

  const renderPagination = () => {
    if (!pagination) return;
    pagination.innerHTML = '';
    slides.forEach((slide, index) => {
      const dot = document.createElement('button');
      dot.type = 'button';
      dot.setAttribute('aria-label', `Event ${index + 1}`);
      dot.addEventListener('click', () => setActive(index));
      pagination.appendChild(dot);
    });
  };

  const createSlide = (event) => {
    const slide = document.createElement('article');
    slide.className = 'event-slide';
    if (event.isIntro) {
      slide.classList.add('event-slide--intro');
      slide.innerHTML = `
        <div class="event-slide__intro">
          <p class="event-slide__intro-heading i18n-de">${event.titleDe}</p>
          <p class="event-slide__intro-heading i18n-en">${event.titleEn}</p>
          <p class="event-slide__intro-copy i18n-de">${event.textDe}</p>
          <p class="event-slide__intro-copy i18n-en">${event.textEn}</p>
        </div>
      `;
      return slide;
    }
    slide.innerHTML = `
      <img src="${event.src}" alt="${event.title}" loading="lazy" />
    `;
    return slide;
  };

  const buildSlides = () => {
    track.innerHTML = '';
    slides.forEach(data => {
      track.appendChild(createSlide(data));
    });
  };

  const renderIntroOnly = (options = {}) => {
    slides = [makeIntroSlide(options)];
    buildSlides();
    renderPagination();
    updateBadge();
    setActive(0);
    showEmptyState('', false);
  };

  const parseFromName = (fileName) => {
    const base = fileName.replace(/^.*\//, '');
    const withoutExt = base.replace(/\.[^.]+$/, '');
    const parts = withoutExt.split('_');
    const rawDate = parts.shift() || '';
    parts.shift(); // remove counter
    const title = (parts.join(' ') || 'Event').replace(/-/g, ' ');
    let dateDe = 'Termin folgt';
    let dateEn = 'Date tbd';
    if (rawDate.length === 8) {
      const y = rawDate.slice(0, 4);
      const m = rawDate.slice(4, 6);
      const d = rawDate.slice(6, 8);
      const date = new Date(`${y}-${m}-${d}T00:00:00`);
      if (!Number.isNaN(date.getTime())) {
        dateDe = date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
        dateEn = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
      }
    }
    return {
      name: base,
      title,
      dateDe,
      dateEn,
      src: `img/news/${base}`
    };
  };

  const applyData = (list) => {
    const files = Array.isArray(list) ? list : [];
    pruneHistory(files);
    const intro = makeIntroSlide();
    slides = [intro, ...files.map(parseFromName)];
    buildSlides();
    renderPagination();
    updateBadge();
    setActive(0);
    showEmptyState('Noch keine Event-Fotos hinzugefügt.', files.length === 0);
  };

  const onPrev = () => setActive(current - 1);
  const onNext = () => setActive(current + 1);

  addEventListener('keydown', (e) => {
    if (!slides.length) return;
    if (e.key === 'ArrowLeft') onPrev();
    if (e.key === 'ArrowRight') onNext();
  });

  let pointerActive = false;
  let pointerStartX = 0;
  let pointerDeltaX = 0;
  let justSwiped = false;

  const pointerThreshold = 40;

  const normalizeX = (event) => {
    if (typeof event.clientX === 'number') return event.clientX;
    if (event.touches && event.touches[0]) return event.touches[0].clientX;
    return 0;
  };

  const endPointer = () => {
    if (!pointerActive) return;
    pointerActive = false;
    if (Math.abs(pointerDeltaX) > pointerThreshold) {
      justSwiped = true;
      if (pointerDeltaX < 0) {
        onNext();
      } else {
        onPrev();
      }
      setTimeout(() => { justSwiped = false; }, 180);
    }
    pointerDeltaX = 0;
  };

  if (carousel) {
    if (prevButton) prevButton.addEventListener('click', onPrev);
    if (nextButton) nextButton.addEventListener('click', onNext);
    carousel.addEventListener('pointerdown', (e) => {
      pointerActive = true;
      pointerStartX = normalizeX(e);
      pointerDeltaX = 0;
    });
    carousel.addEventListener('pointermove', (e) => {
      if (!pointerActive) return;
      pointerDeltaX = normalizeX(e) - pointerStartX;
    });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(evt => {
      carousel.addEventListener(evt, endPointer);
    });
  }

  const showLightbox = (src, alt) => {
    const overlay = document.createElement('div');
    overlay.className = 'events-lightbox';
    overlay.innerHTML = `
      <figure>
        <button type="button" class="events-lightbox__close" aria-label="Schließen">&times;</button>
        <img src="${src}" alt="${alt}" />
      </figure>
    `;
    const removeOverlay = () => {
      overlay.remove();
      document.body.style.overflow = '';
    };
    overlay.addEventListener('click', (ev) => {
      if (ev.target === overlay || ev.target.classList.contains('events-lightbox__close')) {
        removeOverlay();
      }
    });
    document.body.style.overflow = 'hidden';
    document.body.appendChild(overlay);
  };

  track.addEventListener('click', (e) => {
    if (justSwiped) return;
    const img = e.target.closest('.event-slide img');
    if (!img) return;
    e.preventDefault();
    showLightbox(img.currentSrc || img.src, img.alt || 'Event Foto');
  });

  if (carousel && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        carouselVisible = entry.isIntersecting;
        if (carouselVisible) {
          markCurrentIfVisible();
        }
      });
    }, { threshold: 0.35 });
    io.observe(carousel);
  } else {
    carouselVisible = true;
  }

  renderIntroOnly();

  fetch(manifestUrl)
    .then(res => res.ok ? res.json() : [])
    .then(applyData)
    .catch(() => {
      showEmptyState('Event-Fotos konnten nicht geladen werden.', true);
      updateBadge();
    });
})();

// Legal modals (Impressum / Datenschutz)
(function(){
  const openers = document.querySelectorAll('[data-open-modal]');
  const modals = document.querySelectorAll('.legal-modal');
  if (!openers.length || !modals.length) return;

  let activeModal = null;
  let previousFocus = null;

  const getModalByName = (name) => document.getElementById(`modal-${name}`);

  const closeModal = () => {
    if (!activeModal) return;
    activeModal.hidden = true;
    activeModal.classList.remove('is-visible');
    document.body.style.overflow = '';
    if (previousFocus) previousFocus.focus();
    activeModal = null;
  };

  const openModal = (name) => {
    const modal = getModalByName(name);
    if (!modal || activeModal === modal) return;
    if (activeModal) closeModal();
    previousFocus = document.activeElement;
    modal.hidden = false;
    modal.classList.add('is-visible');
    document.body.style.overflow = 'hidden';
    activeModal = modal;
    const focusTarget = modal.querySelector('.legal-modal__close') || modal;
    requestAnimationFrame(() => focusTarget.focus());
  };

  openers.forEach(btn => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      openModal(btn.getAttribute('data-open-modal'));
    });
  });

  modals.forEach(modal => {
    modal.addEventListener('click', (event) => {
      if (event.target === modal) closeModal();
    });
    modal.querySelectorAll('[data-modal-close]').forEach(close => {
      close.addEventListener('click', closeModal);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeModal();
    }
  });
})();
