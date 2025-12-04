# TFS AI Infrastructure - ECS Cluster Configuration
# Provides container orchestration for AI transcription services

# =============================================================================
# ECS CLUSTER
# =============================================================================

resource "aws_ecs_cluster" "ai_cluster" {
  name = var.ecs_cluster_name

  # Enable Container Insights for monitoring
  setting {
    name  = "containerInsights"
    value = var.container_insights_enabled ? "enabled" : "disabled"
  }

  # Configuration for execute command (ECS Exec)
  configuration {
    execute_command_configuration {
      logging = "OVERRIDE"

      log_configuration {
        cloud_watch_encryption_enabled = true
        cloud_watch_log_group_name     = aws_cloudwatch_log_group.ecs_exec.name
      }
    }
  }

  tags = merge(var.common_tags, {
    Name = var.ecs_cluster_name
  })
}

# =============================================================================
# CLOUDWATCH LOG GROUPS
# =============================================================================

# Log group for ECS Exec commands
resource "aws_cloudwatch_log_group" "ecs_exec" {
  name              = "/aws/ecs/exec/${var.ecs_cluster_name}"
  retention_in_days = var.cloudwatch_retention_days

  tags = var.common_tags
}

# Log group for container logs
resource "aws_cloudwatch_log_group" "ecs_containers" {
  name              = "/aws/ecs/${var.ecs_cluster_name}"
  retention_in_days = var.cloudwatch_retention_days

  tags = var.common_tags
}

# =============================================================================
# CAPACITY PROVIDERS
# =============================================================================

# Default capacity provider for non-GPU workloads (Fargate)
resource "aws_ecs_cluster_capacity_providers" "main" {
  cluster_name = aws_ecs_cluster.ai_cluster.name

  capacity_providers = [
    "FARGATE",
    "FARGATE_SPOT",
    aws_ecs_capacity_provider.gpu.name
  ]

  # Default to Fargate for services that don't need GPU
  default_capacity_provider_strategy {
    base              = 0
    weight            = 100
    capacity_provider = "FARGATE"
  }
}

# =============================================================================
# SERVICE DISCOVERY NAMESPACE
# =============================================================================

# Private DNS namespace for internal service discovery
resource "aws_service_discovery_private_dns_namespace" "main" {
  name        = "${var.environment}.${var.project_prefix}.local"
  vpc         = local.vpc_id
  description = "Private DNS namespace for ${var.project_prefix} service discovery"

  tags = var.common_tags
}

# Service discovery for inter-service communication
resource "aws_service_discovery_service" "ecs_services" {
  for_each = toset([
    "laravel-api",
    "audio-extraction",
    "transcription",
    "music-term-recognition"
  ])

  name = each.key

  dns_config {
    namespace_id = aws_service_discovery_private_dns_namespace.main.id

    dns_records {
      ttl  = 10
      type = "A"
    }

    routing_policy = "MULTIVALUE"
  }

  health_check_custom_config {
    failure_threshold = 1
  }

  tags = var.common_tags
}

# =============================================================================
# IAM ROLES
# =============================================================================

# ECS Task Execution Role (used by ECS to pull images and write logs)
resource "aws_iam_role" "ecs_task_execution" {
  name = "${var.project_prefix}-ecs-execution-role-${var.environment}"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "ecs-tasks.amazonaws.com"
        }
      }
    ]
  })

  tags = var.common_tags
}

# Attach AWS managed policy for ECS task execution
resource "aws_iam_role_policy_attachment" "ecs_task_execution" {
  role       = aws_iam_role.ecs_task_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

# Additional policy for cross-account ECR access
resource "aws_iam_role_policy" "ecs_cross_account_ecr" {
  name = "cross-account-ecr-access"
  role = aws_iam_role.ecs_task_execution.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "ecr:GetAuthorizationToken",
          "ecr:BatchCheckLayerAvailability",
          "ecr:GetDownloadUrlForLayer",
          "ecr:BatchGetImage"
        ]
        Resource = "*"
      },
      {
        Effect = "Allow"
        Action = [
          "ecr:GetAuthorizationToken"
        ]
        Resource = "*"
        Condition = {
          StringEquals = {
            "aws:SourceAccount" : var.route53_zone_account_id
          }
        }
      }
    ]
  })
}

