#!/bin/bash
# Interactive script to deploy the bastion host

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Configuration
ENV="staging"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo -e "${CYAN}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║        TFS AI Infrastructure - Bastion Deployment          ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Function to check prerequisites
check_prerequisites() {
    echo -e "${BLUE}[1/7] Checking prerequisites...${NC}"
    
    # Check Docker
    if command -v docker &> /dev/null; then
        echo -e "${GREEN}  ✓ Docker is installed${NC}"
    else
        echo -e "${RED}  ✗ Docker is not installed${NC}"
        echo -e "${YELLOW}    Please install Docker: https://docs.docker.com/get-docker/${NC}"
        exit 1
    fi
    
    # Check AWS CLI
    if command -v aws &> /dev/null; then
        echo -e "${GREEN}  ✓ AWS CLI is installed${NC}"
    else
        echo -e "${RED}  ✗ AWS CLI is not installed${NC}"
        echo -e "${YELLOW}    Please install AWS CLI: https://aws.amazon.com/cli/${NC}"
        exit 1
    fi
    
    # Check AWS profile
    if aws configure list --profile tfs-ai-terraform &>/dev/null; then
        echo -e "${GREEN}  ✓ AWS profile 'tfs-ai-terraform' is configured${NC}"
    else
        echo -e "${RED}  ✗ AWS profile 'tfs-ai-terraform' not found${NC}"
        echo -e "${YELLOW}    Run: aws configure --profile tfs-ai-terraform${NC}"
        exit 1
    fi
    
    # Check SSH key
    if [[ -f "${PROJECT_ROOT}/keys/bastion-staging" ]]; then
        echo -e "${GREEN}  ✓ SSH key exists${NC}"
    else
        echo -e "${RED}  ✗ SSH key not found${NC}"
        echo -e "${YELLOW}    Run: make generate-ssh-key ENV=staging${NC}"
        exit 1
    fi
    
    echo ""
}

# Function to update security group
update_security() {
    echo -e "${BLUE}[2/7] Configuring security...${NC}"
    
    # Get current IP
    CURRENT_IP=$(curl -s https://api.ipify.org)
    echo -e "${YELLOW}  Your current IP: ${CURRENT_IP}${NC}"
    
    # Check if tfvars has open security group
    if grep -q "0.0.0.0/0" "${PROJECT_ROOT}/terraform/environments/staging.tfvars"; then
        echo -e "${YELLOW}  ⚠ Security group is open to the world!${NC}"
        echo -n "  Do you want to restrict SSH access to your IP only? (recommended) [Y/n]: "
        read -r response
        
        if [[ "${response}" != "n" && "${response}" != "N" ]]; then
            # Update tfvars file
            sed -i.bak "s|0.0.0.0/0|${CURRENT_IP}/32|g" "${PROJECT_ROOT}/terraform/environments/staging.tfvars"
            echo -e "${GREEN}  ✓ Updated security group to allow only ${CURRENT_IP}/32${NC}"
        else
            echo -e "${YELLOW}  ⚠ Keeping security group open (not recommended)${NC}"
        fi
    else
        echo -e "${GREEN}  ✓ Security group already configured${NC}"
    fi
    
    echo ""
}

# Function to create backend
create_backend() {
    echo -e "${BLUE}[3/7] Setting up Terraform backend...${NC}"
    
    "${PROJECT_ROOT}/scripts/create-backend.sh" staging
    
    echo ""
}

# Function to initialize terraform
init_terraform() {
    echo -e "${BLUE}[4/7] Initializing Terraform...${NC}"
    
    cd "${PROJECT_ROOT}"
    
    # First run make init to ensure .terraform directory exists
    make init
    
    # Then initialize with backend config
    cd terraform
    ../terraform-docker.sh init -backend-config=backend-staging.hcl
    cd ..
    
    echo -e "${GREEN}  ✓ Terraform initialized${NC}"
    echo ""
}

# Function to plan infrastructure
plan_infrastructure() {
    echo -e "${BLUE}[5/7] Planning infrastructure...${NC}"
    
    cd "${PROJECT_ROOT}"
    make plan ENV=staging
    
    echo ""
    echo -e "${YELLOW}Please review the plan above.${NC}"
    echo -n "Do you want to proceed with applying these changes? [y/N]: "
    read -r response
    
    if [[ "${response}" != "y" && "${response}" != "Y" ]]; then
        echo -e "${RED}Deployment cancelled.${NC}"
        exit 0
    fi
    
    echo ""
}

# Function to apply infrastructure
apply_infrastructure() {
    echo -e "${BLUE}[6/7] Applying infrastructure...${NC}"
    echo -e "${YELLOW}This will take approximately 5-10 minutes...${NC}"
    
    cd "${PROJECT_ROOT}"
    make apply ENV=staging
    
    echo -e "${GREEN}  ✓ Infrastructure deployed${NC}"
    echo ""
}

# Function to verify deployment
verify_deployment() {
    echo -e "${BLUE}[7/7] Verifying deployment...${NC}"
    
    # Run monitoring script
    "${PROJECT_ROOT}/scripts/monitor-deployment.sh" staging
    
    echo ""
}

# Function to show next steps
show_next_steps() {
    echo -e "${CYAN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║                    Deployment Complete!                    ║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${GREEN}Next steps:${NC}"
    echo ""
    echo -e "1. ${YELLOW}Connect to bastion:${NC}"
    echo -e "   make ssh-bastion ENV=staging"
    echo ""
    echo -e "2. ${YELLOW}Monitor resources:${NC}"
    echo -e "   ./scripts/monitor-deployment.sh staging"
    echo ""
    echo -e "3. ${YELLOW}View Terraform outputs:${NC}"
    echo -e "   cd terraform && ../terraform-docker.sh output"
    echo ""
    echo -e "4. ${YELLOW}Add to SSH config:${NC}"
    echo -e "   Host thoth-bastion-staging"
    echo -e "       HostName bastion.thoth-staging.tfs.services"
    echo -e "       User ec2-user"
    echo -e "       IdentityFile ${PROJECT_ROOT}/keys/bastion-staging"
    echo ""
}

# Main deployment flow
main() {
    check_prerequisites
    update_security
    create_backend
    init_terraform
    plan_infrastructure
    apply_infrastructure
    verify_deployment
    show_next_steps
}

# Handle Ctrl+C
trap 'echo -e "\n${RED}Deployment interrupted.${NC}"; exit 1' INT

# Run main function
main