# TFS AI Infrastructure - Local Values

locals {
  # Construct name prefix from project and environment
  name_prefix = "${var.project_prefix}-${var.environment}"

  # Common resource tags
  resource_tags = merge(var.common_tags, {
    Environment = var.environment
    Project     = var.project_prefix
    ManagedBy   = "terraform"
    AccountId   = var.aws_account_id
    Region      = var.aws_region
  })

  # Subnet IDs based on whether using existing VPC or creating new
  public_subnet_ids  = var.vpc_id != "" ? var.public_subnet_ids : aws_subnet.public[*].id
  private_subnet_ids = var.vpc_id != "" ? var.private_subnet_ids : aws_subnet.private[*].id

  # VPC ID
  vpc_id = var.vpc_id != "" ? var.vpc_id : aws_vpc.main[0].id

  # Service names
  services = {
    laravel          = "laravel"
    audio_extraction = "audio-extraction"
    transcription    = "transcription"
    music_term       = "music-term-recognition"
  }

  # Container ports
  container_ports = {
    laravel          = 80
    audio_extraction = 5000
    transcription    = 5000
    music_term       = 5000
  }

  # ECR repository URLs (to be created or referenced)
  ecr_repositories = {
    laravel          = "${var.aws_account_id}.dkr.ecr.${var.aws_region}.amazonaws.com/${local.name_prefix}-laravel"
    audio_extraction = "${var.aws_account_id}.dkr.ecr.${var.aws_region}.amazonaws.com/${local.name_prefix}-audio-extraction"
    transcription    = "${var.aws_account_id}.dkr.ecr.${var.aws_region}.amazonaws.com/${local.name_prefix}-transcription"
    music_term       = "${var.aws_account_id}.dkr.ecr.${var.aws_region}.amazonaws.com/${local.name_prefix}-music-term"
  }
}