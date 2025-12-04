# TFS AI Infrastructure - Provider Configuration

# =============================================================================
# PROVIDERS
# =============================================================================

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = var.common_tags
  }

  # Assume role configuration can be added here if needed for cross-account access
}

provider "random" {
  # Used for generating unique suffixes and passwords
}