---
status: accepted
date: 2026-07-22
---

# Use a file-backed editorial store

The Redaktionsbereich will persist its small structured data set in versioned JSON documents rather than a database. Updates use schema validation, expected-revision conflict detection, exclusive locking, write-to-temporary-and-rename semantics, and bounded timestamped backups. Owner-uploaded sources stay outside the publicly served directory and become bounded public variants; deletion moves records and media to a size- and age-limited recoverable trash area. Code never overwrites an unsupported schema: it reads compatible additive changes or refuses safely, while production migrations use an expand/contract compatibility path that keeps the tested rollback operational. If IONOS cannot provide private storage outside the document root, launch is blocked until an equivalent server-denied location is configured and verified.
