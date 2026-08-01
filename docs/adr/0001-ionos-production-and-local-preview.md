---
status: accepted
date: 2026-07-22
---

# Use IONOS Apache/PHP for production and Docker/nginx for local preview

The public site and its PHP endpoints are deployed to IONOS using Apache/PHP. Docker/nginx exists only to preview and test the static front end locally; it is not a production deployment target and does not execute PHP. Docker serves sanitized fixtures at the same public URLs used by the production read endpoints, while PHP behaviour is verified with CLI tests and the isolated IONOS staging environment.
