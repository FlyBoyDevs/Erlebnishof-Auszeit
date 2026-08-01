---
status: accepted
date: 2026-07-22
---

# Enforce a mobile performance budget

The visitor experience is released only when a pinned, documented production-like mobile profile passes three cold-cache runs: Lighthouse Mobile Performance is at least 90 in every run, median LCP is at or below 2.5 seconds, median CLS is at or below 0.1, and no more than 700 KB transfers before below-the-fold media. The budget includes a measured reserve and controlled production verification for any production-only GoatCounter cost. Scripted interaction traces must stay within a 200 ms response target and show no seasonal-effect long tasks; real field INP at or below 200 ms is monitored after launch when enough data exists because a lab Lighthouse run cannot certify field INP. Only the first hero image loads eagerly; galleries and later hero images are deferred.
