# TFS AI Infrastructure - Variable Definitions

# =============================================================================
# GENERAL VARIABLES
# =============================================================================

variable "aws_region" {
  description = "AWS region for resources"
  type        = string
  default     = "us-east-1"
}

variable "aws_account_id" {
  description = "AWS account ID"
  type        = string
}

variable "environment" {
  description = "Environment name (staging, production)"
  type        = string
  validation {
    condition     = contains(["staging", "production", "dev"], var.environment)
    error_message = "Environment must be staging, production, or dev"
  }
}

variable "project_prefix" {
  description = "Prefix for all resources"
  type        = string
  default     = "tfs-ai"
}

variable "common_tags" {
  description = "Common tags for all resources"
  type        = map(string)
  default     = {}
}

# =============================================================================
# NETWORKING VARIABLES
# =============================================================================

variable "vpc_id" {
  description = "Existing VPC ID (leave empty to create new)"
  type        = string
  default     = ""
}

variable "vpc_cidr" {
  description = "CIDR block for new VPC"
  type        = string
  default     = "10.25.0.0/16"
}

variable "availability_zones" {
  description = "Availability zones for deployment"
  type        = list(string)
  default     = ["us-east-1a", "us-east-1b", "us-east-1c"]
}

variable "private_subnet_ids" {
  description = "Existing private subnet IDs (leave empty to create new)"
  type        = list(string)
  default     = []
}

variable "public_subnet_ids" {
  description = "Existing public subnet IDs (leave empty to create new)"
  type        = list(string)
  default     = []
}

# =============================================================================
# BASTION VARIABLES
# =============================================================================

variable "bastion_instance_type" {
  description = "Instance type for bastion host"
  type        = string
  default     = "t3.micro"
}

variable "bastion_volume_size" {
  description = "Root volume size in GB for bastion host"
  type        = number
  default     = 30
}

variable "bastion_public_key" {
  description = "Public SSH key for bastion access"
  type        = string
}

variable "bastion_allowed_cidrs" {
  description = "CIDR blocks allowed to SSH to bastion"
  type        = list(string)
  default     = []
}

variable "create_bastion_nlb" {
  description = "Create Network Load Balancer for bastion (provides static IP)"
  type        = bool
  default     = false
}

variable "create_bastion_static_ip" {
  description = "Create static Elastic IP for bastion"
  type        = bool
  default     = false
}

variable "additional_ssh_keys" {
  description = "Additional SSH public keys to add to bastion"
  type        = list(string)
  default     = []
}

# =============================================================================
# GPU INSTANCE VARIABLES
# =============================================================================

variable "gpu_instance_type" {
  description = "GPU instance type for transcription"
  type        = string
  default     = "g4dn.xlarge"
}

variable "gpu_min_instances" {
  description = "Minimum number of GPU instances"
  type        = number
  default     = 0
}

variable "gpu_max_instances" {
  description = "Maximum number of GPU instances"
  type        = number
  default     = 5
}

variable "gpu_desired_instances" {
  description = "Desired number of GPU instances"
  type        = number
  default     = 1
}

variable "spot_enabled" {
  description = "Enable spot instances for GPU nodes"
  type        = bool
  default     = true
}

variable "spot_max_price" {
  description = "Maximum spot price as percentage of on-demand"
  type        = string
  default     = "0.5"
}

# =============================================================================
# ECS VARIABLES
# =============================================================================

variable "ecs_cluster_name" {
  description = "Name of the ECS cluster"
  type        = string
}

variable "container_insights_enabled" {
  description = "Enable Container Insights for ECS"
  type        = bool
  default     = true
}

# =============================================================================
# DATABASE VARIABLES
# =============================================================================

variable "db_engine" {
  description = "Database engine"
  type        = string
  default     = "aurora-mysql"
}

variable "db_engine_version" {
  description = "Database engine version"
  type        = string
  default     = "8.0.mysql_aurora.3.04.0"
}

variable "db_instance_class" {
  description = "Database instance class"
  type        = string
  default     = "db.t3.medium"
}

variable "db_name" {
  description = "Database name"
  type        = string
  default     = "ai_transcription"
}

variable "db_username" {
  description = "Database username"
  type        = string
  default     = "admin"
}

variable "db_instance_count" {
  description = "Number of database instances in the cluster"
  type        = number
  default     = 2
}

variable "db_backup_retention_days" {
  description = "Database backup retention in days"
  type        = number
  default     = 7
}

variable "db_kms_key_id" {
  description = "KMS key ID for database encryption (optional)"
  type        = string
  default     = ""
}

variable "database_subnet_ids" {
  description = "Existing database subnet IDs (leave empty to create new)"
  type        = list(string)
  default     = []
}

