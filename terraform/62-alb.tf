# TFS AI Infrastructure - Application Load Balancer
# VPN-only access for internal development

# =============================================================================
# APPLICATION LOAD BALANCER (VPN-ONLY)
# =============================================================================

# ALB Security Group - IP whitelist for now (TODO: VPC peering for VPN access)
resource "aws_security_group" "alb" {
  count = var.create_alb ? 1 : 0

  name_prefix = "${var.project_prefix}-alb-sg-"
  description = "Security group for Public ALB - IP whitelisted"
  vpc_id      = local.vpc_id

  # Allow HTTPS from whitelisted IPs
  ingress {
    description = "HTTPS from whitelisted IPs"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = var.bastion_allowed_cidrs  # Reuse bastion allowed CIDRs
  }

  # Allow HTTP from whitelisted IPs (redirect to HTTPS)
  ingress {
    description = "HTTP from whitelisted IPs"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = var.bastion_allowed_cidrs  # Reuse bastion allowed CIDRs
  }

  # Allow all outbound
  egress {
    description = "All outbound"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-alb-sg"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# Application Load Balancer - Public with IP whitelist (TODO: VPC peering for VPN)
resource "aws_lb" "main" {
  count = var.create_alb ? 1 : 0

  name               = "${var.project_prefix}-alb-${var.environment}"
  internal           = false  # Public ALB with IP whitelist
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb[0].id]
  subnets            = local.public_subnet_ids  # Public subnets for internet access

  enable_deletion_protection       = var.environment == "production"
  enable_http2                     = true
  enable_cross_zone_load_balancing = true

  access_logs {
    bucket  = aws_s3_bucket.alb_logs[0].id
    enabled = true
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-alb"
  })
}

# S3 bucket for ALB logs
resource "aws_s3_bucket" "alb_logs" {
  count = var.create_alb ? 1 : 0

  bucket = "${var.s3_bucket_prefix}-alb-logs-${var.environment}"

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-alb-logs"
    Type = "logs"
  })
}

# S3 bucket policy for ALB logs
resource "aws_s3_bucket_policy" "alb_logs" {
  count = var.create_alb ? 1 : 0

  bucket = aws_s3_bucket.alb_logs[0].id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Principal = {
          AWS = "arn:aws:iam::127311923021:root" # ELB service account for us-east-1
        }
        Action   = "s3:PutObject"
        Resource = "${aws_s3_bucket.alb_logs[0].arn}/*"
      }
    ]
  })
}

# Target Group for Laravel API
resource "aws_lb_target_group" "laravel_api" {
  count = var.create_alb ? 1 : 0

  name        = "${var.project_prefix}-laravel-tg"
  port        = 80
  protocol    = "HTTP"
  vpc_id      = local.vpc_id
  target_type = "ip"

  health_check {
    enabled             = true
    healthy_threshold   = 2
    interval            = 30
    matcher             = "200"
    path                = "/health.php"  # Simple PHP health check (no Laravel)
    port                = "traffic-port"
    protocol            = "HTTP"
    timeout             = 5
    unhealthy_threshold = 3
  }

  deregistration_delay = 30

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-laravel-tg"
  })
}

# HTTPS Listener
resource "aws_lb_listener" "https" {
  count = var.create_alb ? 1 : 0

  load_balancer_arn = aws_lb.main[0].arn
  port              = "443"
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS-1-2-2017-01"
  certificate_arn   = aws_acm_certificate_validation.thoth_cert.certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.laravel_api[0].arn
  }
}

# HTTP Listener (redirect to HTTPS)
resource "aws_lb_listener" "http" {
  count = var.create_alb ? 1 : 0

  load_balancer_arn = aws_lb.main[0].arn
  port              = "80"
  protocol          = "HTTP"

  default_action {
    type = "redirect"

    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }
}

# Update Laravel service to use target group
resource "aws_ecs_service" "laravel_api_with_alb" {
  count = var.create_alb ? 1 : 0

  name             = "${var.project_prefix}-laravel-api-alb"
  cluster          = aws_ecs_cluster.ai_cluster.id
  task_definition  = aws_ecs_task_definition.laravel_api.arn
  desired_count    = var.laravel_desired_count
  launch_type      = "FARGATE"
  platform_version = "LATEST"

  network_configuration {
    subnets          = local.private_subnet_ids
    security_groups  = [aws_security_group.laravel_service_alb[0].id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.laravel_api[0].arn
    container_name   = "laravel"
    container_port   = 80
  }

  service_registries {
    registry_arn = aws_service_discovery_service.ecs_services["laravel-api"].arn
  }

  enable_execute_command = true

  deployment_maximum_percent         = 200
  deployment_minimum_healthy_percent = 100

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  lifecycle {
    ignore_changes = [desired_count]
  }

  depends_on = [
    aws_iam_role_policy.ecs_exec,
    aws_rds_cluster_instance.main,
    aws_lb_listener.https
  ]

  tags = var.common_tags
}

# Security group for Laravel service with ALB
resource "aws_security_group" "laravel_service_alb" {
  count = var.create_alb ? 1 : 0

  name        = "${var.project_prefix}-laravel-service-alb-sg-${var.environment}"
  description = "Security group for Laravel ECS service with ALB"
  vpc_id      = local.vpc_id

  # Allow HTTP from ALB
  ingress {
    description     = "HTTP from ALB"
    from_port       = 80
    to_port         = 80
    protocol        = "tcp"
    security_groups = [aws_security_group.alb[0].id]
  }

  # Allow all outbound
  egress {
    description = "All outbound"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-laravel-service-alb-sg"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "alb_dns_name" {
  description = "DNS name of the Application Load Balancer"
  value       = var.create_alb ? aws_lb.main[0].dns_name : null
}

output "alb_zone_id" {
  description = "Zone ID of the Application Load Balancer"
  value       = var.create_alb ? aws_lb.main[0].zone_id : null
}