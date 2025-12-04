# TFS AI Infrastructure - Bastion Host with Auto Scaling Group
# Provides secure SSH access to private resources with automatic key management

# =============================================================================
# DATA SOURCES
# =============================================================================

# Get the latest Amazon Linux 2023 AMI
data "aws_ami" "amazon_linux_2023" {
  most_recent = true
  owners      = ["amazon"]

  filter {
    name   = "name"
    values = ["al2023-ami-*-x86_64"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

# =============================================================================
# VARIABLES (Additional ASG-specific variables)
# =============================================================================

variable "bastion_asg_min_size" {
  description = "Minimum number of bastion instances"
  type        = number
  default     = 1
}

variable "bastion_asg_max_size" {
  description = "Maximum number of bastion instances"
  type        = number
  default     = 2
}

variable "bastion_asg_desired_capacity" {
  description = "Desired number of bastion instances"
  type        = number
  default     = 1
}

# =============================================================================
# SECURITY GROUP
# =============================================================================

resource "aws_security_group" "bastion" {
  name        = "${var.project_prefix}-bastion-sg-${var.environment}"
  description = "Security group for bastion host"
  vpc_id      = var.vpc_id != "" ? var.vpc_id : aws_vpc.main[0].id

  # SSH access from allowed IPs
  ingress {
    description = "SSH from allowed IPs"
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = var.bastion_allowed_cidrs
  }

  # Allow all outbound traffic
  egress {
    description = "Allow all outbound"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-bastion-sg"
    Type = "bastion"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# IAM ROLE AND INSTANCE PROFILE
# =============================================================================

resource "aws_iam_role" "bastion" {
  name = "${var.project_prefix}-bastion-role-${var.environment}"

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

# Attach SSM policy for Session Manager access (alternative to SSH)
resource "aws_iam_role_policy_attachment" "bastion_ssm" {
  role       = aws_iam_role.bastion.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

# Policy for CloudWatch logs
resource "aws_iam_role_policy" "bastion_logs" {
  name = "bastion-cloudwatch-logs"
  role = aws_iam_role.bastion.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "logs:CreateLogGroup",
          "logs:CreateLogStream",
          "logs:PutLogEvents",
          "logs:DescribeLogStreams"
        ]
        Resource = "arn:aws:logs:*:*:*"
      }
    ]
  })
}

# Policy for EIP association (allows bastion to associate its own EIP on boot)
resource "aws_iam_role_policy" "bastion_eip" {
  count = var.create_bastion_static_ip ? 1 : 0
  name  = "bastion-eip-association"
  role  = aws_iam_role.bastion.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "ec2:AssociateAddress",
          "ec2:DescribeAddresses",
          "ec2:DescribeInstances"
        ]
        Resource = "*"
      }
    ]
  })
}

# Policy for ECR access (for Docker image pulls)
resource "aws_iam_role_policy" "bastion_ecr" {
  name = "bastion-ecr-access"
  role = aws_iam_role.bastion.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "ecr:GetAuthorizationToken"
        ]
        Resource = "*"
      },
      {
        Effect = "Allow"
        Action = [
          "ecr:BatchCheckLayerAvailability",
          "ecr:GetDownloadUrlForLayer",
          "ecr:BatchGetImage",
          "ecr:DescribeRepositories",
          "ecr:ListImages",
          "ecr:InitiateLayerUpload",
          "ecr:UploadLayerPart",
          "ecr:CompleteLayerUpload",
          "ecr:PutImage"
        ]
        Resource = "arn:aws:ecr:${var.aws_region}:${var.aws_account_id}:repository/${var.project_prefix}/*"
      }
    ]
  })
}

# Policy for ECS management (for deployment operations)
resource "aws_iam_role_policy" "bastion_ecs" {
  name = "bastion-ecs-management"
  role = aws_iam_role.bastion.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "ecs:DescribeClusters",
          "ecs:DescribeServices",
          "ecs:DescribeTasks",
          "ecs:ListTasks",
          "ecs:ListServices",
          "ecs:UpdateService",
          "ecs:ExecuteCommand"
        ]
        Resource = "*"
      }
    ]
  })
}

resource "aws_iam_instance_profile" "bastion" {
  name = "${var.project_prefix}-bastion-profile-${var.environment}"
  role = aws_iam_role.bastion.name
}

