# TFS AI Infrastructure - RDS Aurora Database
# Provides MySQL-compatible Aurora database for production use

# =============================================================================
# RDS SUBNET GROUP
# =============================================================================

resource "aws_db_subnet_group" "main" {
  name       = lower("${var.project_prefix}-db-subnet-group")
  subnet_ids = length(var.database_subnet_ids) > 0 ? var.database_subnet_ids : aws_subnet.database[*].id

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-db-subnet-group"
  })
}

# =============================================================================
# SECURITY GROUP FOR RDS
# =============================================================================

resource "aws_security_group" "rds" {
  name        = "${var.project_prefix}-rds-sg-${var.environment}"
  description = "Security group for RDS Aurora cluster"
  vpc_id      = local.vpc_id

  # MySQL/Aurora from ECS tasks
  ingress {
    description     = "MySQL from ECS tasks"
    from_port       = 3306
    to_port         = 3306
    protocol        = "tcp"
    security_groups = [aws_security_group.ecs_gpu.id]
  }

  # MySQL/Aurora from Laravel service
  ingress {
    description     = "MySQL from Laravel service"
    from_port       = 3306
    to_port         = 3306
    protocol        = "tcp"
    security_groups = [aws_security_group.laravel_service.id]
  }

  # MySQL/Aurora from Laravel service with ALB
  dynamic "ingress" {
    for_each = var.create_alb ? [1] : []
    content {
      description     = "MySQL from Laravel ALB service"
      from_port       = 3306
      to_port         = 3306
      protocol        = "tcp"
      security_groups = [aws_security_group.laravel_service_alb[0].id]
    }
  }

  # MySQL/Aurora from bastion
  ingress {
    description     = "MySQL from bastion"
    from_port       = 3306
    to_port         = 3306
    protocol        = "tcp"
    security_groups = [aws_security_group.bastion.id]
  }

  # Allow all outbound traffic
  egress {
    description = "All outbound traffic"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-rds-sg"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# RDS CLUSTER PARAMETER GROUP
# =============================================================================

resource "aws_rds_cluster_parameter_group" "main" {
  name        = "${lower(var.project_prefix)}-aurora-mysql-${replace(replace(var.db_engine_version, ".", "-"), "_", "-")}"
  family      = "aurora-mysql8.0"
  description = "RDS cluster parameter group for ${var.project_prefix}"

  # Performance optimizations
  parameter {
    name  = "max_connections"
    value = "1000"
  }

  parameter {
    name  = "innodb_buffer_pool_size"
    value = "{DBInstanceClassMemory*3/4}"
  }

  # Enable slow query log
  parameter {
    name  = "slow_query_log"
    value = "1"
  }

  parameter {
    name  = "long_query_time"
    value = "2"
  }

  # Character set
  parameter {
    name  = "character_set_server"
    value = "utf8mb4"
  }

  parameter {
    name  = "collation_server"
    value = "utf8mb4_unicode_ci"
  }

  tags = var.common_tags

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# DB INSTANCE PARAMETER GROUP
# =============================================================================

resource "aws_db_parameter_group" "main" {
  name        = "${lower(var.project_prefix)}-aurora-mysql-instance-${var.environment}"
  family      = "aurora-mysql8.0"
  description = "DB instance parameter group for ${var.project_prefix}"

  # Performance monitoring (static parameter - requires reboot)
  parameter {
    name         = "performance_schema"
    value        = "1"
    apply_method = "pending-reboot"
  }

  tags = var.common_tags

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# RANDOM PASSWORD FOR DATABASE
# =============================================================================

resource "random_password" "db_master" {
  length           = 32
  special          = true
  override_special = "!#$%&*()-_=+[]{}<>:?" # Exclude /, @, ", and space
}

# =============================================================================
# SECRETS MANAGER SECRET FOR DATABASE CREDENTIALS
# =============================================================================

resource "aws_secretsmanager_secret" "db_credentials" {
  name        = "${var.project_prefix}-db-credentials-${var.environment}"
  description = "Database credentials for ${var.project_prefix} Aurora cluster"

  tags = var.common_tags
}

resource "aws_secretsmanager_secret_version" "db_credentials" {
  secret_id = aws_secretsmanager_secret.db_credentials.id
  secret_string = jsonencode({
    username = var.db_username
    password = random_password.db_master.result
    engine   = "mysql"
    host     = aws_rds_cluster.main.endpoint
    port     = aws_rds_cluster.main.port
    dbname   = var.db_name
  })
}

# =============================================================================
# RDS AURORA CLUSTER
# =============================================================================

resource "aws_rds_cluster" "main" {
  cluster_identifier = "${var.project_prefix}-aurora-cluster"
  engine             = var.db_engine
  engine_version     = var.db_engine_version
  engine_mode        = "provisioned"

  database_name   = var.db_name
  master_username = var.db_username
  master_password = random_password.db_master.result

  db_cluster_parameter_group_name = aws_rds_cluster_parameter_group.main.name
  db_subnet_group_name            = aws_db_subnet_group.main.name
  vpc_security_group_ids          = [aws_security_group.rds.id]

  # Backup configuration
  backup_retention_period      = var.db_backup_retention_days
  preferred_backup_window      = "03:00-04:00"
  preferred_maintenance_window = "sun:04:00-sun:05:00"

  # Encryption
  storage_encrypted = true
  kms_key_id        = var.db_kms_key_id

  # High availability
  availability_zones = var.availability_zones

  # Deletion protection
  deletion_protection       = var.environment == "production" ? true : false
  skip_final_snapshot       = var.environment == "production" ? false : true
  final_snapshot_identifier = var.environment == "production" ? "${var.project_prefix}-aurora-final-snapshot-${formatdate("YYYY-MM-DD-hhmm", timestamp())}" : null

  # Enhanced monitoring
  enabled_cloudwatch_logs_exports = ["audit", "error", "general", "slowquery"]

  # Backtrack (Aurora MySQL only)
  backtrack_window = 72 # 72 hours

  # Tags
  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-aurora-cluster"
  })

  lifecycle {
    ignore_changes = [master_password]
  }
}

# =============================================================================
# RDS AURORA INSTANCES
# =============================================================================

resource "aws_rds_cluster_instance" "main" {
  count = var.db_instance_count

  identifier         = "${var.project_prefix}-aurora-instance-${count.index + 1}"
  cluster_identifier = aws_rds_cluster.main.id
  instance_class     = var.db_instance_class
  engine             = aws_rds_cluster.main.engine
  engine_version     = aws_rds_cluster.main.engine_version

  db_parameter_group_name = aws_db_parameter_group.main.name

  # Performance Insights (not supported on t3.medium)
  performance_insights_enabled          = can(regex("^db\\.(r5|r6|m5|m6)", var.db_instance_class))
  performance_insights_retention_period = can(regex("^db\\.(r5|r6|m5|m6)", var.db_instance_class)) ? 7 : null

  # Enhanced monitoring
  monitoring_interval = 60
  monitoring_role_arn = aws_iam_role.rds_monitoring.arn

  # Auto minor version upgrade
  auto_minor_version_upgrade = true

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-aurora-instance-${count.index + 1}"
  })
}

