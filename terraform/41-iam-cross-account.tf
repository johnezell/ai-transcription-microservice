# TFS AI Infrastructure - Cross-Account IAM Roles
# Provides IAM roles for cross-account access to shared resources

# =============================================================================
# ROUTE53 CROSS-ACCOUNT ROLE
# =============================================================================

# This role allows the AI services account to manage Route53 records
# in the shared services account
resource "aws_iam_role" "route53_cross_account" {
  count = var.route53_zone_account_id != "" && var.route53_zone_account_id != var.aws_account_id ? 1 : 0

  name = "${var.project_prefix}-route53-cross-account"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Principal = {
          AWS = "arn:aws:iam::${var.route53_zone_account_id}:root"
        }
        Action = "sts:AssumeRole"
        Condition = {
          StringEquals = {
            "sts:ExternalId" = var.cross_account_external_id
          }
        }
      }
    ]
  })

  tags = merge(var.common_tags, {
    Name         = "${var.project_prefix}-route53-cross-account"
    Purpose      = "Cross-account Route53 access"
    TrustAccount = var.route53_zone_account_id
  })
}

# Policy for Route53 operations
resource "aws_iam_role_policy" "route53_operations" {
  count = var.route53_zone_account_id != "" && var.route53_zone_account_id != var.aws_account_id ? 1 : 0

  name = "route53-operations"
  role = aws_iam_role.route53_cross_account[0].id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "route53:GetHostedZone",
          "route53:ListResourceRecordSets",
          "route53:ChangeResourceRecordSets",
          "route53:GetChange"
        ]
        Resource = [
          "arn:aws:route53:::hostedzone/${var.route53_zone_id}",
          "arn:aws:route53:::change/*"
        ]
      },
      {
        Effect = "Allow"
        Action = [
          "route53:ListHostedZones",
          "route53:ListHostedZonesByName"
        ]
        Resource = "*"
      }
    ]
  })
}

# =============================================================================
# ECR CROSS-ACCOUNT PULL ROLE
# =============================================================================

# This role allows services in the AI account to pull images from 
# ECR repositories in the shared services account
resource "aws_iam_role" "ecr_cross_account_pull" {
  count = var.shared_services_account_id != "" && var.shared_services_account_id != var.aws_account_id ? 1 : 0

  name = "${var.project_prefix}-ecr-cross-account-pull"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Principal = {
          Service = "ecs-tasks.amazonaws.com"
        }
        Action = "sts:AssumeRole"
      },
      {
        Effect = "Allow"
        Principal = {
          AWS = [
            aws_iam_role.ecs_task_execution.arn
          ]
        }
        Action = "sts:AssumeRole"
      }
    ]
  })

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-ecr-cross-account-pull"
    Purpose = "Cross-account ECR pull access"
  })
}

# Policy for ECR pull operations
resource "aws_iam_role_policy" "ecr_pull_operations" {
  count = var.shared_services_account_id != "" && var.shared_services_account_id != var.aws_account_id ? 1 : 0

  name = "ecr-pull-operations"
  role = aws_iam_role.ecr_cross_account_pull[0].id

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
      }
    ]
  })
}

# =============================================================================
# SHARED SERVICES ASSUME ROLE POLICY
# =============================================================================

# This policy allows ECS tasks to assume a role in the shared services account
# for accessing shared resources like Secrets Manager
resource "aws_iam_role_policy" "assume_shared_services_role" {
  count = var.shared_services_role_arn != "" ? 1 : 0

  name = "assume-shared-services-role"
  role = aws_iam_role.ecs_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect   = "Allow"
        Action   = "sts:AssumeRole"
        Resource = var.shared_services_role_arn
      }
    ]
  })
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "route53_cross_account_role_arn" {
  description = "ARN of the Route53 cross-account role"
  value       = var.route53_zone_account_id != "" && var.route53_zone_account_id != var.aws_account_id ? aws_iam_role.route53_cross_account[0].arn : ""
}

output "ecr_cross_account_pull_role_arn" {
  description = "ARN of the ECR cross-account pull role"
  value       = var.shared_services_account_id != "" && var.shared_services_account_id != var.aws_account_id ? aws_iam_role.ecr_cross_account_pull[0].arn : ""
}