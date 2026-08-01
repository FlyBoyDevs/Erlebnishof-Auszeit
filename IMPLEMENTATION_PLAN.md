# Implementation Plan — erlebnishof-auszeit.de

Status: agreed planning baseline after the grill-with-docs session, 22 July 2026.

This plan replaces the previous implementation plan completely. The canonical guest and editorial terms live in [CONTEXT.md](CONTEXT.md), durable choices are indexed in [the ADRs](docs/adr/README.md), and owner/provider work is separated in [MANUAL_TASKS.md](MANUAL_TASKS.md).

## 1. Intended outcome

Deliver a German-only, mobile-first website that feels warm, informal, family-oriented, and enjoyable to open while remaining fast, accessible, legally reviewed, simple to operate, and inexpensive to host on IONOS.

The release must:

- invite spontaneous visits without making guests complete a form;
- distinguish Hofcafé and self-service Hofladen hours and status clearly;
- make the Festnetz, directions, current Google menu, and Aktuelles & Termine easy to reach;
- describe changing food and products without publishing unstable prices, dishes, or stock;
- use authentic owner-supplied imagery without wheat or generic rustic clichés;
- replace Bootstrap with a tested local gallery implementation;
- give the two Bearbeitungsberechtigten a narrow PHP Redaktionsbereich;
- keep production editorial data independent from code deployments and rollback;
- load no Google embed and no unverified third party automatically;
- meet the agreed mobile performance and WCAG 2.2 AA release criteria;
- receive Rechtliche Freigabe before production publication.

## 2. Non-negotiable scope rules

- The public visitor experience remains one scrolling page.
- Impressum and Datenschutz are standalone, directly linkable pages.
- The site is German only and consistently uses **ihr/euch**.
- The food area is **Unser Hofcafé**, never Restaurant.
- There are no public contact, reservation, celebration, newsletter, or review forms.
- A Tischreservierungsanfrage is made by Festnetz or email and becomes a reservation only after direct confirmation.
- The current menu remains in Google Business Profile/Maps; no prices or copied menu appear on the site.
- Aktuelles & Termine uses readable cards, not a carousel.
- Permanent content galleries are manual only and never autoplay.
- The decorative hero may crossfade every 12 seconds under the shared Bewegungspräferenz.
- There is no separate hero/carousel pause control and no permanent mobile bottom action bar.
- No wheat stalks, sheaves, wheat fields, burlap styling, or generic Hofladen iconography is introduced.
- GoatCounter stays only if its verified production behaviour and Rechtliche Freigabe support the agreed configuration.
- Docker/nginx is a static local preview, not the production runtime and not a PHP environment.
- IONOS Apache/PHP is the production runtime.
- The admin remains narrow; stable copy, galleries, contacts, regular hours, and legal pages require a code release.
- One strong shared Betreiberkonto remains acceptable for the two Bearbeitungsberechtigten; public registration, account recovery, roles, and 2FA are out of scope.

## 3. Delivery and safety rules

- Work on a short-lived redesign branch and deploy it first to the isolated IONOS test environment.
- Treat each numbered slice below as a small reviewable commit or closely related commit group.
- Leave the repository in a runnable state after every slice.
- Do not reset, overwrite, or absorb unrelated working-tree changes.
- Inventory ignored and untracked files before changing image or deployment rules.
- Never delete original imagery as an optimization step. Archive only after explicit review and keep recovery possible.
- Never commit real passwords, password hashes, private editorial data, production uploads, backups, or environment paths.
- Never make production writes while developing or testing.
- Build one code/static release artifact. Keep staging configuration, production configuration, and mutable editorial data outside it.
- A rollback replaces release-managed code/static assets only. It must preserve newer production entries, uploads, backups, and trash.
- A legal-text edit is a review item, not a mechanical search-and-replace task.

## 4. Target information architecture

The mobile page order is:

1. Compact sticky header with logo, hamburger, and accessible Neu-Hinweis.
2. Hero with two separate opening statuses and prominent actions.
3. Short welcoming introduction.
4. Aktuelles & Termine, omitted when empty.
5. Unser Hofcafé.
6. Hofladen (Selbstbedienung).
7. Feiern & Gruppen.
8. Gut zu wissen, contact, regular hours, exceptions, and Google links.
9. Social and legal footer links.

Aktuelles & Termine ordering is:

1. Ongoing Öffentliche Termine, ending soonest first.
2. Upcoming Öffentliche Termine, starting soonest first.
3. Current Neuigkeiten, most recently made public first.
4. Three cards initially, followed by **„Weitere anzeigen“** when more exist.

## 5. Target architecture and ownership

| Concern | Authoritative source | Public result | Change path |
|---|---|---|---|
| Stable copy and page order | Repository | Committed HTML | Reviewed code release |
| Address, contacts, regular hours, links, gallery manifests | Repository-managed structured site data | Generated/validated committed HTML and base business data | Reviewed code release |
| Permanent source photos | Repository | Content-hashed responsive variants | Local image build and reviewed release |
| Aktuelles & Termine | Private versioned JSON | Current public JSON entries | Redaktionsbereich |
| Opening exceptions | Private versioned JSON | Current/upcoming public exceptions and status | Redaktionsbereich |
| Theme windows and mode | Private versioned JSON | One resolved Aktives Saisonthema | Redaktionsbereich |
| Editorial source images | Private upload storage | Bounded content-hashed public variants | Redaktionsbereich |
| Motion preference | Guest device after explicit action | Hero/effect behaviour | Guest |
| Aktuelles read version | Guest device after explicit action | Neu-Hinweis behaviour | Guest |

### 5.1 Repository-managed site data

Create one structured source for stable facts such as:

- venue name and canonical URL;
- postal address and geographic coordinates;
- Festnetz, secondary mobile, and hallo email address;
- separate Hofcafé and Hofladen regular schedules;
- route, current-menu, and social URLs;
- stable identifiers for one venue entity and its nested Hofcafé and Hofladen departments;
- gallery-manifest schema and image-slot definitions; approved image selections are populated in Slice 2.2.

A small local render/validation tool updates the committed static representation and fails when generated HTML, visible hours, contact details, or base structured data are stale. Define hero, gallery, editorial-card, logo, favicon, and social-image slot contracts before choosing generated widths. Production performs no build.

### 5.2 Private editorial contract

The versioned private model contains:

