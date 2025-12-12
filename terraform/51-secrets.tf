# TFS AI Infrastructure - Secrets Management
# Creates AWS Secrets Manager secrets for application services

# =============================================================================
# SECRETS FOR LARAVEL API
# =============================================================================

resource "aws_secretsmanager_secret" "laravel" {
  name        = "${var.project_prefix}-laravel-${var.environment}"
  description = "Secrets for Laravel API service"

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-laravel-secrets"
    Service = "laravel-api"
  })
}

resource "aws_secretsmanager_secret_version" "laravel" {
  secret_id = aws_secretsmanager_secret.laravel.id

  secret_string = jsonencode({
    APP_KEY                   = "base64:${base64encode(random_password.laravel_app_key.result)}"
    DB_HOST                   = aws_rds_cluster.main.endpoint
    DB_PORT                   = "3306"
    DB_DATABASE               = var.db_name
    DB_USERNAME               = var.db_username
    DB_PASSWORD               = random_password.db_master.result
    REDIS_HOST                = "${var.project_prefix}-redis.${aws_service_discovery_private_dns_namespace.main.name}"
    REDIS_PORT                = "6379"
    S3_BUCKET                 = aws_s3_bucket.audio_input.id
    S3_REGION                 = var.aws_region
    QUEUE_NAME                = aws_sqs_queue.audio_uploads.name
    API_URL                   = "https://${var.domain_name}"
    AWS_BEARER_TOKEN_BEDROCK  = var.bedrock_api_key
    BEDROCK_DEFAULT_MODEL     = var.bedrock_default_model
  })
}

# Generate Laravel APP_KEY
resource "random_password" "laravel_app_key" {
  length  = 32
  special = false
}

# =============================================================================
# SECRETS FOR AUDIO EXTRACTION SERVICE
# =============================================================================

resource "aws_secretsmanager_secret" "audio_extraction" {
  name        = "${var.project_prefix}-audio-extraction-${var.environment}"
  description = "Secrets for Audio Extraction service"

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-audio-extraction-secrets"
    Service = "audio-extraction"
  })
}

resource "aws_secretsmanager_secret_version" "audio_extraction" {
  secret_id = aws_secretsmanager_secret.audio_extraction.id

  secret_string = jsonencode({
    API_URL       = "https://${var.domain_name}"
    S3_BUCKET     = aws_s3_bucket.audio_input.id
    S3_OUTPUT     = aws_s3_bucket.transcription_output.id
    S3_REGION     = var.aws_region
    TEMP_DIR      = "/tmp/audio"
    MAX_FILE_SIZE = "2048" # MB
  })
}

# =============================================================================
# SECRETS FOR TRANSCRIPTION SERVICE
# =============================================================================

resource "aws_secretsmanager_secret" "transcription" {
  name        = "${var.project_prefix}-transcription-${var.environment}"
  description = "Secrets for Transcription service"

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-transcription-secrets"
    Service = "transcription"
  })
}

resource "aws_secretsmanager_secret_version" "transcription" {
  secret_id = aws_secretsmanager_secret.transcription.id

  secret_string = jsonencode({
    API_URL     = "https://${var.domain_name}"
    S3_BUCKET   = aws_s3_bucket.transcription_output.id
    S3_REGION   = var.aws_region
    MODEL_PATH  = "/models/whisper"
    MODEL_SIZE  = var.whisper_model_size
    BATCH_SIZE  = "4"
    GPU_ENABLED = "true"
    DEVICE_ID   = "0"
  })
}

# =============================================================================
# SECRETS FOR MUSIC TERM RECOGNITION SERVICE
# =============================================================================

resource "aws_secretsmanager_secret" "music_term_recognition" {
  name        = "${var.project_prefix}-music-term-recognition-${var.environment}"
  description = "Secrets for Music Term Recognition service"

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-music-term-recognition-secrets"
    Service = "music-term-recognition"
  })
}

resource "aws_secretsmanager_secret_version" "music_term_recognition" {
  secret_id = aws_secretsmanager_secret.music_term_recognition.id

  secret_string = jsonencode({
    API_URL              = "https://${var.domain_name}"
    S3_BUCKET            = aws_s3_bucket.transcription_output.id
    S3_REGION            = var.aws_region
    MODEL_PATH           = "/models/music-nlp"
    SPACY_MODEL          = "en_core_web_lg"
    CONFIDENCE_THRESHOLD = "0.7"
  })
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "secrets_arns" {
  description = "ARNs of created secrets"
  value = {
    laravel                = aws_secretsmanager_secret.laravel.arn
    audio_extraction       = aws_secretsmanager_secret.audio_extraction.arn
    transcription          = aws_secretsmanager_secret.transcription.arn
    music_term_recognition = aws_secretsmanager_secret.music_term_recognition.arn
  }
}