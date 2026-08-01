---
status: accepted
date: 2026-07-22
---

# Use one password-only owner account

The Redaktionsbereich will initially use one shared Betreiberkonto for the two Bearbeitungsberechtigten, protected by a strong unique password. Separate accounts and two-factor authentication are deferred to keep operation simple; this explicitly accepts the absence of individual attribution and selective revocation. The password hash and other secrets stay outside the repository and public document root; authentication prevents unauthorized access, explicit noindex controls discourage indexing, frame restrictions prevent embedding, sessions are hardened, mutations require CSRF protection, and failed logins use bounded throttling. Separate accounts or stronger authentication must be reconsidered if the editor group grows, individual auditability is required, or one person must be revoked without rotating the shared credential.