# =============================================================================
# SSH KEY PAIR
# =============================================================================

resource "aws_key_pair" "bastion" {
  key_name   = "${var.project_prefix}-bastion-${var.environment}"
  public_key = var.bastion_public_key

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-bastion-key"
  })
}

# =============================================================================
# CLOUDWATCH LOG GROUP
# =============================================================================

resource "aws_cloudwatch_log_group" "bastion" {
  name              = "/aws/ec2/bastion/${var.project_prefix}"
  retention_in_days = var.cloudwatch_retention_days

  tags = var.common_tags
}

# =============================================================================
# ELASTIC IP (FOR STATIC ACCESS - OPTIONAL)
# =============================================================================

resource "aws_eip" "bastion" {
  count  = var.create_bastion_static_ip ? 1 : 0
  domain = "vpc"

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-bastion-eip"
  })
}

# =============================================================================
# LAUNCH TEMPLATE
# =============================================================================

resource "aws_launch_template" "bastion" {
  name                   = "${var.project_prefix}-bastion-${var.environment}"
  description            = "Launch template for bastion hosts with SSH key injection"
  update_default_version = true

  # Use the same AMI as before
  image_id      = data.aws_ami.amazon_linux_2023.id
  instance_type = var.bastion_instance_type
  key_name      = aws_key_pair.bastion.key_name

  # IAM instance profile
  iam_instance_profile {
    name = aws_iam_instance_profile.bastion.name
  }

  # Security group
  vpc_security_group_ids = [aws_security_group.bastion.id]

  # Enable detailed monitoring
  monitoring {
    enabled = true
  }

  # Use gp3 for better cost/performance - sized for development workloads
  block_device_mappings {
    device_name = "/dev/xvda"

    ebs {
      volume_type           = "gp3"
      volume_size           = var.bastion_volume_size
      iops                  = 3000
      throughput            = 125
      delete_on_termination = true
      encrypted             = true
    }
  }

  # User data with SSH key injection and EIP association
  user_data = base64encode(templatefile("${path.module}/templates/bastion-user-data.sh", {
    environment         = var.environment
    region              = var.aws_region
    project             = var.project_prefix
    additional_ssh_keys = join("\n", var.additional_ssh_keys)
    eip_allocation_id   = var.create_bastion_static_ip ? aws_eip.bastion[0].id : ""
    enable_dev_tools    = true
  }))

  # Metadata options
  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required" # IMDSv2 only
    http_put_response_hop_limit = 1
    instance_metadata_tags      = "enabled"
  }

  # Tags for instances launched from this template
  tag_specifications {
    resource_type = "instance"

    tags = merge(var.common_tags, {
      Name = "${var.project_prefix}-bastion-asg"
      Type = "bastion"
    })
  }

  tag_specifications {
    resource_type = "volume"

    tags = merge(var.common_tags, {
      Name = "${var.project_prefix}-bastion-asg-root"
    })
  }

  tag_specifications {
    resource_type = "network-interface"

    tags = merge(var.common_tags, {
      Name = "${var.project_prefix}-bastion-asg-eni"
    })
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-bastion-launch-template"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# AUTO SCALING GROUP
# =============================================================================

resource "aws_autoscaling_group" "bastion" {
  name                = "${var.project_prefix}-bastion-asg-${var.environment}"
  vpc_zone_identifier = length(var.public_subnet_ids) > 0 ? var.public_subnet_ids : aws_subnet.public[*].id

  min_size         = var.bastion_asg_min_size
  max_size         = var.bastion_asg_max_size
  desired_capacity = var.bastion_asg_desired_capacity

  # Health check settings
  health_check_type         = "EC2"
  health_check_grace_period = 300

  # Launch template
  launch_template {
    id      = aws_launch_template.bastion.id
    version = "$Latest"
  }

  # Instance refresh settings for automatic updates
  instance_refresh {
    strategy = "Rolling"
    preferences {
      min_healthy_percentage = 50
    }
    # Launch template changes automatically trigger refresh
  }

  # Enable metrics collection
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
      Name = "${var.project_prefix}-bastion-asg"
      Type = "bastion-asg"
    })

    content {
      key                 = tag.key
      value               = tag.value
      propagate_at_launch = true
    }
  }

  lifecycle {
    create_before_destroy = true
    ignore_changes        = [desired_capacity]
  }
}

