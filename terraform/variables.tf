variable "app_name" {
  description = "Name of the application"
  type        = string
  default     = "aws-transcription"
}

variable "aws_region" {
  description = "AWS region to deploy resources"
  type        = string
  default     = "us-east-1"
}

variable "vpc_id" {
  description = "ID of the existing VPC"
  type        = string
  default     = "vpc-09422297ced61f9d2"
}

variable "public_subnet_ids" {
  description = "IDs of public subnets in the VPC"
  type        = list(string)
  default     = ["subnet-0460f66368d31fd0d", "subnet-02355996f055ea5ac"]
}

variable "private_subnet_ids" {
  description = "IDs of private subnets in the VPC"
  type        = list(string)
  default     = ["subnet-096caf8b193f1d108", "subnet-0afef54f7c422ecab"]
}

variable "deploy_services" {
  description = "Whether to deploy ECS services or just infrastructure"
  type        = bool
  default     = false
}

variable "rds_instance_class" {
  description = "Instance class for RDS database"
  type        = string
  default     = "db.t3.small"
}

variable "rds_allocated_storage" {
  description = "Allocated storage for RDS in GB"
  type        = number
  default     = 20
}

variable "rds_engine_version" {
  description = "MySQL engine version for RDS"
  type        = string
  default     = "8.0"
}

variable "rds_database_name" {
  description = "Name of the database to create in RDS"
  type        = string
  default     = "aws_transcription"
}

variable "rds_username" {
  description = "Username for RDS database"
  type        = string
  default     = "admin"
}

variable "ecs_task_cpu" {
  description = "CPU units for ECS tasks (1024 = 1 vCPU)"
  type = map(number)
  default = {
    laravel         = 1024
    audio           = 512
    transcription   = 512
    music           = 512
  }
}

variable "ecs_task_memory" {
  description = "Memory for ECS tasks in MB"
  type = map(number)
  default = {
    laravel         = 2048
    audio           = 1024
    transcription   = 1024
    music           = 1024
  }
}

variable "ecs_desired_count" {
  description = "Desired count of ECS tasks per service"
  type = map(number)
  default = {
    laravel         = 2
    audio           = 1
    transcription   = 1
    music           = 1
  }
}

variable "ecr_image_tag_mutability" {
  description = "Image tag mutability setting for ECR repositories"
  type        = string
  default     = "MUTABLE"
}

variable "ecr_scan_on_push" {
  description = "Scan ECR images for vulnerabilities on push"
  type        = bool
  default     = true
}

variable "log_retention_days" {
  description = "Number of days to keep CloudWatch logs"
  type        = number
  default     = 30
}

variable "alb_deletion_protection" {
  description = "Enable deletion protection for ALB"
  type        = bool
  default     = false
}

variable "environment_variables" {
  description = "Environment variables for container definitions"
  type        = map(map(string))
  default     = {
    laravel = {
      APP_ENV = "production"
    }
    audio = {
      LOG_LEVEL = "info"
    }
    transcription = {
      LOG_LEVEL = "info"
    }
    music = {
      LOG_LEVEL = "info"
    }
  }
} 