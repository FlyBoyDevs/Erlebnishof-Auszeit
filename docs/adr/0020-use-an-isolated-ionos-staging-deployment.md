---
status: accepted
date: 2026-07-22
---

# Use an isolated IONOS staging deployment

The redesign is developed on a short-lived branch and deployed to an isolated IONOS test folder exposed through a password-protected, **noindex** test subdomain. Staging uses separate configuration, editorial files, and media, disables GoatCounter, and cannot write to production storage. After owner review, automated/manual QA, and Rechtliche Freigabe, the same reviewed code/static artifact is deployed to production; environment configuration and mutable data are deliberately different. Rollback replaces only release-managed assets, preserves newer editorial data/uploads/revision ledger, and includes the compatibility reader needed to keep the admin and current-content route operational after an additive schema migration.