- entries with stable ID, type, editorial intent, bounded plain text, optional image-asset reference/image description, type-specific dates, and approval metadata;
- only draft, approved, archived, and trashed are stored as editorial intent; Geplant, Veröffentlicht, and Abgelaufen are derived from intent and time;
- a Neuigkeit has an optional display start and optional expiry; an Öffentlicher Termin has an optional display start plus required event start and optional end, but no competing expiry field;
- opening exceptions with stable ID, target Hofcafé/Hofladen/both, closure or replacement hours, bounded local dates, and approved/archive/trash intent;
- Spring, Summer, and Autumn start dates, each resolving to 14 consecutive calendar days;
- Themenmodus: Automatisch, Aus, or one named manual theme;
- schema version, write revision, recovery metadata, and the next relevant time boundary;
- a separate durable Aktuelles transition ledger containing a generation token, monotonic sequence high-water, and the authoritative per-entry visibility/material fingerprint plus assigned change version; ordinary content restore, cache deletion, code deploy, and rollback cannot reduce or replace it.

Calendar dates use ISO **YYYY-MM-DD**, wall times use **HH:mm**, and stored instants use RFC 3339 with an offset or UTC marker. All business interpretation uses Europe/Berlin. An inclusive end date ends at the following local midnight; an event without an end remains current until the local midnight after its start date. Invalid, ambiguous, impossible, or overlapping records are rejected before they can replace valid data.

Initial safety limits are explicit and configurable: at most 500 retained entries, 50 simultaneously public entries, 100 retained exceptions, 50 public exceptions, title 120 characters, body 3,000 characters, image description 300 characters, and a 256 KB public JSON response. Staging measurements may lower these limits; raising them requires renewed performance/storage review.

### 5.3 Public current-content contract

The public route **/content/current.json** returns a small JSON document containing:

- schema version;
- generation time and next transition time;
- release/schema/effect-capability version, general snapshot revision, and cache validator;
- the current ledger generation and maximum sequence among visible entries, or zero when there are none;
- each currently visible Neuigkeit and Öffentlicher Termin with its ledger-assigned generation/sequence change version;
- all approved non-expired exceptions beginning within the next 366 days, sorted by start/end/stable ID and bounded by rejecting approval beyond 50 rather than silently dropping one;
- one resolved Aktives Saisonthema and the effect currently available for it;
- public editorial image URLs and dimensions.

It never contains:

- drafts, archived/trash records, or embargoed future entries;
- private source-image paths or account/configuration data;
- past entries or expired exceptions;
- the full private theme schedule;
- filename-derived metadata.

The snapshot revision changes whenever any public representation changes. A new sequence is allocated whenever an entry transitions from non-visible to visible, regardless of whether time, a display-window edit, approval, restore, or re-publication caused it. A sequence is also allocated when a guest-visible material field changes while the entry remains visible: type, title, body, image, image description, or event start/end. Window-only edits allocate nothing only when they do not create a non-visible-to-visible transition; expiry/removal, draft edits, themes, and hours allocate nothing. The Neu-Hinweis is shown only when at least one current entry has a newer generation/sequence than the device’s explicitly stored read version, so an unread item that expires cannot leave a dot for absent content.

### 5.4 Time-aware resolution

On IONOS, Apache routes **/content/current.json** to an editorially read-only PHP resolver. It never changes editorial intent, but it may atomically write derived cache and transition-ledger state. It:

- checks the private write revision and recorded next transition;
- resolves the current state in Europe/Berlin;
- rebuilds the public cache under a lock when editorial data, release/schema/effect capability, or a time boundary changed;
- allocates and durably records an entry change revision exactly once when a scheduled/restored entry becomes public;
- preserves the ledger generation/high-water across normal restore, cache loss, deploy, and rollback;
- writes the cache atomically;
- supplies ETag and cache headers that cannot outlive the next transition;
- returns only the public contract.

The next transition also includes the moment an approved exception outside the 366-day horizon enters it. Because approval beyond 50 non-expired public exceptions is rejected, no relevant exception is silently hidden by the response cap.

Every successful admin mutation invokes the same reconciliation before returning success, while public GET requests handle elapsed time boundaries. This ensures an archive followed by restore is recorded even when no visitor requests the intermediate state. If derived reconciliation fails after a valid editorial write, the admin reports publication as incomplete, leaves a durable dirty marker, and the next admin/public request retries without reusing a sequence.

This makes scheduled appearance, expiry, exceptions, and themes change without an admin visit or cron job. Docker maps a sanitized test fixture from the test-fixture directory to the same URL; that file is excluded from every IONOS artifact.

An open page schedules the earliest of the snapshot’s next transition, the next regular opening/closing boundary, local midnight/month change, and a five-minute maximum refresh. It refetches/re-evaluates on that timer, pageshow, visibility return, reconnect, and significant clock change. A response whose next transition is already past is stale and triggers one guarded refresh; until successful, the page shows a conservative unavailable state rather than an open-now claim.

If the route fails, stable content and regular hours remain readable, but the page says current information is unavailable and does not claim a live opening status. A noscript notice gives the same warning and points guests to the regular hours and main telephone. A full disaster restore that cannot prove it has the newest ledger creates a new random generation; any current entry in a different generation is treated as unread, preventing a lost high-water value from suppressing new information.

### 5.5 Production storage boundaries

Keep these areas separate:

- release-managed code and committed static assets;
- environment configuration and password hash outside repository/public root;
- private editorial JSON and private source uploads outside public root;
- publicly readable generated editorial variants;
- public current-content cache;
- durable Aktuelles transition/revision ledger;
- bounded backups;
- recoverable Papierkorb.

Deploy and rollback scripts must use explicit allowlists. They must never mirror-delete or overwrite mutable data directories.

## 6. Implementation slices

### Dependency gates

- Before Slice 1.2, complete the owner fact, contact, hours, Google/social URL, and Google Business Profile access checks in MANUAL_TASKS.md.
- Before Slice 2.2, complete image provenance and initial owner selection approval; selected source images must remain tracked/recoverable in a fresh checkout.
- Before Slice 3.5, approve the food claims and explicitly confirm that the new focus intentionally omits or de-emphasizes the breakfast message present on the current site.
- Before Milestone 4, verify the IONOS PHP, Exif/orientation, Apache rewrite/header/compression, private-storage, locking, upload, and permission capabilities.
- Before automatic backup/Papierkorb pruning is enabled, approve its limits, privacy treatment, off-host owner, and recovery schedule.
- Rechtliche Freigabe occurs only after the final staging build and its complete production-request/data-flow inventory exist.

### Milestone 0 — Preserve the baseline and establish checks

#### Slice 0.1 — Record the current state

Work:

- inventory tracked, ignored, and untracked files;
- record existing local changes without resetting them;
- capture mobile and desktop screenshots;
- record HTML/CSS/JavaScript sizes, initial transfer, third-party requests, Lighthouse results, broken links, console errors, and current admin risks;
- identify current production-only configuration and writable paths without printing secrets.

Acceptance:

- a dated baseline report is committed;
- existing source images and editorial files are accounted for;
- no unrelated file is modified or removed;
- every later performance claim can be compared with the baseline.

#### Slice 0.2 — Create one verification entry point

Work:

- pin the local Node/image/test toolchain;
- add documented commands for formatting/syntax, HTML validation, internal links, case-sensitive paths, JSON schemas/fixtures, image freshness, and missing assets;
- add separate PHP syntax/unit/security-test commands without making nginx execute PHP;
- add repeatable browser test and Lighthouse commands.

Acceptance:

- one documented command runs all static checks;
- PHP checks run through an available PHP CLI or a dedicated test container;
- one documented command starts the static Docker preview;
- a deliberately broken fixture proves each validator can fail.

#### Slice 0.3 — Define the test profiles

Record:

- pinned Lighthouse/Chrome versions;
- cold-cache mobile network and CPU settings;
- representative viewport and ordinary-phone profile;
- supported current Safari/iOS, Chrome/Android, Firefox, and desktop Chrome/Edge targets;
- accessibility manual-test setup;
- production-like hostname rules for analytics tests.

Acceptance:

- three repeatable runs produce materially comparable measurements;
- the report distinguishes lab interaction evidence from post-launch field INP.

### Milestone 1 — Establish stable data and frontend foundations

#### Slice 1.1 — Restructure local assets without changing behaviour

Work:

- create the new local ES-module shell and extract only stable logic that survives the redesign;
- consolidate styles toward one maintained site stylesheet;
- create clear directories for source media, generated media, icons, modules, schemas, fixtures, and tests;
- replace legacy features vertically rather than spending work modularizing language, Maps, Bootstrap, or carousel code that will be removed;
- preserve current public URLs temporarily or add explicit redirects where needed.

Acceptance:

- the current page still renders before visual redesign begins;
- no external framework is added;
- Docker copies every required committed public asset;
- missing modules/assets produce test failures rather than silent 404s.

#### Slice 1.2 — Define stable site data

Work:

- encode approved stable contacts, address, canonical URLs, separate hours, and coordinates in the repository-managed source;
- define the gallery-manifest schema plus hero, permanent-gallery, editorial-card, logo, favicon, and social-preview slot contracts without selecting the final images yet;
- add the small static render/validation tool;
- generate or validate matching visible HTML and base business data;
- make all date logic explicitly use Europe/Berlin.

Acceptance:

- tests cover Hofcafé Friday–Sunday 08:30–17:00;
- tests cover Hofladen September–March 08:00–19:00 and April–August 08:00–21:00;
- holiday dates follow regular hours unless an exception exists;
- visible facts and base structured facts cannot silently diverge;
- no Restaurant type, combined schedule, price range, or fixed menu exists.

#### Slice 1.3 — Define editorial schemas and sanitized fixtures

Work:

- create versioned private-entry, opening-exception, theme-setting, and public-snapshot schemas;
- create representative valid/invalid fixtures with fictional staging content;
- define bounded plain-text fields and normalized identifiers rather than allowing editor-supplied HTML;
- add shared date/status/theme resolution tests;
- define compatible schema migration rules.

Acceptance:

- invalid types, unknown fields, malformed dates, unsafe paths, duplicate IDs, and overlapping exceptions/windows are rejected;
- markup/script payloads remain harmless visible text and overlong/control-character input is rejected;
- tests cover DST changes, leap-year February, month boundaries, year-crossing Christmas/Winter, scheduled appearance, expiry, and no-end event behaviour;
- the public fixture contains no real secrets, private paths, drafts, or unapproved future announcements.

#### Slice 1.4 — Serve the same public URL locally and in production

Work:

- make nginx serve the sanitized fixture at **/content/current.json**;
- add a production rewrite placeholder for the later PHP resolver;
- configure local/staging analytics off by hostname;
- add unavailable/malformed/old-schema frontend fixtures.

Acceptance:

- identical visitor JavaScript works against local fixture and production contract;
- Docker does not need PHP;
- the fixture is mounted or copied only into the Docker image and is absent from the IONOS release manifest;
- the page degrades safely for failed, malformed, or unsupported snapshots.

### Milestone 2 — Repair the media pipeline

#### Slice 2.1 — Make core image generation collision-safe

Work:

- update the local optimizer to use normalized source identity plus content hash;
- generate placement-appropriate AVIF, WebP, and JPEG widths;
- auto-orient pixels, strip metadata/GPS, and record dimensions/byte sizes;
- pin Sharp and relevant image-tool versions;
- add manifest and freshness validation;
- make orphan reporting non-destructive.

Acceptance:

- two files with the same basename or different source extensions cannot overwrite one another;
- a second unchanged generation is idempotent;
- changed sources make validation fail until regenerated;
- outputs have correct orientation, dimensions, formats, and no unwanted metadata;
- public names may safely receive one-year immutable caching.

#### Slice 2.2 — Curate core imagery

Work:

- review duplicate, unused, oversized, HEIC, video, and temporary assets;
- choose the hero and 5–8 permanent gallery sources per section;
- populate the approved gallery/hero manifests and their image descriptions;
- retain every selected original in a tracked source location that survives a fresh checkout; an ignored local archive is never its sole recovery copy;
- remove generated output from ignore rules and commit the required variants;
- create a deployment allowlist that excludes sources, archives, videos, and tooling.

Acceptance:

- a fresh checkout contains every public variant referenced by the site;
- no public markup references a multi-megabyte source photo;
- owner/user image provenance is recorded;
- no wheat imagery is selected;
- nothing is permanently deleted as part of this slice.

#### Slice 2.3 — Add responsive image markup

Work:

- use picture/source/srcset/sizes with intrinsic width and height;
- preload and eagerly load only the actual first hero/LCP image;
- lazy-load below-fold gallery images and later hero images;
- set fetch priority deliberately;
- use meaningful alt text for informative images and empty alt for truly decorative ones.

Acceptance:

- no layout shift is caused by missing media dimensions;
- initial navigation does not fetch all hero/gallery media;
- a 320 px phone does not receive desktop-width gallery files;
- missing variants and inaccurate sizes fail verification.

### Milestone 3 — Build the visitor experience

#### Slice 3.1 — Create the final semantic German shell

Work:

- implement the agreed page order;
- add skip link, main landmark, semantic sections, and consistent heading levels;
- remove English duplication, language controls, and language preference storage;
- migrate the existing legal content into accessible standalone Impressum and Datenschutz page shells, pending final legal wording, and replace modal links with direct links;
- remove the non-functional consent notice and old map iframe;
- add a noscript warning that live status/current exceptions cannot be verified and offers the main telephone;
- keep useful content and contact details understandable without JavaScript.

Acceptance:

- the document language is German;
- the HTML validator and heading/landmark checks pass;
- keyboard users can skip the header;
- no language-toggle, translation-state, cookie-banner, modal-legal, or embedded-map code remains;
- Impressum and Datenschutz have direct URLs and footer links.

#### Slice 3.2 — Implement the visual system

Work:

- define warm ivory, deep moss green, charcoal, muted terracotta, and restrained honey tokens;
- establish mobile-first typography, spacing, cards, buttons, focus, borders, and shadows;
- use the existing whimsical logo without pretending its bitmap wrapper is a true vector;
- remove duplicated/conflicting CSS and generic wheat/rustic motifs;
- keep authentic photography dominant.

Acceptance:

- 320 px, common phone, tablet, laptop, and wide desktop screenshots have no clipping or horizontal scroll;
- text/background and control contrast meet WCAG 2.2 AA;
- visible focus is clear in every theme;
- the design reads as friendly Hofcafé/family visit, not formal restaurant or generic farm template.

#### Slice 3.3 — Build the compact header and navigation

Work:

- keep the compact sticky logo/header and three-line menu;
- use labels matching the final section headings;
- implement keyboard/focus-safe menu open/close;
- reserve space for the Neu-Hinweis on the closed hamburger;
- hide the Aktuelles navigation/action and badge after a successful empty snapshot;
- point the Aktuelles navigation/action to the visible unavailable panel when the snapshot is failed/stale;
- avoid a permanent bottom action bar.

Acceptance:

- menu state is announced correctly;
- focus is not lost or trapped when the menu closes;
- active links and touch targets are clear;
- the badge is visible without relying on color alone and has an accessible label.

#### Slice 3.4 — Build hero, statuses, and primary actions

Work:

- show Hofcafé-Öffnungsstatus and Hofladen-Öffnungsstatus in separate rows;
- derive each from regular hours plus today’s exception;
- add primary Festnetz **„Anrufen“** and route actions;
- add the hero Aktuelles action only when current entries exist, or point it to the visible unavailable panel while current data is failed/stale;
- include a secondary email route without making reservations feel mandatory;
- implement a 12-second gentle decorative crossfade;
- pause rotation when the document is hidden, during relevant interaction, for reduced motion, or when movement is off;
- schedule status recalculation at the next open/close, local-midnight, or seasonal shop boundary and on page resume/reconnect.

Acceptance:

- status tests pass across weekdays, shop-season boundary, closure/replacement exceptions, and DST dates;
- no single ambiguous **„Erlebnishof geöffnet“** label exists;
- Hofladen status says self-service and never implies café staff are present;
- no occupancy or table-availability claim exists;
- a page left open across 08:30, 17:00, midnight, March/April, or August/September updates without a manual reload;
- only the first hero image is eager;
- there are no hero arrows, dots, swipe gestures, or separate pause control.

#### Slice 3.5 — Write and place the stable content

Work:

- add a concise warm welcome;
- replace Speisekarte with **Unser Hofcafé**;
- describe coffee, Brotzeit, lunch, mostly house-made cake selection, and mostly regional sourcing with accurate qualifiers;
- describe Hofladen examples only with **„Je nach Saison und Verfügbarkeit“**;
- add **Feiern & Gruppen** for birthdays, family celebrations, and small company/team groups;
- use phone/email calls to action only.

Acceptance:

- copy consistently uses ihr/euch;
- there are no prices, fixed dishes, promised inventory, or unsupported all-local/all-homemade/direct-from-farm claims;
- there is no contact/reservation/celebration form or honeypot;
- the word Restaurant is absent from guest-facing copy.

#### Slice 3.6 — Replace Bootstrap with the Bilderkarussell

Work:

- build one reusable local gallery component with swipe, previous/next buttons, position indicators, keyboard operation, and a fullscreen dialog;
- use local SVG icons;
- implement focus entry, confinement, Escape close, background inertness, and focus restoration for the dialog;
- support zero, one, and multiple images;
- migrate Hofcafé, Hofladen, and Feiern & Gruppen galleries;
- remove Bootstrap CSS/JavaScript/Icons and jsDelivr requests.

Acceptance:

- galleries never autoplay;
- swipe and buttons do not create double navigation;
- Arrow keys, Home/End where appropriate, Tab, Shift+Tab, Enter/Space, and Escape are tested;
- one-image galleries hide meaningless controls;
- dialog focus and screen-reader labelling pass;
- no Bootstrap/jsDelivr request or unused compatibility CSS remains.

#### Slice 3.7 — Implement Aktuelles & Termine

Work:

- fetch and validate the public snapshot;
- render text-first cards with one optional responsive image using safe DOM text APIs, never editor-controlled HTML;
- order Termine and Neuigkeiten according to section rules;
- show three initially and implement **„Weitere anzeigen“**;
- reuse the accessible fullscreen image dialog;
- hide the entire section when there are no current entries;
- show an unobtrusive unavailable state when the snapshot fails.

Acceptance:

- the envelope, version, revisions, entries, exceptions, theme, and every public URL must all pass the strict public schema; any failure uses the conservative whole-snapshot unavailable state rather than a partial live status;
- drafts, future embargoed entries, expired entries, and private paths never render;
- every flyer has real title/date/description text outside the image;
- cards work without an image;
- passed Termine disappear after their defined end;
- preview and public output use the same safe renderer and schema-approved same-origin editorial image URLs.

#### Slice 3.8 — Implement explicit read state

Work:

- add **„Aktuelles auf diesem Gerät als gelesen markieren“**;
- treat absence of a stored read version as no matching generation/sequence, so a first-time guest sees the indicator when at least one current entry exists;
- store only the current generation plus highest sequence among currently visible entries after that explicit action;
- add a reset action;
- display the Neu-Hinweis in the closed hamburger and section;
- announce state changes accessibly;
- handle unavailable or blocked device storage without breaking the page.

Acceptance:

- merely loading, scrolling, opening a card, or opening an image writes nothing;
- a theme, opening exception, expiry, or draft save does not trigger the red dot;
- a newly visible or materially updated entry brings the indicator back;
- a generation mismatch with at least one current entry brings the indicator back;
- expiry/removal of an unread item also removes its contribution to the indicator;
- restoring/re-publishing an entry into the current collection allocates a new change revision and may bring the indicator back;
- reset removes the remembered revision;
- no indicator is shown when no current entry exists;
- no personal identifier or cross-device history is created.