# =============================================================================
# STORAGE VARIABLES
# =============================================================================

variable "efs_performance_mode" {
  description = "EFS performance mode"
  type        = string
  default     = "generalPurpose"
}

variable "efs_throughput_mode" {
  description = "EFS throughput mode"
  type        = string
  default     = "bursting"
}

variable "s3_bucket_prefix" {
  description = "Prefix for S3 buckets"
  type        = string
}

# =============================================================================
# MONITORING VARIABLES
# =============================================================================

variable "cloudwatch_retention_days" {
  description = "CloudWatch log retention in days"
  type        = number
  default     = 30
}

variable "enable_detailed_monitoring" {
  description = "Enable detailed monitoring"
  type        = bool
  default     = true
}

variable "alarm_email" {
  description = "Email for CloudWatch alarms"
  type        = string
}

# =============================================================================
# COST MANAGEMENT VARIABLES
# =============================================================================

variable "budget_amount" {
  description = "Monthly budget amount in USD"
  type        = number
  default     = 1000
}

variable "budget_alert_threshold" {
  description = "Budget alert threshold percentage"
  type        = number
  default     = 80
}

# =============================================================================
# CROSS-ACCOUNT VARIABLES
# =============================================================================

variable "shared_services_account_id" {
  description = "AWS account ID for shared services (for cross-account access)"
  type        = string
  default     = ""
}

variable "route53_zone_account_id" {
  description = "AWS account ID where Route53 hosted zone exists"
  type        = string
  default     = ""
}

variable "route53_zone_id" {
  description = "Route53 hosted zone ID"
  type        = string
  default     = ""
}

variable "cross_account_external_id" {
  description = "External ID for cross-account assume role"
  type        = string
  default     = ""
}

variable "shared_services_role_arn" {
  description = "ARN of role in shared services account to assume"
  type        = string
  default     = ""
}

# =============================================================================
# FEATURE FLAGS
# =============================================================================

variable "enable_vpc_flow_logs" {
  description = "Enable VPC flow logs"
  type        = bool
  default     = true
}

variable "create_alb" {
  description = "Create Application Load Balancer"
  type        = bool
  default     = false # Will be true when we create the ALB
}

# =============================================================================
# ECS TASK DEFINITIONS
# =============================================================================

variable "laravel_task_cpu" {
  description = "CPU units for Laravel task (1024 = 1 vCPU)"
  type        = string
  default     = "512"
}

variable "laravel_task_memory" {
  description = "Memory for Laravel task in MB"
  type        = string
  default     = "1024"
}

variable "audio_task_cpu" {
  description = "CPU units for audio extraction task"
  type        = string
  default     = "256"
}

variable "audio_task_memory" {
  description = "Memory for audio extraction task in MB"
  type        = string
  default     = "512"
}

variable "transcription_task_cpu" {
  description = "CPU units for transcription task"
  type        = string
  default     = "4096"
}

variable "transcription_task_memory" {
  description = "Memory for transcription task in MB"
  type        = string
  default     = "8192"
}

variable "music_task_cpu" {
  description = "CPU units for music term recognition task"
  type        = string
  default     = "512"
}

variable "music_task_memory" {
  description = "Memory for music term recognition task in MB"
  type        = string
  default     = "1024"
}

variable "image_tag" {
  description = "Docker image tag to deploy"
  type        = string
  default     = "latest"
}

variable "whisper_model_size" {
  description = "Whisper model size to use"
  type        = string
  default     = "base"
}

# =============================================================================
# ECS SERVICE CONFIGURATION
# =============================================================================

# Laravel service scaling
variable "laravel_desired_count" {
  description = "Desired number of Laravel tasks"
  type        = number
  default     = 1
}

variable "laravel_min_count" {
  description = "Minimum number of Laravel tasks"
  type        = number
  default     = 1
}

variable "laravel_max_count" {
  description = "Maximum number of Laravel tasks"
  type        = number
  default     = 3
}

# Audio extraction service scaling
variable "audio_desired_count" {
  description = "Desired number of audio extraction tasks"
  type        = number
  default     = 1
}

variable "audio_min_count" {
  description = "Minimum number of audio extraction tasks"
  type        = number
  default     = 1
}

variable "audio_max_count" {
  description = "Maximum number of audio extraction tasks"
  type        = number
  default     = 3
}

# Transcription service scaling
variable "transcription_desired_count" {
  description = "Desired number of transcription tasks"
  type        = number
  default     = 1
}

# Music term recognition service scaling
variable "music_desired_count" {
  description = "Desired number of music term recognition tasks"
  type        = number
  default     = 1
}