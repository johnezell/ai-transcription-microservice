# TFS AI Infrastructure - Laravel Queue Worker
# ECS Fargate service for processing Laravel queue jobs

# =============================================================================
# QUEUE WORKER TASK DEFINITION
# =============================================================================

resource "aws_ecs_task_definition" "queue_worker" {
  family                   = "${var.project_prefix}-queue-worker"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = var.queue_worker_cpu
  memory                   = var.queue_worker_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  container_definitions = jsonencode([
    {
      name  = "queue-worker"
      image = "${local.ecr_repositories.laravel}:${var.image_tag}"

      # Override the default command to run the queue worker
      command = [
        "php",
        "artisan",
        "queue:work",
        "sqs",
        "--sleep=3",
        "--tries=3",
        "--max-time=3600",
        "--memory=512"
      ]

      environment = [
        {
          name  = "APP_ENV"
          value = var.environment
        },
        {
          name  = "APP_DEBUG"
          value = var.environment == "production" ? "false" : "true"
        },
        {
          name  = "APP_URL"
          value = "https://${var.domain_name}"
        },
        {
          name  = "LOG_CHANNEL"
          value = "stderr"
        },
        {
          name  = "DB_CONNECTION"
          value = "mysql"
        },
        {
          name  = "DB_HOST"
          value = aws_rds_cluster.main.endpoint
        },
        {
          name  = "DB_PORT"
          value = "3306"
        },
        {
          name  = "DB_DATABASE"
          value = var.db_name
        },
        {
          name  = "QUEUE_CONNECTION"
          value = "sqs"
        },
        {
          name  = "SQS_PREFIX"
          value = "https://sqs.${var.aws_region}.amazonaws.com/${var.aws_account_id}"
        },
        {
          name  = "SQS_QUEUE"
          value = aws_sqs_queue.laravel_jobs.name
        },
        {
          name  = "AWS_DEFAULT_REGION"
          value = var.aws_region
        },
        {
          name  = "AWS_BUCKET"
          value = aws_s3_bucket.audio_input.id
        },
        # Redis configuration
        {
          name  = "REDIS_HOST"
          value = aws_elasticache_cluster.main[0].cache_nodes[0].address
        },
        {
          name  = "REDIS_PORT"
          value = "6379"
        },
        {
          name  = "CACHE_DRIVER"
          value = "redis"
        },
        {
          name  = "SESSION_DRIVER"
          value = "redis"
        },
        # Service URLs
        {
          name  = "AUDIO_EXTRACTION_SERVICE_URL"
          value = "http://audio-extraction.${aws_service_discovery_private_dns_namespace.main.name}:5000"
        },
        {
          name  = "TRANSCRIPTION_SERVICE_URL"
          value = "http://transcription.${aws_service_discovery_private_dns_namespace.main.name}:5000"
        },
        {
          name  = "MUSIC_TERM_SERVICE_URL"
          value = "http://music-term-recognition.${aws_service_discovery_private_dns_namespace.main.name}:5000"
        }
      ]

      secrets = [
        {
          name      = "APP_KEY"
          valueFrom = "${aws_secretsmanager_secret.app_key.arn}:key::"
        },
        {
          name      = "DB_USERNAME"
          valueFrom = "${aws_secretsmanager_secret.db_credentials.arn}:username::"
        },
        {
          name      = "DB_PASSWORD"
          valueFrom = "${aws_secretsmanager_secret.db_credentials.arn}:password::"
        },
        # TrueFire database credentials (read-only access)
        {
          name      = "TRUEFIRE_DB_HOST"
          valueFrom = "${data.aws_secretsmanager_secret.truefire_db.arn}:host::"
        },
        {
          name      = "TRUEFIRE_DB_DATABASE"
          valueFrom = "${data.aws_secretsmanager_secret.truefire_db.arn}:database::"
        },
        {
          name      = "TRUEFIRE_DB_USERNAME"
          valueFrom = "${data.aws_secretsmanager_secret.truefire_db.arn}:username::"
        },
        {
          name      = "TRUEFIRE_DB_PASSWORD"
          valueFrom = "${data.aws_secretsmanager_secret.truefire_db.arn}:password::"
        }
      ]

      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.ecs_containers.name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "queue-worker"
        }
      }

      # No health check needed for queue workers - they don't expose HTTP
    }
  ])

  tags = var.common_tags
}

# =============================================================================
# QUEUE WORKER ECS SERVICE
# =============================================================================