#### Slice 3.9 — Add visit planning and outbound contact

Work:

- show separate regular hours and visible upcoming/current exceptions;
- add address, Festnetz, secondary mobile, hallo email, route link, and current-menu link;
- add Gut zu wissen only after owner fact approval;
- use explicit social links without embeds;
- make tap targets and copy useful on mobile.

Acceptance:

- no Google/social resource loads before a guest chooses a link;
- the current-menu link is visibly external and does not imply the website owns the menu;
- opening facts match the repository data and snapshot;
- no unsupported accessibility, payment, dog, family, or facility claim appears.

#### Slice 3.10 — Add the core seasonal system

Work:

- implement Themenmodus precedence and calendar resolution;
- implement restrained static theme accents;
- port the existing snow idea into a bounded Winter effect;
- add the single **„Bewegung ausschalten/einschalten“** control;
- remember the motion choice only after explicit action;
- default motion off when reduced motion is requested;
- connect hero rotation and effect engine to the same preference.

Acceptance:

- Christmas, Winter, and all three 14-day windows resolve correctly at local boundaries;
- overlapping editable windows are rejected by the admin contract;
- Aus removes all seasonal decoration;
- movement off retains the active static theme;
- reduced motion causes no automatic movement;
- no content, control, or pointer target is blocked by decoration;
- ordinary-phone traces show no long seasonal-animation tasks.

### Milestone 4 — Rebuild the narrow PHP Redaktionsbereich

#### Slice 4.1 — Establish private configuration and preflight

Work:

- move environment paths and the password hash outside repository/public root;
- define explicit private data, revision-ledger, private upload, public variant, cache, backup, and Papierkorb paths;
- add CLI/startup preflight that reports capabilities without revealing secrets or complete paths;
- fail closed when a required path, rewrite rule, decoder, or extension is unavailable.

Acceptance:

- no secret appears in Git, HTML, logs, diagnostics, or error output;
- staging and production configuration cannot point to the same mutable directories;
- private paths are not retrievable over HTTP;
- startup refuses unsafe permissions or a publicly reachable private-data directory.

#### Slice 4.2 — Implement locked atomic storage primitives

Work:

- implement same-filesystem lock, temporary write, validation, fsync/close where available, and atomic rename helpers;
- implement bounded backup and Papierkorb primitives without enabling automatic pruning yet;
- implement compare-and-swap writes requiring the form’s expected writeRevision;
- implement a separate durable revision allocator/transition ledger with random generation, monotonic sequence, and per-entry visibility/material fingerprints;
- preserve write/revision generation and high-water values during normal content restore and code rollback.

Acceptance:

- concurrent processes cannot corrupt a document or allocate the same revision;
- two tabs based on one writeRevision cause the second save to receive a conflict rather than overwrite the first;
- interrupted/failed writes preserve the last valid file;
- cache deletion and ordinary backup restore cannot decrease or reuse a news revision;
- a simulated disaster recovery without the newest ledger creates a new generation instead of reusing an older device-visible sequence;
- conflict errors preserve the editor’s text and offer reload/copy/reconcile rather than silent loss.

#### Slice 4.3 — Harden the shared owner session and protect diagnostics

Work:

- use generic login errors;
- implement a bounded, expiring throttle store using the atomic primitives, minimized network keys, documented unlock behaviour, and only provider-trusted proxy headers;
- regenerate session ID after authentication;
- set Secure, HttpOnly, and appropriate SameSite cookies;
- add idle and absolute session expiry;
- require CSRF tokens for every state-changing request;
- make logout a POST action;
- add authentication, no-store, noindex, content-type, and frame protections;
- expose capability diagnostics only after authentication.

Acceptance:

- absent/invalid CSRF tokens reject every mutation;
- repeated failures throttle without permanent lockout or unbounded network data;
- spoofed forwarding headers cannot bypass or lock out arbitrary clients;
- the session identifier changes at login;
- unauthenticated requests expose no data, preview, source image, or diagnostic detail;
- back-button/cache testing does not reveal an authenticated page after logout;
- throttle retention and network-data treatment are included in legal review.

#### Slice 4.4 — Implement the versioned editorial store

Work:

- validate the complete proposed model before writing;
- derive Geplant, Veröffentlicht, and Abgelaufen from approved intent plus dates rather than storing redundant mutable states;
- preserve bounded timestamped data backups and recovery timestamps;
- implement archive, Papierkorb, restore, and schema migration;
- implement configurable caps, initially proposing the last 30 valid data backups and 30-day recoverable trash;
- use additive expand/contract migrations so the tested rollback release can read the new schema; defer destructive cleanup until the rollback window closes.

Acceptance:

- invalid schema cannot replace valid data;
- every mutation uses expected writeRevision and reports a useful stale-edit conflict;
- restore works from a backup and from Papierkorb without reducing the revision allocator;
- disk-limit behaviour fails safely and explains the remedy;
- rollback code reads the expanded schema through a tested compatibility path and never overwrites an unsupported schema;
- only recovery/write timestamps are retained; no misleading individual audit trail is claimed for the shared account.

#### Slice 4.5 — Implement the time-aware public resolver

Work:

- resolve entry visibility, the 366-day bounded opening-exception horizon, and Aktives Saisonthema in Europe/Berlin;
- calculate the next transition, including an out-of-horizon exception entering the horizon, and the release/schema/effect cache key;
- allocate a per-entry change version for every non-visible-to-visible transition and every material edit that remains visible;
- expose ledger generation and maximum current sequence separately from snapshotRevision;
- build and replace cache/ledger state atomically;
- invoke reconciliation after every successful admin mutation as well as on public boundary requests;
- add ETag/conditional response and boundary-safe cache headers.

Acceptance:

- a scheduled entry appears after its boundary without an admin save;
- an expired entry disappears and no longer contributes to the indicator;
- a scheduled/restored entry receives exactly one new revision even across cache loss or retry;
- archive then restore between two visitor requests still records the non-visible-to-visible transition exactly once;
- moving a planned start to now or extending an expired item back into visibility allocates exactly once; a window edit that does not make an entry visible allocates nothing;
- expiry/removal, hours, and theme changes allocate no news revision;
- ordinary restore/rollback never lowers the allocator, and a disaster recovery with a proven current generation seeds it above every recovered ledger/public-cache sequence;
- if the newest high-water cannot be proven during disaster recovery, a new generation is created and current entries are treated as unread;
- release/schema/effect changes invalidate the derived cache;
- first request after a boundary never serves the previous state as current;
- future embargoed entry text cannot be fetched from the public route;
- public exceptions are deterministically start/end/ID ordered, and approval of a 51st non-expired exception is rejected rather than truncating output;
- failure returns a safe status and never leaks a PHP trace or path.

