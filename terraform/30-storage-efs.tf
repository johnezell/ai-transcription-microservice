# TFS AI Infrastructure - EFS Storage Configuration
# Provides shared storage for models and job files

# =============================================================================
# EFS FILE SYSTEM
# =============================================================================

resource "aws_efs_file_system" "shared_storage" {
  creation_token = "${var.project_prefix}-shared-storage"

  # Performance settings
  performance_mode = var.efs_performance_mode
  throughput_mode  = var.efs_throughput_mode

  # Encryption at rest
  encrypted = true

  # Lifecycle policy to move infrequent files to IA storage class
  lifecycle_policy {
    transition_to_ia = "AFTER_30_DAYS"
  }

  # Enable automatic backups
  lifecycle_policy {
    transition_to_primary_storage_class = "AFTER_1_ACCESS"
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-shared-storage"
    Type = "efs"
  })
}

# =============================================================================
# EFS MOUNT TARGETS
# =============================================================================

resource "aws_efs_mount_target" "main" {
  count           = length(var.private_subnet_ids) > 0 ? length(var.private_subnet_ids) : length(aws_subnet.private)
  file_system_id  = aws_efs_file_system.shared_storage.id
  subnet_id       = length(var.private_subnet_ids) > 0 ? var.private_subnet_ids[count.index] : aws_subnet.private[count.index].id
  security_groups = [aws_security_group.efs.id]
}

# =============================================================================
# EFS ACCESS POINTS
# =============================================================================

# Access point for model storage
resource "aws_efs_access_point" "models" {
  file_system_id = aws_efs_file_system.shared_storage.id

  # POSIX user
  posix_user {
    gid = 1000
    uid = 1000
  }

  # Root directory
  root_directory {
    path = "/models"
    creation_info {
      owner_gid   = 1000
      owner_uid   = 1000
      permissions = "755"
    }
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-models-ap"
    Type = "models"
  })
}

# Access point for job files
resource "aws_efs_access_point" "jobs" {
  file_system_id = aws_efs_file_system.shared_storage.id

  posix_user {
    gid = 1000
    uid = 1000
  }

  root_directory {
    path = "/jobs"
    creation_info {
      owner_gid   = 1000
      owner_uid   = 1000
      permissions = "755"
    }
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-jobs-ap"
    Type = "jobs"
  })
}

# Access point for temporary files
resource "aws_efs_access_point" "temp" {
  file_system_id = aws_efs_file_system.shared_storage.id

  posix_user {
    gid = 1000
    uid = 1000
  }

  root_directory {
    path = "/temp"
    creation_info {
      owner_gid   = 1000
      owner_uid   = 1000
      permissions = "777"
    }
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-temp-ap"
    Type = "temp"
  })
}

# Access point for shared storage (Laravel and other services)
resource "aws_efs_access_point" "shared" {
  file_system_id = aws_efs_file_system.shared_storage.id

  posix_user {
    gid = 33  # www-data group
    uid = 33  # www-data user
  }

  root_directory {
    path = "/shared"
    creation_info {
      owner_gid   = 33
      owner_uid   = 33
      permissions = "755"
    }
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-shared-ap"
    Type = "shared"
  })
}

# =============================================================================
# SECURITY GROUP FOR EFS
# =============================================================================

resource "aws_security_group" "efs" {
  name        = "${var.project_prefix}-efs-sg-${var.environment}"
  description = "Security group for EFS mount targets"
  vpc_id      = local.vpc_id

  # NFS from VPC
  ingress {
    description = "NFS from VPC"
    from_port   = 2049
    to_port     = 2049
    protocol    = "tcp"
    cidr_blocks = [var.vpc_cidr]
  }

  # Allow from ECS GPU tasks
  ingress {
    description     = "NFS from ECS GPU tasks"
    from_port       = 2049
    to_port         = 2049
    protocol        = "tcp"
    security_groups = [aws_security_group.ecs_gpu.id]
  }

  # Allow from Laravel service (for shared storage)
  ingress {
    description     = "NFS from Laravel service"
    from_port       = 2049
    to_port         = 2049
    protocol        = "tcp"
    security_groups = var.create_alb ? [aws_security_group.laravel_service_alb[0].id] : []
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-efs-sg"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# EFS BACKUP CONFIGURATION
# =============================================================================

# Backup vault for EFS
resource "aws_backup_vault" "efs" {
  name = "${var.project_prefix}-efs-backup-vault"

  tags = var.common_tags
}

# Backup plan
resource "aws_backup_plan" "efs" {
  name = "${var.project_prefix}-efs-backup-plan"

  rule {
    rule_name         = "daily_backup"
    target_vault_name = aws_backup_vault.efs.name
    schedule          = "cron(0 3 * * ? *)" # 3 AM UTC daily

    lifecycle {
      delete_after = 30 # Keep for 30 days
    }

    recovery_point_tags = var.common_tags
  }

  rule {
    rule_name         = "weekly_backup"
    target_vault_name = aws_backup_vault.efs.name
    schedule          = "cron(0 4 ? * SUN *)" # 4 AM UTC on Sundays

    lifecycle {
      delete_after = 90 # Keep for 90 days
    }

    recovery_point_tags = var.common_tags
  }

  tags = var.common_tags
}

# Backup selection
resource "aws_backup_selection" "efs" {
  name         = "${var.project_prefix}-efs-backup-selection"
  plan_id      = aws_backup_plan.efs.id
  iam_role_arn = aws_iam_role.backup.arn

  resources = [
    aws_efs_file_system.shared_storage.arn
  ]
}

# IAM role for AWS Backup
resource "aws_iam_role" "backup" {
  name = "${var.project_prefix}-backup-role-${var.environment}"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "backup.amazonaws.com"
        }
      }
    ]
  })

  tags = var.common_tags
}

resource "aws_iam_role_policy_attachment" "backup" {
  role       = aws_iam_role.backup.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSBackupServiceRolePolicyForBackup"
}

# =============================================================================
# CLOUDWATCH ALARMS
# =============================================================================

# Alarm for high EFS burst credit balance
resource "aws_cloudwatch_metric_alarm" "efs_burst_credits" {
  count               = var.efs_throughput_mode == "bursting" ? 1 : 0
  alarm_name          = "${var.project_prefix}-efs-burst-credits-low"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = "2"
  metric_name         = "BurstCreditBalance"
  namespace           = "AWS/EFS"
  period              = "300"
  statistic           = "Average"
  threshold           = "1000000000000" # 1 TB of burst credits
  alarm_description   = "EFS burst credit balance is low"
  treat_missing_data  = "notBreaching"

  dimensions = {
    FileSystemId = aws_efs_file_system.shared_storage.id
  }

  alarm_actions = var.alarm_email != "" ? [aws_sns_topic.alerts[0].arn] : []
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "efs_id" {
  description = "ID of the EFS file system"
  value       = aws_efs_file_system.shared_storage.id
}

output "efs_dns_name" {
  description = "DNS name of the EFS file system"
  value       = aws_efs_file_system.shared_storage.dns_name
}

output "efs_access_point_models" {
  description = "Access point ID for models"
  value       = aws_efs_access_point.models.id
}

output "efs_access_point_jobs" {
  description = "Access point ID for jobs"
  value       = aws_efs_access_point.jobs.id
}

output "efs_access_point_temp" {
  description = "Access point ID for temporary files"
  value       = aws_efs_access_point.temp.id
}

output "efs_security_group_id" {
  description = "Security group ID for EFS"
  value       = aws_security_group.efs.id
}