#!/usr/bin/env node

import {spawnSync} from 'node:child_process';
import {readFile, readdir, stat} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const CANONICAL_ORIGIN = 'https://erlebnishof-auszeit.de';
const REQUIRED_FILES = [
    'index.html',
    'impressum.html',
    'datenschutz.html',
    '404.html',
    'styles.css',
    'robots.txt',
    'sitemap.xml',
    '.htaccess',
    'assets/js/main.js',
    'assets/js/config.js',
    'assets/js/content.js',
    'assets/js/schedule.js',
    'tests/fixtures/current.json',
    'data/media.json',
    'img/opt/manifest.json',
];

const errors = [];
const checkedAssets = new Set();

function check(condition, message) {
    if (!condition) errors.push(message);
}

async function read(relativePath) {
    return readFile(path.join(ROOT, relativePath), 'utf8');
}

async function exactFileExists(relativePath) {
    const clean = relativePath.replace(/^\/+/, '').split('/').filter(Boolean);
    let cursor = ROOT;
    for (const segment of clean) {
        let names;
        try {
            names = await readdir(cursor);
        } catch {
            return false;
        }
        if (!names.includes(segment)) return false;
        cursor = path.join(cursor, segment);
    }
    try {
        return (await stat(cursor)).isFile();
    } catch {
        return false;
    }
}

function localPath(reference, documentPath) {
    if (!reference || reference.startsWith('#') || /^(?:mailto:|tel:|data:|javascript:)/i.test(reference)) return null;
    let candidate = reference;
    if (/^https?:\/\//i.test(candidate)) {
        const parsed = new URL(candidate);
        if (parsed.origin !== CANONICAL_ORIGIN) return null;
        candidate = parsed.pathname;
    }
    candidate = candidate.split('#')[0].split('?')[0];
    if (!candidate || candidate === '/') return 'index.html';
    const decoded = decodeURIComponent(candidate);
    const relative = decoded.startsWith('/')
        ? decoded.slice(1)
        : path.posix.normalize(path.posix.join(path.posix.dirname(documentPath), decoded));
    if (!relative || relative.endsWith('/')) return path.posix.join(relative, 'index.html');
    return relative;
}

function collectMarkupReferences(markup) {
    const references = [];
    const single = /\b(?:src|href|poster|data-full-src)\s*=\s*["']([^"']+)["']/gi;
    for (const match of markup.matchAll(single)) references.push(match[1]);
    const sourceSets = /\b(?:srcset|imagesrcset)\s*=\s*["']([^"']+)["']/gi;
    for (const match of markup.matchAll(sourceSets)) {
        for (const candidate of match[1].split(',')) references.push(candidate.trim().split(/\s+/)[0]);
    }
    return references;
}

function collectCssReferences(css) {
    return [...css.matchAll(/url\(\s*["']?([^"')]+)["']?\s*\)/gi)].map((match) => match[1]);
}

async function walk(directory, suffix) {
    const found = [];
    async function visit(relativeDirectory) {
        for (const entry of await readdir(path.join(ROOT, relativeDirectory), {withFileTypes: true})) {
            const relative = path.posix.join(relativeDirectory, entry.name);
            if (entry.isDirectory()) await visit(relative);
            else if (entry.isFile() && relative.endsWith(suffix)) found.push(relative);
        }
    }
    await visit(directory);
    return found.sort();
}

async function verifyReferences(documentPath, text, references) {
    for (const reference of references) {
        const target = localPath(reference, documentPath);
        if (!target) continue;
        if (target.startsWith('../') || path.isAbsolute(target)) {
            errors.push(`${documentPath}: unsicherer lokaler Pfad ${reference}`);
            continue;
        }
        checkedAssets.add(target);
        if (!await exactFileExists(target)) errors.push(`${documentPath}: Datei fehlt oder Groß-/Kleinschreibung stimmt nicht: ${target}`);
    }
}

