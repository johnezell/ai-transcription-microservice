# TFS AI Infrastructure - Redis (ElastiCache)
# Provides caching and session storage for Laravel

# =============================================================================
# ELASTICACHE SUBNET GROUP
# =============================================================================

resource "aws_elasticache_subnet_group" "main" {
  count = var.vpc_id == "" ? 1 : 0

  name        = "${var.project_prefix}-redis-subnet-group"
  description = "Subnet group for ${var.project_prefix} Redis cluster"
  subnet_ids  = aws_subnet.private[*].id

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-redis-subnet-group"
  })
}

# =============================================================================
# SECURITY GROUP FOR REDIS
# =============================================================================

resource "aws_security_group" "redis" {
  count = var.vpc_id == "" ? 1 : 0

  name        = "${var.project_prefix}-redis-sg"
  description = "Security group for Redis cluster"
  vpc_id      = aws_vpc.main[0].id

  # Redis port from Laravel services
  ingress {
    from_port       = 6379
    to_port         = 6379
    protocol        = "tcp"
    security_groups = [aws_security_group.laravel_service.id]
    description     = "Redis from Laravel services"
  }

  # Redis port from Python services
  ingress {
    from_port       = 6379
    to_port         = 6379
    protocol        = "tcp"
    security_groups = [aws_security_group.python_service.id]
    description     = "Redis from Python services"
  }

  # Redis port from bastion (for debugging)
  ingress {
    from_port       = 6379
    to_port         = 6379
    protocol        = "tcp"
    security_groups = [aws_security_group.bastion.id]
    description     = "Redis from bastion"
  }

  # Redis port from Laravel ALB service (when ALB is enabled)
  dynamic "ingress" {
    for_each = var.create_alb ? [1] : []
    content {
      from_port       = 6379
      to_port         = 6379
      protocol        = "tcp"
      security_groups = [aws_security_group.laravel_service_alb[0].id]
      description     = "Redis from Laravel ALB service"
    }
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
    description = "Allow all outbound"
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-redis-sg"
  })
}

# =============================================================================
# ELASTICACHE REDIS CLUSTER
# =============================================================================

resource "aws_elasticache_cluster" "main" {
  count = var.vpc_id == "" ? 1 : 0

  cluster_id           = "${var.project_prefix}-redis"
  engine               = "redis"
  engine_version       = var.redis_engine_version
  node_type            = var.redis_node_type
  num_cache_nodes      = 1
  parameter_group_name = "default.redis7"
  port                 = 6379

  subnet_group_name  = aws_elasticache_subnet_group.main[0].name
  security_group_ids = [aws_security_group.redis[0].id]

  # Maintenance window (Sunday 3-4 AM EST)
  maintenance_window = "sun:08:00-sun:09:00"

  # Snapshot settings
  snapshot_retention_limit = var.environment == "production" ? 7 : 1
  snapshot_window          = "07:00-08:00"

  # Apply changes immediately in non-production
  apply_immediately = var.environment != "production"

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-redis"
  })
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "redis_endpoint" {
  description = "Redis cluster endpoint"
  value       = var.vpc_id == "" ? aws_elasticache_cluster.main[0].cache_nodes[0].address : null
}

output "redis_port" {
  description = "Redis port"
  value       = 6379
}

output "redis_security_group_id" {
  description = "Redis security group ID"
  value       = var.vpc_id == "" ? aws_security_group.redis[0].id : null
}

