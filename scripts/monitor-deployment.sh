#!/bin/bash
# Script to monitor the bastion deployment progress

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
ENV=${1:-staging}
AWS_PROFILE="tfs-ai-terraform"
REGION="us-east-1"

echo -e "${BLUE}=== TFS AI Bastion Deployment Monitor ===${NC}"
echo -e "${YELLOW}Environment: ${ENV}${NC}"
echo -e "${YELLOW}AWS Profile: ${AWS_PROFILE}${NC}"
echo -e "${YELLOW}Region: ${REGION}${NC}"
echo ""

# Function to check AWS connectivity
check_aws_connectivity() {
    echo -e "${BLUE}Checking AWS connectivity...${NC}"
    if aws sts get-caller-identity --profile "${AWS_PROFILE}" --region "${REGION}" &>/dev/null; then
        ACCOUNT_ID=$(aws sts get-caller-identity --profile "${AWS_PROFILE}" --query Account --output text)
        echo -e "${GREEN}✓ Connected to AWS Account: ${ACCOUNT_ID}${NC}"
    else
        echo -e "${RED}✗ Cannot connect to AWS. Check your credentials and profile.${NC}"
        exit 1
    fi
}

# Function to check backend resources
check_backend() {
    echo -e "\n${BLUE}Checking Terraform backend...${NC}"
    
    BUCKET_NAME="tfs-ai-terraform-state-087439708020"
    TABLE_NAME="tfs-ai-terraform-locks"
    
    # Check S3 bucket
    if aws s3api head-bucket --bucket "${BUCKET_NAME}" --profile "${AWS_PROFILE}" 2>/dev/null; then
        echo -e "${GREEN}✓ S3 bucket exists: ${BUCKET_NAME}${NC}"
    else
        echo -e "${RED}✗ S3 bucket not found: ${BUCKET_NAME}${NC}"
        echo -e "${YELLOW}  Run: ./scripts/create-backend.sh ${ENV}${NC}"
    fi
    
    # Check DynamoDB table
    if aws dynamodb describe-table --table-name "${TABLE_NAME}" --profile "${AWS_PROFILE}" --region "${REGION}" &>/dev/null; then
        echo -e "${GREEN}✓ DynamoDB table exists: ${TABLE_NAME}${NC}"
    else
        echo -e "${RED}✗ DynamoDB table not found: ${TABLE_NAME}${NC}"
        echo -e "${YELLOW}  Run: ./scripts/create-backend.sh ${ENV}${NC}"
    fi
}

# Function to check VPC resources
check_vpc() {
    echo -e "\n${BLUE}Checking VPC resources...${NC}"
    
    # Check for VPCs with our tag
    VPC_ID=$(aws ec2 describe-vpcs \
        --filters "Name=tag:Project,Values=tfs-ai-thoth" "Name=tag:Environment,Values=${ENV}" \
        --query "Vpcs[0].VpcId" \
        --output text \
        --profile "${AWS_PROFILE}" \
        --region "${REGION}" 2>/dev/null)
    
    if [[ "${VPC_ID}" != "None" && -n "${VPC_ID}" ]]; then
        echo -e "${GREEN}✓ VPC found: ${VPC_ID}${NC}"
        
        # Get VPC CIDR
        VPC_CIDR=$(aws ec2 describe-vpcs \
            --vpc-ids "${VPC_ID}" \
            --query "Vpcs[0].CidrBlock" \
            --output text \
            --profile "${AWS_PROFILE}" \
            --region "${REGION}")
        echo -e "  CIDR: ${VPC_CIDR}"
        
        # Count subnets
        SUBNET_COUNT=$(aws ec2 describe-subnets \
            --filters "Name=vpc-id,Values=${VPC_ID}" \
            --query "length(Subnets)" \
            --output text \
            --profile "${AWS_PROFILE}" \
            --region "${REGION}")
        echo -e "  Subnets: ${SUBNET_COUNT}"
    else
        echo -e "${YELLOW}✗ VPC not found${NC}"
    fi
}

# Function to check bastion instance
check_bastion() {
    echo -e "\n${BLUE}Checking bastion instance...${NC}"
    
    # Look for bastion instance
    INSTANCE_INFO=$(aws ec2 describe-instances \
        --filters "Name=tag:Name,Values=tfs-ai-${ENV}-bastion" \
                  "Name=instance-state-name,Values=pending,running,stopping,stopped" \
        --query "Reservations[0].Instances[0].[InstanceId,State.Name,PublicIpAddress]" \
        --output text \
        --profile "${AWS_PROFILE}" \
        --region "${REGION}" 2>/dev/null)
    
    if [[ -n "${INSTANCE_INFO}" && "${INSTANCE_INFO}" != "None" ]]; then
        INSTANCE_ID=$(echo "${INSTANCE_INFO}" | awk '{print $1}')
        INSTANCE_STATE=$(echo "${INSTANCE_INFO}" | awk '{print $2}')
        PUBLIC_IP=$(echo "${INSTANCE_INFO}" | awk '{print $3}')
        
        echo -e "${GREEN}✓ Bastion instance found${NC}"
        echo -e "  Instance ID: ${INSTANCE_ID}"
        echo -e "  State: ${INSTANCE_STATE}"
        
        if [[ "${INSTANCE_STATE}" == "running" ]]; then
            echo -e "  Public IP: ${PUBLIC_IP}"
            
            # Check if we can reach port 22
            if timeout 2 bash -c "echo >/dev/tcp/${PUBLIC_IP}/22" 2>/dev/null; then
                echo -e "  ${GREEN}✓ SSH port 22 is reachable${NC}"
            else
                echo -e "  ${YELLOW}⚠ SSH port 22 is not reachable yet${NC}"
            fi
        fi
    else
        echo -e "${YELLOW}✗ Bastion instance not found${NC}"
    fi
}

