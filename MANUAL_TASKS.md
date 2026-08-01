# Manual tasks

These tasks require the owner, IONOS, Google, or qualified legal review. Repository implementation must not silently substitute assumptions for them.

## Release blockers — business facts and content

- [ ] Verify the legal operator name, postal address, Festnetz number, secondary mobile number, and every fact required by Impressum with the owner.
- [ ] Confirm the regular hours exactly as published: Hofcafé Friday–Sunday 08:30–17:00; self-service Hofladen daily 08:00–19:00 from September through March and 08:00–21:00 from April through August.
- [ ] Confirm that regular hours also apply on public holidays unless the owner publishes an exception; enter any known launch-period exceptions.
- [ ] Approve the final German copy and the claims **„überwiegend hausgemachte Kuchen“** and **„überwiegend regional bezogen“**.
- [ ] Confirm that the redesign should intentionally omit or de-emphasize **Frühstück**, which the current live site promotes, in favour of the agreed coffee, cakes, Brotzeit, and lunch focus.
- [ ] Approve the exact **„Gut zu wissen“** wording. Verify parking, accepted cash/card payments, concrete accessibility features, high chairs, changing facilities, dog conditions, child-specific facilities, and indoor/outdoor seating. Do not approve a generic **„barrierefrei“** claim without naming what is actually accessible.
- [ ] Approve 5–8 curated images for each permanent Hofcafé, Hofladen, and Feiern & Gruppen gallery, the hero sequence, and the social-preview image. Approve meaningful image descriptions where images convey information.
- [ ] Record that the owner/user supplied the selected images and confirm that the website may publish them, including any recognizable people.
- [ ] Supply and verify the exact outbound URLs for route planning, the current Google menu, Google Business Profile, and each retained social account.
- [ ] Confirm that the owner has edit access to the correct Google Business Profile before relying on it for the current menu and special hours.
- [ ] Before production publication, synchronize the Google Business Profile website URL, phone, regular/special hours, and current menu with the approved site facts; verify the website’s menu link opens that exact profile.
- [ ] Review migrated Aktuelles & Termine records and supply their real titles, descriptions, dates, display windows, image descriptions, and publication state. Do not infer dates from filenames.

## Release blockers — communication

- [ ] Create **hallo@erlebnishof-auszeit.de** at IONOS and forward it to the operating mailbox.
- [ ] Configure replies to send from the branded address and verify inbound delivery, outbound delivery, SPF, DKIM, and DMARC behaviour.
- [ ] Test the Festnetz **„Anrufen“** link and secondary mobile link from a real phone.

## Release blockers — IONOS and secrets

- [ ] Create an isolated test folder and test subdomain at IONOS with HTTPS, password protection, and both HTML and HTTP-header **noindex** protection.
- [ ] Create separate staging editorial data, uploads, backups, trash, and configuration. Prove that staging cannot read or write production editorial storage.
- [ ] Confirm the production PHP version and the availability of sessions, JSON, Fileinfo, Mbstring, Exif orientation reading, and GD image decoding/encoding for JPEG and WebP. Record whether AVIF is supported rather than assuming it.
- [ ] Verify Apache rewrite, custom-header, compression, error-page, and cache-control support, plus correct WebP/AVIF MIME serving.
- [ ] Confirm that file locking, atomic rename within one filesystem, writable-directory permissions, and the planned upload-size limits work on IONOS.
- [ ] Allocate private editorial data and source-upload storage outside the public document root. If IONOS cannot provide that, configure and verify a server-denied fallback before release.
- [ ] Move the password hash and environment-specific paths out of the repository and public document root. Verify the existing shared password’s strength without recording or exposing it, preserve it unless a security reason requires rotation, and ensure only the two Bearbeitungsberechtigten know it.
- [ ] Set storage limits and a retention policy for backups, Papierkorb, login-throttle data, and recovery timestamps.
- [ ] Define the actual off-host backup mechanism, schedule, credentials owner, alert/retry behaviour, and restore test.
- [ ] Configure branch-to-staging and production deployment so release uploads never overwrite private data, public editorial media, backups, trash, or environment configuration.
- [ ] Decide HSTS scope only after confirming HTTPS on every affected subdomain; do not enable **includeSubDomains** by assumption.

