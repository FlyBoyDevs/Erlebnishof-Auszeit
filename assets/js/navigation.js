export function setupNavigation() {
    const header = document.querySelector('[data-site-header]');
    const toggle = document.querySelector('[data-menu-toggle]');
    const navigation = document.querySelector('[data-site-nav]');
    const desktop = window.matchMedia('(min-width: 64rem)');
    if (!header || !toggle || !navigation) return null;

    const links = Array.from(navigation.querySelectorAll('a'));
    let open = false;
    let hasUnread = false;

    const syncLabel = () => {
        if (open) {
            toggle.setAttribute('aria-label', 'Menü schließen');
        } else {
            toggle.setAttribute('aria-label', hasUnread ? 'Menü öffnen, neue aktuelle Informationen' : 'Menü öffnen');
        }
    };

    const syncMode = () => {
        if (desktop.matches) {
            open = false;
            header.classList.remove('menu-open');
            toggle.setAttribute('aria-expanded', 'false');
            navigation.inert = false;
        } else {
            navigation.inert = !open;
        }
        syncLabel();
    };

    const close = ({restoreFocus = false} = {}) => {
        if (!open) return;
        open = false;
        header.classList.remove('menu-open');
        toggle.setAttribute('aria-expanded', 'false');
        navigation.inert = !desktop.matches;
        syncLabel();
        if (restoreFocus) toggle.focus();
    };

    const show = () => {
        open = true;
        header.classList.add('menu-open');
        toggle.setAttribute('aria-expanded', 'true');
        navigation.inert = false;
        syncLabel();
        const firstVisible = links.find((link) => !link.hidden);
        firstVisible?.focus();
    };

    toggle.addEventListener('click', () => open ? close({restoreFocus: true}) : show());
    navigation.addEventListener('click', (event) => {
        const link = event.target.closest('a');
        if (!link) return;
        const target = link.hash ? document.getElementById(link.hash.slice(1)) : null;
        close();
        const heading = target?.querySelector('h1, h2, h3');
        if (heading) {
            heading.tabIndex = -1;
            heading.focus({preventScroll: true});
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && open) close({restoreFocus: true});
    });
    document.addEventListener('pointerdown', (event) => {
        if (open && !header.contains(event.target)) close();
    });
    desktop.addEventListener('change', syncMode);
    syncMode();

    return {
        close,
        setNewsMode(mode) {
            const targets = document.querySelectorAll('[data-news-link]');
            targets.forEach((target) => {
                target.hidden = mode === 'hidden';
                target.setAttribute('href', mode === 'unavailable' ? '#aktuelles-status' : '#aktuelles');
            });
        },
        setNewsBadge(visible) {
            hasUnread = visible;
            document.querySelectorAll('[data-news-badge]').forEach((badge) => {
                badge.hidden = !visible;
            });
            syncLabel();
        },
    };
}
