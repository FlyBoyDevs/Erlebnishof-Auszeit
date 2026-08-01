---
status: accepted
date: 2026-07-22
---

# Publish one public JSON snapshot

The file-backed editorial store remains private. One versioned public JSON representation contains only entries currently intended for guests, a bounded horizon of approved current/upcoming opening-hours exceptions, and the resolved active theme; it never contains drafts, embargoed future entries, private paths, or source uploads. A general snapshot revision supports caching. Each visible entry has a generation/sequence version allocated from a separate durable transition ledger whenever it moves from non-visible to visible or is materially edited while remaining visible; the snapshot exposes the current generation and maximum current sequence. The dot exists only when a current entry is newer than the device’s explicit read version, so theme/hours changes or an expired unread entry cannot create a false Neu-Hinweis. A disaster restore that cannot prove the newest ledger rotates generation so an old device value cannot suppress current entries.

Production resolves this representation according to ADR 0021, while Docker maps a sanitized test fixture to the same URL without packaging it for IONOS. The same local JavaScript renders cards and creates exactly one business-data graph from repository facts plus a valid current snapshot; stale/failed current data emits no potentially misleading graph. Client-rendered structured data is deliberately verified after rendering, but validation and Search Console observation do not guarantee a Google search enhancement. Öffentliche Termine are not emitted as Event markup because the site has no unique event leaf pages.
