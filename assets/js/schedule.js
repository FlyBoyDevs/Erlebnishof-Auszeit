import {SITE_CONFIG} from './config.js';

const PARTS_FORMATTER = new Intl.DateTimeFormat('en-CA', {
    timeZone: SITE_CONFIG.timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
});

const WEEKDAY_INDEX = {Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6};
const WEEKDAYS_DE = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

export function zonedParts(date = new Date()) {
    const values = {};
    PARTS_FORMATTER.formatToParts(date).forEach(({type, value}) => {
        if (type !== 'literal') values[type] = value;
    });

    return {
        year: Number(values.year),
        month: Number(values.month),
        day: Number(values.day),
        weekday: WEEKDAY_INDEX[values.weekday],
        hour: Number(values.hour),
        minute: Number(values.minute),
        second: Number(values.second),
        isoDate: `${values.year}-${values.month}-${values.day}`,
    };
}

export function isIsoDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return false;
    const [year, month, day] = value.split('-').map(Number);
    const probe = new Date(Date.UTC(year, month - 1, day));
    return probe.getUTCFullYear() === year
        && probe.getUTCMonth() === month - 1
        && probe.getUTCDate() === day;
}

export function isWallTime(value) {
    if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value || '')) return false;
    return true;
}

function minutes(value) {
    const [hour, minute] = value.split(':').map(Number);
    return hour * 60 + minute;
}

function addDays(isoDate, offset) {
    const [year, month, day] = isoDate.split('-').map(Number);
    const probe = new Date(Date.UTC(year, month - 1, day + offset, 12));
    const nextYear = probe.getUTCFullYear();
    const nextMonth = String(probe.getUTCMonth() + 1).padStart(2, '0');
    const nextDay = String(probe.getUTCDate()).padStart(2, '0');
    return `${nextYear}-${nextMonth}-${nextDay}`;
}

function instantForLocalTime(isoDate, wallTime = '00:00') {
    const [year, month, day] = isoDate.split('-').map(Number);
    const [hour, minute] = wallTime.split(':').map(Number);
    const targetAsUtc = Date.UTC(year, month - 1, day, hour, minute);
    let guess = targetAsUtc;

    // Re-evaluate the zone offset because it can change at a DST boundary.
    for (let attempt = 0; attempt < 3; attempt += 1) {
        const parts = zonedParts(new Date(guess));
        const representedAsUtc = Date.UTC(
            parts.year,
            parts.month - 1,
            parts.day,
            parts.hour,
            parts.minute,
            parts.second,
        );
        const correction = targetAsUtc - representedAsUtc;
        guess += correction;
        if (correction === 0) break;
    }
    return guess;
}

export function endOfLocalDay(instant) {
    const localDate = zonedParts(new Date(instant)).isoDate;
    return instantForLocalTime(addDays(localDate, 1)) - 1;
}

function dateFacts(isoDate) {
    const [year, month, day] = isoDate.split('-').map(Number);
    const probe = new Date(Date.UTC(year, month - 1, day, 12));
    return {year, month, day, weekday: probe.getUTCDay()};
}

function applicableException(exceptions, target, isoDate) {
    return exceptions.find((exception) => (
        (exception.target === target || exception.target === 'both')
        && exception.startDate <= isoDate
        && exception.endDate >= isoDate
    ));
}

function regularSchedule(target, isoDate) {
    const {month, weekday} = dateFacts(isoDate);
    if (target === 'cafe') {
        const schedule = SITE_CONFIG.regularHours.cafe;
        return schedule.days.includes(weekday)
            ? {opens: schedule.opens, closes: schedule.closes}
            : null;
    }

    const shop = SITE_CONFIG.regularHours.shop;
    if (!shop.days.includes(weekday)) return null;
    const seasonal = shop.summer.months.includes(month) ? shop.summer : shop.winter;
    return {opens: seasonal.opens, closes: seasonal.closes};
}

function scheduleForDate(target, isoDate, exceptions) {
    const exception = applicableException(exceptions, target, isoDate);
    if (exception?.closed) return {schedule: null, exception};
    if (exception) {
        return {schedule: {opens: exception.opens, closes: exception.closes}, exception};
    }
    return {schedule: regularSchedule(target, isoDate), exception: null};
}

function nextOpening(target, exceptions, nowParts) {
    const nowMinutes = nowParts.hour * 60 + nowParts.minute;

    for (let offset = 0; offset <= 14; offset += 1) {
        const isoDate = addDays(nowParts.isoDate, offset);
        const {schedule} = scheduleForDate(target, isoDate, exceptions);
        if (!schedule) continue;
        if (offset === 0 && minutes(schedule.opens) <= nowMinutes) continue;
        const facts = dateFacts(isoDate);
        return {offset, weekday: facts.weekday, opens: schedule.opens};
    }
    return null;
}

export function openingStatus(target, exceptions, date = new Date()) {
    const now = zonedParts(date);
    const {schedule, exception} = scheduleForDate(target, now.isoDate, exceptions);
    const nowMinutes = now.hour * 60 + now.minute;
    const isOpen = Boolean(schedule)
        && nowMinutes >= minutes(schedule.opens)
        && nowMinutes < minutes(schedule.closes);

    if (isOpen) {
        return {
            isOpen: true,
            label: `Jetzt geöffnet · bis ${schedule.closes} Uhr`,
            exception,
        };
    }

    const next = nextOpening(target, exceptions, now);
    let label = 'Jetzt geschlossen';
    if (next?.offset === 0) label += ` · heute ab ${next.opens} Uhr`;
    else if (next?.offset === 1) label += ` · morgen ab ${next.opens} Uhr`;
    else if (next) label += ` · ${WEEKDAYS_DE[next.weekday]} ab ${next.opens} Uhr`;

    return {isOpen: false, label, exception};
}

export function formatExceptionDate(exception) {
    const format = (isoDate, includeYear = true) => {
        const [year, month, day] = isoDate.split('-').map(Number);
        return new Intl.DateTimeFormat('de-DE', {
            timeZone: 'UTC',
            day: '2-digit',
            month: '2-digit',
            ...(includeYear ? {year: 'numeric'} : {}),
        }).format(new Date(Date.UTC(year, month - 1, day, 12)));
    };

    if (exception.startDate === exception.endDate) return format(exception.startDate);
    return `${format(exception.startDate, false)}–${format(exception.endDate)}`;
}

export function nextStatusDelay(exceptions = [], date = new Date()) {
    const now = zonedParts(date);
    const nowTime = date.getTime();
    const candidates = [instantForLocalTime(addDays(now.isoDate, 1))];

    for (let offset = 0; offset <= 2; offset += 1) {
        const isoDate = addDays(now.isoDate, offset);
        ['cafe', 'shop'].forEach((target) => {
            const {schedule} = scheduleForDate(target, isoDate, exceptions);
            if (!schedule) return;
            candidates.push(
                instantForLocalTime(isoDate, schedule.opens),
                instantForLocalTime(isoDate, schedule.closes),
            );
        });
    }

    const nextBoundary = Math.min(...candidates.filter((candidate) => candidate > nowTime));
    const untilBoundary = Number.isFinite(nextBoundary) ? nextBoundary - nowTime + 250 : Infinity;
    return Math.max(1_000, Math.min(5 * 60 * 1_000, untilBoundary));
}