#### Slice 4.6 — Implement safe editorial image handling

Work:

- accept only explicitly supported raster formats such as JPEG, PNG, and WebP;
- validate upload status, configurable 12 MiB byte limit, Fileinfo MIME, decoded image, maximum 8,000 px per dimension/40 megapixels, and decompression limits;
- reject SVG, GIF, HEIC, malformed, or renamed non-image input with a useful German message;
- decode, orient through verified Exif support, strip metadata, and re-encode bounded public WebP/JPEG variants, adding AVIF only when verified on IONOS;
- give every upload a stable opaque asset ID and put its content hash in its public variant names;
- allow reuse only through an explicit asset reference and never trash variants still referenced by another record;
- retain the source privately and serve authenticated previews with no-store;
- move unreferenced deleted sources/variants to Papierkorb.

Acceptance:

- malformed, oversized, extreme-dimension, wrong-MIME, and unsupported files are rejected;
- only decoded pixels are re-encoded publicly; suspicious originals remain private and are never served;
- no EXIF GPS or private filename is public;
- public images have intrinsic dimensions and byte caps;
- identical uploads attached to two records cannot be broken by deleting one record;
- a failed conversion does not create a partial entry or destroy a previous image;
- image replacement and restore do not break an already published page.

#### Slice 4.7 — Implement Neuigkeit and Öffentlicher Termin editing

Work:

- let editors choose draft, approved, archived, or trash intent and clearly show derived Entwurf, Geplant, Veröffentlicht, Abgelaufen, Archiviert, and Papierkorb states;
- allow one optional Beitragsbild with image description;
- provide a mobile viewport Vorschau through the same safe renderer used publicly;
- support optional Neuigkeit display start/expiry;
- support optional Termin display start plus required start/optional end, with no separate competing expiry;
- make public ordering visible in preview;
- submit expected writeRevision on every save;
- offer recoverable archive/delete actions.

Acceptance:

- save-as-draft never changes public output;
- publication requires title and meaningful text;
- plain text is encoded correctly in HTML, attribute, and JSON contexts; editor HTML/URLs never become executable;
- Termine require a valid start, cannot end before they begin, and without an end remain current until the next local midnight;
- a future approved display start is shown as Geplant without storing that derived state;
- stale tabs cannot overwrite newer work;
- the laptop workflow is efficient and urgent phone edits remain usable;
- changes to type/title/body/image/image description/event start/end while visible allocate a new entry revision;
- display-window-only changes allocate nothing unless they cause a non-visible-to-visible transition, which always gets one new sequence.

#### Slice 4.8 — Implement opening exceptions

Work:

- choose Hofcafé, Hofladen, or both;
- support full-day closure or replacement opening interval;
- support one day or a bounded inclusive range;
- show the resulting guest status and structured-data preview;
- allow approved, archive, trash, and recovery intent;
- submit expected writeRevision on every save.

Acceptance:

- invalid times, end-before-start, and conflicting rules are rejected with actionable messages;
- a Hofladen-only exception never changes Hofcafé;
- an approved future exception enters the public 366-day horizon automatically, and approval beyond 50 non-expired exceptions is rejected;
- current status, hours section, and structured data resolve the same rule;
- expired exceptions stop affecting output automatically.

#### Slice 4.9 — Implement theme windows and mode

Work:

- let an editor select the start of each Spring, Summer, and Autumn Themenfenster and show the derived inclusive end at start plus 13 days;
- implement Automatisch, Aus, and named Manuelle Themenwahl;
- show a persistent warning while a manual theme is forced;
- preview static and movement-off states;
- submit expected writeRevision and publish changes immediately after explicit save.

Acceptance:

- fixed Christmas/Winter ranges and editable windows cannot overlap;
- editable windows cannot overlap one another;
- a manual theme wins until changed;
- Aus and Bewegung ausgeschaltet remain distinct;
- a configured theme whose moving effect has not shipped still has a safe static presentation.

#### Slice 4.10 — Migrate existing news safely

Work:

- inventory existing manifest records and news images;
- copy selected sources into private staging storage;
- create structured draft records;
- ask the owner to approve real metadata;
- publish only approved records;
- retain the old admin and source data recoverably until acceptance.

Acceptance:

- no date/title is inferred from a filename;
- ambiguous names such as shortened numeric dates remain unresolved drafts;
- migrated images receive the same validation and public variants as new uploads;
- imported revision data cannot reduce/reuse the production allocator;
- the old manifest/image proxy is removed only after staging parity and backup verification.

#### Slice 4.11 — Complete admin accessibility and recovery

Work:

- add semantic labels, instructions, error summaries, focus management, live status, touch targets, and zoom/reflow support;
- prevent accidental navigation loss when unsaved changes exist;
- provide a clear stale-write reconcile workflow;
- document backup/Papierkorb restore inside the owner guide.

Acceptance:

- automated WCAG checks pass;
- keyboard, screen-reader, 200–400% zoom/reflow, and phone emergency-edit tests pass;
- errors preserve safe field input and move focus to a useful summary;
- backup and trash restoration are demonstrated with staging data.

### Milestone 5 — Privacy, legal alignment, search, and server behaviour

#### Slice 5.1 — Audit automatic third-party requests

Work:

- verify that the earlier Bootstrap/jsDelivr and Google Maps removal is complete and remove any remaining unused external resource;
- keep Maps, menu, and social services as explicit outbound links;
- inventory every request from a cold production-like load;
- condition GoatCounter on the exact production hostname and verified decision.

Acceptance:

- local and staging loads make no GoatCounter request;
- no Google/social request occurs before a click;
- production makes only documented automatic requests;
- no consent banner is shown unless legal review changes the architecture.

#### Slice 5.2 — Align legal pages with real behaviour

Work:

- finalize the standalone page behaviour and apply only owner/qualified-reviewer-approved Impressum and Datenschutz wording;
- inventory hosting logs, admin processing, uploads/backups, GoatCounter, explicit device preferences, and outbound links for the reviewer;
- correct legal text only from owner/qualified-reviewer instructions;
- test that behaviour matches the approved description.

Acceptance:

