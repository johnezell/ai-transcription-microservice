# AWS Transcription Service Deployment Plan

## Overview

This document outlines the plan for deploying the AWS Transcription Service application to Amazon ECS using Terraform. The application consists of multiple microservices that need to be containerized and deployed to AWS infrastructure.

## Application Components

The application consists of the following components:
1. **Laravel Web Application** - PHP Laravel application serving both web UI and API endpoints
2. **Audio Extraction Service** - Python service that extracts audio from video files
3. **Transcription Service** - Python service that interfaces with AWS Transcription API
4. **Music Term Recognition Service** - Python service for music term recognition

## Infrastructure Requirements

1. **Container Repositories** - Amazon ECR for storing Docker images
2. **Container Orchestration** - Amazon ECS for running containers
3. **Database** - Amazon RDS MySQL as a replacement for the local MySQL container
4. **Shared Storage** - Amazon EFS for shared storage across services
5. **Load Balancing** - Application Load Balancer for routing traffic
6. **Networking** - Using existing VPC and subnets

## Pre-existing Resources

We will use the following pre-existing network infrastructure:

- **VPC ID**: vpc-09422297ced61f9d2
- **Public Subnets**:
  - subnet-0460f66368d31fd0d (Availability Zone A)
  - subnet-02355996f055ea5ac (Availability Zone B)
- **Private Subnets**:
  - subnet-096caf8b193f1d108 (Availability Zone A)
  - subnet-0afef54f7c422ecab (Availability Zone B)
- **AWS Credentials**: Using the AWS profile `tfs-shared-services` configured in ~/.aws
- **AWS Region**: us-east-1

## Deployment Progress Checklist

| Component | Status | Notes/Findings |
|-----------|--------|----------------|
| **Setup & Configuration** |  |  |
| ✅ Create deployment plan | Completed | Initial plan document created |
| ⬜ Set up Terraform directory structure |  |  |
| ⬜ Configure Terraform backend |  |  |
| ⬜ Create deployment menu script | In Progress | Basic structure created |
| ⬜ Configure Docker for Terraform | In Progress | Added to script, not yet tested |
| **Infrastructure Deployment** |  |  |
| ⬜ Network configuration |  |  |
| ⬜ Security groups |  |  |
| ⬜ ECR repositories |  |  |
| ⬜ EFS file system |  |  |
| ⬜ RDS database |  |  |
| ⬜ ECS cluster |  |  |
| ⬜ Load balancer |  |  |
| ⬜ IAM roles and policies |  |  |
| **Service Deployment** |  |  |
| ⬜ Laravel service |  |  |
| ⬜ Audio extraction service |  |  |
| ⬜ Transcription service |  |  |
| ⬜ Music term recognition service |  |  |
| **Testing & Validation** |  |  |
| ⬜ Infrastructure validation |  |  |
| ⬜ Service discovery testing |  |  |
| ⬜ End-to-end functionality test |  |  |
| ⬜ Performance testing |  |  |
| ⬜ Security validation |  |  |
| **Documentation & Handover** |  |  |
| ⬜ Update documentation |  |  |
| ⬜ Create operational runbook |  |  |
| ⬜ Document CI/CD pipeline |  |  |

## Terraform Implementation Plan

### 1. Provider and Backend Configuration
- Configure AWS provider using the `tfs-shared-services` profile in us-east-1 region
- Set up S3 backend for Terraform state
- Define common variables and data sources
- Reference existing VPC and subnet resources using data sources

### 2. Networking Infrastructure
- Import existing VPC and subnet resources
- Create security groups within the existing VPC
- Create Application Load Balancer in the public subnets
- Configure routing rules for the ALB

### 3. ECR Repositories
- Create ECR repositories for each service:
  - `aws-transcription-laravel`
  - `aws-audio-extraction`
  - `aws-transcription-service`
  - `aws-music-term-recognition`
- Configure lifecycle policies

### 4. ECS Infrastructure
- Create ECS cluster in us-east-1 region
- Set up task execution and task roles with appropriate permissions
- Create CloudWatch log groups

### 5. Database
- Create RDS MySQL instance in the private subnets
- Configure security groups and subnet groups
- Set up parameter groups and backup policies

### 6. Shared Storage
- Create EFS file system
- Configure mount targets in the private subnets
- Set up access points and security groups