## Release blockers — privacy and legal

- [ ] Inspect the hosted GoatCounter account and production requests. Verify aggregate operation, whether individual pageview storage is disabled, all data sent with a request, retention, operator location, and any required agreement or legal basis.
- [ ] Disable GoatCounter for localhost and the IONOS test hostname and prove this with the browser network log.
- [ ] Confirm IONOS access-log behaviour, retention, and the relevant provider/processing information for Datenschutz.
- [ ] Include the bounded login-throttle network key and recovery timestamps in the retention/privacy review; do not add an individual admin audit claim to a shared account.
- [ ] Have the owner or a qualified German legal professional record Rechtliche Freigabe for the final Impressum and Datenschutz only after the production services, device storage, admin processing, uploads, logs, and outbound links are fixed; qualified professional review is strongly recommended for the legal-accuracy goal.
- [ ] Have the reviewer decide the correct statutory references and treatment of tax numbers. Do not mechanically replace TMG references with similarly numbered DDG provisions.
- [ ] If the verified GoatCounter behaviour or legal review requires consent, stop release and revisit ADRs 0009 and 0015 instead of adding a cosmetic banner.

## Release blockers — acceptance and deployment

- [ ] Review the password-protected staging site on the owner’s laptop and at least one ordinary iPhone/Safari and Android/Chrome phone.
- [ ] Exercise draft, preview, publish, scheduled appearance, expiry, opening exception, theme selection, upload, Papierkorb, restore, and backup recovery using staging-only data.
- [ ] Approve the final mobile appearance, carousel behaviour, seasonal motion, motion-off state, copy, imagery, contact actions, and current-information workflow.
- [ ] Complete the technical performance, accessibility, security, privacy-network, link, and structured-data release evidence listed in IMPLEMENTATION_PLAN.md.
- [ ] Back up the current production site and mutable production data, record the exact rollback procedure, and rehearse rollback without replacing newer editorial data.
- [ ] Merge the reviewed redesign branch only after owner, QA, and Rechtliche Freigabe gates pass; deploy the exact reviewed code/static artifact with production configuration kept separate.
- [ ] Include the measured GoatCounter production-only overhead in the pre-release budget, then run one controlled read-only production performance check with the real loader; disable GoatCounter or roll back if the accepted budget fails.

## Non-blocking brand improvement

- [ ] Commission a faithful vectorization of the existing Markenlogo; this is not a redesign. Obtain the editable source plus genuine SVG and print-ready PDF exports, transparent-background and monochrome variants, and owner approval. The existing supplied artwork may remain until this is ready.

## Immediately after launch

- [ ] Run read-only production smoke tests for HTTPS, canonical redirects, phones, email, route/menu links, opening statuses, current information, authenticated admin login/logout without edits, public snapshot, GoatCounter, and 404 behaviour. Perform upload/publish/restore tests only on staging.
- [ ] Verify Google Search Console ownership, submit the final sitemap, request/inspect the rendered homepage, and watch for crawl or structured-data errors. Validation does not guarantee a search enhancement.
- [ ] Recheck after launch that Google Business Profile regular/special hours and the current-menu link still match the live website.
- [ ] Confirm that the first automated/off-host editorial backup completed and can be opened.

## Ongoing owner tasks

- [ ] Keep Google Business Profile hours, special hours, current menu, phone, website URL, and photos synchronized with reality.
- [ ] Enter temporary Hofcafé/Hofladen exceptions early enough for guests to see them.
- [ ] Maintain Aktuelles & Termine, expiry dates, image descriptions, and the Papierkorb; archive stale information instead of leaving it public.
- [ ] Choose one 14-day Spring, Summer, and Autumn Themenfenster each year and return any Manuelle Themenwahl to Automatisch when it is no longer wanted.
- [ ] Test **hallo@erlebnishof-auszeit.de** forwarding and send-as behaviour periodically.
- [ ] Download/verify backups and rehearse recovery on test data at an agreed interval.
- [ ] Recheck Datenschutz and the no-banner decision before adding analytics, embeds, chat, forms, marketing storage, or any new third party.
- [ ] Monitor Search Console, GoatCounter aggregate trends, broken outbound links, real-user Core Web Vitals when available, and IONOS disk usage.
