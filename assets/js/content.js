import {endOfLocalDay, isIsoDate, isWallTime} from './schedule.js';

const MAX_RESPONSE_BYTES = 256 * 1024;
const ENTRY_TYPES = new Set(['news', 'event']);
const TARGETS = new Set(['cafe', 'shop', 'both']);
const THEMES = new Set(['off', 'christmas', 'winter', 'spring', 'summer', 'autumn']);
const EFFECTS = new Set([null, 'christmas', 'winter', 'spring', 'summer', 'autumn']);
const ID_PATTERN = /^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,79}$/;

function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function onlyKeys(record, allowed) {
    return Object.keys(record).every((key) => allowed.includes(key));
}

function boundedString(value, max, {required = true} = {}) {
    if (typeof value !== 'string') return false;
    const length = value.trim().length;
    return required ? length > 0 && length <= max : length <= max;
}

function validInstant(value) {
    if (typeof value !== 'string') return false;
    const match = value.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|([+-])(\d{2}):(\d{2}))$/);
    if (!match || !isIsoDate(match[1])) return false;
    const hour = Number(match[2]);
    const minute = Number(match[3]);
    const second = Number(match[4] ?? 0);
    const offsetHour = Number(match[7] ?? 0);
    const offsetMinute = Number(match[8] ?? 0);
    if (hour > 23 || minute > 59 || second > 59 || offsetHour > 14 || offsetMinute > 59) return false;
    if (offsetHour === 14 && offsetMinute !== 0) return false;
    return Number.isFinite(Date.parse(value));
}

function validVersion(value) {
    return isRecord(value)
        && onlyKeys(value, ['generation', 'sequence'])
        && boundedString(value.generation, 100)
        && Number.isSafeInteger(value.sequence)
        && value.sequence >= 0;
}

function safeAssetPath(value) {
    return typeof value === 'string'
        && value.length <= 300
        && /^(?:\/content\/media\/[a-zA-Z0-9][a-zA-Z0-9._-]*|\/img\/opt\/(?:[a-zA-Z0-9._-]+\/)*[a-zA-Z0-9][a-zA-Z0-9._-]*)\.(?:webp|jpe?g)$/i.test(value);
}

function validImage(image) {
    return isRecord(image)
        && onlyKeys(image, ['url', 'width', 'height', 'alt'])
        && safeAssetPath(image.url)
        && Number.isInteger(image.width) && image.width > 0 && image.width <= 8000
        && Number.isInteger(image.height) && image.height > 0 && image.height <= 8000
        && boundedString(image.alt, 300, {required: false});
}

function parseEntry(entry) {
    if (!isRecord(entry) || !onlyKeys(entry, [
        'id', 'type', 'title', 'body', 'publishedAt', 'eventStart', 'eventEnd',
        'image', 'changeVersion',
    ])) return null;
    if (!ID_PATTERN.test(entry.id || '') || !ENTRY_TYPES.has(entry.type)) return null;
    if (!boundedString(entry.title, 120) || !boundedString(entry.body, 3000)) return null;
    if (!validInstant(entry.publishedAt) || !validVersion(entry.changeVersion)) return null;
    if (entry.image !== undefined && entry.image !== null && !validImage(entry.image)) return null;

    if (entry.type === 'event') {
        if (!validInstant(entry.eventStart)) return null;
        if (entry.eventEnd !== undefined && entry.eventEnd !== null) {
            if (!validInstant(entry.eventEnd) || Date.parse(entry.eventEnd) < Date.parse(entry.eventStart)) return null;
        }
    } else if ((entry.eventStart ?? null) !== null || (entry.eventEnd ?? null) !== null) {
        return null;
    }

    return {
        id: entry.id,
        type: entry.type,
        title: entry.title.trim(),
        body: entry.body.trim(),
        publishedAt: entry.publishedAt,
        eventStart: entry.eventStart ?? null,
        eventEnd: entry.eventEnd ?? null,
        image: entry.image ?? null,
        changeVersion: entry.changeVersion,
    };
}

