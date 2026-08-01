# Deployment boundaries

Build the reviewed IONOS artifact with:

```sh
npm run release
```

The builder first runs the public/static and image checks. It then copies an explicit set of public files, PHP entry points, PHP library files, local assets, and only the 162 variants named by the curated image manifest. The adjacent file list and SHA-256 file identify exactly what was reviewed.

The artifact deliberately excludes Docker fixtures, source photos, the image manifest and provenance, tests, schemas, development tools, `admin/config.php`, `admin/sample.config.php`, and every mutable path described in `admin/README.md`.

Deploy the artifact to the password-protected IONOS test folder first. Never use a mirror/delete option. Production and staging must each provide their own `HOFLADEN_CONFIG`; release extraction must not replace editorial data, private uploads, public editorial media, cache, ledger, backups, trash, throttle state, or configuration. Actual IONOS credentials, folders, backup, staging seed data, deployment, and rollback rehearsal remain the manual release gates in `MANUAL_TASKS.md`.