### 7. Task Definitions
- Define task definitions for each service with:
  - Container definitions (image, CPU, memory, port mappings)
  - Volume configurations (EFS mounts)
  - Environment variables
  - Logging configuration

### 8. ECS Services
- Create ECS services for each component in the private subnets
- Configure service discovery for inter-service communication
- Set up autoscaling policies
- Define health checks and deployment configuration

### 9. CI/CD Pipeline
- Create scripts for building and pushing Docker images to ECR
- Configure CodePipeline/CodeBuild or GitHub Actions for automated deployments

## Iterative Testing Approach

We'll use a modular, incremental approach to build and test the infrastructure:

### 1. Module-by-Module Testing
- Divide Terraform configurations into logical modules
- Test each module independently before integration
- Use the following testing sequence:
  1. Network configuration and security groups
  2. ECR repositories
  3. EFS file system
  4. RDS database
  5. ECS cluster and base infrastructure
  6. Individual services one at a time

### 2. Local Docker Testing
- Test service containers locally before pushing to ECR:
  ```bash
  docker-compose up audio-extraction-service
  ```
- Validate individual container functionality
- Test inter-service communication locally

### 3. Terraform Workspaces
- Use Terraform workspaces to isolate environments:
  ```bash
  terraform workspace new dev
  terraform workspace select dev
  ```
- Create a `dev` environment for testing before applying to production
- Use consistent naming with environment prefixes to avoid resource conflicts

### 4. Partial Deployments
- Use targeted applies to test specific resources:
  ```bash
  terraform apply -target=module.ecr -var-file=terraform.tfvars
  ```
- Build and validate ECR repositories first
- Push container images to ECR before creating ECS services
- Create ECS task definitions before services

### 5. Test Environment Variables
- Create separate `.tfvars` files for different environments:
  ```
  terraform/
  ├── terraform.tfvars       # Common variables
  ├── dev.tfvars             # Development-specific overrides
  ├── prod.tfvars            # Production-specific overrides
  ```
- Test with dev settings before applying production configuration

### 6. Infrastructure Validation
- Use built-in validation:
  ```bash
  terraform validate
  ```
- Run plan mode frequently to check for potential issues:
  ```bash
  terraform plan -var-file=dev.tfvars
  ```
- Review planned changes carefully before applying

## Using Docker for Terraform

To eliminate the need for local Terraform installation and ensure consistent versions across all deployments, we'll use Docker to run Terraform:

### 1. Docker Approach Benefits
- No need to install Terraform locally
- Consistent Terraform version across all environments
- Isolated execution environment
- Works on any platform with Docker

### 2. Docker Command Pattern
```bash
docker run --rm -it \
  -v $(pwd):/workspace \
  -v ~/.aws:/root/.aws \
  -e AWS_PROFILE=tfs-shared-services \
  -e AWS_REGION=us-east-1 \
  -w /workspace \
  hashicorp/terraform:1.5.7 \
  [command]
```

### 3. Usage with Deployment Script
Our `deploy-menu.sh` script has been updated to:
- Check for Docker instead of Terraform
- Pull the Terraform Docker image if needed
- Run all Terraform commands through Docker
- Mount the necessary volumes for AWS credentials and Terraform files

### 4. Image Version Management
- We're using a specific version tag (1.5.7) to ensure consistency
- Version can be updated in the script as needed
- Script checks if the image exists locally before pulling

## Deployment Process

1. **Initialize Infrastructure**
   ```bash
   terraform init
   terraform plan -var-file=terraform.tfvars
   terraform apply -var-file=terraform.tfvars
   ```

2. **Build and Push Docker Images**
   ```bash
   AWS_PROFILE=tfs-shared-services AWS_REGION=us-east-1 ./scripts/build-push.sh
   ```

3. **Deploy Services**
   ```bash
   terraform apply -var-file=terraform.tfvars -var='deploy_services=true'
   ```

## Key Considerations

1. **Environment Variables**: 
   - Store sensitive environment variables in AWS Systems Manager Parameter Store
   - Update environment configurations to use AWS services instead of local resources

2. **Service Communication**:
   - Use ECS Service Discovery or internal ALB for service-to-service communication
   - Update service URLs to use the discovered endpoints

3. **Security**:
   - Use IAM roles for service permissions
   - Restrict security group access
   - Encrypt data at rest and in transit

4. **Cost Optimization**:
   - Use Fargate Spot for non-critical services
   - Implement autoscaling based on demand
   - Use the appropriate instance types for RDS

