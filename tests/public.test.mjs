import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

import {orderedEntries, validateSnapshot} from '../assets/js/content.js';
import {isIsoDate, openingStatus} from '../assets/js/schedule.js';

const fixture = JSON.parse(await readFile(new URL('./fixtures/current.json', import.meta.url), 'utf8'));

test('Hofcafé ist Freitag bis Sonntag von 08:30 bis 17:00 Uhr geöffnet', () => {
    assert.equal(openingStatus('cafe', [], new Date('2026-07-24T07:00:00Z')).isOpen, true);
    assert.equal(openingStatus('cafe', [], new Date('2026-07-24T15:00:00Z')).isOpen, false);
    assert.equal(openingStatus('cafe', [], new Date('2026-07-27T07:00:00Z')).isOpen, false);
});

test('Hofladen folgt den beiden Saisonzeiten', () => {
    assert.equal(openingStatus('shop', [], new Date('2026-03-20T19:30:00+01:00')).isOpen, false);
    assert.equal(openingStatus('shop', [], new Date('2026-04-20T20:30:00+02:00')).isOpen, true);
    assert.equal(openingStatus('shop', [], new Date('2026-09-20T19:30:00+02:00')).isOpen, false);
});

test('genehmigte Schließung und Ersatzzeit überschreiben nur ihr Ziel', () => {
    const closed = [{id: 'x', target: 'cafe', startDate: '2026-07-24', endDate: '2026-07-24', closed: true}];
    assert.equal(openingStatus('cafe', closed, new Date('2026-07-24T10:00:00+02:00')).isOpen, false);
    assert.equal(openingStatus('shop', closed, new Date('2026-07-24T10:00:00+02:00')).isOpen, true);

    const replacement = [{id: 'y', target: 'shop', startDate: '2026-07-24', endDate: '2026-07-24', closed: false, opens: '10:00', closes: '12:00'}];
    assert.equal(openingStatus('shop', replacement, new Date('2026-07-24T09:00:00+02:00')).isOpen, false);
    assert.equal(openingStatus('shop', replacement, new Date('2026-07-24T11:00:00+02:00')).isOpen, true);
});

test('ISO-Datumsprüfung berücksichtigt Schaltjahre', () => {
    assert.equal(isIsoDate('2028-02-29'), true);
    assert.equal(isIsoDate('2027-02-29'), false);
    assert.equal(isIsoDate('2026-13-01'), false);
});

test('öffentliche Testdaten erfüllen den Client-Vertrag', () => {
    const parsed = validateSnapshot(fixture);
    assert.equal(parsed.schemaVersion, 1);
    assert.ok(Array.isArray(parsed.entries));
});

test('externe Bild-URLs und widersprüchliche Ausnahmen werden abgelehnt', () => {
    const externalImage = structuredClone(fixture);
    externalImage.entries[0].image = {url: 'https://example.test/a.jpg', width: 10, height: 10, alt: ''};
    assert.throws(() => validateSnapshot(externalImage));

    const overlap = structuredClone(fixture);
    overlap.exceptions = [
        {id: 'a', target: 'both', startDate: '2026-10-01', endDate: '2026-10-02', closed: true},
        {id: 'b', target: 'shop', startDate: '2026-10-02', endDate: '2026-10-03', closed: true},
    ];
    assert.throws(() => validateSnapshot(overlap));
});

test('laufende und kommende Termine stehen vor Neuigkeiten', () => {
    const entries = [
        {id: 'news', type: 'news', publishedAt: '2026-07-01T10:00:00+02:00'},
        {id: 'later', type: 'event', publishedAt: '2026-07-01T10:00:00+02:00', eventStart: '2026-07-26T10:00:00+02:00', eventEnd: null},
        {id: 'now', type: 'event', publishedAt: '2026-07-01T10:00:00+02:00', eventStart: '2026-07-24T10:00:00+02:00', eventEnd: '2026-07-24T14:00:00+02:00'},
    ];
    const ordered = orderedEntries(entries, new Date('2026-07-24T10:30:00+02:00'));
    assert.deepEqual(ordered.map(({id}) => id), ['now', 'later', 'news']);
});
