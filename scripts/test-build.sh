#!/bin/bash
# Test build for a single service

set -e

# Configuration
AWS_REGION="us-east-1"
AWS_ACCOUNT_ID="087439708020"
ECR_REGISTRY="${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
AWS_PROFILE="${AWS_PROFILE:-tfs-ai-terraform}"

echo "Testing ECR login..."
aws ecr get-login-password --region ${AWS_REGION} --profile ${AWS_PROFILE} | \
    docker login --username AWS --password-stdin ${ECR_REGISTRY}

echo "Building audio-extraction service as test..."
docker build -f Dockerfile.audio-service -t test-audio:latest .

echo "Test build successful!"