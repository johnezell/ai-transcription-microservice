# TFS AI Infrastructure - GPU Capacity Provider
# Manages GPU-enabled EC2 instances for AI workloads

# =============================================================================
# DATA SOURCES
# =============================================================================

# Get the latest ECS-optimized AMI with GPU support
data "aws_ami" "ecs_gpu" {
  most_recent = true
  owners      = ["amazon"]

  filter {
    name   = "name"
    values = ["amzn2-ami-ecs-gpu-hvm-*-x86_64-ebs"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

# =============================================================================
# LAUNCH TEMPLATE FOR GPU INSTANCES
# =============================================================================

resource "aws_launch_template" "gpu_instances" {
  name        = "${var.project_prefix}-gpu-${var.environment}"
  description = "Launch template for GPU-enabled ECS instances"

  # AMI and instance configuration
  image_id      = data.aws_ami.ecs_gpu.id
  instance_type = var.gpu_instance_type

  # IAM instance profile
  iam_instance_profile {
    name = aws_iam_instance_profile.ecs_gpu.name
  }

  # Key pair for SSH access (through bastion)
  key_name = aws_key_pair.bastion.key_name

  # Security group
  vpc_security_group_ids = [aws_security_group.ecs_gpu.id]

  # Enable detailed monitoring
  monitoring {
    enabled = true
  }

  # Metadata options (IMDSv2)
  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 2
    instance_metadata_tags      = "enabled"
  }

  # Block device mappings
  block_device_mappings {
    device_name = "/dev/xvda"

    ebs {
      volume_type           = "gp3"
      volume_size           = 100 # Larger for model storage
      iops                  = 3000
      throughput            = 125
      delete_on_termination = true
      encrypted             = true
    }
  }

  # User data to join ECS cluster and install GPU drivers
  user_data = base64encode(templatefile("${path.module}/templates/gpu-user-data.sh", {
    ecs_cluster_name = aws_ecs_cluster.ai_cluster.name
    region           = var.aws_region
    efs_id           = aws_efs_file_system.shared_storage.id
  }))

  # Spot instance configuration
  dynamic "instance_market_options" {
    for_each = var.spot_enabled ? [1] : []

    content {
      market_type = "spot"

      spot_options {
        max_price                      = var.spot_max_price
        spot_instance_type             = "one-time"
        instance_interruption_behavior = "terminate"
      }
    }
  }

  tag_specifications {
    resource_type = "instance"

    tags = merge(var.common_tags, {
      Name = "${var.project_prefix}-gpu-ecs"
      Type = "gpu-compute"
    })
  }

  tag_specifications {
    resource_type = "volume"

    tags = merge(var.common_tags, {
      Name = "${var.project_prefix}-gpu-ecs-root"
    })
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-gpu-launch-template"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# AUTO SCALING GROUP FOR GPU INSTANCES
# =============================================================================

resource "aws_autoscaling_group" "gpu_instances" {
  name                = "${var.project_prefix}-gpu-asg-${var.environment}"
  vpc_zone_identifier = length(var.private_subnet_ids) > 0 ? var.private_subnet_ids : aws_subnet.private[*].id

  min_size         = var.gpu_min_instances
  max_size         = var.gpu_max_instances
  desired_capacity = var.gpu_desired_instances

  # Health check settings
  health_check_type         = "EC2"
  health_check_grace_period = 600 # 10 minutes for GPU instances to initialize

  # Launch template
  launch_template {
    id      = aws_launch_template.gpu_instances.id
    version = "$Latest"
  }

  # Note: Warm pools are not supported with spot instances

  # Instance refresh settings
  instance_refresh {
    strategy = "Rolling"
    preferences {
      min_healthy_percentage = 50
      instance_warmup        = 300
    }
    # Launch template changes automatically trigger refresh
  }

  # Enable metrics
  enabled_metrics = [
    "GroupMinSize",
    "GroupMaxSize",
    "GroupDesiredCapacity",
    "GroupInServiceInstances",
    "GroupTotalInstances"
  ]

  # Tags
  dynamic "tag" {
    for_each = merge(var.common_tags, {
      Name        = "${var.project_prefix}-gpu-asg"
      Type        = "gpu-compute"
      ECSCluster  = aws_ecs_cluster.ai_cluster.name
      SpotEnabled = tostring(var.spot_enabled)
    })

    content {
      key                 = tag.key
      value               = tag.value
      propagate_at_launch = true
    }
  }

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# ECS CAPACITY PROVIDER
# =============================================================================

resource "aws_ecs_capacity_provider" "gpu" {
  name = "${var.project_prefix}-gpu-capacity"

  auto_scaling_group_provider {
    auto_scaling_group_arn         = aws_autoscaling_group.gpu_instances.arn
    managed_termination_protection = "DISABLED"

    managed_scaling {
      maximum_scaling_step_size = 2
      minimum_scaling_step_size = 1
      status                    = "ENABLED"
      target_capacity           = 80 # Target 80% utilization

      # Scale in more slowly to avoid disrupting long-running jobs
      instance_warmup_period = 300
    }
  }

  tags = var.common_tags
}

# =============================================================================
# SECURITY GROUP FOR GPU INSTANCES
# =============================================================================

resource "aws_security_group" "ecs_gpu" {
  name        = "${var.project_prefix}-ecs-gpu-sg-${var.environment}"
  description = "Security group for GPU-enabled ECS instances"
  vpc_id      = local.vpc_id

  # Allow all traffic from within the VPC
  ingress {
    description = "All traffic from VPC"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = [var.vpc_cidr]
  }

  # SSH from bastion
  ingress {
    description     = "SSH from bastion"
    from_port       = 22
    to_port         = 22
    protocol        = "tcp"
    security_groups = [aws_security_group.bastion.id]
  }

  # Allow all outbound traffic
  egress {
    description = "All outbound traffic"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-ecs-gpu-sg"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# IAM ROLE FOR GPU INSTANCES
# =============================================================================

resource "aws_iam_role" "ecs_gpu_instance" {
  name = "${var.project_prefix}-ecs-gpu-instance-role-${var.environment}"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "ec2.amazonaws.com"
        }
      }
    ]
  })

  tags = var.common_tags
}

# Attach policies for ECS
resource "aws_iam_role_policy_attachment" "ecs_gpu_instance" {
  role       = aws_iam_role.ecs_gpu_instance.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonEC2ContainerServiceforEC2Role"
}

# Attach SSM policy for management
resource "aws_iam_role_policy_attachment" "ecs_gpu_ssm" {
  role       = aws_iam_role.ecs_gpu_instance.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

# Policy for CloudWatch
resource "aws_iam_role_policy" "ecs_gpu_cloudwatch" {
  name = "cloudwatch-access"
  role = aws_iam_role.ecs_gpu_instance.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "cloudwatch:PutMetricData",
          "logs:CreateLogGroup",
          "logs:CreateLogStream",
          "logs:PutLogEvents",
          "logs:DescribeLogStreams"
        ]
        Resource = "*"
      }
    ]
  })
}

