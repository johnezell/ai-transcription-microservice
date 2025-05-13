#!/bin/bash

# AWS Transcription Service Deployment Menu
# This script provides a menu-driven interface for managing deployments

# Colors for better readability
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Configuration
AWS_PROFILE="tfs-shared-services"
AWS_REGION="us-east-1"
TERRAFORM_DIR="../terraform"
ENVIRONMENT="dev" # Default environment
TERRAFORM_IMAGE="hashicorp/terraform:1.5.7"
TERRAFORM_WORKDIR="/workspace"

# Check for AWS CLI and Docker installation
check_prerequisites() {
    echo -e "${BLUE}Checking prerequisites...${NC}"
    
    if ! command -v aws &> /dev/null; then
        echo -e "${RED}AWS CLI is not installed. Please install it first.${NC}"
        exit 1
    fi
    
    if ! command -v docker &> /dev/null; then
        echo -e "${RED}Docker is not installed. Please install it first.${NC}"
        exit 1
    fi
    
    # Pull Terraform Docker image if not already pulled
    if ! docker image inspect $TERRAFORM_IMAGE &> /dev/null; then
        echo -e "${YELLOW}Pulling Terraform Docker image...${NC}"
        docker pull $TERRAFORM_IMAGE
    fi
    
    echo -e "${GREEN}Prerequisites check passed.${NC}"
}

# Helper function to run Terraform commands via Docker
run_terraform() {
    local cmd=$1
    local extra_args=${2:-""}
    
    # Create absolute path to terraform directory
    local abs_terraform_dir=$(cd $TERRAFORM_DIR && pwd)
    
    # Mount AWS credentials, terraform directory, and run terraform command
    docker run --rm -it \
        -v ~/.aws:/root/.aws \
        -v $abs_terraform_dir:$TERRAFORM_WORKDIR \
        -w $TERRAFORM_WORKDIR \
        -e AWS_PROFILE=$AWS_PROFILE \
        -e AWS_REGION=$AWS_REGION \
        $TERRAFORM_IMAGE \
        $cmd $extra_args
}

# Set AWS environment variables
set_aws_env() {
    export AWS_PROFILE=$AWS_PROFILE
    export AWS_REGION=$AWS_REGION
    echo -e "${GREEN}Using AWS Profile: ${YELLOW}$AWS_PROFILE${NC}"
    echo -e "${GREEN}Using AWS Region: ${YELLOW}$AWS_REGION${NC}"
}

# Display header
show_header() {
    clear
    echo -e "${BLUE}================================================${NC}"
    echo -e "${BLUE}    AWS Transcription Service Deployment Tool    ${NC}"
    echo -e "${BLUE}================================================${NC}"
    echo -e "${GREEN}Environment: ${YELLOW}$ENVIRONMENT${NC}"
    echo -e "${GREEN}AWS Profile: ${YELLOW}$AWS_PROFILE${NC}"
    echo -e "${GREEN}AWS Region: ${YELLOW}$AWS_REGION${NC}"
    echo -e "${GREEN}Terraform Version: ${YELLOW}Using Docker Image ${TERRAFORM_IMAGE}${NC}"
    echo -e "${BLUE}------------------------------------------------${NC}"
    echo ""
}

# Build and push Docker images to ECR
build_push_images() {
    show_header
    echo -e "${BLUE}Building and pushing Docker images to ECR...${NC}"
    
    # Check if ECR repositories exist
    if ./check-ecr-repos.sh; then
        echo -e "${GREEN}ECR repositories found.${NC}"
    else
        echo -e "${YELLOW}ECR repositories not found. Creating them first...${NC}"
        terraform_apply_target "module.ecr"
    fi
    
    # Build and push images
    ./build-push.sh
    
    echo -e "${GREEN}Docker images built and pushed successfully.${NC}"
    read -p "Press Enter to continue..."
}

# Initialize Terraform
terraform_init() {
    show_header
    echo -e "${BLUE}Initializing Terraform...${NC}"
    
    # Run terraform init via Docker
    run_terraform "init"
    
    echo -e "${GREEN}Terraform initialized successfully.${NC}"
    read -p "Press Enter to continue..."
}

# Run Terraform plan
terraform_plan() {
    show_header
    echo -e "${BLUE}Running Terraform plan...${NC}"
    
    # Create or select workspace through Docker
    run_terraform "workspace select $ENVIRONMENT || terraform workspace new $ENVIRONMENT"
    
    # Run terraform plan via Docker
    run_terraform "plan" "-var-file=${ENVIRONMENT}.tfvars"
    
    echo -e "${GREEN}Terraform plan completed.${NC}"
    read -p "Press Enter to continue..."
}