# Function to check Route53 records
check_dns() {
    echo -e "\n${BLUE}Checking DNS records...${NC}"
    
    ZONE_ID="Z07716653GDXJUDL4P879"
    DOMAIN="thoth-${ENV}.tfs.services"
    
    # Check if we can access the hosted zone (cross-account)
    if aws route53 list-resource-record-sets \
        --hosted-zone-id "${ZONE_ID}" \
        --query "ResourceRecordSets[?Name=='bastion.${DOMAIN}.']" \
        --profile "${AWS_PROFILE}" \
        --region "${REGION}" &>/dev/null; then
        
        echo -e "${GREEN}✓ Route53 access working${NC}"
        
        # Check for bastion DNS record
        BASTION_RECORD=$(aws route53 list-resource-record-sets \
            --hosted-zone-id "${ZONE_ID}" \
            --query "ResourceRecordSets[?Name=='bastion.${DOMAIN}.'].ResourceRecords[0].Value" \
            --output text \
            --profile "${AWS_PROFILE}" \
            --region "${REGION}" 2>/dev/null)
        
        if [[ -n "${BASTION_RECORD}" && "${BASTION_RECORD}" != "None" ]]; then
            echo -e "${GREEN}✓ Bastion DNS record found${NC}"
            echo -e "  bastion.${DOMAIN} → ${BASTION_RECORD}"
            
            # Try DNS resolution
            if host "bastion.${DOMAIN}" &>/dev/null; then
                echo -e "  ${GREEN}✓ DNS resolves correctly${NC}"
            else
                echo -e "  ${YELLOW}⚠ DNS not propagated yet${NC}"
            fi
        else
            echo -e "${YELLOW}✗ Bastion DNS record not found${NC}"
        fi
    else
        echo -e "${YELLOW}⚠ Cannot access Route53 (may need cross-account permissions)${NC}"
    fi
}

# Function to check SSL certificate
check_ssl() {
    echo -e "\n${BLUE}Checking SSL certificate...${NC}"
    
    DOMAIN="thoth-${ENV}.tfs.services"
    
    # Check for ACM certificate
    CERT_ARN=$(aws acm list-certificates \
        --query "CertificateSummaryList[?DomainName=='${DOMAIN}'].CertificateArn" \
        --output text \
        --profile "${AWS_PROFILE}" \
        --region "${REGION}" 2>/dev/null)
    
    if [[ -n "${CERT_ARN}" && "${CERT_ARN}" != "None" ]]; then
        # Get certificate status
        CERT_STATUS=$(aws acm describe-certificate \
            --certificate-arn "${CERT_ARN}" \
            --query "Certificate.Status" \
            --output text \
            --profile "${AWS_PROFILE}" \
            --region "${REGION}")
        
        echo -e "${GREEN}✓ SSL certificate found${NC}"
        echo -e "  Domain: ${DOMAIN}"
        echo -e "  Status: ${CERT_STATUS}"
        
        if [[ "${CERT_STATUS}" != "ISSUED" ]]; then
            echo -e "  ${YELLOW}⚠ Certificate not yet validated${NC}"
        fi
    else
        echo -e "${YELLOW}✗ SSL certificate not found${NC}"
    fi
}

# Function to show connection instructions
show_connection_info() {
    echo -e "\n${BLUE}Connection Information:${NC}"
    
    if [[ -n "${PUBLIC_IP}" ]]; then
        echo -e "${GREEN}SSH Connection:${NC}"
        echo -e "  ssh -i keys/bastion-${ENV} ec2-user@${PUBLIC_IP}"
        echo -e "\n${GREEN}Or use the Makefile:${NC}"
        echo -e "  make ssh-bastion ENV=${ENV}"
    else
        echo -e "${YELLOW}Bastion not yet available${NC}"
    fi
}

# Main monitoring flow
main() {
    check_aws_connectivity
    check_backend
    check_vpc
    check_bastion
    check_dns
    check_ssl
    show_connection_info
    
    echo -e "\n${BLUE}=== Monitoring Complete ===${NC}"
}

# Run main function
main