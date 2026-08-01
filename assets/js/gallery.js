function setupDialog() {
    const dialog = document.querySelector('[data-image-dialog]');
    const image = dialog?.querySelector('[data-dialog-image]');
    const caption = dialog?.querySelector('[data-dialog-caption]');
    const closeButton = dialog?.querySelector('[data-dialog-close]');
    if (!dialog || !image || !caption || !closeButton) return () => {};

    let opener = null;
    const close = () => {
        if (dialog.open) dialog.close();
    };

    closeButton.addEventListener('click', close);
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) close();
    });
    dialog.addEventListener('close', () => {
        image.removeAttribute('src');
        caption.textContent = '';
        opener?.focus();
        opener = null;
    });

    return (button) => {
        const source = button.querySelector('img');
        if (!source) return;
        opener = button;
        image.src = button.dataset.fullSrc || source.currentSrc || source.src;
        image.alt = source.alt;
        caption.textContent = button.dataset.caption || source.alt;
        dialog.showModal();
        closeButton.focus();
    };
}

export function setupGalleries() {
    const openDialog = setupDialog();

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-enlarge]');
        if (trigger) openDialog(trigger);
    });

    document.querySelectorAll('[data-gallery]').forEach((gallery) => {
        const track = gallery.querySelector('[data-gallery-track]');
        const slides = Array.from(gallery.querySelectorAll('[data-gallery-slide]'));
        const previous = gallery.querySelector('[data-gallery-prev]');
        const next = gallery.querySelector('[data-gallery-next]');
        const indicators = gallery.querySelector('[data-gallery-indicators]');
        const status = gallery.querySelector('[data-gallery-status]');
        if (!track || !slides.length || !previous || !next || !indicators || !status) return;

        let index = 0;
        let pointerStart = null;
        let suppressClick = false;

        slides.forEach((slide, slideIndex) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'gallery-indicator';
            button.setAttribute('aria-label', `Bild ${slideIndex + 1} anzeigen`);
            button.addEventListener('click', () => update(slideIndex, true));
            indicators.append(button);
        });
        const indicatorButtons = Array.from(indicators.children);

        const update = (nextIndex, announce = false) => {
            const focusInsideChangingSlide = slides.some((slide) => slide.contains(document.activeElement));
            index = Math.min(slides.length - 1, Math.max(0, nextIndex));
            track.dataset.position = String(index);
            slides.forEach((slide, slideIndex) => {
                const active = slideIndex === index;
                slide.setAttribute('aria-hidden', String(!active));
                slide.inert = !active;
            });
            indicatorButtons.forEach((button, buttonIndex) => {
                const active = buttonIndex === index;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-current', active ? 'true' : 'false');
            });
            previous.disabled = index === 0;
            next.disabled = index === slides.length - 1;
            status.textContent = announce ? `Bild ${index + 1} von ${slides.length}` : '';
            if (focusInsideChangingSlide) gallery.focus({preventScroll: true});
        };

        previous.addEventListener('click', () => update(index - 1, true));
        next.addEventListener('click', () => update(index + 1, true));
        gallery.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                update(index - 1, true);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                update(index + 1, true);
            } else if (event.key === 'Home') {
                event.preventDefault();
                update(0, true);
            } else if (event.key === 'End') {
                event.preventDefault();
                update(slides.length - 1, true);
            }
        });
        gallery.addEventListener('pointerdown', (event) => {
            if (event.pointerType === 'mouse') return;
            gallery.setPointerCapture?.(event.pointerId);
            pointerStart = {x: event.clientX, y: event.clientY};
        }, {passive: true});
        gallery.addEventListener('pointerup', (event) => {
            if (!pointerStart) return;
            const deltaX = event.clientX - pointerStart.x;
            const deltaY = event.clientY - pointerStart.y;
            pointerStart = null;
            gallery.releasePointerCapture?.(event.pointerId);
            if (Math.abs(deltaX) < 48 || Math.abs(deltaX) <= Math.abs(deltaY)) return;
            suppressClick = true;
            window.setTimeout(() => { suppressClick = false; }, 350);
            update(index + (deltaX < 0 ? 1 : -1), true);
        }, {passive: true});
        gallery.addEventListener('pointercancel', () => {
            pointerStart = null;
        });
        gallery.addEventListener('click', (event) => {
            if (!suppressClick) return;
            event.preventDefault();
            event.stopPropagation();
            suppressClick = false;
        }, {capture: true});

        gallery.dataset.slideCount = String(slides.length);
        if (slides.length === 1) gallery.classList.add('gallery--single');
        update(0);
    });
}
