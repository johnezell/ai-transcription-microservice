# TFS AI Infrastructure - Monitoring and Alerts
# Provides CloudWatch alarms and SNS notifications

# =============================================================================
# SNS TOPICS
# =============================================================================

resource "aws_sns_topic" "alerts" {
  count = var.alarm_email != "" ? 1 : 0

  name = "${var.project_prefix}-alerts-${var.environment}"

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-alerts"
  })
}

resource "aws_sns_topic_subscription" "alerts_email" {
  count = var.alarm_email != "" ? 1 : 0

  topic_arn = aws_sns_topic.alerts[0].arn
  protocol  = "email"
  endpoint  = var.alarm_email
}

# =============================================================================
# CLOUDWATCH DASHBOARD
# =============================================================================

resource "aws_cloudwatch_dashboard" "ai_services" {
  dashboard_name = "${var.project_prefix}-${var.environment}"

  dashboard_body = jsonencode({
    widgets = [
      # GPU Metrics
      {
        type   = "metric"
        width  = 12
        height = 6
        properties = {
          metrics = [
            ["TFS-AI/GPU", "GPUUtilization", { stat = "Average" }],
            [".", "GPUMemoryUtilization", { stat = "Average" }],
            [".", "GPUTemperature", { stat = "Average", yAxis = "right" }]
          ]
          period = 300
          stat   = "Average"
          region = var.aws_region
          title  = "GPU Metrics"
        }
      },
      # ECS Service Metrics
      {
        type   = "metric"
        width  = 12
        height = 6
        properties = {
          metrics = [
            ["AWS/ECS", "CPUUtilization", "ServiceName", "transcription", "ClusterName", var.ecs_cluster_name],
            [".", "MemoryUtilization", ".", ".", ".", "."],
            [".", "CPUUtilization", ".", "audio-extraction", ".", "."],
            [".", "MemoryUtilization", ".", ".", ".", "."]
          ]
          period = 300
          stat   = "Average"
          region = var.aws_region
          title  = "ECS Service Utilization"
        }
      },
      # SQS Queue Metrics
      {
        type   = "metric"
        width  = 12
        height = 6
        properties = {
          metrics = [
            ["AWS/SQS", "ApproximateNumberOfMessagesVisible", "QueueName", "${var.project_prefix}-audio-uploads"],
            [".", "ApproximateAgeOfOldestMessage", ".", ".", { yAxis = "right" }]
          ]
          period = 300
          stat   = "Average"
          region = var.aws_region
          title  = "Audio Processing Queue"
        }
      },
      # S3 Request Metrics
      {
        type   = "metric"
        width  = 12
        height = 6
        properties = {
          metrics = [
            ["AWS/S3", "NumberOfObjects", "BucketName", aws_s3_bucket.audio_input.id, "StorageType", "AllStorageTypes"],
            [".", "BucketSizeBytes", ".", ".", ".", ".", { yAxis = "right" }]
          ]
          period = 86400
          stat   = "Average"
          region = var.aws_region
          title  = "S3 Storage Metrics"
        }
      }
    ]
  })
}

# =============================================================================
# BUDGET ALERTS
# =============================================================================

resource "aws_budgets_budget" "ai_services" {
  name         = "${var.project_prefix}-budget-${var.environment}"
  budget_type  = "COST"
  limit_amount = tostring(var.budget_amount)
  limit_unit   = "USD"
  time_unit    = "MONTHLY"

  cost_filter {
    name = "TagKeyValue"
    values = [
      "Environment$${var.environment}",
      "Project$${var.project_prefix}"
    ]
  }

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = var.budget_alert_threshold
    threshold_type             = "PERCENTAGE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = var.alarm_email != "" ? [var.alarm_email] : []
  }

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 100
    threshold_type             = "PERCENTAGE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = var.alarm_email != "" ? [var.alarm_email] : []
  }
}

# =============================================================================
# COMPOSITE ALARMS
# =============================================================================

# High severity alarm for service health
resource "aws_cloudwatch_composite_alarm" "service_health" {
  count = var.alarm_email != "" ? 1 : 0

  alarm_name        = "${var.project_prefix}-service-health-critical"
  alarm_description = "Critical service health alarm"
  actions_enabled   = true
  alarm_actions     = [aws_sns_topic.alerts[0].arn]

  alarm_rule = join(" OR ", [
    "ALARM(${aws_cloudwatch_metric_alarm.gpu_high_utilization.alarm_name})",
    "ALARM(${aws_cloudwatch_metric_alarm.efs_burst_credits[0].alarm_name})"
  ])

  tags = var.common_tags
}

# =============================================================================
# LOG METRIC FILTERS
# =============================================================================

# Error rate monitoring
resource "aws_cloudwatch_log_metric_filter" "transcription_errors" {
  name           = "${var.project_prefix}-transcription-errors"
  log_group_name = aws_cloudwatch_log_group.ecs_containers.name
  pattern        = "[time, request_id, level=ERROR, ...]"

  metric_transformation {
    name      = "TranscriptionErrors"
    namespace = "${var.project_prefix}/Application"
    value     = "1"
  }
}

resource "aws_cloudwatch_metric_alarm" "transcription_error_rate" {
  count               = var.alarm_email != "" ? 1 : 0
  alarm_name          = "${var.project_prefix}-high-transcription-error-rate"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "2"
  metric_name         = "TranscriptionErrors"
  namespace           = "${var.project_prefix}/Application"
  period              = "300"
  statistic           = "Sum"
  threshold           = "10"
  alarm_description   = "High transcription error rate detected"
  alarm_actions       = [aws_sns_topic.alerts[0].arn]
  treat_missing_data  = "notBreaching"
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "sns_alerts_topic_arn" {
  description = "ARN of the SNS alerts topic"
  value       = var.alarm_email != "" ? aws_sns_topic.alerts[0].arn : ""
}

output "cloudwatch_dashboard_url" {
  description = "URL to the CloudWatch dashboard"
  value       = "https://console.aws.amazon.com/cloudwatch/home?region=${var.aws_region}#dashboards:name=${aws_cloudwatch_dashboard.ai_services.dashboard_name}"
}