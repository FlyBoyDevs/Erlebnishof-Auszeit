const MOTION_KEY = 'erlebnishof:bewegung:v1';
const THEMES = new Set(['off', 'christmas', 'winter', 'spring', 'summer', 'autumn']);

function storedMotion() {
    try {
        const value = localStorage.getItem(MOTION_KEY);
        return value === 'on' || value === 'off' ? value : null;
    } catch {
        return null;
    }
}

export function setupMotion() {
    const button = document.querySelector('[data-motion-toggle]');
    const slides = Array.from(document.querySelectorAll('[data-hero-slide]'));
    const hero = document.querySelector('[data-hero]');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const explicit = storedMotion();
    let enabled = explicit ? explicit === 'on' : !reduced.matches;
    let activeSlide = 0;
    let interval = null;
    let interactionPaused = false;
    let theme = {name: 'off', effect: null};

    const syncButton = () => {
        if (!button) return;
        button.hidden = false;
        button.textContent = enabled ? 'Bewegung ausschalten' : 'Bewegung einschalten';
        button.setAttribute('aria-label', button.textContent);
    };

    const removeEffect = () => document.querySelector('[data-seasonal-layer]')?.remove();

    const buildSeasonalEffect = () => {
        removeEffect();
        if (!enabled || theme.effect === null || theme.effect !== theme.name) return;
        const definitions = {
            christmas: {count: 12, className: 'christmas-charm', symbols: ['🎁', '🎄', '🍬']},
            winter: {count: 18, className: 'snowflake', symbols: ['✦', '•', '•']},
            spring: {count: 14, className: 'spring-petal', symbols: ['✿', '❀', '•']},
            summer: {count: 16, className: 'firefly', symbols: ['']},
            autumn: {count: 14, className: 'autumn-leaf', symbols: ['◆', '◇', '◆']},
        };
        const definition = definitions[theme.effect];
        if (!definition) return;
        const layer = document.createElement('div');
        layer.className = `seasonal-layer seasonal-layer--${theme.effect}`;
        layer.dataset.seasonalLayer = '';
        layer.setAttribute('aria-hidden', 'true');
        for (let index = 0; index < definition.count; index += 1) {
            const particle = document.createElement('span');
            particle.className = `${definition.className} seasonal-particle`;
            particle.textContent = definition.symbols[index % definition.symbols.length];
            layer.append(particle);
        }
        document.body.append(layer);
    };

    const stopHero = () => {
        if (interval !== null) window.clearInterval(interval);
        interval = null;
    };

    const startHero = () => {
        stopHero();
        if (!enabled || interactionPaused || document.hidden || slides.length < 2) return;
        interval = window.setInterval(() => {
            slides[activeSlide].classList.remove('is-active');
            slides[activeSlide].setAttribute('aria-hidden', 'true');
            activeSlide = (activeSlide + 1) % slides.length;
            slides[activeSlide].classList.add('is-active');
            slides[activeSlide].setAttribute('aria-hidden', 'false');
        }, 12_000);
    };

    const applyEnabled = (value, {remember = false} = {}) => {
        enabled = value;
        if (remember) {
            try {
                localStorage.setItem(MOTION_KEY, enabled ? 'on' : 'off');
            } catch {
                // The preference still applies for this page view when storage is unavailable.
            }
        }
        document.documentElement.classList.toggle('motion-off', !enabled);
        syncButton();
        buildSeasonalEffect();
        startHero();
    };

    button?.addEventListener('click', () => applyEnabled(!enabled, {remember: true}));
    reduced.addEventListener('change', (event) => {
        if (storedMotion() === null) applyEnabled(!event.matches);
    });
    document.addEventListener('visibilitychange', startHero);
    hero?.addEventListener('pointerenter', () => {
        interactionPaused = true;
        stopHero();
    });
    hero?.addEventListener('pointerleave', () => {
        interactionPaused = false;
        startHero();
    });
    hero?.addEventListener('focusin', () => {
        interactionPaused = true;
        stopHero();
    });
    hero?.addEventListener('focusout', () => {
        interactionPaused = false;
        startHero();
    });

    slides.forEach((slide, index) => {
        slide.classList.toggle('is-active', index === 0);
        slide.setAttribute('aria-hidden', String(index !== 0));
    });
    applyEnabled(enabled);

    return {
        applyTheme(nextTheme) {
            theme = THEMES.has(nextTheme?.name)
                ? {name: nextTheme.name, effect: nextTheme.effect ?? null}
                : {name: 'off', effect: null};
            if (theme.name === 'off') delete document.documentElement.dataset.theme;
            else document.documentElement.dataset.theme = theme.name;
            buildSeasonalEffect();
        },
        clearTheme() {
            theme = {name: 'off', effect: null};
            delete document.documentElement.dataset.theme;
            removeEffect();
        },
    };
}