5. **Monitoring and Logging**:
   - Set up CloudWatch alarms for service metrics
   - Configure log shipping to CloudWatch Logs
   - Set up tracing with X-Ray (optional)

## Terraform Directory Structure

```
terraform/
├── main.tf              # Provider configuration, backend, common variables
├── variables.tf         # Input variables
├── terraform.tfvars     # Variable values including AWS profile settings
├── dev.tfvars           # Development environment variables
├── outputs.tf           # Output values
├── network.tf           # Import existing VPC and subnets, create security groups
├── ecr.tf               # ECR repositories
├── ecs-cluster.tf       # ECS cluster configuration
├── ecs-tasks.tf         # Task definitions
├── ecs-services.tf      # ECS services
├── rds.tf               # Database resources
├── efs.tf               # Shared storage resources
└── monitoring.tf        # CloudWatch alarms and dashboards
```

## Terraform Code Examples with Comments

### Provider and Backend Configuration (main.tf)

```hcl
# Terraform block defines the required providers and versions
# This is similar to package.json in Node.js or requirements.txt in Python
terraform {
  # Requiring specific version of AWS provider ensures compatibility
  required_providers {
    aws = {
      source  = "hashicorp/aws"  # Provider source - hashicorp is the official maintainer
      version = "~> 4.0"         # Version constraint - ~> means "compatible with"
    }
  }
  
  # Backend configuration determines where Terraform state is stored
  # S3 backend enables team collaboration and state locking
  backend "s3" {
    bucket  = "your-terraform-state-bucket"  # S3 bucket to store state
    key     = "aws-transcription-service/terraform.tfstate"  # Path within bucket
    region  = "us-east-1"
    profile = "tfs-shared-services"
    
    # DynamoDB table for state locking to prevent concurrent modifications
    dynamodb_table = "terraform-state-lock"  # Uncomment if using state locking
  }
}

# Configure the AWS Provider
# This is equivalent to configuring the AWS SDK or CLI
provider "aws" {
  region  = "us-east-1"
  profile = "tfs-shared-services"
  
  # Default tags apply to all resources created by this provider
  # This is more efficient than adding tags to each resource manually
  default_tags {
    tags = {
      Project     = "AWS-Transcription-Service"
      Environment = terraform.workspace  # Automatically uses workspace name (dev, prod)
      ManagedBy   = "Terraform"
    }
  }
}

# Variables are like function parameters for your Terraform configuration
# Define variables in variables.tf, set values in .tfvars files
variable "app_name" {
  description = "Name of the application"
  type        = string
  default     = "aws-transcription"
}

# Data sources fetch information from your AWS account
# These don't create resources - they just query existing ones
# Useful for working with pre-existing infrastructure

# Reference existing VPC - data sources use read-only API calls
data "aws_vpc" "existing" {
  id = "vpc-09422297ced61f9d2"
}

# Reference existing subnets - these could alternatively be fetched by
# filters like tags instead of hardcoded IDs, which is more maintainable
data "aws_subnet" "public_a" {
  id = "subnet-0460f66368d31fd0d"
}

data "aws_subnet" "public_b" {
  id = "subnet-02355996f055ea5ac"
}

data "aws_subnet" "private_a" {
  id = "subnet-096caf8b193f1d108"
}

data "aws_subnet" "private_b" {
  id = "subnet-0afef54f7c422ecab"
}

# Local values are like local variables in programming
# Use locals for values derived from other values or for readability
locals {
  environment = terraform.workspace
  common_tags = {
    Application = var.app_name
    Environment = local.environment
  }
}
```

### ECR Repositories (ecr.tf)

