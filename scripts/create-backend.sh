#!/bin/bash
# Script to create S3 backend resources for Terraform state

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Get environment
ENV=${1:-staging}
AWS_ACCOUNT_ID="087439708020"
AWS_REGION="us-east-1"
BUCKET_NAME="tfs-ai-terraform-state-${AWS_ACCOUNT_ID}"
DYNAMODB_TABLE="tfs-ai-terraform-locks"

# Set AWS profile
AWS_PROFILE="tfs-ai-terraform"

echo -e "${GREEN}Creating Terraform backend resources for ${ENV}...${NC}"
echo -e "${YELLOW}AWS Account: ${AWS_ACCOUNT_ID}${NC}"
echo -e "${YELLOW}AWS Profile: ${AWS_PROFILE}${NC}"
echo -e "${YELLOW}Region: ${AWS_REGION}${NC}"

# Check if S3 bucket exists
if aws s3api head-bucket --bucket "${BUCKET_NAME}" --profile "${AWS_PROFILE}" 2>/dev/null; then
    echo -e "${YELLOW}S3 bucket ${BUCKET_NAME} already exists${NC}"
else
    echo -e "${GREEN}Creating S3 bucket ${BUCKET_NAME}...${NC}"
    
    # Create bucket
    aws s3api create-bucket \
        --bucket "${BUCKET_NAME}" \
        --region "${AWS_REGION}" \
        --profile "${AWS_PROFILE}"
    
    # Enable versioning
    aws s3api put-bucket-versioning \
        --bucket "${BUCKET_NAME}" \
        --versioning-configuration Status=Enabled \
        --profile "${AWS_PROFILE}"
    
    # Enable encryption
    aws s3api put-bucket-encryption \
        --bucket "${BUCKET_NAME}" \
        --server-side-encryption-configuration '{
            "Rules": [{
                "ApplyServerSideEncryptionByDefault": {
                    "SSEAlgorithm": "AES256"
                }
            }]
        }' \
        --profile "${AWS_PROFILE}"
    
    # Block public access
    aws s3api put-public-access-block \
        --bucket "${BUCKET_NAME}" \
        --public-access-block-configuration \
            "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true" \
        --profile "${AWS_PROFILE}"
    
    echo -e "${GREEN}S3 bucket created successfully${NC}"
fi

# Check if DynamoDB table exists
if aws dynamodb describe-table --table-name "${DYNAMODB_TABLE}" --profile "${AWS_PROFILE}" --region "${AWS_REGION}" 2>/dev/null; then
    echo -e "${YELLOW}DynamoDB table ${DYNAMODB_TABLE} already exists${NC}"
else
    echo -e "${GREEN}Creating DynamoDB table ${DYNAMODB_TABLE}...${NC}"
    
    aws dynamodb create-table \
        --table-name "${DYNAMODB_TABLE}" \
        --attribute-definitions AttributeName=LockID,AttributeType=S \
        --key-schema AttributeName=LockID,KeyType=HASH \
        --provisioned-throughput ReadCapacityUnits=5,WriteCapacityUnits=5 \
        --region "${AWS_REGION}" \
        --profile "${AWS_PROFILE}"
    
    # Wait for table to be active
    echo -e "${YELLOW}Waiting for DynamoDB table to be active...${NC}"
    aws dynamodb wait table-exists \
        --table-name "${DYNAMODB_TABLE}" \
        --region "${AWS_REGION}" \
        --profile "${AWS_PROFILE}"
    
    echo -e "${GREEN}DynamoDB table created successfully${NC}"
fi

# Create backend configuration file
BACKEND_CONFIG="terraform/backend-${ENV}.hcl"
echo -e "${GREEN}Creating backend configuration file: ${BACKEND_CONFIG}${NC}"

cat > "${BACKEND_CONFIG}" << EOF
bucket         = "${BUCKET_NAME}"
key            = "${ENV}/terraform.tfstate"
region         = "${AWS_REGION}"
dynamodb_table = "${DYNAMODB_TABLE}"
encrypt        = true
EOF

echo -e "${GREEN}Backend configuration created at ${BACKEND_CONFIG}${NC}"
echo ""
echo -e "${YELLOW}To initialize Terraform with this backend, run:${NC}"
echo -e "cd terraform && terraform init -backend-config=backend-${ENV}.hcl"
echo ""
echo -e "${GREEN}Backend setup complete!${NC}"