# TFS AI Infrastructure - ECR Repositories
# Container registries for AI transcription microservices

# =============================================================================
# ECR REPOSITORIES
# =============================================================================

# Repository for Laravel API service
resource "aws_ecr_repository" "laravel_api" {
  name                 = "${var.project_prefix}/laravel-api"
  image_tag_mutability = "MUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    encryption_type = "AES256"
  }

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-laravel-api"
    Service = "laravel-api"
  })
}

# Repository for audio extraction service
resource "aws_ecr_repository" "audio_extraction" {
  name                 = "${var.project_prefix}/audio-extraction"
  image_tag_mutability = "MUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    encryption_type = "AES256"
  }

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-audio-extraction"
    Service = "audio-extraction"
  })
}

# Repository for transcription service
resource "aws_ecr_repository" "transcription" {
  name                 = "${var.project_prefix}/transcription"
  image_tag_mutability = "MUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    encryption_type = "AES256"
  }

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-transcription"
    Service = "transcription"
  })
}

# Repository for music term recognition service
resource "aws_ecr_repository" "music_term_recognition" {
  name                 = "${var.project_prefix}/music-term-recognition"
  image_tag_mutability = "MUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    encryption_type = "AES256"
  }

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-music-term-recognition"
    Service = "music-term-recognition"
  })
}

# =============================================================================
# ECR LIFECYCLE POLICIES
# =============================================================================

# Lifecycle policy to keep only recent images
locals {
  ecr_lifecycle_policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Remove untagged images after 7 days"
        selection = {
          tagStatus   = "untagged"
          countType   = "sinceImagePushed"
          countUnit   = "days"
          countNumber = 7
        }
        action = {
          type = "expire"
        }
      },
      {
        rulePriority = 2
        description  = "Keep only the last 10 images"
        selection = {
          tagStatus   = "any"
          countType   = "imageCountMoreThan"
          countNumber = 10
        }
        action = {
          type = "expire"
        }
      }
    ]
  })
}

# Apply lifecycle policy to all repositories
resource "aws_ecr_lifecycle_policy" "laravel_api" {
  repository = aws_ecr_repository.laravel_api.name
  policy     = local.ecr_lifecycle_policy
}

resource "aws_ecr_lifecycle_policy" "audio_extraction" {
  repository = aws_ecr_repository.audio_extraction.name
  policy     = local.ecr_lifecycle_policy
}

resource "aws_ecr_lifecycle_policy" "transcription" {
  repository = aws_ecr_repository.transcription.name
  policy     = local.ecr_lifecycle_policy
}

resource "aws_ecr_lifecycle_policy" "music_term_recognition" {
  repository = aws_ecr_repository.music_term_recognition.name
  policy     = local.ecr_lifecycle_policy
}

# =============================================================================
# ECR REPOSITORY POLICIES (for cross-account access if needed)
# =============================================================================

# Policy to allow cross-account pull from shared services account
locals {
  ecr_cross_account_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "AllowCrossAccountPull"
        Effect = "Allow"
        Principal = {
          AWS = var.shared_services_account_id != "" ? [
            "arn:aws:iam::${var.shared_services_account_id}:root"
          ] : []
        }
        Action = [
          "ecr:GetDownloadUrlForLayer",
          "ecr:BatchGetImage",
          "ecr:BatchCheckLayerAvailability"
        ]
      },
      {
        Sid    = "AllowECSTaskExecution"
        Effect = "Allow"
        Principal = {
          AWS = [
            aws_iam_role.ecs_task_execution.arn
          ]
        }
        Action = [
          "ecr:GetAuthorizationToken",
          "ecr:BatchCheckLayerAvailability",
          "ecr:GetDownloadUrlForLayer",
          "ecr:BatchGetImage"
        ]
      }
    ]
  })
}

# Apply repository policies if cross-account access is needed
resource "aws_ecr_repository_policy" "laravel_api" {
  count      = var.shared_services_account_id != "" ? 1 : 0
  repository = aws_ecr_repository.laravel_api.name
  policy     = local.ecr_cross_account_policy
}

resource "aws_ecr_repository_policy" "audio_extraction" {
  count      = var.shared_services_account_id != "" ? 1 : 0
  repository = aws_ecr_repository.audio_extraction.name
  policy     = local.ecr_cross_account_policy
}

resource "aws_ecr_repository_policy" "transcription" {
  count      = var.shared_services_account_id != "" ? 1 : 0
  repository = aws_ecr_repository.transcription.name
  policy     = local.ecr_cross_account_policy
}

resource "aws_ecr_repository_policy" "music_term_recognition" {
  count      = var.shared_services_account_id != "" ? 1 : 0
  repository = aws_ecr_repository.music_term_recognition.name
  policy     = local.ecr_cross_account_policy
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "ecr_repository_urls" {
  description = "URLs of the ECR repositories"
  value = {
    laravel_api            = aws_ecr_repository.laravel_api.repository_url
    audio_extraction       = aws_ecr_repository.audio_extraction.repository_url
    transcription          = aws_ecr_repository.transcription.repository_url
    music_term_recognition = aws_ecr_repository.music_term_recognition.repository_url
  }
}

output "ecr_repository_arns" {
  description = "ARNs of the ECR repositories"
  value = {
    laravel_api            = aws_ecr_repository.laravel_api.arn
    audio_extraction       = aws_ecr_repository.audio_extraction.arn
    transcription          = aws_ecr_repository.transcription.arn
    music_term_recognition = aws_ecr_repository.music_term_recognition.arn
  }
}

output "ecr_registry_id" {
  description = "The registry ID where the repositories are created"
  value       = aws_ecr_repository.laravel_api.registry_id
}

output "ecr_registry_url" {
  description = "The URL of the ECR registry"
  value       = "${aws_ecr_repository.laravel_api.registry_id}.dkr.ecr.${var.aws_region}.amazonaws.com"
}