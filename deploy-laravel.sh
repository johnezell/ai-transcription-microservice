#!/bin/bash
set -e

echo "Starting Laravel deployment..."

# Set AWS profile
export AWS_PROFILE="tfs-shared-services"
echo "Using AWS profile: $AWS_PROFILE"

# Configuration - update these values to match your environment
AWS_REGION=$(aws configure get region --profile $AWS_PROFILE --no-cli-pager || echo "us-east-1")
AWS_ACCOUNT=$(aws sts get-caller-identity --query Account --output text --profile $AWS_PROFILE --no-cli-pager)
ECR_REPO="aws-transcription-laravel"
CLUSTER_NAME="aws-transcription-cluster"
SERVICE_NAME="aws-transcription-laravel-service"

# Check if required tools are available
if ! command -v aws &> /dev/null; then
    echo "AWS CLI is not installed. Please install it first."
    exit 1
fi

if ! command -v docker &> /dev/null; then
    echo "Docker is not installed. Please install it first."
    exit 1
fi

# Build Docker image
echo "Building Laravel Docker image..."
docker build -f Dockerfile.laravel -t $ECR_REPO:latest .

# Login to ECR
echo "Logging into ECR..."
aws ecr get-login-password --region $AWS_REGION --profile $AWS_PROFILE --no-cli-pager | docker login --username AWS --password-stdin $AWS_ACCOUNT.dkr.ecr.$AWS_REGION.amazonaws.com

# Check if repository exists and create if it doesn't
echo "Checking ECR repository..."
aws ecr describe-repositories --repository-names $ECR_REPO --region $AWS_REGION --profile $AWS_PROFILE --no-cli-pager || \
    aws ecr create-repository --repository-name $ECR_REPO --region $AWS_REGION --profile $AWS_PROFILE --no-cli-pager

# Tag and push the image
echo "Pushing image to ECR..."
docker tag $ECR_REPO:latest $AWS_ACCOUNT.dkr.ecr.$AWS_REGION.amazonaws.com/$ECR_REPO:latest
docker push $AWS_ACCOUNT.dkr.ecr.$AWS_REGION.amazonaws.com/$ECR_REPO:latest || { echo "Error: Failed to push Docker image to ECR"; exit 1; }

# Force new deployment of the service
echo "Forcing new ECS deployment..."
aws ecs update-service --cluster $CLUSTER_NAME --service $SERVICE_NAME --force-new-deployment --region $AWS_REGION --profile $AWS_PROFILE --no-cli-pager

echo "Deployment initiated successfully!"
echo "You can check the status with: aws ecs describe-services --cluster $CLUSTER_NAME --services $SERVICE_NAME --region $AWS_REGION --profile $AWS_PROFILE --no-cli-pager --output json" 