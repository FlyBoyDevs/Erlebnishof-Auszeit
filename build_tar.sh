#!/usr/bin/env bash

# Exit on error, unset vars, and failures in pipelines
set -euo pipefail

IMAGE_NAME="erlebnishof-auszeit-site-test"
TAR_NAME="${IMAGE_NAME}-amd64.tar"
REMOTE_HOST="192.168.2.129"
REMOTE_PATH="/tmp/${TAR_NAME}"

echo "[1/3] Building Docker image for linux/amd64: ${IMAGE_NAME}"
docker build --platform linux/amd64 -t "${IMAGE_NAME}" .

echo "[2/3] Saving image to tarball: ${TAR_NAME}"
docker save -o "${TAR_NAME}" "${IMAGE_NAME}"

echo "[3/3] Copying tarball to ${REMOTE_HOST}:${REMOTE_PATH} (scp will prompt for password)"
scp "${TAR_NAME}" "${REMOTE_HOST}:${REMOTE_PATH}"

echo "Done. Image tar uploaded to ${REMOTE_HOST}:${REMOTE_PATH}"