- no modal or JavaScript is required to reach legal information;
- legal pages work with styles/scripts blocked;
- no suspected tax-number or statutory-reference issue is silently guessed;
- Rechtliche Freigabe is recorded after production behaviour is fixed.

#### Slice 5.3 — Implement linked business data

Work:

- emit stable linked entities for Erlebnishof Auszeit Hofcafé as CafeOrCoffeeShop and Erlebnishof Auszeit Hofladen as Store;
- use one LocalBusiness graph/object for Erlebnishof Auszeit with the two stable-ID components nested through Google’s supported **department** relationship;
- attach the same verified address and correct separate schedules to the two departments;
- combine repository regular hours with current exceptions from the public snapshot;
- remove the legacy static graph and create exactly one complete JSON-LD element only after the current snapshot validates; remove/omit it for stale or failed current data rather than leaving misleading exceptions;
- include visible contact and canonical facts only;
- omit Restaurant, Event, prices, priceRange, and copied-menu claims.

Acceptance:

- visible page and rendered data match for both components;
- exactly one business graph exists after JavaScript resolution;
- the parent venue carries no invented combined opening schedule;
- Hofladen seasonal hours resolve for the relevant year;
- exceptions affect only their targets;
- rendered-source validation passes;
- test documentation states that validation does not guarantee Google presentation.

#### Slice 5.4 — Correct metadata, sitemap, and discoverability

Work:

- add accurate German title and description, canonical URL, Open Graph/social metadata, approved 1200×630 image, and local favicon set;
- remove English alternates and stale metadata;
- make sitemap list only real canonical URLs, never fragments;
- update last-modified values only for real content changes;
- link the sitemap from robots.txt;
- ensure admin and staging have independent noindex protections.

Acceptance:

- no nonexistent URL or page fragment appears in sitemap;
- canonical/social URLs use production HTTPS;
- link/image/case checks pass on a case-sensitive filesystem;
- social preview and favicon tests pass;
- Search Console submission remains a manual post-launch task.

#### Slice 5.5 — Configure Apache/nginx security, caching, and errors

Work:

- add compression and correct MIME types;
- cache content-hashed assets long-term and immutable;
- make HTML revalidate and admin responses no-store;
- cap current-content caching at its next transition;
- add a narrowly scoped Content Security Policy, Referrer Policy, nosniff, frame restrictions, and appropriate Permissions Policy;
- enable HSTS only after HTTPS/subdomain readiness is confirmed;
- return real 404s for unknown paths;
- keep nginx preview behaviour aligned where applicable.

Acceptance:

- header tests pass for public, legal, content, admin, and missing routes;
- hashed images never reuse a URL for changed bytes;
- rollback and mutable data are unaffected by cache policy;
- no catch-all route returns the homepage as HTTP 200;
- the production CSP permits only the explicitly reviewed GoatCounter origins when GoatCounter is enabled.

### Milestone 6 — Verify, stage, approve, and release

#### Slice 6.1 — Automated public-site coverage

Cover:

- internal links and exact external URLs;
- separate schedule/status/date boundaries;
- snapshot failure, stale schema, empty, one, three, and many entries;
- card ordering and **„Weitere anzeigen“**;
- read/reset state and no automatic storage;
- header menu and Neu-Hinweis;
- gallery swipe/keyboard/dialog/focus;
- hero and theme movement rules;
- structured-data generation;
- prohibited automatic third-party requests;
- server headers, caches, and 404s.

Acceptance:

- the supported browser matrix passes;
- tests run from a fresh checkout;
- failures preserve screenshots/traces without secrets.

#### Slice 6.2 — Security and admin coverage

Cover:

- authentication, generic errors, throttle, session rotation/expiry, logout, and CSRF;
- private-path denial and authenticated media;
- stored/reflected script payloads, output escaping, path traversal, and unsafe redirect/input cases;
- schema validation, concurrent/failed atomic writes, migrations, backups, and restore;
- upload MIME/decode/dimension/metadata/content-hash tests;
- staging/production separation;
- resolver boundaries and cache races;
- deployment/rollback preservation of mutable data.

Acceptance:

- a documented threat model/risk rubric covers authentication, storage, uploads, rendering, public resolution, deployment, and recovery, with no known critical/high risk left open;
- tests use fixtures, never production credentials/data;
- recovery is demonstrated, not merely documented.

#### Slice 6.3 — Deploy isolated IONOS staging

Work:

- deploy the review artifact from the redesign branch;
- configure separate private/public writable paths;
- enable HTTPS, authentication, and noindex;
- disable GoatCounter;
- seed test-only editorial content;
- verify PHP/GD/Exif/Apache/filesystem behaviour.

Acceptance:

- staging cannot read/write production data;
- source uploads are not public;
- the Docker-only current-content fixture is absent;
- every visitor/admin flow works on actual IONOS;
- backup and rollback are rehearsed before final evidence is collected.

#### Slice 6.4 — Accessibility release evidence

Verify both public site and Redaktionsbereich with:

- automated WCAG analysis;
- complete keyboard operation and visible focus;
- screen-reader landmarks, headings, status announcements, buttons, forms, carousel, and dialog;
- contrast in base and every static seasonal theme;
- 200%, 300%, and 400% zoom/reflow;
- text spacing and long German content;
- touch targets and orientation;
- reduced motion and explicit movement-off;
- meaningful/empty alt decisions.

Acceptance:

- WCAG 2.2 AA is met as an internal release criterion;
- known limitations are resolved before launch rather than hidden behind a score.

#### Slice 6.5 — Mobile performance release evidence

Run three cold-cache tests against IONOS staging after the final content and every effect included in the core release are present.

Because ordinary staging deliberately disables GoatCounter, add a measured reserve for its exact production loader/request bytes and exercise the captured loader cost with its analytics send intercepted. The final production smoke then verifies the real production-only request once before the release is accepted.

Acceptance:

- Lighthouse Mobile Performance is at least 90 in all three runs;
- median LCP is at or below 2.5 seconds;
- median CLS is at or below 0.1;
- initial transfer before below-fold media is at or below 700 KB;
- the totals include the reserved measured GoatCounter transfer/runtime cost when GoatCounter is retained;
- scripted interactions stay within the 200 ms response target;
- traces show no seasonal-effect long tasks or gallery jank;
- real field INP is marked as post-launch monitoring, not falsely certified from Lighthouse.

#### Slice 6.6 — Owner and legal approval

Release is blocked until:

