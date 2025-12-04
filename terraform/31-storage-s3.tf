# TFS AI Infrastructure - S3 Storage Configuration
# Provides object storage for audio files and transcription results

# =============================================================================
# S3 BUCKETS
# =============================================================================

# Bucket for audio input files
resource "aws_s3_bucket" "audio_input" {
  bucket = "${var.s3_bucket_prefix}-audio-input-${var.environment}"

  tags = merge(var.common_tags, {
    Name = "${var.s3_bucket_prefix}-audio-input"
    Type = "audio-storage"
  })
}

# Bucket for transcription output
resource "aws_s3_bucket" "transcription_output" {
  bucket = "${var.s3_bucket_prefix}-transcription-output-${var.environment}"

  tags = merge(var.common_tags, {
    Name = "${var.s3_bucket_prefix}-transcription-output"
    Type = "transcription-storage"
  })
}

# Bucket for archived files
resource "aws_s3_bucket" "archive" {
  bucket = "${var.s3_bucket_prefix}-archive-${var.environment}"

  tags = merge(var.common_tags, {
    Name = "${var.s3_bucket_prefix}-archive"
    Type = "archive-storage"
  })
}

# =============================================================================
# BUCKET CONFIGURATIONS
# =============================================================================

# Versioning for all buckets
resource "aws_s3_bucket_versioning" "audio_input" {
  bucket = aws_s3_bucket.audio_input.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_versioning" "transcription_output" {
  bucket = aws_s3_bucket.transcription_output.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_versioning" "archive" {
  bucket = aws_s3_bucket.archive.id

  versioning_configuration {
    status = "Enabled"
  }
}

# Server-side encryption
resource "aws_s3_bucket_server_side_encryption_configuration" "audio_input" {
  bucket = aws_s3_bucket.audio_input.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "transcription_output" {
  bucket = aws_s3_bucket.transcription_output.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "archive" {
  bucket = aws_s3_bucket.archive.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

# Public access block
resource "aws_s3_bucket_public_access_block" "audio_input" {
  bucket = aws_s3_bucket.audio_input.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_public_access_block" "transcription_output" {
  bucket = aws_s3_bucket.transcription_output.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_public_access_block" "archive" {
  bucket = aws_s3_bucket.archive.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# =============================================================================
# LIFECYCLE POLICIES
# =============================================================================

# Lifecycle policy for audio input
resource "aws_s3_bucket_lifecycle_configuration" "audio_input" {
  bucket = aws_s3_bucket.audio_input.id

  rule {
    id     = "archive-old-files"
    status = "Enabled"

    filter {}

    transition {
      days          = 30
      storage_class = "STANDARD_IA"
    }

    transition {
      days          = 90
      storage_class = "GLACIER"
    }

    noncurrent_version_transition {
      noncurrent_days = 30
      storage_class   = "STANDARD_IA"
    }

    noncurrent_version_expiration {
      noncurrent_days = 90
    }
  }

  rule {
    id     = "delete-incomplete-uploads"
    status = "Enabled"

    filter {}

    abort_incomplete_multipart_upload {
      days_after_initiation = 7
    }
  }
}

# Lifecycle policy for transcription output
resource "aws_s3_bucket_lifecycle_configuration" "transcription_output" {
  bucket = aws_s3_bucket.transcription_output.id

  rule {
    id     = "archive-old-transcriptions"
    status = "Enabled"

    filter {}

    transition {
      days          = 90
      storage_class = "STANDARD_IA"
    }

    transition {
      days          = 180
      storage_class = "GLACIER"
    }

    expiration {
      days = 730 # Delete after 2 years
    }
  }
}

# Lifecycle policy for archive bucket
resource "aws_s3_bucket_lifecycle_configuration" "archive" {
  bucket = aws_s3_bucket.archive.id

  rule {
    id     = "deep-archive"
    status = "Enabled"

    filter {}

    transition {
      days          = 1
      storage_class = "GLACIER"
    }

    transition {
      days          = 180
      storage_class = "DEEP_ARCHIVE"
    }
  }
}

# =============================================================================
# S3 EVENT NOTIFICATIONS
# =============================================================================

# SQS queue for audio upload notifications
resource "aws_sqs_queue" "audio_uploads" {
  name                       = "${var.project_prefix}-audio-uploads"
  visibility_timeout_seconds = 300   # 5 minutes
  message_retention_seconds  = 86400 # 24 hours

  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.audio_uploads_dlq.arn
    maxReceiveCount     = 3
  })

  tags = var.common_tags
}

# Dead letter queue
resource "aws_sqs_queue" "audio_uploads_dlq" {
  name                      = "${var.project_prefix}-audio-uploads-dlq"
  message_retention_seconds = 1209600 # 14 days

  tags = var.common_tags
}

# Queue policy to allow S3 to send messages
resource "aws_sqs_queue_policy" "audio_uploads" {
  queue_url = aws_sqs_queue.audio_uploads.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Principal = {
          Service = "s3.amazonaws.com"
        }
        Action   = "SQS:SendMessage"
        Resource = aws_sqs_queue.audio_uploads.arn
        Condition = {
          ArnEquals = {
            "aws:SourceArn" = aws_s3_bucket.audio_input.arn
          }
        }
      }
    ]
  })
}