```hcl
# ECR repositories store container images like Docker Hub but in your AWS account
# Each service gets its own repository for better separation of concerns

# Create repository for Laravel service
resource "aws_ecr_repository" "laravel" {
  name                 = "${var.app_name}-laravel"
  image_tag_mutability = "MUTABLE"  # Allows overwriting tags (like "latest")
  
  # Enable image scanning for security vulnerabilities
  image_scanning_configuration {
    scan_on_push = true  # Automatically scan images when pushed
  }
  
  # Encryption configuration for images at rest
  encryption_configuration {
    encryption_type = "KMS"  # AWS Key Management Service
    # Optional: kms_key = aws_kms_key.ecr.arn
  }
  
  # Uncomment to automatically apply tags from variables
  # tags = local.common_tags
}

# Create repository for Audio Extraction service
resource "aws_ecr_repository" "audio" {
  name                 = "${var.app_name}-audio-extraction"
  image_tag_mutability = "MUTABLE"
  
  image_scanning_configuration {
    scan_on_push = true
  }
  
  # Tags can be merged with local values
  tags = merge(local.common_tags, {
    Service = "audio-extraction"
  })
}

# Repeat similar blocks for transcription and music services

# Lifecycle policy controls image retention to manage costs
# This is like setting up garbage collection for your container registry
resource "aws_ecr_lifecycle_policy" "laravel" {
  repository = aws_ecr_repository.laravel.name
  
  # Policy document in JSON format - Terraform uses a lot of JSON/HCL hybrid syntax
  policy = jsonencode({
    rules = [
      {
        rulePriority = 1,
        description  = "Keep only 10 untagged images",
        selection = {
          tagStatus   = "untagged",  # Applies to images without specific tags
          countType   = "imageCountMoreThan",
          countNumber = 10
        },
        action = {
          type = "expire"  # Delete images that match the selection criteria
        }
      },
      {
        rulePriority = 2,
        description  = "Keep last 30 tagged images per service",
        selection = {
          tagStatus     = "any",
          countType     = "imageCountMoreThan",
          countNumber   = 30
        },
        action = {
          type = "expire"
        }
      }
    ]
  })
}

# Output values are like return values from your Terraform configuration
# Use them to display important information after applying
output "repository_urls" {
  description = "URLs of the ECR repositories"
  value = {
    laravel       = aws_ecr_repository.laravel.repository_url
    audio         = aws_ecr_repository.audio.repository_url
    # Add other repositories here
  }
}
```

### ECS Resources (ecs-cluster.tf)

```hcl
# ECS Cluster is the container orchestration platform
# Similar to creating a Kubernetes cluster but AWS-specific
resource "aws_ecs_cluster" "main" {
  name = "${var.app_name}-cluster-${local.environment}"
  
  # Enable Container Insights for advanced monitoring
  setting {
    name  = "containerInsights"
    value = "enabled"
  }
  
  # Service Connect enables service discovery between containers
  # This is similar to Kubernetes service discovery
  service_connect_defaults {
    namespace = aws_service_discovery_http_namespace.main.arn
  }
  
  tags = local.common_tags
}

# Service discovery namespace for internal container communication
resource "aws_service_discovery_http_namespace" "main" {
  name        = "${var.app_name}-namespace"
  description = "Service discovery namespace for ${var.app_name} services"
  
  tags = local.common_tags
}

# IAM role for ECS task execution
# This gives the ECS service permission to pull images, write logs, etc.
resource "aws_iam_role" "ecs_task_execution" {
  name = "${var.app_name}-task-execution-role"
  
  # Assume role policy allows ECS to assume this role
  # This is similar to IAM role trust relationships in the console
  assume_role_policy = jsonencode({
    Version = "2012-10-17",
    Statement = [
      {
        Action = "sts:AssumeRole",
        Principal = {
          Service = "ecs-tasks.amazonaws.com"
        },
        Effect = "Allow",
        Sid    = ""
      }
    ]
  })
  
  tags = local.common_tags
}

# Attach AWS managed policy for basic task execution permissions
# Using managed policies is easier than creating custom policies
resource "aws_iam_role_policy_attachment" "ecs_task_execution" {
  role       = aws_iam_role.ecs_task_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

# IAM role for the tasks themselves (application permissions)
# This is what your application code uses to access AWS services
resource "aws_iam_role" "ecs_task" {
  name = "${var.app_name}-task-role"
  
  assume_role_policy = jsonencode({
    Version = "2012-10-17",
    Statement = [
      {
        Action = "sts:AssumeRole",
        Principal = {
          Service = "ecs-tasks.amazonaws.com"
        },
        Effect = "Allow",
        Sid    = ""
      }
    ]
  })
  
  # Inline policy for specific permissions
  # You can use inline or managed policies based on complexity
  inline_policy {
    name = "transcription-permissions"
    policy = jsonencode({
      Version = "2012-10-17",
      Statement = [
        {
          Effect = "Allow",
          Action = [
            # Specific permissions needed by your application
            "transcribe:StartTranscriptionJob",
            "transcribe:GetTranscriptionJob",
            "s3:GetObject",
            "s3:PutObject"
          ],
          Resource = "*"  # Limit this to specific resources in production
        }
      ]
    })
  }
  
  tags = local.common_tags
}

# CloudWatch Log Group for container logs
# Similar to creating log groups in CloudWatch console
resource "aws_cloudwatch_log_group" "main" {
  name              = "/ecs/${var.app_name}-${local.environment}"
  retention_in_days = 30  # Adjust based on your requirements
  
  # Encryption for sensitive logs
  kms_key_id = aws_kms_key.logs.arn
  
  tags = local.common_tags
}

# KMS key for log encryption
# Creates a custom encryption key in KMS
resource "aws_kms_key" "logs" {
  description         = "KMS key for encrypting ${var.app_name} logs"
  enable_key_rotation = true  # Security best practice
  
  tags = local.common_tags
}
```