# Instance profile
resource "aws_iam_instance_profile" "ecs_gpu" {
  name = "${var.project_prefix}-ecs-gpu-profile-${var.environment}"
  role = aws_iam_role.ecs_gpu_instance.name
}

# =============================================================================
# AUTO SCALING POLICIES
# =============================================================================

# Target tracking for GPU utilization
resource "aws_autoscaling_policy" "gpu_utilization" {
  name                   = "${var.project_prefix}-gpu-utilization"
  autoscaling_group_name = aws_autoscaling_group.gpu_instances.name
  policy_type            = "TargetTrackingScaling"

  target_tracking_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ASGAverageCPUUtilization"
    }
    target_value = 70.0
  }
}

# Scale up based on ECS capacity
resource "aws_autoscaling_policy" "gpu_scale_up" {
  name                   = "${var.project_prefix}-gpu-scale-up"
  scaling_adjustment     = 1
  adjustment_type        = "ChangeInCapacity"
  cooldown               = 300
  autoscaling_group_name = aws_autoscaling_group.gpu_instances.name
}

# Scale down policy
resource "aws_autoscaling_policy" "gpu_scale_down" {
  name                   = "${var.project_prefix}-gpu-scale-down"
  scaling_adjustment     = -1
  adjustment_type        = "ChangeInCapacity"
  cooldown               = 600 # Slower scale down
  autoscaling_group_name = aws_autoscaling_group.gpu_instances.name
}

# =============================================================================
# CLOUDWATCH ALARMS
# =============================================================================

# High GPU utilization alarm
resource "aws_cloudwatch_metric_alarm" "gpu_high_utilization" {
  alarm_name          = "${var.project_prefix}-gpu-high-utilization"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "2"
  metric_name         = "GPUUtilization"
  namespace           = "AWS/ECS"
  period              = "300"
  statistic           = "Average"
  threshold           = "90"
  alarm_description   = "GPU utilization is too high"
  alarm_actions       = [aws_autoscaling_policy.gpu_scale_up.arn]

  dimensions = {
    AutoScalingGroupName = aws_autoscaling_group.gpu_instances.name
  }
}

# Low GPU utilization alarm
resource "aws_cloudwatch_metric_alarm" "gpu_low_utilization" {
  alarm_name          = "${var.project_prefix}-gpu-low-utilization"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = "3"
  metric_name         = "GPUUtilization"
  namespace           = "AWS/ECS"
  period              = "300"
  statistic           = "Average"
  threshold           = "20"
  alarm_description   = "GPU utilization is low"
  alarm_actions       = [aws_autoscaling_policy.gpu_scale_down.arn]

  dimensions = {
    AutoScalingGroupName = aws_autoscaling_group.gpu_instances.name
  }
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "gpu_capacity_provider_name" {
  description = "Name of the GPU capacity provider"
  value       = aws_ecs_capacity_provider.gpu.name
}

output "gpu_asg_name" {
  description = "Name of the GPU Auto Scaling Group"
  value       = aws_autoscaling_group.gpu_instances.name
}

output "gpu_launch_template_id" {
  description = "ID of the GPU launch template"
  value       = aws_launch_template.gpu_instances.id
}

output "gpu_security_group_id" {
  description = "Security group ID for GPU instances"
  value       = aws_security_group.ecs_gpu.id
}