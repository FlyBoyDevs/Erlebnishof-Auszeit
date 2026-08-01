import {fetchSnapshot, formatEventPeriod, orderedEntries} from './content.js';
import {formatExceptionDate, nextStatusDelay, openingStatus} from './schedule.js';
import {setupNavigation} from './navigation.js';
import {setupGalleries} from './gallery.js';
import {setupMotion} from './motion.js';
import {clearStructuredData, updateStructuredData} from './structured-data.js';
import {loadProductionAnalytics} from './analytics.js';

const CONTENT_URL = '/content/current.json';
const READ_KEY = 'erlebnishof:aktuelles-gelesen:v1';
const CARD_LIMIT = 3;

document.documentElement.classList.remove('no-js');
document.documentElement.classList.add('js');

const navigation = setupNavigation();
setupGalleries();
const motion = setupMotion();
loadProductionAnalytics();

const elements = {
    cafeStatus: document.querySelector('[data-cafe-status]'),
    shopStatus: document.querySelector('[data-shop-status]'),
    statusGroup: document.querySelector('[data-opening-statuses]'),
    newsSection: document.querySelector('[data-news-section]'),
    newsStatus: document.querySelector('[data-news-status]'),
    newsList: document.querySelector('[data-news-list]'),
    newsActions: document.querySelector('[data-news-actions]'),
    loadMore: document.querySelector('[data-news-more]'),
    markRead: document.querySelector('[data-mark-read]'),
    resetRead: document.querySelector('[data-reset-read]'),
    readFeedback: document.querySelector('[data-read-feedback]'),
    exceptions: document.querySelector('[data-exceptions]'),
    exceptionsList: document.querySelector('[data-exceptions-list]'),
};

let snapshot = null;
let refreshTimer = null;
let visibleCount = CARD_LIMIT;
let lastClock = Date.now();
let requestInFlight = null;

function readVersion() {
    try {
        const raw = localStorage.getItem(READ_KEY);
        if (!raw) return null;
        const value = JSON.parse(raw);
        if (typeof value.generation !== 'string' || !Number.isSafeInteger(value.sequence) || value.sequence < 0) return null;
        return value;
    } catch {
        return null;
    }
}

function versionIsNewer(version, read) {
    if (!read) return true;
    if (version.generation !== read.generation) return true;
    return version.sequence > read.sequence;
}

function currentUnreadEntries() {
    if (!snapshot) return [];
    const read = readVersion();
    return orderedEntries(snapshot.entries).filter((entry) => versionIsNewer(entry.changeVersion, read));
}

function setOpeningStatus(element, status, label) {
    if (!element) return;
    element.classList.toggle('is-open', status.isOpen);
    element.classList.toggle('is-closed', !status.isOpen);
    element.querySelector('[data-status-name]').textContent = label;
    element.querySelector('[data-status-value]').textContent = status.label;
    element.removeAttribute('aria-busy');
}

function showUnavailableStatuses() {
    [elements.cafeStatus, elements.shopStatus].forEach((element) => {
        if (!element) return;
        element.classList.remove('is-open', 'is-closed');
        element.querySelector('[data-status-value]').textContent = 'Aktueller Status nicht verfügbar';
        element.removeAttribute('aria-busy');
    });
}

function renderStatuses() {
    if (!snapshot) return showUnavailableStatuses();
    setOpeningStatus(elements.cafeStatus, openingStatus('cafe', snapshot.exceptions), 'Hofcafé');
    setOpeningStatus(elements.shopStatus, openingStatus('shop', snapshot.exceptions), 'Hofladen · Selbstbedienung');
}

function exceptionSummary(exception) {
    const area = exception.target === 'both'
        ? 'Hofcafé und Hofladen'
        : exception.target === 'cafe' ? 'Hofcafé' : 'Hofladen';
    const schedule = exception.closed
        ? 'geschlossen'
        : `${exception.opens}–${exception.closes} Uhr geöffnet`;
    return `${formatExceptionDate(exception)} · ${area} ${schedule}${exception.note ? ` · ${exception.note}` : ''}`;
}

