# TFS AI Infrastructure - Staging Environment Variables

# General settings
aws_region     = "us-east-1"
aws_account_id = "087439708020"
environment    = "staging"
project_prefix = "tfs-ai"

# Common tags
common_tags = {
  Environment = "staging"
  Project     = "tfs-ai-thoth"
  ManagedBy   = "terraform"
  Owner       = "infrastructure-team"
  CostCenter  = "ai-services-staging"
}

# Laravel task resources (increased for better performance)
laravel_task_cpu    = "1024"  # 1 vCPU
laravel_task_memory = "2048"  # 2GB RAM

# Networking - Using new VPC
vpc_id             = "" # Empty to create new VPC
vpc_cidr           = "10.25.0.0/16"
availability_zones = ["us-east-1a", "us-east-1b", "us-east-1c"]

# Bastion configuration - sized for development workloads
bastion_instance_type = "t3.xlarge" # 4 vCPU, 16GB RAM for Docker + IDE
bastion_volume_size   = 150         # Room for Docker images, models, code
bastion_public_key    = "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACAQCiXLWcWBwMDjeSLxs3/wg94ZYgrm8s9C+j4eJUdGkdGKaBFtCnO2c3NDx1Wu0CHS9I8fOYevlj8OY/AOoFFfPiuUEIiXgYipGa5BmzExAwm8Ncg81lljqzdvzHko5GO2FcLWyJulYneXdtM55ovUU7KQjGisTQgs0h4fmdEZKAHgWHLQERsTJ9qZFsckH9zTNapFHJVreDM5WWpg2sLU1ZyNg0j6CJ+DmEdBvyNT/KCKS8rUztcrBAfuAUWXaMPGokUYA2D+2PLWQvKXu+5d4WZtLH4SP/lOhWEJulLA7UJcyFVuFy9mQVR5JrabedC+eNIG70inUKaM+Vz20JKCxPYPBP4wLXc1CJj96pI8EukHb/kzuppLDXNO+RiJfIk0lBE3AOuZcKLS7UD7NrE/SPgK34p2RjL0GgqF7QajpUYln/X66CdXI3HUisb4RFGZe+7v4Gi2q/tCs/Pot42dVIiLcswgUN+B3/YoguychwCGhkATWmJVrkHC8LB8mq4hg/UHs2dng92W5E3JKlCZylNQYM9o+niSGWLsIJetGMX594ZnxZYLKvsaMxRRLdjC6MvFv+oK9bY51CbXGN0zxu+R1ichK7W0LTxJfaIiVrUMxDzYwPNHkm6TmoXg+TSTVBQuNuozKO4fBU5JjEOzir4f64LKTsFaPYxexSTuURZQ== bastion-staging@thoth.tfs.services"

# Allow SSH from these CIDR blocks (locked to specific IPs for security)
bastion_allowed_cidrs = [
  "107.146.127.17/32", # John's office IP
  "71.28.3.179/32"     # John's home IP
]

# Additional SSH keys to inject into bastion instances
additional_ssh_keys = [
  "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACAQDLj3/8WMcfEwh6aJLkJj1gTm2b5j1V/izuWKxmCqs+e7W71WaNCd9Ays2dCLwnpBHkrmCqcEoafUw9ScuQ1AoosPsCqJAmBfjiWhTIuchCDQbD2T2e4JJq4t5djjWIOdKhHvkDVM6KOc6ZkyUcfaE2VL8AziLYphoQ9cjPQglg65udJAmekfoJMbcIL1NP/XmMyyeJiYLNKYsF3CuaOUB7M81pSkogLD6QsudrnGyIThqWu3iewlPVUh7qJlJk+s9jmZxJrgMTI2GJ50ts2cccwFlCFhTIJfXkFbjPV2WPU1cH4jVY3UWgdNU3/rKaNrEAR6dOGlmy+Lb6h6Pvo6VUY3gVkhd2tWO7MRdZqXHIekPJcat+Fh0wdM5Yn5K76A7mg+U2gLzVGUDg+HjxkUljdjnE6E2Q4IFqiwOVTatifrEe/5nYPPeP/5HYW+u0lsMZJIYJC4UpiEDpPerE0wRSlVHE91GdjnU2I0b003t0X+iz4MtcDEaNvTLWkJ8kpR91kluDTfPXRI5NlHKjLaRbjJffxrFtICXNb2w3k3oUK4MvxVhE2KNbVC+sqsZV4FVypDElCtB8Usu2CwCb7YQe58+iLUdkuBduApG0N0NS/vThRNgazCsvTrVI/SkHHTK0Ewpq3frr5/THMMosvWhLnbXnr76VHTzl2MCu7NaMiw== john@MacBook-Pro-7.local"
]

# Bastion ASG configuration
bastion_asg_min_size         = 1
bastion_asg_max_size         = 1 # Single instance for dev - no need for HA
bastion_asg_desired_capacity = 1

# Enable static IP for consistent SSH access
create_bastion_static_ip = true

# Route53 and domain configuration
route53_zone_id         = "Z07716653GDXJUDL4P879"
route53_zone_account_id = "542876199144"
domain_name             = "app.thoth.tfs.services"  # Uses delegated zone thoth.tfs.services

# Cross-account configuration
shared_services_account_id = "542876199144"
cross_account_external_id  = "tfs-ai-staging-external-id"
shared_services_role_arn   = "" # To be created in shared account if needed

# VPC Peering configuration (optional - set enable_vpc_peering to true when ready)
enable_vpc_peering = false
peer_vpc_id        = "" # Add shared services VPC ID when ready
peer_vpc_cidr      = "" # Add shared services VPC CIDR when ready
peer_account_id    = "542876199144"
peer_region        = "us-east-1"

# ECS configuration (for future use)
ecs_cluster_name           = "tfs-ai-staging-cluster"
container_insights_enabled = true

# GPU configuration (for future use)
gpu_instance_type     = "g4dn.xlarge"
gpu_min_instances     = 0
gpu_max_instances     = 3
gpu_desired_instances = 0  # Set to 0 for now - will scale up when needed
spot_enabled          = true
spot_max_price        = "0.5"

# Database configuration (for future use)
db_engine         = "aurora-mysql"
db_engine_version = "8.0.mysql_aurora.3.04.0"
db_instance_class = "db.t3.medium"  # t3.small not supported for Aurora MySQL 8.0
db_instance_count = 1               # Single instance for staging
db_name           = "ai_transcription_staging"
db_username       = "admin"

# Storage configuration (for future use)
efs_performance_mode = "generalPurpose"
efs_throughput_mode  = "bursting"
s3_bucket_prefix     = "tfs-ai-transcription-staging"

# Monitoring configuration
cloudwatch_retention_days  = 7
enable_detailed_monitoring = true
alarm_email                = "staging-alerts@truefirsstudios.com"

# Cost management
budget_amount          = 500
budget_alert_threshold = 80

# Feature flags
enable_vpc_flow_logs = true
create_alb           = true  # ALB enabled for public access
# ECS Task Definitions
image_tag          = "latest"
whisper_model_size = "base" # Use "small" or "medium" for better accuracy
