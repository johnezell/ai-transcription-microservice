# TFS AI Infrastructure - ECS Task Definitions
# Defines container configurations for all services

# =============================================================================
# EXTERNAL SECRETS (data sources for pre-existing secrets)
# =============================================================================

# TrueFire production database credentials
# Created manually: aws secretsmanager create-secret --name tfs-ai-truefire-db-credentials
data "aws_secretsmanager_secret" "truefire_db" {
  name = "tfs-ai-truefire-db-credentials"
}

# =============================================================================
# TASK DEFINITIONS
# =============================================================================

# Laravel API Task Definition
resource "aws_ecs_task_definition" "laravel_api" {
  family                   = "${var.project_prefix}-laravel-api"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = var.laravel_task_cpu
  memory                   = var.laravel_task_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  # EFS Volume for shared storage - temporarily disabled pending network debugging
  # volume {
  #   name = "efs-shared"
  #
  #   efs_volume_configuration {
  #     file_system_id          = aws_efs_file_system.shared_storage.id
  #     transit_encryption      = "ENABLED"
  #     authorization_config {
  #       access_point_id = aws_efs_access_point.shared.id
  #       iam             = "ENABLED"
  #     }
  #   }
  # }

  container_definitions = jsonencode([
    {
      name  = "laravel"
      image = "${local.ecr_repositories.laravel}:${var.image_tag}"

      portMappings = [
        {
          containerPort = 80
          protocol      = "tcp"
        }
      ]

      # EFS mount temporarily disabled
      # mountPoints = [
      #   {
      #     sourceVolume  = "efs-shared"
      #     containerPath = "/mnt/efs"
      #     readOnly      = false
      #   }
      # ]

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
          name  = "ASSET_URL"
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
          "awslogs-stream-prefix" = "laravel"
        }
      }

      healthCheck = {
        command     = ["CMD-SHELL", "curl -f http://localhost/api/health || exit 1"]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 60
      }
    }
  ])

  tags = var.common_tags
}

# Audio Extraction Task Definition
resource "aws_ecs_task_definition" "audio_extraction" {
  family                   = "${var.project_prefix}-audio-extraction"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = var.audio_task_cpu
  memory                   = var.audio_task_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  container_definitions = jsonencode([
    {
      name  = "audio-extraction"
      image = "${local.ecr_repositories.audio_extraction}:${var.image_tag}"

      portMappings = [
        {
          containerPort = 5000
          protocol      = "tcp"
        }
      ]

      environment = [
        {
          name  = "FLASK_ENV"
          value = var.environment == "production" ? "production" : "development"
        },
        {
          name  = "AWS_DEFAULT_REGION"
          value = var.aws_region
        },
        {
          name  = "S3_BUCKET"
          value = aws_s3_bucket.audio_input.id
        },
        {
          name  = "OUTPUT_S3_BUCKET"
          value = aws_s3_bucket.transcription_output.id
        }
      ]

      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.ecs_containers.name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "audio-extraction"
        }
      }

      healthCheck = {
        command     = ["CMD-SHELL", "curl -f http://localhost:5000/health || exit 1"]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 30
      }
    }
  ])

  tags = var.common_tags
}

# Transcription Task Definition (GPU)
resource "aws_ecs_task_definition" "transcription" {
  family                   = "${var.project_prefix}-transcription"
  network_mode             = "awsvpc"
  requires_compatibilities = ["EC2"]
  cpu                      = var.transcription_task_cpu
  memory                   = var.transcription_task_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  # GPU requirements
  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }

  container_definitions = jsonencode([
    {
      name  = "transcription"
      image = "${local.ecr_repositories.transcription}:${var.image_tag}"

      portMappings = [
        {
          containerPort = 5000
          protocol      = "tcp"
        }
      ]

      # GPU configuration
      resourceRequirements = [
        {
          type  = "GPU"
          value = "1"
        }
      ]

      environment = [
        {
          name  = "FLASK_ENV"
          value = var.environment == "production" ? "production" : "development"
        },
        {
          name  = "AWS_DEFAULT_REGION"
          value = var.aws_region
        },
        {
          name  = "S3_BUCKET"
          value = aws_s3_bucket.audio_input.id
        },
        {
          name  = "OUTPUT_S3_BUCKET"
          value = aws_s3_bucket.transcription_output.id
        },
        {
          name  = "WHISPER_MODEL"
          value = var.whisper_model_size
        },
        {
          name  = "MODEL_CACHE_DIR"
          value = "/models"
        }
      ]

      # Mount EFS for model storage
      mountPoints = [
        {
          sourceVolume  = "models"
          containerPath = "/models"
          readOnly      = false
        }
      ]

      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.ecs_containers.name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "transcription"
        }
      }

      healthCheck = {
        command     = ["CMD-SHELL", "curl -f http://localhost:5000/health || exit 1"]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 300 # 5 minutes for model loading
      }

      # Increase ulimits for GPU
      ulimits = [
        {
          name      = "memlock"
          softLimit = -1
          hardLimit = -1
        },
        {
          name      = "stack"
          softLimit = 67108864
          hardLimit = 67108864
        }
      ]
    }
  ])

  # EFS volume for model storage
  volume {
    name = "models"

    efs_volume_configuration {
      file_system_id     = aws_efs_file_system.shared_storage.id
      transit_encryption = "ENABLED"

      authorization_config {
        access_point_id = aws_efs_access_point.models.id
        iam             = "ENABLED"
      }
    }
  }

  tags = var.common_tags
}

# Music Term Recognition Task Definition
resource "aws_ecs_task_definition" "music_term_recognition" {
  family                   = "${var.project_prefix}-music-term-recognition"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = var.music_task_cpu
  memory                   = var.music_task_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  container_definitions = jsonencode([
    {
      name  = "music-term-recognition"
      image = "${local.ecr_repositories.music_term}:${var.image_tag}"

      portMappings = [
        {
          containerPort = 5000
          protocol      = "tcp"
        }
      ]

      environment = [
        {
          name  = "FLASK_ENV"
          value = var.environment == "production" ? "production" : "development"
        },
        {
          name  = "AWS_DEFAULT_REGION"
          value = var.aws_region
        },
        {
          name  = "S3_BUCKET"
          value = aws_s3_bucket.transcription_output.id
        }
      ]

      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.ecs_containers.name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "music-term-recognition"
        }
      }

      healthCheck = {
        command     = ["CMD-SHELL", "curl -f http://localhost:5000/health || exit 1"]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 30
      }
    }
  ])

  tags = var.common_tags
}

# =============================================================================
# SECRETS
# =============================================================================

# Generate and store Laravel APP_KEY
resource "random_password" "app_key" {
  length  = 32
  special = true
}

resource "aws_secretsmanager_secret" "app_key" {
  name        = "${var.project_prefix}-app-key-${var.environment}"
  description = "Laravel application key for ${var.project_prefix}"

  tags = var.common_tags
}

resource "aws_secretsmanager_secret_version" "app_key" {
  secret_id = aws_secretsmanager_secret.app_key.id
  secret_string = jsonencode({
    key = "base64:${base64encode(random_password.app_key.result)}"
  })
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "task_definition_arns" {
  description = "ARNs of the task definitions"
  value = {
    laravel_api            = aws_ecs_task_definition.laravel_api.arn
    audio_extraction       = aws_ecs_task_definition.audio_extraction.arn
    transcription          = aws_ecs_task_definition.transcription.arn
    music_term_recognition = aws_ecs_task_definition.music_term_recognition.arn
  }
}