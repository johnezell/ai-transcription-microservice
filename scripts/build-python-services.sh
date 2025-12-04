#!/bin/bash
# Build and push Python services only

set -e

# Configuration
AWS_REGION="us-east-1"
AWS_ACCOUNT_ID="087439708020"
ECR_REGISTRY="${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
AWS_PROFILE="${AWS_PROFILE:-tfs-ai-terraform}"
TAG="${1:-latest}"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}Building Python services...${NC}"

# Login to ECR
echo "Logging into ECR..."
aws ecr get-login-password --region ${AWS_REGION} --profile ${AWS_PROFILE} | \
    docker login --username AWS --password-stdin ${ECR_REGISTRY}

# Build audio-extraction
echo -e "${GREEN}Building audio-extraction...${NC}"
docker build -f Dockerfile.audio-service -t tfs-ai/audio-extraction:${TAG} .
docker tag tfs-ai/audio-extraction:${TAG} ${ECR_REGISTRY}/tfs-ai/audio-extraction:${TAG}
docker push ${ECR_REGISTRY}/tfs-ai/audio-extraction:${TAG}
echo -e "${GREEN}✓ Pushed audio-extraction${NC}"

# Build transcription
echo -e "${GREEN}Building transcription...${NC}"
docker build -f Dockerfile.transcription -t tfs-ai/transcription:${TAG} .
docker tag tfs-ai/transcription:${TAG} ${ECR_REGISTRY}/tfs-ai/transcription:${TAG}
docker push ${ECR_REGISTRY}/tfs-ai/transcription:${TAG}
echo -e "${GREEN}✓ Pushed transcription${NC}"

# Build music-term-recognition
echo -e "${GREEN}Building music-term-recognition...${NC}"
docker build -f Dockerfile.music-service -t tfs-ai/music-term-recognition:${TAG} .
docker tag tfs-ai/music-term-recognition:${TAG} ${ECR_REGISTRY}/tfs-ai/music-term-recognition:${TAG}
docker push ${ECR_REGISTRY}/tfs-ai/music-term-recognition:${TAG}
echo -e "${GREEN}✓ Pushed music-term-recognition${NC}"

echo -e "${GREEN}All Python services built and pushed successfully!${NC}"