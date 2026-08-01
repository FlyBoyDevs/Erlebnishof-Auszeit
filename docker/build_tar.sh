#!/usr/bin/env bash

# Exit on error, unset vars, and failures in pipelines
set -euo pipefail

IMAGE_NAME="erlebnishof-auszeit-site-test"
TAR_NAME="${IMAGE_NAME}-amd64.tar"
REMOTE_HOST="192.168.2.129"
REMOTE_PATH="/tmp/${TAR_NAME}"

echo "[1/4] Building Docker image for linux/amd64: ${IMAGE_NAME}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
docker build --platform linux/amd64 -f "${SCRIPT_DIR}/Dockerfile" -t "${IMAGE_NAME}" "${SCRIPT_DIR}/.."

echo "[2/4] Saving image to tarball: ${TAR_NAME}"
docker save -o "${TAR_NAME}" "${IMAGE_NAME}"

echo "[3/4] Copying tarball to ${REMOTE_HOST}:${REMOTE_PATH} "
scp "${TAR_NAME}" "${REMOTE_HOST}:${REMOTE_PATH}"

echo "[4/4] unload tarball on host ${REMOTE_HOST}:${REMOTE_PATH} "
ssh "${REMOTE_HOST}" "docker load -i ${REMOTE_PATH}"

echo "Done. Image tar uploaded to ${REMOTE_HOST}:${REMOTE_PATH}"