function parseException(exception) {
    if (!isRecord(exception) || !onlyKeys(exception, [
        'id', 'target', 'startDate', 'endDate', 'closed', 'opens', 'closes', 'note',
    ])) return null;
    if (!ID_PATTERN.test(exception.id || '') || !TARGETS.has(exception.target)) return null;
    if (!isIsoDate(exception.startDate) || !isIsoDate(exception.endDate)) return null;
    if (exception.endDate < exception.startDate || typeof exception.closed !== 'boolean') return null;
    if (exception.note !== undefined && !boundedString(exception.note, 300, {required: false})) return null;
    if (!exception.closed) {
        if (!isWallTime(exception.opens) || !isWallTime(exception.closes) || exception.opens >= exception.closes) return null;
    } else if ((exception.opens ?? null) !== null || (exception.closes ?? null) !== null) {
        return null;
    }
    return {
        id: exception.id,
        target: exception.target,
        startDate: exception.startDate,
        endDate: exception.endDate,
        closed: exception.closed,
        opens: exception.opens ?? null,
        closes: exception.closes ?? null,
        note: (exception.note || '').trim(),
    };
}

function exceptionsConflict(exceptions) {
    const expandsTo = (target) => target === 'both' ? ['cafe', 'shop'] : [target];
    for (let index = 0; index < exceptions.length; index += 1) {
        for (let other = index + 1; other < exceptions.length; other += 1) {
            const a = exceptions[index];
            const b = exceptions[other];
            const targetsOverlap = expandsTo(a.target).some((target) => expandsTo(b.target).includes(target));
            const datesOverlap = a.startDate <= b.endDate && b.startDate <= a.endDate;
            if (targetsOverlap && datesOverlap) return true;
        }
    }
    return false;
}

function parseTheme(theme) {
    if (!isRecord(theme) || !onlyKeys(theme, ['name', 'effect'])) return null;
    const name = theme.name ?? 'off';
    const effect = theme.effect ?? null;
    if (!THEMES.has(name) || !EFFECTS.has(effect)) return null;
    if (effect !== null && effect !== name) return null;
    return {name, effect};
}

export function validateSnapshot(payload) {
    const topLevelKeys = [
        'schemaVersion', 'releaseVersion', 'effectCapabilityVersion', 'generatedAt',
        'nextTransitionAt', 'snapshotRevision', 'cacheValidator', 'newsVersion',
        'entries', 'exceptions', 'theme',
    ];
    if (!isRecord(payload) || !onlyKeys(payload, topLevelKeys)) throw new Error('Unbekannte Datenstruktur.');
    if (payload.schemaVersion !== 1) throw new Error('Nicht unterstützte Datenversion.');
    if (!validInstant(payload.generatedAt)) throw new Error('Ungültiger Erstellungszeitpunkt.');
    if (payload.nextTransitionAt !== null && !validInstant(payload.nextTransitionAt)) {
        throw new Error('Ungültiger Übergangszeitpunkt.');
    }
    if (!boundedString(payload.snapshotRevision, 100) || !validVersion(payload.newsVersion)) {
        throw new Error('Ungültiger Datenstand.');
    }
    if (payload.releaseVersion !== undefined && !boundedString(payload.releaseVersion, 100)) throw new Error('Ungültige Release-Version.');
    if (payload.effectCapabilityVersion !== undefined && !boundedString(payload.effectCapabilityVersion, 100)) throw new Error('Ungültige Effekt-Version.');
    if (payload.cacheValidator !== undefined && !boundedString(payload.cacheValidator, 200)) throw new Error('Ungültiger Cache-Prüfwert.');
    if (!Array.isArray(payload.entries) || payload.entries.length > 50) throw new Error('Zu viele oder ungültige Einträge.');
    if (!Array.isArray(payload.exceptions) || payload.exceptions.length > 50) throw new Error('Zu viele oder ungültige Abweichungen.');

    const entries = payload.entries.map(parseEntry);
    if (entries.some((entry) => !entry)) throw new Error('Ungültiger Aktuelles-Eintrag.');
    if (new Set(entries.map((entry) => entry.id)).size !== entries.length) {
        throw new Error('Doppelte Aktuelles-Kennung.');
    }
    const maximumSequence = entries.reduce((maximum, entry) => {
        if (entry.changeVersion.generation !== payload.newsVersion.generation) return Number.NaN;
        return Math.max(maximum, entry.changeVersion.sequence);
    }, 0);
    if (!Number.isFinite(maximumSequence) || maximumSequence !== payload.newsVersion.sequence) {
        throw new Error('Widersprüchlicher Aktuelles-Stand.');
    }
    if (entries.some((entry) => Date.parse(entry.publishedAt) > Date.parse(payload.generatedAt))) {
        throw new Error('Noch nicht veröffentlichter Eintrag.');
    }

    const exceptions = payload.exceptions.map(parseException);
    if (exceptions.some((exception) => !exception)
        || new Set(exceptions.map((exception) => exception.id)).size !== exceptions.length
        || exceptionsConflict(exceptions)) {
        throw new Error('Ungültige oder widersprüchliche Öffnungszeiten.');
    }
    const theme = parseTheme(payload.theme);
    if (!theme) throw new Error('Ungültiges Saisonthema.');

    return {
        schemaVersion: 1,
        generatedAt: payload.generatedAt,
        nextTransitionAt: payload.nextTransitionAt,
        snapshotRevision: payload.snapshotRevision,
        newsVersion: payload.newsVersion,
        entries,
        exceptions,
        theme,
    };
}