### ECS Task Definition (ecs-tasks.tf)

```hcl
# Task definition describes how to run a container
# Similar to a Docker Compose file or Kubernetes pod definition
resource "aws_ecs_task_definition" "laravel" {
  family                   = "${var.app_name}-laravel"  # Task definition name
  requires_compatibilities = ["FARGATE"]  # Run on Fargate (serverless)
  network_mode             = "awsvpc"     # Required for Fargate
  cpu                      = "1024"       # 1 vCPU
  memory                   = "2048"       # 2 GB RAM
  
  # Execution role is for the container agent
  execution_role_arn = aws_iam_role.ecs_task_execution.arn
  
  # Task role is for your application code
  task_role_arn      = aws_iam_role.ecs_task.arn
  
  # Volume configuration for EFS
  # This creates a persistent volume that survives container restarts
  volume {
    name = "shared-storage"
    
    efs_volume_configuration {
      file_system_id     = aws_efs_file_system.main.id
      transit_encryption = "ENABLED"
      
      authorization_config {
        access_point_id = aws_efs_access_point.laravel.id
      }
    }
  }
  
  # Container definitions - similar to entries in a docker-compose.yml
  # This JSON defines the containers in your task
  container_definitions = jsonencode([
    {
      name  = "laravel",
      image = "${aws_ecr_repository.laravel.repository_url}:latest",
      
      # Port mappings expose container ports
      portMappings = [
        {
          containerPort = 80,
          hostPort      = 80,
          protocol      = "tcp"
        }
      ],
      
      # Environment variables for container configuration
      # These could come from SSM Parameter Store for sensitive values
      environment = [
        { name = "APP_ENV", value = local.environment },
        { name = "DB_HOST", value = aws_rds_cluster.main.endpoint },
        { name = "AUDIO_SERVICE_URL", value = "http://audio-extraction-service.${aws_service_discovery_http_namespace.main.name}" },
        { name = "TRANSCRIPTION_SERVICE_URL", value = "http://transcription-service.${aws_service_discovery_http_namespace.main.name}" },
        { name = "MUSIC_TERM_SERVICE_URL", value = "http://music-term-recognition-service.${aws_service_discovery_http_namespace.main.name}" }
      ],
      
      # Secret environment variables from SSM Parameter Store
      # More secure than hardcoding in the task definition
      secrets = [
        { name = "DB_PASSWORD", valueFrom = "arn:aws:ssm:us-east-1:${data.aws_caller_identity.current.account_id}:parameter/transcription/db/password" }
      ],
      
      # Mount points connect volumes to container paths
      mountPoints = [
        {
          sourceVolume  = "shared-storage",
          containerPath = "/var/www/storage/app/public/s3",
          readOnly      = false
        }
      ],
      
      # Essential containers must be running for the task to be considered healthy
      essential = true,
      
      # Logging configuration sends container logs to CloudWatch
      logConfiguration = {
        logDriver = "awslogs",
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.main.name,
          "awslogs-region"        = "us-east-1",
          "awslogs-stream-prefix" = "laravel"
        }
      },
      
      # Health check ensures container is running properly
      # Docker will restart the container if checks fail
      healthCheck = {
        command     = ["CMD-SHELL", "curl -f http://localhost/api/health || exit 1"],
        interval    = 30,
        timeout     = 5,
        retries     = 3,
        startPeriod = 60  # Allow time for startup
      }
    }
  ])
  
  # Using depends_on ensures proper resource creation order
  # Not strictly necessary but helps avoid race conditions
  depends_on = [
    aws_efs_mount_target.private_a,
    aws_efs_mount_target.private_b
  ]
  
  tags = local.common_tags
}

# Get current account ID for use in ARNs
data "aws_caller_identity" "current" {}
```