- owner-approved facts, copy, images, links, and Gut zu wissen are present;
- hallo email and Festnetz actions work;
- the owner approves phone and laptop presentation and workflow;
- GoatCounter behaviour is verified or GoatCounter is disabled;
- Google Business Profile access, menu link, website URL, phone, regular hours, and launch-period special hours are synchronized;
- the final staging request/data-flow inventory is complete;
- the owner or a qualified German legal professional records Rechtliche Freigabe for final Impressum and Datenschutz; qualified professional review is strongly recommended for the stated legal-accuracy goal;
- all release-blocking items in MANUAL_TASKS.md are complete;
- the exact reviewed artifact and rollback package are recorded.

#### Slice 6.7 — Production deployment and smoke test

Work:

- back up current live assets and mutable data;
- merge the reviewed branch;
- deploy only release-managed files;
- preserve production configuration/editorial data;
- run non-destructive smoke tests;
- confirm analytics and current-content behaviour;
- run one controlled cold-cache check with the real production-only GoatCounter loader; disable GoatCounter or roll back if it breaks the accepted budget;
- retain an immediately usable rollback package.

Acceptance:

- HTTPS/canonical, contacts, links, two statuses, current information, images, themes, legal pages, admin authentication, public resolver, headers, and 404s pass;
- the actual production-only GoatCounter cost remains inside the performance budget when GoatCounter is enabled;
- no staging password/noindex/test data remains on production;
- rollback can restore code/static assets without reverting editorial changes.

### Milestone 7 — Independent seasonal-effect follow-ups

These are attractive enhancements, not blockers for the core legal/performance/mobile/admin release. Each uses the shared effect layer, bounded object counts, no input capture, and the global movement preference.

#### Slice 7.1 — Christmas

- gently fade individual presents, trees, candy canes, and related simple shapes in and out;
- avoid a busy repeating wallpaper or falling-object storm.

#### Slice 7.2 — Spring

- use occasional blossoms/petals near screen edges;
- avoid covering text or suggesting continuous heavy snowfall.

#### Slice 7.3 — Summer

- use subtle warm fireflies/light motes;
- keep contrast and battery/GPU cost restrained.

#### Slice 7.4 — Autumn

- use sparse gently falling and rotating leaves;
- cap density and avoid motion across key reading areas.

Acceptance for every effect:

- static theme works before the moving effect ships;
- Bewegung ausgeschaltet and reduced motion produce no animation;
- ordinary-phone performance budget and no-long-task checks pass;
- focus, pointer, contrast, and screen-reader behaviour are unaffected;
- the owner approves the short staging preview before activation.

## 7. Cross-cutting test scenarios

### Opening hours

- Friday café opening, Monday café closure, and daily shop opening.
- 31 March to 1 April shop closing-time change.
- 31 August to 1 September shop closing-time change.
- closure/replacement for café only, shop only, and both.
- future exception shown ahead of time and removed after expiry.
- approved exception crosses into the 366-day horizon without an editorial save; the 51st non-expired approval is rejected.
- holiday with no exception follows regular rules.
- page left open across open/close, local midnight, March/April, and August/September boundaries.
- JavaScript disabled, snapshot unavailable/stale, visibility return, reconnect, and clock change.

### Aktuelles & Termine

- no entries, exactly three, and more than three.
- scheduled item just before/after display boundary.
- event with/without end and just after passing.
- update with/without expiry and just after expiry.
- public edit, draft edit, archive, deletion, and restore.
- planned start moved to now, expired Neuigkeit extended back into visibility, and archive/restore between resolver requests.
- image absent, valid, failed, replaced, and restored.
- first visit with current entries, explicit mark read, reset, and storage denied.
- per-entry generation/sequence allocated for every non-visible-to-visible transition and visible material edit.
- unread entry expires before return and leaves no false indicator.
- revision allocator survives cache loss, content restore, deploy, and rollback; unprovable disaster recovery rotates generation.

### Themes and motion

- 1 December, 6/7 January, leap/non-leap February end.
- each editable 14-day window first and last day.
- rejected overlaps.
- Automatisch, Aus, and every forced manual theme.
- default motion, reduced motion, explicit off/on, storage denied, and reset browser state.
- hidden/background tab and interaction pause.

### Media and galleries

- duplicate source basenames and extensions.
- rotated image, metadata/GPS, tiny image, oversized pixels, corrupt image, and unsupported HEIC.
- zero, one, and many gallery images.
- touch swipe, keyboard, resize/orientation, fullscreen close, and focus restoration.

### Failure and recovery

- current-content endpoint unavailable, invalid JSON, unsupported schema, and cache rebuild race.
- disk full/permission loss during write or image conversion.
- stale release rolled back over a newer data schema.
- backup restore and Papierkorb restore.
- third-party request blocked.

## 8. Definition of done

The core redesign is done only when:

- the final site matches CONTEXT.md and all accepted ADRs;
- every Milestone 0–6 acceptance criterion passes;
- no release blocker in MANUAL_TASKS.md remains;
- owner and Rechtliche Freigabe are recorded;
- production mutable data is protected from deploy and rollback;
- German copy, external links, visible hours, and structured facts agree;
- the public and admin experiences meet the internal WCAG 2.2 AA target;
- the final mobile performance evidence meets ADR 0016;
- the production network request inventory matches Datenschutz;
- a tested rollback exists;
- post-launch Search Console, Google Business Profile, and backup actions have named owners.

Seasonal moving effects other than Winter may complete independently under Milestone 7.

## 9. Explicitly out of scope

- public web forms, honeypots, CAPTCHA, online booking, or stored enquiries;
- a website menu, advertised prices, fixed Hofladen inventory, ordering, or payment;
- English copies or an in-site language switcher;
- database infrastructure or a general-purpose CMS;
- individual admin accounts, roles, password reset, or 2FA for the current two-person scope;
- Google Maps/social embeds, chat widgets, marketing trackers, or a cosmetic consent banner;
- Bootstrap or other client-side UI frameworks;
- automatic content-gallery playback;
- a separate hero pause preference;
- per-event pages and Event structured data;
- an invented animal carousel/section outside the agreed page hierarchy;
- automatic Google Business Profile/menu updates;
- automatic redesign/vectorization of the logo;
- destructive removal of original imagery;
- claims that legal, accessibility, Lighthouse, or structured-data tools guarantee compliance or Google presentation.

## 10. Future candidates requiring a new decision

These are not approved implementation work:

- a verified outbound Google review link;
- a dedicated animal/story section;
- per-event pages and Event structured data;
- additional languages;
- individual admin accounts or MFA;
- a database if editorial volume or concurrency materially grows;
- new analytics, embeds, chat, newsletter, or form functionality.

Each candidate must be reconsidered against privacy, legal, performance, accessibility, owner workload, and the existing ADRs before implementation.
