---
status: accepted
date: 2026-07-22
---

# Resolve time-dependent public content on request

Scheduled publication, expiry, opening-exception horizon entry/effect, and seasonal windows must change at their Europe/Berlin boundaries even when no editor is present. In production, the public JSON URL is backed by a small PHP resolver that never changes editorial intent but may atomically update a derived cache and a separate durable generation/sequence transition ledger when editorial data, release capability, or time boundaries change; every successful admin mutation invokes the same reconciliation so intermediate archive/restore transitions are not lost. Cache headers never outlive the next transition. Open pages refetch at that transition and re-evaluate at regular opening/closing boundaries and on resume/reconnect. This avoids exposing embargoed future entries and avoids depending on an IONOS cron job. Docker maps a sanitized test fixture to the same URL, and stale/unavailable data falls back without claiming a live opening status.