# =============================================================================
# AUTO SCALING POLICIES
# =============================================================================

# Scale up policy
resource "aws_autoscaling_policy" "bastion_scale_up" {
  name                   = "${var.project_prefix}-bastion-scale-up"
  scaling_adjustment     = 1
  adjustment_type        = "ChangeInCapacity"
  cooldown               = 300
  autoscaling_group_name = aws_autoscaling_group.bastion.name
}

# Scale down policy
resource "aws_autoscaling_policy" "bastion_scale_down" {
  name                   = "${var.project_prefix}-bastion-scale-down"
  scaling_adjustment     = -1
  adjustment_type        = "ChangeInCapacity"
  cooldown               = 300
  autoscaling_group_name = aws_autoscaling_group.bastion.name
}

# =============================================================================
# CLOUDWATCH ALARMS
# =============================================================================

# High CPU alarm
resource "aws_cloudwatch_metric_alarm" "bastion_cpu_high" {
  alarm_name          = "${var.project_prefix}-bastion-cpu-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "2"
  metric_name         = "CPUUtilization"
  namespace           = "AWS/EC2"
  period              = "300"
  statistic           = "Average"
  threshold           = "80"
  alarm_description   = "This metric monitors bastion cpu utilization"
  alarm_actions       = [aws_autoscaling_policy.bastion_scale_up.arn]

  dimensions = {
    AutoScalingGroupName = aws_autoscaling_group.bastion.name
  }
}

# Low CPU alarm
resource "aws_cloudwatch_metric_alarm" "bastion_cpu_low" {
  alarm_name          = "${var.project_prefix}-bastion-cpu-low"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = "2"
  metric_name         = "CPUUtilization"
  namespace           = "AWS/EC2"
  period              = "300"
  statistic           = "Average"
  threshold           = "20"
  alarm_description   = "This metric monitors bastion cpu utilization"
  alarm_actions       = [aws_autoscaling_policy.bastion_scale_down.arn]

  dimensions = {
    AutoScalingGroupName = aws_autoscaling_group.bastion.name
  }
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "bastion_launch_template_id" {
  description = "ID of the bastion launch template"
  value       = aws_launch_template.bastion.id
}

output "bastion_launch_template_version" {
  description = "Latest version of the bastion launch template"
  value       = aws_launch_template.bastion.latest_version
}

output "bastion_asg_name" {
  description = "Name of the bastion Auto Scaling Group"
  value       = aws_autoscaling_group.bastion.name
}

output "bastion_asg_id" {
  description = "ID of the bastion Auto Scaling Group"
  value       = aws_autoscaling_group.bastion.id
}

output "bastion_security_group_id" {
  description = "Security group ID of the bastion host"
  value       = aws_security_group.bastion.id
}

output "bastion_static_ip" {
  description = "Static Elastic IP for bastion (if enabled)"
  value       = var.create_bastion_static_ip ? aws_eip.bastion[0].public_ip : null
}

output "bastion_ssh_instructions" {
  description = "Instructions for connecting to bastion instances"
  value = var.create_bastion_static_ip ? join("\n", [
    "To connect to bastion (Static IP):",
    "1. SSH: ssh -i ~/.ssh/tfs-ai-bastion ec2-user@${aws_eip.bastion[0].public_ip}",
    "",
    "For Cursor Remote SSH, add to ~/.ssh/config:",
    "Host thoth-dev",
    "    HostName ${aws_eip.bastion[0].public_ip}",
    "    User ec2-user",
    "    IdentityFile ~/.ssh/tfs-ai-bastion",
    "    ForwardAgent yes"
    ]) : join("\n", [
    "To connect to bastion instances:",
    "1. Get instance IPs: aws ec2 describe-instances --filters \"Name=tag:Name,Values=${var.project_prefix}-bastion-asg\" --query \"Reservations[].Instances[].PublicIpAddress\" --profile tfs-ai-terraform",
    "2. SSH: ssh -i ~/.ssh/tfs-ai-bastion ec2-user@<instance-ip>",
    "3. Or use Session Manager: aws ssm start-session --target <instance-id> --profile tfs-ai-terraform"
  ])
}

# =============================================================================
# NOTES
# =============================================================================

# The ASG will automatically inject additional SSH keys into new instances
# Update the 'additional_ssh_keys' variable in tfvars to add more keys