async function verifyJavascript() {
    const javascriptFiles = await walk('assets/js', '.js');
    for (const file of javascriptFiles) {
        const syntax = spawnSync(process.execPath, ['--check', path.join(ROOT, file)], {encoding: 'utf8'});
        if (syntax.status !== 0) errors.push(`${file}: JavaScript-Syntaxfehler: ${syntax.stderr.trim()}`);
        const source = await read(file);
        for (const match of source.matchAll(/\b(?:import|export)\s+(?:[^'";]+?\s+from\s+)?["']([^"']+)["']/g)) {
            if (!match[1].startsWith('.')) continue;
            const target = path.posix.normalize(path.posix.join(path.posix.dirname(file), match[1]));
            if (!await exactFileExists(target)) errors.push(`${file}: importierte Datei fehlt: ${target}`);
        }
    }
}

async function verifyStableFacts(index) {
    const {SITE_CONFIG} = await import(`${pathToFileURL(path.join(ROOT, 'assets/js/config.js')).href}?verify=1`);
    check(SITE_CONFIG.name === 'Erlebnishof Auszeit', 'config.js: falscher Betriebsname');
    check(SITE_CONFIG.timeZone === 'Europe/Berlin', 'config.js: Geschäftszeitzone muss Europe/Berlin sein');
    check(SITE_CONFIG.contact.email === 'hallo@erlebnishof-auszeit.de', 'config.js: falsche E-Mail-Adresse');
    check(SITE_CONFIG.contact.phoneHref === 'tel:+4994077249620', 'config.js: falsche Festnetznummer');
    check(JSON.stringify(SITE_CONFIG.regularHours.cafe) === JSON.stringify({days: [0, 5, 6], opens: '08:30', closes: '17:00'}), 'config.js: Hofcafé-Zeiten stimmen nicht');
    check(SITE_CONFIG.regularHours.shop.winter.opens === '08:00' && SITE_CONFIG.regularHours.shop.winter.closes === '19:00', 'config.js: Hofladen-Zeiten September–März stimmen nicht');
    check(SITE_CONFIG.regularHours.shop.summer.opens === '08:00' && SITE_CONFIG.regularHours.shop.summer.closes === '21:00', 'config.js: Hofladen-Zeiten April–August stimmen nicht');
    for (const value of [SITE_CONFIG.contact.email, SITE_CONFIG.contact.phoneHref.replace('tel:', ''), SITE_CONFIG.address.street]) {
        check(index.includes(value), `index.html: sichtbare stabile Angabe fehlt: ${value}`);
    }
}

async function verifyPublicFixture() {
    const fixture = JSON.parse(await read('tests/fixtures/current.json'));
    const {validateSnapshot} = await import(`${pathToFileURL(path.join(ROOT, 'assets/js/content.js')).href}?verify=1`);
    try {
        validateSnapshot(fixture);
    } catch (error) {
        errors.push(`tests/fixtures/current.json: ${error.message}`);
    }
    const serialized = JSON.stringify(fixture);
    check(!/(?:password|private|draft|sourcePath|config\.php)/i.test(serialized), 'öffentliche Testdaten enthalten ein privates Feld');
}

async function verifyImages(index) {
    const manifest = JSON.parse(await read('img/opt/manifest.json'));
    const media = JSON.parse(await read('data/media.json'));
    const configuredSources = new Set(media.assets.map((asset) => asset.source));
    const manifestSources = new Set(manifest.sources.map((source) => source.source.path));
    check(configuredSources.size === media.assets.length, 'data/media.json: Quellen müssen eindeutig sein');
    check(manifestSources.size === configuredSources.size
        && [...configuredSources].every((source) => manifestSources.has(source)), 'Bildmanifest und kuratierte Medienauswahl stimmen nicht überein');
    for (const source of manifest.sources) {
        const configured = media.assets.find((asset) => asset.source === source.source.path);
        if (!configured) continue;
        check(source.source.usage?.id === configured.id, `Bildmanifest: falsche Slot-Zuordnung für ${configured.id}`);
        check(source.variants.every((variant) => variant.bytes <= configured.maximumVariantBytes), `Bildmanifest: Variantenbudget überschritten für ${configured.id}`);
        if (configured.alt) check(index.includes(configured.alt), `index.html: freigegebene Bildbeschreibung fehlt: ${configured.id}`);
    }
    const generated = new Set(manifest.sources.flatMap((source) => source.variants.map((variant) => variant.path)));
    const imageReferences = collectMarkupReferences(index)
        .map((reference) => localPath(reference, 'index.html'))
        .filter((reference) => reference?.startsWith('img/opt/'));
    for (const reference of imageReferences) {
        check(generated.has(reference), `index.html: ${reference} ist keine aktuelle Manifest-Variante`);
        check(/-i[0-9a-f]{12}-c[0-9a-f]{20}-r[0-9a-f]{12}-\d+\.(?:avif|webp|jpg)$/.test(reference), `index.html: Bild-URL ist nicht unveränderlich gehasht: ${reference}`);
    }
    check(imageReferences.length > 0, 'index.html: keine optimierten Bilder eingebunden');
}

async function verifyDiscovery() {
    const sitemap = await read('sitemap.xml');
    const locations = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
    check(locations.length === 3, 'sitemap.xml: erwartet werden genau Startseite, Impressum und Datenschutz');
    check(locations.every((location) => location.startsWith(`${CANONICAL_ORIGIN}/`) && !location.includes('#')), 'sitemap.xml: nur echte kanonische URLs ohne Fragmente verwenden');
    const robots = await read('robots.txt');
    check(robots.includes(`Sitemap: ${CANONICAL_ORIGIN}/sitemap.xml`), 'robots.txt: Sitemap-Verweis fehlt');
    check(!/Disallow:\s*\/(?:styles\.css|assets|img)/i.test(robots), 'robots.txt: öffentliche Assets dürfen nicht gesperrt sein');
}

async function approximateInitialBytes(index) {
    const files = new Set(['index.html', 'styles.css']);
    for (const file of await walk('assets/js', '.js')) files.add(file);
    for (const reference of collectMarkupReferences(index)) {
        const local = localPath(reference, 'index.html');
        if (!local) continue;
        if (local.startsWith('assets/icons/') || local === 'img/toon_logo.webp') files.add(local);
    }
    const preload = index.match(/<link\b[^>]*rel=["']preload["'][^>]*imagesrcset=["']([^"']+)["']/i)?.[1];
    if (preload) {
        const candidates = preload.split(',').map((candidate) => {
            const [reference, descriptor] = candidate.trim().split(/\s+/);
            return {reference: localPath(reference, 'index.html'), width: Number.parseInt(descriptor, 10)};
        }).filter((candidate) => candidate.reference && Number.isFinite(candidate.width));
        const selected = candidates.find((candidate) => candidate.width >= 640) ?? candidates.at(-1);
        if (selected) files.add(selected.reference);
    }
    let bytes = 0;
    for (const file of files) {
        if (await exactFileExists(file)) bytes += (await stat(path.join(ROOT, file))).size;
    }
    check(bytes <= 700_000, `angenäherte mobile Erstlast ${bytes} Bytes überschreitet 700.000 Bytes`);
    return bytes;
}

async function main() {
    for (const file of REQUIRED_FILES) check(await exactFileExists(file), `Pflichtdatei fehlt: ${file}`);
    if (errors.length > 0) throw new Error('Pflichtdateien fehlen');

    const htmlFiles = ['index.html', 'impressum.html', 'datenschutz.html', '404.html'];
    const documents = new Map();
    for (const file of htmlFiles) {
        const markup = await read(file);
        documents.set(file, markup);
        await verifyReferences(file, markup, collectMarkupReferences(markup));
        check(/<html\b[^>]*\blang=["']de["']/i.test(markup), `${file}: lang="de" fehlt`);
    }
    const index = documents.get('index.html');
    const css = await read('styles.css');
    await verifyReferences('styles.css', css, collectCssReferences(css));

    check(/<link\b(?=[^>]*\brel=["']canonical["'])(?=[^>]*\bhref=["']https:\/\/erlebnishof-auszeit\.de\/["'])[^>]*>/i.test(index), 'index.html: kanonische URL fehlt');
    check(/href=["']impressum\.html["']/.test(index) && /href=["']datenschutz\.html["']/.test(index), 'index.html: direkte Links zu Impressum/Datenschutz fehlen');
    check(!/<form\b/i.test(index), 'index.html: Besucherformular ist nicht vorgesehen');
    check(!/(?:cdn\.jsdelivr\.net|bootstrap(?:\.min)?|google\.com\/maps\/embed|<iframe\b|consent-banner|hero-wheat|priceRange|["']Restaurant["'])/i.test(index), 'index.html: entfernte oder unzulässige Alt-Funktion gefunden');
    const automaticExternal = /<script\b[^>]*\bsrc=["']https?:\/\//i.test(index)
        || /<(?:img|iframe)\b[^>]*\bsrc=["']https?:\/\//i.test(index)
        || /<link\b(?=[^>]*\brel=["'](?:stylesheet|preload|modulepreload)["'])(?=[^>]*\bhref=["']https?:\/\/)[^>]*>/i.test(index);
    check(!automaticExternal, 'index.html: automatische externe Ressource im HTML gefunden');
    check(!/<script\b[^>]*type=["']application\/ld\+json["']/i.test(index), 'index.html: statisches JSON-LD darf den aktuellen Ausnahmen nicht widersprechen');
    check((index.match(/<h1\b/gi) ?? []).length === 1, 'index.html: genau eine H1 erwartet');

    await verifyJavascript();
    await verifyStableFacts(index);
    await verifyPublicFixture();
    await verifyImages(index);
    await verifyDiscovery();
    const initialBytes = await approximateInitialBytes(index);

    if (errors.length > 0) {
        for (const error of errors) console.error(`ERROR: ${error}`);
        process.exitCode = 1;
        return;
    }
    console.log(`site verification: OK — ${checkedAssets.size} lokale Verweise, angenäherte mobile Erstlast ${initialBytes} Bytes`);
}

main().catch((error) => {
    if (errors.length > 0) for (const problem of errors) console.error(`ERROR: ${problem}`);
    console.error(`site verification failed: ${error.message}`);
    process.exitCode = 1;
});
