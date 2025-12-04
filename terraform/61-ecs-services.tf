# TFS AI Infrastructure - ECS Services
# Manages running instances of our task definitions

# =============================================================================
# ECS SERVICES
# =============================================================================

# Laravel API Service
resource "aws_ecs_service" "laravel_api" {
  name             = "${var.project_prefix}-laravel-api"
  cluster          = aws_ecs_cluster.ai_cluster.id
  task_definition  = aws_ecs_task_definition.laravel_api.arn
  desired_count    = var.laravel_desired_count
  launch_type      = "FARGATE"
  platform_version = "LATEST"

  network_configuration {
    subnets          = local.private_subnet_ids
    security_groups  = [aws_security_group.laravel_service.id]
    assign_public_ip = false
  }

  service_registries {
    registry_arn = aws_service_discovery_service.ecs_services["laravel-api"].arn
  }

  # Enable ECS Exec for debugging
  enable_execute_command = true

  # Deployment configuration
  deployment_maximum_percent         = 200
  deployment_minimum_healthy_percent = 100

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  # Auto-scaling target
  lifecycle {
    ignore_changes = [desired_count]
  }

  depends_on = [
    aws_iam_role_policy.ecs_exec,
    aws_rds_cluster_instance.main
  ]

  tags = var.common_tags
}

# Audio Extraction Service
resource "aws_ecs_service" "audio_extraction" {
  name             = "${var.project_prefix}-audio-extraction"
  cluster          = aws_ecs_cluster.ai_cluster.id
  task_definition  = aws_ecs_task_definition.audio_extraction.arn
  desired_count    = var.audio_desired_count
  launch_type      = "FARGATE"
  platform_version = "LATEST"

  network_configuration {
    subnets          = local.private_subnet_ids
    security_groups  = [aws_security_group.python_service.id]
    assign_public_ip = false
  }

  service_registries {
    registry_arn = aws_service_discovery_service.ecs_services["audio-extraction"].arn
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
    aws_iam_role_policy.ecs_exec
  ]

  tags = var.common_tags
}

# Transcription Service (GPU)
resource "aws_ecs_service" "transcription" {
  name            = "${var.project_prefix}-transcription"
  cluster         = aws_ecs_cluster.ai_cluster.id
  task_definition = aws_ecs_task_definition.transcription.arn
  desired_count   = var.transcription_desired_count
  # launch_type removed when using capacity_provider_strategy

  # Placement constraints for GPU instances
  placement_constraints {
    type       = "memberOf"
    expression = "attribute:ecs.instance-type =~ g4dn.*"
  }

  # Use GPU capacity provider
  capacity_provider_strategy {
    capacity_provider = aws_ecs_capacity_provider.gpu.name
    weight            = 100
    base              = 0
  }

  network_configuration {
    subnets          = local.private_subnet_ids
    security_groups  = [aws_security_group.python_service.id]
    assign_public_ip = false
  }

  service_registries {
    registry_arn = aws_service_discovery_service.ecs_services["transcription"].arn
  }

  enable_execute_command = true

  deployment_maximum_percent         = 100 # Can't exceed 100% for GPU instances
  deployment_minimum_healthy_percent = 0   # Allow full replacement

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  lifecycle {
    ignore_changes = [desired_count]
  }

  depends_on = [
    aws_iam_role_policy.ecs_exec,
    aws_autoscaling_group.gpu_instances,
    aws_efs_mount_target.main
  ]

  tags = var.common_tags
}

# Music Term Recognition Service
resource "aws_ecs_service" "music_term_recognition" {
  name             = "${var.project_prefix}-music-term-recognition"
  cluster          = aws_ecs_cluster.ai_cluster.id
  task_definition  = aws_ecs_task_definition.music_term_recognition.arn
  desired_count    = var.music_desired_count
  launch_type      = "FARGATE"
  platform_version = "LATEST"

  network_configuration {
    subnets          = local.private_subnet_ids
    security_groups  = [aws_security_group.python_service.id]
    assign_public_ip = false
  }

  service_registries {
    registry_arn = aws_service_discovery_service.ecs_services["music-term-recognition"].arn
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
    aws_iam_role_policy.ecs_exec
  ]

  tags = var.common_tags
}

# =============================================================================
# SECURITY GROUPS
# =============================================================================

