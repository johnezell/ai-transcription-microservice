# TFS AI Infrastructure - Bedrock IAM Permissions
# Provides access to AWS Bedrock for article generation using Claude

# =============================================================================
# BEDROCK ACCESS POLICY
# =============================================================================

# Policy for Bedrock model invocation
resource "aws_iam_role_policy" "ecs_bedrock_access" {
  name = "bedrock-access"
  role = aws_iam_role.ecs_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "BedrockModelInvocation"
        Effect = "Allow"
        Action = [
          "bedrock:InvokeModel",
          "bedrock:InvokeModelWithResponseStream"
        ]
        Resource = [
          # Claude 3 Sonnet
          "arn:aws:bedrock:${var.aws_region}::foundation-model/anthropic.claude-3-sonnet-20240229-v1:0",
          # Claude 3.5 Sonnet
          "arn:aws:bedrock:${var.aws_region}::foundation-model/anthropic.claude-3-5-sonnet-20240620-v1:0",
          # Claude 3 Haiku (for faster/cheaper operations)
          "arn:aws:bedrock:${var.aws_region}::foundation-model/anthropic.claude-3-haiku-20240307-v1:0",
          # Claude 3.5 Sonnet v2 (latest)
          "arn:aws:bedrock:${var.aws_region}::foundation-model/anthropic.claude-3-5-sonnet-20241022-v2:0"
        ]
      },
      {
        Sid    = "BedrockListModels"
        Effect = "Allow"
        Action = [
          "bedrock:ListFoundationModels",
          "bedrock:GetFoundationModel"
        ]
        Resource = "*"
      }
    ]
  })
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "bedrock_models_available" {
  description = "Bedrock models available for article generation"
  value = [
    "anthropic.claude-3-sonnet-20240229-v1:0",
    "anthropic.claude-3-5-sonnet-20240620-v1:0",
    "anthropic.claude-3-haiku-20240307-v1:0",
    "anthropic.claude-3-5-sonnet-20241022-v2:0"
  ]
}