# S3 event notification
resource "aws_s3_bucket_notification" "audio_input" {
  bucket = aws_s3_bucket.audio_input.id

  queue {
    queue_arn     = aws_sqs_queue.audio_uploads.arn
    events        = ["s3:ObjectCreated:*"]
    filter_suffix = ".mp3"
  }

  queue {
    queue_arn     = aws_sqs_queue.audio_uploads.arn
    events        = ["s3:ObjectCreated:*"]
    filter_suffix = ".wav"
  }

  queue {
    queue_arn     = aws_sqs_queue.audio_uploads.arn
    events        = ["s3:ObjectCreated:*"]
    filter_suffix = ".m4a"
  }

  queue {
    queue_arn     = aws_sqs_queue.audio_uploads.arn
    events        = ["s3:ObjectCreated:*"]
    filter_suffix = ".flac"
  }

  depends_on = [aws_sqs_queue_policy.audio_uploads]
}

# =============================================================================
# CORS CONFIGURATION
# =============================================================================

# CORS for audio input (for direct browser uploads)
resource "aws_s3_bucket_cors_configuration" "audio_input" {
  bucket = aws_s3_bucket.audio_input.id

  cors_rule {
    allowed_headers = ["*"]
    allowed_methods = ["GET", "HEAD", "PUT", "POST"]
    allowed_origins = var.environment == "production" ? ["https://*.tfs.services"] : ["https://*.tfs.services", "http://localhost:*"]
    expose_headers  = ["ETag"]
    max_age_seconds = 3000
  }
}

# =============================================================================
# CLOUDWATCH METRICS
# =============================================================================

# Alarm for high S3 request errors
resource "aws_cloudwatch_metric_alarm" "s3_errors" {
  alarm_name          = "${var.project_prefix}-s3-high-error-rate"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "2"
  metric_name         = "4xxErrors"
  namespace           = "AWS/S3"
  period              = "300"
  statistic           = "Sum"
  threshold           = "10"
  alarm_description   = "S3 bucket experiencing high error rate"
  treat_missing_data  = "notBreaching"

  dimensions = {
    BucketName = aws_s3_bucket.audio_input.id
  }
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "s3_audio_input_bucket" {
  description = "Name of the audio input S3 bucket"
  value       = aws_s3_bucket.audio_input.id
}

output "s3_audio_input_bucket_arn" {
  description = "ARN of the audio input S3 bucket"
  value       = aws_s3_bucket.audio_input.arn
}

output "s3_transcription_output_bucket" {
  description = "Name of the transcription output S3 bucket"
  value       = aws_s3_bucket.transcription_output.id
}

output "s3_transcription_output_bucket_arn" {
  description = "ARN of the transcription output S3 bucket"
  value       = aws_s3_bucket.transcription_output.arn
}

output "s3_archive_bucket" {
  description = "Name of the archive S3 bucket"
  value       = aws_s3_bucket.archive.id
}

output "sqs_audio_uploads_queue_url" {
  description = "URL of the audio uploads SQS queue"
  value       = aws_sqs_queue.audio_uploads.url
}

output "sqs_audio_uploads_queue_arn" {
  description = "ARN of the audio uploads SQS queue"
  value       = aws_sqs_queue.audio_uploads.arn
}