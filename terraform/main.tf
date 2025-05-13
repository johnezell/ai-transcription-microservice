# AWS Transcription Service - Terraform Configuration
# Main provider and backend configuration

terraform {
  # Requiring specific version of AWS provider ensures compatibility
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
  }
  
  # Backend configuration for storing Terraform state in S3
  # Uncomment and configure after S3 bucket is created
  /*
  backend "s3" {
    bucket  = "aws-transcription-terraform-state"  # Change to your bucket name
    key     = "aws-transcription-service/terraform.tfstate"
    region  = "us-east-1"
    profile = "tfs-shared-services"
    # Optional: Enable state locking with DynamoDB
    # dynamodb_table = "terraform-state-lock"
  }
  */
}

# Configure the AWS Provider with existing profile
provider "aws" {
  region  = "us-east-1"
  profile = "tfs-shared-services"
  
  # Default tags that apply to all resources
  default_tags {
    tags = {
      Project     = "AWS-Transcription-Service"
      Environment = terraform.workspace
      ManagedBy   = "Terraform"
    }
  }
}

# Reference existing VPC and subnets
data "aws_vpc" "existing" {
  id = "vpc-09422297ced61f9d2"
}

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

# Get current AWS account ID
data "aws_caller_identity" "current" {}

# Define common local values
locals {
  app_name    = var.app_name
  environment = terraform.workspace
  common_tags = {
    Application = local.app_name
    Environment = local.environment
  }
} 