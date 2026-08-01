---
status: accepted
date: 2026-07-22
---

# Commit generated responsive image variants

Repository-managed source images are processed locally with a pinned toolchain into AVIF, WebP, and JPEG variants at the dimensions required by the interface. Output URLs contain a collision-safe source identity and content hash, so regenerated assets may use long immutable caching without becoming stale. Generated variants are committed and deployed to IONOS as ready-to-serve static files; production performs no image build or runtime transformation. Verification fails for missing, stale, oversized, or colliding outputs, and orphan cleanup is an explicit reviewable operation rather than destructive generation behaviour.
