#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUTPUT_DIR="${PROJECT_ROOT}/dist"
COMMIT_ID="$(git -C "${PROJECT_ROOT}" rev-parse --short=12 HEAD 2>/dev/null || echo uncommitted)"
BUILD_STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RELEASE_NAME="erlebnishof-auszeit-${BUILD_STAMP}-${COMMIT_ID}"
ARTIFACT="${OUTPUT_DIR}/${RELEASE_NAME}.tar.gz"
FILE_LIST="${OUTPUT_DIR}/${RELEASE_NAME}.files.txt"
CHECKSUM="${ARTIFACT}.sha256"
STAGING_DIR="$(mktemp -d "${TMPDIR:-/tmp}/hofladen-release.XXXXXX")"

cleanup() {
    case "${STAGING_DIR}" in
        "${TMPDIR:-/tmp}"/hofladen-release.*) rm -rf "${STAGING_DIR}" ;;
        *) echo "Refusing unsafe temporary cleanup: ${STAGING_DIR}" >&2 ;;
    esac
}
trap cleanup EXIT

copy_file() {
    local relative="$1"
    if [[ ! -f "${PROJECT_ROOT}/${relative}" || -L "${PROJECT_ROOT}/${relative}" ]]; then
        echo "Release file missing or unsafe: ${relative}" >&2
        exit 1
    fi
    mkdir -p "${STAGING_DIR}/$(dirname "${relative}")"
    cp -p "${PROJECT_ROOT}/${relative}" "${STAGING_DIR}/${relative}"
}

cd "${PROJECT_ROOT}"
node scripts/verify-site.mjs
npm --prefix tools run images:check

STATIC_FILES=(
    .htaccess
    index.html
    impressum.html
    datenschutz.html
    404.html
    styles.css
    robots.txt
    sitemap.xml
    img/toon_logo.webp
)

ADMIN_FILES=(
    admin/.htaccess
    admin/index.php
    admin/img.php
    admin/admin.css
    admin/admin.js
    admin/robots.txt
    admin/preflight.php
    admin/maintenance.php
    content/.htaccess
    content/current.php
)

for file in "${STATIC_FILES[@]}" "${ADMIN_FILES[@]}"; do
    copy_file "${file}"
done

while IFS= read -r file; do
    copy_file "${file}"
done < <(find assets -type f ! -name '.DS_Store' -print | LC_ALL=C sort)

while IFS= read -r file; do
    copy_file "${file}"
done < <(find admin/lib -type f -name '*.php' -print | LC_ALL=C sort)

node --input-type=module -e '
import {readFileSync} from "node:fs";
const manifest = JSON.parse(readFileSync("img/opt/manifest.json", "utf8"));
for (const source of manifest.sources) {
    for (const variant of source.variants) console.log(variant.path);
}
' | while IFS= read -r file; do
    copy_file "${file}"
done

mkdir -p "${OUTPUT_DIR}"
(
    cd "${STAGING_DIR}"
    find . -type f -print | LC_ALL=C sort > "${FILE_LIST}"
)

if rg -n '(?:^|/)(?:config\.php|sample\.config\.php|fixtures|tests|schemas|manifest\.json|media\.json)(?:$|/)' "${FILE_LIST}"; then
    echo "Forbidden private/development path entered release allowlist" >&2
    exit 1
fi

(
    cd "${STAGING_DIR}"
    tar -czf "${ARTIFACT}" .
)

if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "${ARTIFACT}" > "${CHECKSUM}"
else
    shasum -a 256 "${ARTIFACT}" > "${CHECKSUM}"
fi

echo "Release artifact: ${ARTIFACT}"
echo "Release file list: ${FILE_LIST}"
echo "Checksum: ${CHECKSUM}"
echo "Mutable editorial/configuration paths were not packaged."