function renderExceptions() {
    if (!elements.exceptions || !elements.exceptionsList) return;
    elements.exceptionsList.replaceChildren();
    if (!snapshot?.exceptions.length) {
        elements.exceptions.hidden = true;
        return;
    }
    snapshot.exceptions.forEach((exception) => {
        const item = document.createElement('li');
        item.textContent = exceptionSummary(exception);
        elements.exceptionsList.append(item);
    });
    elements.exceptions.hidden = false;
}

function createEntryImage(entry) {
    if (!entry.image) return null;
    const figure = document.createElement('figure');
    figure.className = 'news-card__media';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'news-card__image-button';
    button.dataset.enlarge = '';
    button.dataset.fullSrc = entry.image.url;
    button.dataset.caption = entry.image.alt;
    button.setAttribute('aria-label', `Bild vergrößern: ${entry.image.alt || entry.title}`);
    const image = document.createElement('img');
    image.src = entry.image.url;
    image.width = entry.image.width;
    image.height = entry.image.height;
    image.alt = entry.image.alt;
    image.loading = 'lazy';
    image.decoding = 'async';
    button.append(image);
    figure.append(button);
    return figure;
}

function createNewsCard(entry, unread) {
    const article = document.createElement('article');
    article.className = 'news-card';
    if (unread) article.classList.add('is-new');

    const copy = document.createElement('div');
    copy.className = 'news-card__copy';
    const meta = document.createElement('p');
    meta.className = 'news-card__meta';
    meta.textContent = entry.type === 'event' ? formatEventPeriod(entry) : 'Neuigkeit';
    if (unread) {
        const marker = document.createElement('span');
        marker.className = 'news-card__new';
        marker.textContent = 'Neu';
        meta.append(' ', marker);
    }
    const heading = document.createElement('h3');
    heading.textContent = entry.title;
    const body = document.createElement('p');
    body.className = 'news-card__body';
    body.textContent = entry.body;
    copy.append(meta, heading, body);
    article.append(copy);
    const media = createEntryImage(entry);
    if (media) article.append(media);
    return article;
}

function updateReadUi() {
    if (!snapshot) {
        navigation?.setNewsBadge(false);
        return;
    }
    const unread = currentUnreadEntries();
    navigation?.setNewsBadge(unread.length > 0);
    if (elements.markRead) elements.markRead.hidden = unread.length === 0 || snapshot.entries.length === 0;
    if (elements.resetRead) elements.resetRead.hidden = readVersion() === null;
}

function renderNews() {
    if (!elements.newsSection || !elements.newsList || !elements.newsStatus) return;
    elements.newsList.replaceChildren();
    elements.newsStatus.hidden = true;
    const entries = orderedEntries(snapshot.entries);

    if (!entries.length) {
        elements.newsSection.hidden = true;
        navigation?.setNewsMode('hidden');
        navigation?.setNewsBadge(false);
        return;
    }

    elements.newsSection.hidden = false;
    navigation?.setNewsMode('available');
    const unreadIds = new Set(currentUnreadEntries().map((entry) => entry.id));
    entries.slice(0, visibleCount).forEach((entry) => {
        elements.newsList.append(createNewsCard(entry, unreadIds.has(entry.id)));
    });
    if (elements.loadMore) elements.loadMore.hidden = visibleCount >= entries.length;
    if (elements.newsActions) elements.newsActions.hidden = false;
    updateReadUi();
}

function showNewsFailure() {
    if (!elements.newsSection || !elements.newsStatus || !elements.newsList) return;
    elements.newsSection.hidden = false;
    elements.newsList.replaceChildren();
    elements.newsStatus.hidden = false;
    elements.newsStatus.textContent = 'Aktuelle Informationen sind gerade nicht verfügbar. Bitte orientiert euch an den regulären Öffnungszeiten oder ruft uns kurz an.';
    if (elements.newsActions) elements.newsActions.hidden = true;
    navigation?.setNewsMode('unavailable');
    navigation?.setNewsBadge(false);
}

