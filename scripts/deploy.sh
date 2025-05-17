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
echo ">>> Step 1: Building Laravel frontend assets..."
cd "${WORKSPACE_ROOT}/app/laravel"
if [ -f "package.json" ]; then
    npm run build
else
    echo "package.json not found in ${WORKSPACE_ROOT}/app/laravel, skipping npm run build."
fi
cd "${WORKSPACE_ROOT}"
echo "Frontend assets build step completed."

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