#!/bin/bash
# Helper script to run Terraform in Docker container

set -e

# Configuration
TERRAFORM_VERSION=${TERRAFORM_VERSION:-1.7.0}
DOCKER_IMAGE="hashicorp/terraform:${TERRAFORM_VERSION}"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TERRAFORM_DIR="${PROJECT_ROOT}/terraform"
AWS_DIR="${HOME}/.aws"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Error: Docker is not installed${NC}"
    exit 1
fi

# Check if terraform directory exists
if [ ! -d "$TERRAFORM_DIR" ]; then
    echo -e "${RED}Error: Terraform directory not found at $TERRAFORM_DIR${NC}"
    exit 1
fi

# Get AWS profile from environment or use default
AWS_PROFILE=${AWS_PROFILE:-default}

# Run terraform in Docker
echo -e "${GREEN}Running Terraform ${TERRAFORM_VERSION} in Docker...${NC}"
echo -e "${YELLOW}AWS Profile: ${AWS_PROFILE}${NC}"

# Check if we're in an interactive terminal
if [ -t 0 ]; then
    DOCKER_IT_FLAG="-it"
else
    DOCKER_IT_FLAG=""
fi

docker run --rm ${DOCKER_IT_FLAG} \
    -v "${TERRAFORM_DIR}":/terraform \
    -v "${AWS_DIR}":/root/.aws:ro \
    -w /terraform \
    -e AWS_PROFILE="${AWS_PROFILE}" \
    -e TF_LOG="${TF_LOG}" \
    "${DOCKER_IMAGE}" "$@"