function nextRefreshDelay() {
    const maximum = 5 * 60 * 1_000;
    const statusDelay = nextStatusDelay(snapshot?.exceptions ?? []);
    if (!snapshot?.nextTransitionAt) return Math.min(maximum, statusDelay);
    const transitionDelay = Date.parse(snapshot.nextTransitionAt) - Date.now() + 250;
    return Math.max(1_000, Math.min(maximum, statusDelay, transitionDelay));
}

function scheduleRefresh() {
    if (refreshTimer !== null) window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(() => {
        renderStatuses();
        loadCurrentContent();
    }, nextRefreshDelay());
}

async function refreshCurrentContent() {
    try {
        const nextSnapshot = await fetchSnapshot(CONTENT_URL);
        const unchanged = snapshot?.snapshotRevision === nextSnapshot.snapshotRevision;
        snapshot = nextSnapshot;
        visibleCount = Math.max(CARD_LIMIT, Math.min(visibleCount, snapshot.entries.length));
        if (unchanged) {
            renderStatuses();
            updateReadUi();
            return;
        }
        motion.applyTheme(snapshot.theme);
        renderStatuses();
        renderExceptions();
        renderNews();
        updateStructuredData(snapshot);
    } catch (error) {
        snapshot = null;
        motion.clearTheme();
        showUnavailableStatuses();
        renderExceptions();
        showNewsFailure();
        clearStructuredData();
        console.warn('Aktuelle Informationen konnten nicht geladen werden.', error);
    }
}

function loadCurrentContent() {
    if (requestInFlight) return requestInFlight;
    requestInFlight = refreshCurrentContent().finally(() => {
        requestInFlight = null;
        scheduleRefresh();
    });
    return requestInFlight;
}

elements.loadMore?.addEventListener('click', () => {
    visibleCount += CARD_LIMIT;
    renderNews();
    elements.newsList?.lastElementChild?.scrollIntoView({block: 'nearest'});
    elements.newsList?.lastElementChild?.focus?.({preventScroll: true});
});

elements.markRead?.addEventListener('click', () => {
    if (!snapshot) return;
    try {
        localStorage.setItem(READ_KEY, JSON.stringify(snapshot.newsVersion));
        if (elements.readFeedback) elements.readFeedback.textContent = 'Aktuelles wurde auf diesem Gerät als gelesen markiert.';
        renderNews();
        if (elements.readFeedback) {
            elements.readFeedback.tabIndex = -1;
            elements.readFeedback.focus({preventScroll: true});
        }
    } catch {
        if (elements.readFeedback) elements.readFeedback.textContent = 'Der Gelesenstand konnte auf diesem Gerät nicht gespeichert werden.';
    }
});

elements.resetRead?.addEventListener('click', () => {
    try {
        localStorage.removeItem(READ_KEY);
        if (elements.readFeedback) elements.readFeedback.textContent = 'Der Gelesenstand wurde zurückgesetzt.';
        renderNews();
        if (elements.readFeedback) {
            elements.readFeedback.tabIndex = -1;
            elements.readFeedback.focus({preventScroll: true});
        }
    } catch {
        if (elements.readFeedback) elements.readFeedback.textContent = 'Der Gelesenstand konnte nicht zurückgesetzt werden.';
    }
});

window.addEventListener('pageshow', () => loadCurrentContent());
window.addEventListener('online', () => loadCurrentContent());
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) loadCurrentContent();
});
window.setInterval(() => {
    const now = Date.now();
    if (Math.abs(now - lastClock - 60_000) > 30_000) loadCurrentContent();
    lastClock = now;
}, 60_000);

document.querySelectorAll('[data-current-year]').forEach((node) => {
    node.textContent = String(new Date().getFullYear());
});

loadCurrentContent();