# =============================================================================
# IAM ROLE FOR ENHANCED MONITORING
# =============================================================================

resource "aws_iam_role" "rds_monitoring" {
  name = "${var.project_prefix}-rds-monitoring-role-${var.environment}"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "monitoring.rds.amazonaws.com"
        }
      }
    ]
  })

  tags = var.common_tags
}

resource "aws_iam_role_policy_attachment" "rds_monitoring" {
  role       = aws_iam_role.rds_monitoring.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonRDSEnhancedMonitoringRole"
}

# =============================================================================
# CLOUDWATCH ALARMS
# =============================================================================

# CPU utilization alarm
resource "aws_cloudwatch_metric_alarm" "rds_cpu" {
  alarm_name          = "${var.project_prefix}-rds-high-cpu"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "2"
  metric_name         = "CPUUtilization"
  namespace           = "AWS/RDS"
  period              = "300"
  statistic           = "Average"
  threshold           = "80"
  alarm_description   = "RDS instance CPU utilization is too high"
  alarm_actions       = var.alarm_email != "" ? [aws_sns_topic.alerts[0].arn] : []

  dimensions = {
    DBClusterIdentifier = aws_rds_cluster.main.id
  }
}

# Database connections alarm
resource "aws_cloudwatch_metric_alarm" "rds_connections" {
  alarm_name          = "${var.project_prefix}-rds-high-connections"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "2"
  metric_name         = "DatabaseConnections"
  namespace           = "AWS/RDS"
  period              = "300"
  statistic           = "Average"
  threshold           = "800"
  alarm_description   = "RDS instance has high number of connections"
  alarm_actions       = var.alarm_email != "" ? [aws_sns_topic.alerts[0].arn] : []

  dimensions = {
    DBClusterIdentifier = aws_rds_cluster.main.id
  }
}

# Storage space alarm
resource "aws_cloudwatch_metric_alarm" "rds_storage" {
  alarm_name          = "${var.project_prefix}-rds-low-storage"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = "1"
  metric_name         = "VolumeBytesUsed"
  namespace           = "AWS/RDS"
  period              = "300"
  statistic           = "Average"
  threshold           = "10737418240" # 10GB in bytes
  alarm_description   = "RDS cluster storage space is running low"
  alarm_actions       = var.alarm_email != "" ? [aws_sns_topic.alerts[0].arn] : []
  treat_missing_data  = "notBreaching"

  dimensions = {
    DBClusterIdentifier = aws_rds_cluster.main.id
  }
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "rds_cluster_endpoint" {
  description = "Writer endpoint for the Aurora cluster"
  value       = aws_rds_cluster.main.endpoint
}

output "rds_cluster_reader_endpoint" {
  description = "Reader endpoint for the Aurora cluster"
  value       = aws_rds_cluster.main.reader_endpoint
}

output "rds_cluster_id" {
  description = "RDS cluster identifier"
  value       = aws_rds_cluster.main.id
}

output "rds_security_group_id" {
  description = "Security group ID for RDS"
  value       = aws_security_group.rds.id
}

output "db_credentials_secret_arn" {
  description = "ARN of the Secrets Manager secret containing database credentials"
  value       = aws_secretsmanager_secret.db_credentials.arn
}

output "db_credentials_secret_name" {
  description = "Name of the Secrets Manager secret containing database credentials"
  value       = aws_secretsmanager_secret.db_credentials.name
}