#!/bin/bash

# Exit immediately if a command exits with a non-zero status.
set -e

# --- Configuration ---
AWS_PROFILE="tfs-shared-services"
AWS_REGION="us-east-1"
DEFAULT_STACK_TO_DEPLOY="--all" # Defaulting to deploy all stacks
# Determine workspace root assuming the script is in <WORKSPACE_ROOT>/scripts/deploy.sh
# Correctly gets the directory of the script, then goes up one level.
WORKSPACE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/.."


# --- Script Arguments ---
# If $1 is empty or not set, use DEFAULT_STACK_TO_DEPLOY. Otherwise, use $1.
STACKS_TO_DEPLOY=${1:-$DEFAULT_STACK_TO_DEPLOY}

# --- Banner ---
echo "--------------------------------------------------"
echo "Starting Deployment Script"
echo "Workspace Root: ${WORKSPACE_ROOT}"
echo "Target Stacks: ${STACKS_TO_DEPLOY}"
echo "AWS Profile: ${AWS_PROFILE}"
echo "AWS Region: ${AWS_REGION}"
echo "--------------------------------------------------"

# 1. Build Laravel Frontend Assets
echo ""
echo ">>> Step 1: Building Laravel frontend assets for linux/amd64 using an isolated snapshot..."
cd "${WORKSPACE_ROOT}" # Ensure we are in the root

# Define the temporary snapshot directory within the workspace (gitignored recommended)
TMP_SNAPSHOT_DIR="${WORKSPACE_ROOT}/.tmp_laravel_deploy_snapshot"
# Define the path within the snapshot that will hold the laravel app
SNAPSHOT_APP_PATH_FRAGMENT="laravel_app_for_build" # This is a sub-path within TMP_SNAPSHOT_DIR
SNAPSHOT_APP_FULL_PATH="${TMP_SNAPSHOT_DIR}/${SNAPSHOT_APP_PATH_FRAGMENT}"


if [ ! -f "docker-compose.deploy.yml" ] || [ ! -f "Dockerfile.assetbuilder" ]; then
    echo "Error: Missing docker-compose.deploy.yml or Dockerfile.assetbuilder in workspace root." >&2
    exit 1
fi

if ! command -v docker &> /dev/null || ! command -v docker-compose &> /dev/null; then
    echo "Error: docker or docker-compose command not found. Please ensure Docker is installed and running." >&2
    exit 1
fi

echo "Preparing isolated snapshot of app/laravel..."
# Clean up any previous snapshot
rm -rf "${TMP_SNAPSHOT_DIR}"
mkdir -p "${SNAPSHOT_APP_FULL_PATH}"

# Copy the app/laravel contents to the snapshot directory
# Using rsync for a more robust copy that handles hidden files and preserves attributes better.
# The trailing slash on the source is important for rsync to copy contents.
echo "Copying ./app/laravel to ${SNAPSHOT_APP_FULL_PATH}..."
rm -rf "${SNAPSHOT_APP_FULL_PATH}"
mkdir -p "${SNAPSHOT_APP_FULL_PATH}"
rsync -a --exclude '.git' "${WORKSPACE_ROOT}/app/laravel/" "${SNAPSHOT_APP_FULL_PATH}/"

echo "Removing vendor, node_modules, and lock files from snapshot..."
rm -rf "${SNAPSHOT_APP_FULL_PATH}/vendor"
rm -rf "${SNAPSHOT_APP_FULL_PATH}/node_modules"
rm -f "${SNAPSHOT_APP_FULL_PATH}/composer.lock"
rm -f "${SNAPSHOT_APP_FULL_PATH}/package-lock.json"

echo "Building asset-builder Docker image (target: linux/amd64) using snapshot..."
# The Dockerfile.assetbuilder will be modified to COPY from ./.tmp_laravel_deploy_snapshot/laravel_app_for_build
DOCKER_BUILDKIT=1 docker-compose -f docker-compose.deploy.yml build \
    --build-arg LARAVEL_APP_SNAPSHOT_PATH="${SNAPSHOT_APP_PATH_FRAGMENT}" \
    --no-cache \
    --progress=plain asset-builder

LARAVEL_PUBLIC_DIR="${WORKSPACE_ROOT}/app/laravel/public"
echo "Cleaning up previous local build artifacts from ${LARAVEL_PUBLIC_DIR}... GGGG"
rm -rf "${LARAVEL_PUBLIC_DIR}/build"
rm -f "${LARAVEL_PUBLIC_DIR}/hot"
rm -f "${LARAVEL_PUBLIC_DIR}/manifest.json"

echo "Extracting built assets from laravel-asset-builder:latest image..."
TEMP_ASSET_CONTAINER_NAME="temp-asset-extractor-$RANDOM"
docker create --name "${TEMP_ASSET_CONTAINER_NAME}" laravel-asset-builder:latest
# The Dockerfile.assetbuilder will ensure assets are in /app_src/public inside the image
docker cp "${TEMP_ASSET_CONTAINER_NAME}:/app_src/public/." "${LARAVEL_PUBLIC_DIR}/"
docker rm "${TEMP_ASSET_CONTAINER_NAME}"

echo "Cleaning up temporary snapshot directory: ${TMP_SNAPSHOT_DIR}"
rm -rf "${TMP_SNAPSHOT_DIR}"

echo "Frontend assets build and extraction completed. Assets are in ${LARAVEL_PUBLIC_DIR}"

# 2. Deploy CDK Stacks
echo ""
echo ">>> Step 2: Deploying CDK stacks..."
CDK_DIR="${WORKSPACE_ROOT}/cdk-infra"

if [ ! -d "${CDK_DIR}" ]; then
    echo "Error: CDK directory ${CDK_DIR} not found." >&2
    exit 1
fi
cd "${CDK_DIR}"

if [ ! -d ".venv" ]; then
    echo "Error: Python virtual environment .venv not found in ${CDK_DIR}."
    echo "Please create and activate it first (e.g., python3 -m venv .venv && source .venv/bin/activate && pip install -r requirements.txt)." >&2
    exit 1
fi

echo "Activating Python virtual environment..."
source .venv/bin/activate 

if [ ! -f "requirements.txt" ]; then
    echo "Warning: requirements.txt not found in ${CDK_DIR}, skipping pip install."
else
    echo "Ensuring CDK Python dependencies are installed..."
    pip install -r requirements.txt
fi

echo "Removing old cdk.out directory for a clean synthesis..."
rm -rf cdk.out

echo "Deploying stack(s): ${STACKS_TO_DEPLOY}..."
cdk deploy "${STACKS_TO_DEPLOY}" \
    --require-approval never \
    --profile "${AWS_PROFILE}" \
    --region "${AWS_REGION}" \
    -vvv

cd "${WORKSPACE_ROOT}"
echo "--------------------------------------------------"
echo "CDK deployment command for '${STACKS_TO_DEPLOY}' initiated."
echo "Monitor your terminal or AWS CloudFormation console for progress."
echo "Deployment Script Finished."
echo "--------------------------------------------------" 