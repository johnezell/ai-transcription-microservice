# TFS AI Infrastructure - Backend Configuration
# Stores Terraform state in S3 with DynamoDB locking

terraform {
  required_version = ">= 1.7.0"

  backend "s3" {
    # These values should be provided via backend config file or CLI flags
    # terraform init -backend-config=backend-staging.hcl

    # Example backend-staging.hcl:
    # bucket         = "tfs-ai-terraform-state-087439708020"
    # key            = "staging/terraform.tfstate"
    # region         = "us-east-1"
    # dynamodb_table = "tfs-ai-terraform-locks"
    # encrypt        = true
  }

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }
}