export async function fetchSnapshot(url, {retryStale = true} = {}) {
    const separator = url.includes('?') ? '&' : '?';
    const response = await fetch(url, {
        headers: {'Accept': 'application/json'},
        cache: 'no-cache',
        credentials: 'same-origin',
    });
    if (!response.ok) throw new Error(`Aktuelle Informationen antworten mit ${response.status}.`);
    const raw = await response.text();
    if (new TextEncoder().encode(raw).byteLength > MAX_RESPONSE_BYTES) throw new Error('Aktuelle Informationen sind zu groß.');
    let payload;
    try {
        payload = JSON.parse(raw);
    } catch {
        throw new Error('Aktuelle Informationen sind nicht lesbar.');
    }
    const snapshot = validateSnapshot(payload);
    if (snapshot.nextTransitionAt && Date.parse(snapshot.nextTransitionAt) <= Date.now()) {
        if (retryStale) return fetchSnapshot(`${url}${separator}refresh=${Date.now()}`, {retryStale: false});
        throw new Error('Aktuelle Informationen sind veraltet.');
    }
    return snapshot;
}

export function orderedEntries(entries, now = new Date()) {
    const nowTime = now.getTime();
    const eventEnd = (entry) => entry.eventEnd
        ? Date.parse(entry.eventEnd)
        : endOfLocalDay(entry.eventStart);
    const group = (entry) => {
        if (entry.type === 'news') return 2;
        const start = Date.parse(entry.eventStart);
        const end = eventEnd(entry);
        return start <= nowTime && end >= nowTime ? 0 : 1;
    };

    return entries.filter((entry) => entry.type !== 'event' || eventEnd(entry) >= nowTime).sort((a, b) => {
        const aGroup = group(a);
        const bGroup = group(b);
        if (aGroup !== bGroup) return aGroup - bGroup;
        if (aGroup === 0) {
            const aEnd = eventEnd(a);
            const bEnd = eventEnd(b);
            return aEnd - bEnd;
        }
        if (aGroup === 1) return Date.parse(a.eventStart) - Date.parse(b.eventStart);
        return Date.parse(b.publishedAt) - Date.parse(a.publishedAt);
    });
}

export function formatEventPeriod(entry) {
    const date = new Intl.DateTimeFormat('de-DE', {
        timeZone: 'Europe/Berlin',
        weekday: 'long',
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    });
    const time = new Intl.DateTimeFormat('de-DE', {
        timeZone: 'Europe/Berlin',
        hour: '2-digit',
        minute: '2-digit',
    });
    const start = new Date(entry.eventStart);
    if (!entry.eventEnd) return `${date.format(start)} · ${time.format(start)} Uhr`;
    const end = new Date(entry.eventEnd);
    const sameDay = date.format(start) === date.format(end);
    return sameDay
        ? `${date.format(start)} · ${time.format(start)}–${time.format(end)} Uhr`
        : `${date.format(start)}, ${time.format(start)} Uhr – ${date.format(end)}, ${time.format(end)} Uhr`;
}