### Security Groups (network.tf)

```hcl
# Security groups control network traffic like firewalls
# Think of them as firewall rules for your AWS resources

# ALB Security Group - controls traffic to/from the load balancer
resource "aws_security_group" "alb" {
  name        = "${var.app_name}-alb-sg"
  description = "Controls access to the ALB"
  vpc_id      = data.aws_vpc.existing.id
  
  # Ingress rules define allowed inbound traffic
  # This allows HTTP traffic from anywhere
  ingress {
    protocol    = "tcp"
    from_port   = 80
    to_port     = 80
    cidr_blocks = ["0.0.0.0/0"]  # Public access
    description = "Allow HTTP from internet"
  }
  
  # This allows HTTPS traffic from anywhere
  ingress {
    protocol    = "tcp"
    from_port   = 443
    to_port     = 443
    cidr_blocks = ["0.0.0.0/0"]  # Public access
    description = "Allow HTTPS from internet"
  }
  
  # Egress rules define allowed outbound traffic
  # This allows all outbound traffic
  egress {
    protocol    = "-1"  # All protocols
    from_port   = 0     # All ports
    to_port     = 0     # All ports
    cidr_blocks = ["0.0.0.0/0"]
    description = "Allow all outbound traffic"
  }
  
  # Lifecycle settings control how Terraform manages this resource
  # This prevents Terraform from replacing the security group
  lifecycle {
    create_before_destroy = true
  }
  
  tags = merge(local.common_tags, {
    Name = "${var.app_name}-alb-sg"
  })
}

# ECS Security Group - controls traffic to/from the containers
resource "aws_security_group" "ecs" {
  name        = "${var.app_name}-ecs-sg"
  description = "Controls access to the ECS services"
  vpc_id      = data.aws_vpc.existing.id
  
  # Allow traffic from ALB to services
  ingress {
    protocol        = "tcp"
    from_port       = 80
    to_port         = 80
    security_groups = [aws_security_group.alb.id]  # Only allow traffic from ALB
    description     = "Allow HTTP from ALB"
  }
  
  # Allow internal traffic between services
  ingress {
    protocol        = "tcp"
    from_port       = 5000
    to_port         = 5000
    self            = true  # Allow traffic from resources with this security group
    description     = "Allow service-to-service communication"
  }
  
  # Allow all outbound traffic
  egress {
    protocol    = "-1"
    from_port   = 0
    to_port     = 0
    cidr_blocks = ["0.0.0.0/0"]
    description = "Allow all outbound traffic"
  }
  
  tags = merge(local.common_tags, {
    Name = "${var.app_name}-ecs-sg"
  })
}

# RDS Security Group - controls traffic to/from the database
resource "aws_security_group" "rds" {
  name        = "${var.app_name}-rds-sg"
  description = "Controls access to RDS"
  vpc_id      = data.aws_vpc.existing.id
  
  # Allow MySQL traffic from ECS services only
  ingress {
    protocol        = "tcp"
    from_port       = 3306
    to_port         = 3306
    security_groups = [aws_security_group.ecs.id]
    description     = "Allow MySQL from ECS services"
  }
  
  # No direct outbound access needed for database
  
  tags = merge(local.common_tags, {
    Name = "${var.app_name}-rds-sg"
  })
}

# EFS Security Group - controls traffic to/from shared storage
resource "aws_security_group" "efs" {
  name        = "${var.app_name}-efs-sg"
  description = "Controls access to EFS"
  vpc_id      = data.aws_vpc.existing.id
  
  # Allow NFS traffic from ECS services only
  ingress {
    protocol        = "tcp"
    from_port       = 2049  # NFS port
    to_port         = 2049
    security_groups = [aws_security_group.ecs.id]
    description     = "Allow NFS from ECS services"
  }
  
  tags = merge(local.common_tags, {
    Name = "${var.app_name}-efs-sg"
  })
}
```

## Scripts

```
scripts/
├── build-push.sh        # Script to build and push Docker images to ECR
├── deploy-menu.sh       # Interactive menu for deployment operations
└── deploy.sh            # Script to deploy updates
``` 