# Security group for Laravel service
resource "aws_security_group" "laravel_service" {
  name        = "${var.project_prefix}-laravel-service-sg-${var.environment}"
  description = "Security group for Laravel ECS service"
  vpc_id      = local.vpc_id

  # Allow HTTP from within VPC
  ingress {
    description = "HTTP from VPC"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = [var.vpc_cidr]
  }

  # Allow from bastion for debugging
  ingress {
    description     = "HTTP from bastion"
    from_port       = 80
    to_port         = 80
    protocol        = "tcp"
    security_groups = [aws_security_group.bastion.id]
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
    Name = "${var.project_prefix}-laravel-service-sg"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# Security group for Python services
resource "aws_security_group" "python_service" {
  name        = "${var.project_prefix}-python-service-sg-${var.environment}"
  description = "Security group for Python ECS services"
  vpc_id      = local.vpc_id

  # Allow HTTP from Laravel service
  ingress {
    description     = "HTTP from Laravel"
    from_port       = 5000
    to_port         = 5000
    protocol        = "tcp"
    security_groups = [aws_security_group.laravel_service.id]
  }

  # Allow from other Python services
  ingress {
    description = "HTTP from Python services"
    from_port   = 5000
    to_port     = 5000
    protocol    = "tcp"
    self        = true
  }

  # Allow from bastion for debugging
  ingress {
    description     = "HTTP from bastion"
    from_port       = 5000
    to_port         = 5000
    protocol        = "tcp"
    security_groups = [aws_security_group.bastion.id]
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
    Name = "${var.project_prefix}-python-service-sg"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# AUTO SCALING
# =============================================================================

# Auto-scaling target for Laravel API
resource "aws_appautoscaling_target" "laravel_api" {
  max_capacity       = var.laravel_max_count
  min_capacity       = var.laravel_min_count
  resource_id        = "service/${aws_ecs_cluster.ai_cluster.name}/${aws_ecs_service.laravel_api.name}"
  scalable_dimension = "ecs:service:DesiredCount"
  service_namespace  = "ecs"
}

# Auto-scaling policy for Laravel API (CPU)
resource "aws_appautoscaling_policy" "laravel_api_cpu" {
  name               = "${var.project_prefix}-laravel-api-cpu-scaling"
  policy_type        = "TargetTrackingScaling"
  resource_id        = aws_appautoscaling_target.laravel_api.resource_id
  scalable_dimension = aws_appautoscaling_target.laravel_api.scalable_dimension
  service_namespace  = aws_appautoscaling_target.laravel_api.service_namespace

  target_tracking_scaling_policy_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
    target_value = 70.0
  }
}

# Auto-scaling for Audio Extraction
resource "aws_appautoscaling_target" "audio_extraction" {
  max_capacity       = var.audio_max_count
  min_capacity       = var.audio_min_count
  resource_id        = "service/${aws_ecs_cluster.ai_cluster.name}/${aws_ecs_service.audio_extraction.name}"
  scalable_dimension = "ecs:service:DesiredCount"
  service_namespace  = "ecs"
}

resource "aws_appautoscaling_policy" "audio_extraction_cpu" {
  name               = "${var.project_prefix}-audio-extraction-cpu-scaling"
  policy_type        = "TargetTrackingScaling"
  resource_id        = aws_appautoscaling_target.audio_extraction.resource_id
  scalable_dimension = aws_appautoscaling_target.audio_extraction.scalable_dimension
  service_namespace  = aws_appautoscaling_target.audio_extraction.service_namespace

  target_tracking_scaling_policy_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
    target_value = 70.0
  }
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "ecs_service_names" {
  description = "Names of the ECS services"
  value = {
    laravel_api            = aws_ecs_service.laravel_api.name
    audio_extraction       = aws_ecs_service.audio_extraction.name
    transcription          = aws_ecs_service.transcription.name
    music_term_recognition = aws_ecs_service.music_term_recognition.name
  }
}

output "service_discovery_endpoints" {
  description = "Service discovery endpoints for internal communication"
  value = {
    laravel_api            = "laravel-api.${aws_service_discovery_private_dns_namespace.main.name}"
    audio_extraction       = "audio-extraction.${aws_service_discovery_private_dns_namespace.main.name}"
    transcription          = "transcription.${aws_service_discovery_private_dns_namespace.main.name}"
    music_term_recognition = "music-term-recognition.${aws_service_discovery_private_dns_namespace.main.name}"
  }
}