# ECS Task Role (used by containers to access AWS services)
resource "aws_iam_role" "ecs_task" {
  name = "${var.project_prefix}-ecs-task-role-${var.environment}"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "ecs-tasks.amazonaws.com"
        }
      }
    ]
  })

  tags = var.common_tags
}

# Policy for S3 access
resource "aws_iam_role_policy" "ecs_s3_access" {
  name = "s3-access"
  role = aws_iam_role.ecs_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "s3:GetObject",
          "s3:PutObject",
          "s3:DeleteObject",
          "s3:ListBucket"
        ]
        Resource = [
          "arn:aws:s3:::${var.s3_bucket_prefix}-*/*",
          "arn:aws:s3:::${var.s3_bucket_prefix}-*"
        ]
      }
    ]
  })
}

# Policy for ECS Exec
resource "aws_iam_role_policy" "ecs_exec" {
  name = "ecs-exec-policy"
  role = aws_iam_role.ecs_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "ssmmessages:CreateControlChannel",
          "ssmmessages:CreateDataChannel",
          "ssmmessages:OpenControlChannel",
          "ssmmessages:OpenDataChannel"
        ]
        Resource = "*"
      },
      {
        Effect = "Allow"
        Action = [
          "logs:CreateLogStream",
          "logs:DescribeLogStreams",
          "logs:PutLogEvents"
        ]
        Resource = "${aws_cloudwatch_log_group.ecs_exec.arn}:*"
      }
    ]
  })
}

# Additional policy for task execution role to access Secrets Manager
resource "aws_iam_role_policy" "ecs_secrets" {
  name = "ecs-secrets-policy"
  role = aws_iam_role.ecs_task_execution.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "secretsmanager:GetSecretValue"
        ]
        Resource = [
          "arn:aws:secretsmanager:${var.aws_region}:${var.aws_account_id}:secret:${var.project_prefix}-*"
        ]
      }
    ]
  })
}

# Policy for EFS access
resource "aws_iam_role_policy" "ecs_efs" {
  name = "ecs-efs-policy"
  role = aws_iam_role.ecs_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "elasticfilesystem:ClientMount",
          "elasticfilesystem:ClientWrite",
          "elasticfilesystem:ClientRootAccess"
        ]
        Resource = "*"
      }
    ]
  })
}

# Policy for CloudWatch metrics
resource "aws_iam_role_policy" "ecs_cloudwatch" {
  name = "cloudwatch-metrics"
  role = aws_iam_role.ecs_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "cloudwatch:PutMetricData"
        ]
        Resource = "*"
        Condition = {
          StringEquals = {
            "cloudwatch:namespace" : "${var.project_prefix}/AI"
          }
        }
      }
    ]
  })
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "ecs_cluster_id" {
  description = "ID of the ECS cluster"
  value       = aws_ecs_cluster.ai_cluster.id
}

output "ecs_cluster_arn" {
  description = "ARN of the ECS cluster"
  value       = aws_ecs_cluster.ai_cluster.arn
}

output "ecs_cluster_name" {
  description = "Name of the ECS cluster"
  value       = aws_ecs_cluster.ai_cluster.name
}

output "ecs_task_execution_role_arn" {
  description = "ARN of the ECS task execution role"
  value       = aws_iam_role.ecs_task_execution.arn
}

output "ecs_task_role_arn" {
  description = "ARN of the ECS task role"
  value       = aws_iam_role.ecs_task.arn
}

output "ecs_service_discovery_services" {
  description = "Map of service discovery service ARNs"
  value       = { for k, v in aws_service_discovery_service.ecs_services : k => v.arn }
}

output "service_discovery_namespace_id" {
  description = "ID of the service discovery namespace"
  value       = aws_service_discovery_private_dns_namespace.main.id
}

output "service_discovery_namespace_name" {
  description = "Name of the service discovery namespace"
  value       = aws_service_discovery_private_dns_namespace.main.name
}