resource "aws_ecs_service" "queue_worker" {
  name            = "${var.project_prefix}-queue-worker"
  cluster         = aws_ecs_cluster.ai_cluster.id
  task_definition = aws_ecs_task_definition.queue_worker.arn
  desired_count   = var.queue_worker_desired_count
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = local.private_subnet_ids
    security_groups  = [aws_security_group.laravel_service.id]
    assign_public_ip = false
  }

  # Allow service to be updated
  deployment_maximum_percent         = 200
  deployment_minimum_healthy_percent = 50

  # Enable ECS Exec for debugging
  enable_execute_command = true

  # Deployment circuit breaker
  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-queue-worker"
  })

  lifecycle {
    ignore_changes = [desired_count]
  }
}

# =============================================================================
# AUTO SCALING FOR QUEUE WORKER
# =============================================================================

resource "aws_appautoscaling_target" "queue_worker" {
  max_capacity       = var.queue_worker_max_count
  min_capacity       = var.queue_worker_min_count
  resource_id        = "service/${aws_ecs_cluster.ai_cluster.name}/${aws_ecs_service.queue_worker.name}"
  scalable_dimension = "ecs:service:DesiredCount"
  service_namespace  = "ecs"
}

# Scale based on SQS queue depth
resource "aws_appautoscaling_policy" "queue_worker_scale_up" {
  name               = "${var.project_prefix}-queue-worker-scale-up"
  policy_type        = "StepScaling"
  resource_id        = aws_appautoscaling_target.queue_worker.resource_id
  scalable_dimension = aws_appautoscaling_target.queue_worker.scalable_dimension
  service_namespace  = aws_appautoscaling_target.queue_worker.service_namespace

  step_scaling_policy_configuration {
    adjustment_type         = "ChangeInCapacity"
    cooldown                = 60
    metric_aggregation_type = "Average"

    step_adjustment {
      metric_interval_lower_bound = 0
      metric_interval_upper_bound = 100
      scaling_adjustment          = 1
    }

    step_adjustment {
      metric_interval_lower_bound = 100
      scaling_adjustment          = 2
    }
  }
}

resource "aws_appautoscaling_policy" "queue_worker_scale_down" {
  name               = "${var.project_prefix}-queue-worker-scale-down"
  policy_type        = "StepScaling"
  resource_id        = aws_appautoscaling_target.queue_worker.resource_id
  scalable_dimension = aws_appautoscaling_target.queue_worker.scalable_dimension
  service_namespace  = aws_appautoscaling_target.queue_worker.service_namespace

  step_scaling_policy_configuration {
    adjustment_type         = "ChangeInCapacity"
    cooldown                = 300
    metric_aggregation_type = "Average"

    step_adjustment {
      metric_interval_upper_bound = 0
      scaling_adjustment          = -1
    }
  }
}

# CloudWatch alarm for scaling based on queue depth
resource "aws_cloudwatch_metric_alarm" "queue_depth_high" {
  alarm_name          = "${var.project_prefix}-queue-depth-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  metric_name         = "ApproximateNumberOfMessagesVisible"
  namespace           = "AWS/SQS"
  period              = 60
  statistic           = "Average"
  threshold           = 10
  alarm_description   = "Scale up when queue depth exceeds 10 messages"

  dimensions = {
    QueueName = aws_sqs_queue.laravel_jobs.name
  }

  alarm_actions = [aws_appautoscaling_policy.queue_worker_scale_up.arn]

  tags = var.common_tags
}

resource "aws_cloudwatch_metric_alarm" "queue_depth_low" {
  alarm_name          = "${var.project_prefix}-queue-depth-low"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 5
  metric_name         = "ApproximateNumberOfMessagesVisible"
  namespace           = "AWS/SQS"
  period              = 60
  statistic           = "Average"
  threshold           = 1
  alarm_description   = "Scale down when queue is nearly empty"

  dimensions = {
    QueueName = aws_sqs_queue.laravel_jobs.name
  }

  alarm_actions = [aws_appautoscaling_policy.queue_worker_scale_down.arn]

  tags = var.common_tags
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "queue_worker_service_name" {
  description = "Queue worker ECS service name"
  value       = aws_ecs_service.queue_worker.name
}

output "queue_worker_task_definition_arn" {
  description = "Queue worker task definition ARN"
  value       = aws_ecs_task_definition.queue_worker.arn
}

output "laravel_jobs_queue_url" {
  description = "Laravel jobs SQS queue URL"
  value       = aws_sqs_queue.laravel_jobs.url
}

output "laravel_jobs_queue_arn" {
  description = "Laravel jobs SQS queue ARN"
  value       = aws_sqs_queue.laravel_jobs.arn
}

