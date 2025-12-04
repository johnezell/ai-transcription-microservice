#!/bin/bash
# Script to load environment variables from .env files
# Usage: source scripts/load-env.sh staging

set -e

# Check if environment is provided
if [ -z "$1" ]; then
    echo "Error: Environment not specified"
    echo "Usage: source scripts/load-env.sh <environment>"
    echo "Available environments: staging, production"
    exit 1
fi

ENV=$1
ENV_FILE=".env.${ENV}"

# Check if environment file exists
if [ ! -f "$ENV_FILE" ]; then
    echo "Error: Environment file $ENV_FILE not found"
    echo "Please create it by copying .env.example"
    exit 1
fi

# Load environment variables
echo "Loading environment variables from $ENV_FILE..."

# Export variables from .env file
set -a
source "$ENV_FILE"
set +a

# Verify critical variables are set
REQUIRED_VARS=(
    "AWS_ACCOUNT_ID"
    "AWS_REGION"
    "AWS_PROFILE"
    "ECS_CLUSTER_NAME"
)

MISSING_VARS=()
for var in "${REQUIRED_VARS[@]}"; do
    if [ -z "${!var}" ]; then
        MISSING_VARS+=("$var")
    fi
done

if [ ${#MISSING_VARS[@]} -ne 0 ]; then
    echo "Error: The following required variables are not set:"
    printf '%s\n' "${MISSING_VARS[@]}"
    echo "Please update $ENV_FILE with the required values"
    exit 1
fi

# Set AWS profile
export AWS_PROFILE="${AWS_PROFILE}"
echo "AWS Profile set to: $AWS_PROFILE"

# Display loaded configuration
echo "Environment configuration loaded successfully!"
echo "- AWS Account ID: ${AWS_ACCOUNT_ID}"
echo "- AWS Region: ${AWS_REGION}"
echo "- ECS Cluster: ${ECS_CLUSTER_NAME}"
echo "- Environment: ${ENVIRONMENT}"

# Export TF_VAR_ prefixed variables for Terraform
echo ""
echo "Exporting Terraform variables..."
while IFS='=' read -r key value; do
    # Skip comments and empty lines
    [[ $key =~ ^#.*$ ]] && continue
    [[ -z $key ]] && continue
    
    # Export as TF_VAR_ for Terraform
    export "TF_VAR_${key}=${value}"
done < "$ENV_FILE"

echo "Environment ready for Terraform operations!"