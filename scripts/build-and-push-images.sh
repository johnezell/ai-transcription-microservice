#!/bin/bash
# Build and push Docker images to ECR for AI Transcription Microservice

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
AWS_REGION="us-east-1"
AWS_ACCOUNT_ID="087439708020"
ECR_REGISTRY="${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
AWS_PROFILE="${AWS_PROFILE:-tfs-ai-terraform}"
TAG="${1:-latest}"

# Service definitions - using parallel arrays for compatibility
SERVICES=("laravel-api" "audio-extraction" "transcription" "music-term-recognition")
DOCKERFILES=("Dockerfile.laravel" "Dockerfile.audio-service" "Dockerfile.transcription" "Dockerfile.music-service")

# Function to print colored output
print_status() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

print_error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

# Check if we're in the right directory
if [ ! -f "Makefile" ] || [ ! -d "app" ]; then
    print_error "This script must be run from the root of the ai-transcription-microservice directory"
    exit 1
fi

# Login to ECR
print_status "Logging into ECR..."
aws ecr get-login-password --region ${AWS_REGION} --profile ${AWS_PROFILE} | \
    docker login --username AWS --password-stdin ${ECR_REGISTRY}

if [ $? -ne 0 ]; then
    print_error "Failed to login to ECR"
    exit 1
fi

# Build and push each image
for i in "${!SERVICES[@]}"; do
    SERVICE="${SERVICES[$i]}"
    DOCKERFILE="${DOCKERFILES[$i]}"
    IMAGE_NAME="tfs-ai/${SERVICE}"
    FULL_IMAGE="${ECR_REGISTRY}/${IMAGE_NAME}:${TAG}"
    
    print_status "Building ${SERVICE} using ${DOCKERFILE}..."
    
    # Build the image for linux/amd64 (ECS Fargate requires x86_64)
    docker build --platform linux/amd64 -f ${DOCKERFILE} -t ${IMAGE_NAME}:${TAG} .
    
    if [ $? -ne 0 ]; then
        print_error "Failed to build ${SERVICE}"
        exit 1
    fi
    
    # Tag the image for ECR
    docker tag ${IMAGE_NAME}:${TAG} ${FULL_IMAGE}
    
    print_status "Pushing ${SERVICE} to ECR..."
    docker push ${FULL_IMAGE}
    
    if [ $? -ne 0 ]; then
        print_error "Failed to push ${SERVICE}"
        exit 1
    fi
    
    # Also tag and push as 'latest' if we're not already building latest
    if [ "${TAG}" != "latest" ]; then
        docker tag ${IMAGE_NAME}:${TAG} ${ECR_REGISTRY}/${IMAGE_NAME}:latest
        docker push ${ECR_REGISTRY}/${IMAGE_NAME}:latest
    fi
    
    print_status "Successfully pushed ${SERVICE} (${FULL_IMAGE})"
done

print_status "All images built and pushed successfully!"

# Display summary
echo ""
echo "========================================="
echo "Build Summary:"
echo "========================================="
for SERVICE in "${SERVICES[@]}"; do
    echo "✓ ${SERVICE}: ${ECR_REGISTRY}/tfs-ai/${SERVICE}:${TAG}"
done
echo "========================================="

# Clean up local images (optional)
read -p "Do you want to clean up local images? (y/N) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    print_status "Cleaning up local images..."
    for SERVICE in "${SERVICES[@]}"; do
        IMAGE_NAME="tfs-ai/${SERVICE}"
        docker rmi ${IMAGE_NAME}:${TAG} 2>/dev/null || true
        docker rmi ${ECR_REGISTRY}/${IMAGE_NAME}:${TAG} 2>/dev/null || true
    done
fi

print_status "Build and push complete!"