# Apply Terraform changes
terraform_apply() {
    show_header
    echo -e "${BLUE}Applying Terraform changes...${NC}"
    
    # Create or select workspace through Docker
    run_terraform "workspace select $ENVIRONMENT || terraform workspace new $ENVIRONMENT"
    
    # Run terraform apply via Docker
    run_terraform "apply" "-var-file=${ENVIRONMENT}.tfvars"
    
    echo -e "${GREEN}Terraform apply completed.${NC}"
    read -p "Press Enter to continue..."
}

# Apply specific Terraform resources
terraform_apply_target() {
    local target=$1
    
    # Create or select workspace through Docker
    run_terraform "workspace select $ENVIRONMENT || terraform workspace new $ENVIRONMENT"
    
    # Run terraform apply with target via Docker
    run_terraform "apply" "-var-file=${ENVIRONMENT}.tfvars -target=\"$target\""
}

# Deploy specific components
deploy_component() {
    show_header
    echo -e "${BLUE}Select a component to deploy:${NC}"
    echo "1) ECR Repositories"
    echo "2) EFS Storage"
    echo "3) RDS Database"
    echo "4) ECS Cluster"
    echo "5) Load Balancer"
    echo "6) Laravel Service"
    echo "7) Audio Extraction Service"
    echo "8) Transcription Service"
    echo "9) Music Term Recognition Service"
    echo "0) Back to main menu"
    
    read -p "Enter your choice: " component_choice
    
    case $component_choice in
        1) terraform_apply_target "module.ecr" ;;
        2) terraform_apply_target "module.efs" ;;
        3) terraform_apply_target "module.rds" ;;
        4) terraform_apply_target "module.ecs_cluster" ;;
        5) terraform_apply_target "module.alb" ;;
        6) terraform_apply_target "module.ecs_service_laravel" ;;
        7) terraform_apply_target "module.ecs_service_audio" ;;
        8) terraform_apply_target "module.ecs_service_transcription" ;;
        9) terraform_apply_target "module.ecs_service_music" ;;
        0) return ;;
        *) 
            echo -e "${RED}Invalid option${NC}" 
            sleep 2
            deploy_component
            ;;
    esac
    
    read -p "Press Enter to continue..."
}

# View logs
view_logs() {
    show_header
    echo -e "${BLUE}Select a service to view logs:${NC}"
    echo "1) Laravel Service"
    echo "2) Audio Extraction Service"
    echo "3) Transcription Service"
    echo "4) Music Term Recognition Service"
    echo "0) Back to main menu"
    
    read -p "Enter your choice: " logs_choice
    
    local log_group="/ecs/aws-transcription-${ENVIRONMENT}"
    local service_name=""
    
    case $logs_choice in
        1) service_name="laravel" ;;
        2) service_name="audio-extraction" ;;
        3) service_name="transcription" ;;
        4) service_name="music-term-recognition" ;;
        0) return ;;
        *) 
            echo -e "${RED}Invalid option${NC}" 
            sleep 2
            view_logs
            ;;
    esac
    
    if [ -n "$service_name" ]; then
        echo -e "${BLUE}Fetching logs for $service_name...${NC}"
        aws logs tail "$log_group" --log-stream-name-prefix "$service_name" --since 1h
        read -p "Press Enter to continue..."
    fi
}

# Change environment
change_environment() {
    show_header
    echo -e "${BLUE}Select an environment:${NC}"
    echo "1) Development (dev)"
    echo "2) Production (prod)"
    echo "0) Back to main menu"
    
    read -p "Enter your choice: " env_choice
    
    case $env_choice in
        1) ENVIRONMENT="dev" ;;
        2) ENVIRONMENT="prod" ;;
        0) return ;;
        *) 
            echo -e "${RED}Invalid option${NC}" 
            sleep 2
            change_environment
            ;;
    esac
}

# Main menu
main_menu() {
    while true; do
        show_header
        echo -e "${BLUE}Main Menu:${NC}"
        echo "1) Build and Push Docker Images"
        echo "2) Initialize Terraform"
        echo "3) Plan Terraform Changes"
        echo "4) Apply Terraform Changes"
        echo "5) Deploy Specific Component"
        echo "6) View Service Logs"
        echo "7) Change Environment"
        echo "8) Exit"
        
        read -p "Enter your choice: " choice
        
        case $choice in
            1) build_push_images ;;
            2) terraform_init ;;
            3) terraform_plan ;;
            4) terraform_apply ;;
            5) deploy_component ;;
            6) view_logs ;;
            7) change_environment ;;
            8) 
                echo -e "${GREEN}Exiting...${NC}"
                exit 0
                ;;
            *) 
                echo -e "${RED}Invalid option${NC}" 
                sleep 2
                ;;
        esac
    done
}

# Main function
main() {
    check_prerequisites
    set_aws_env
    main_menu
}

# Run the main function
main 