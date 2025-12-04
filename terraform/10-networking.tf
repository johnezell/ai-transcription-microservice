# TFS AI Infrastructure - Networking Configuration
# Creates VPC with non-conflicting CIDR for VPC peering

# =============================================================================
# VPC
# =============================================================================

# Create new VPC only if vpc_id is not provided
resource "aws_vpc" "main" {
  count = var.vpc_id == "" ? 1 : 0

  # Using 10.200.0.0/16 to avoid conflicts with common VPN ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
  # and typical VPC ranges (10.0.0.0/16, 10.1.0.0/16)
  cidr_block = var.vpc_cidr

  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-vpc"
  })
}

# =============================================================================
# INTERNET GATEWAY
# =============================================================================

resource "aws_internet_gateway" "main" {
  count  = var.vpc_id == "" ? 1 : 0
  vpc_id = aws_vpc.main[0].id

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-igw"
  })
}

# =============================================================================
# SUBNETS
# =============================================================================

# Public subnets for bastion, NAT gateways, and ALB
resource "aws_subnet" "public" {
  count = var.vpc_id == "" ? length(var.availability_zones) : 0

  vpc_id                  = aws_vpc.main[0].id
  cidr_block              = cidrsubnet(var.vpc_cidr, 8, count.index)
  availability_zone       = var.availability_zones[count.index]
  map_public_ip_on_launch = true

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-public-${var.availability_zones[count.index]}"
    Type = "public"
  })
}

# Private subnets for ECS tasks and RDS
resource "aws_subnet" "private" {
  count = var.vpc_id == "" ? length(var.availability_zones) : 0

  vpc_id            = aws_vpc.main[0].id
  cidr_block        = cidrsubnet(var.vpc_cidr, 8, count.index + 10)
  availability_zone = var.availability_zones[count.index]

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-private-${var.availability_zones[count.index]}"
    Type = "private"
  })
}

# Database subnets (more isolated)
resource "aws_subnet" "database" {
  count = var.vpc_id == "" ? length(var.availability_zones) : 0

  vpc_id            = aws_vpc.main[0].id
  cidr_block        = cidrsubnet(var.vpc_cidr, 8, count.index + 20)
  availability_zone = var.availability_zones[count.index]

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-database-${var.availability_zones[count.index]}"
    Type = "database"
  })
}

# =============================================================================
# NAT GATEWAYS
# =============================================================================

# Elastic IPs for NAT Gateways
resource "aws_eip" "nat" {
  count  = var.vpc_id == "" ? length(var.availability_zones) : 0
  domain = "vpc"

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-nat-eip-${var.availability_zones[count.index]}"
  })

  depends_on = [aws_internet_gateway.main]
}

# NAT Gateways for private subnet internet access
resource "aws_nat_gateway" "main" {
  count = var.vpc_id == "" ? length(var.availability_zones) : 0

  allocation_id = aws_eip.nat[count.index].id
  subnet_id     = aws_subnet.public[count.index].id

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-nat-${var.availability_zones[count.index]}"
  })

  depends_on = [aws_internet_gateway.main]
}

# =============================================================================
# ROUTE TABLES
# =============================================================================

# Public route table
resource "aws_route_table" "public" {
  count  = var.vpc_id == "" ? 1 : 0
  vpc_id = aws_vpc.main[0].id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.main[0].id
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-public-rt"
    Type = "public"
  })
}

# Private route tables (one per AZ for independent NAT gateways)
resource "aws_route_table" "private" {
  count  = var.vpc_id == "" ? length(var.availability_zones) : 0
  vpc_id = aws_vpc.main[0].id

  route {
    cidr_block     = "0.0.0.0/0"
    nat_gateway_id = aws_nat_gateway.main[count.index].id
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-private-rt-${var.availability_zones[count.index]}"
    Type = "private"
  })
}

# Database route table (no internet access)
resource "aws_route_table" "database" {
  count  = var.vpc_id == "" ? 1 : 0
  vpc_id = aws_vpc.main[0].id

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-database-rt"
    Type = "database"
  })
}

# =============================================================================
# ROUTE TABLE ASSOCIATIONS
# =============================================================================

# Associate public subnets with public route table
resource "aws_route_table_association" "public" {
  count = var.vpc_id == "" ? length(var.availability_zones) : 0

  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public[0].id
}

# Associate private subnets with private route tables
resource "aws_route_table_association" "private" {
  count = var.vpc_id == "" ? length(var.availability_zones) : 0

  subnet_id      = aws_subnet.private[count.index].id
  route_table_id = aws_route_table.private[count.index].id
}

# Associate database subnets with database route table
resource "aws_route_table_association" "database" {
  count = var.vpc_id == "" ? length(var.availability_zones) : 0

  subnet_id      = aws_subnet.database[count.index].id
  route_table_id = aws_route_table.database[0].id
}

# =============================================================================
# VPC ENDPOINTS (Cost Optimization)
# =============================================================================

# S3 VPC Endpoint (Gateway type - free)
resource "aws_vpc_endpoint" "s3" {
  count = var.vpc_id == "" ? 1 : 0

  vpc_id       = aws_vpc.main[0].id
  service_name = "com.amazonaws.${var.aws_region}.s3"

  route_table_ids = concat(
    [aws_route_table.public[0].id],
    aws_route_table.private[*].id,
    [aws_route_table.database[0].id]
  )

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-s3-endpoint"
  })
}

# ECR VPC Endpoints for pulling images without internet
resource "aws_vpc_endpoint" "ecr_api" {
  count = var.vpc_id == "" ? 1 : 0

  vpc_id              = aws_vpc.main[0].id
  service_name        = "com.amazonaws.${var.aws_region}.ecr.api"
  vpc_endpoint_type   = "Interface"
  subnet_ids          = aws_subnet.private[*].id
  security_group_ids  = [aws_security_group.vpc_endpoints[0].id]
  private_dns_enabled = true

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-ecr-api-endpoint"
  })
}

resource "aws_vpc_endpoint" "ecr_dkr" {
  count = var.vpc_id == "" ? 1 : 0

  vpc_id              = aws_vpc.main[0].id
  service_name        = "com.amazonaws.${var.aws_region}.ecr.dkr"
  vpc_endpoint_type   = "Interface"
  subnet_ids          = aws_subnet.private[*].id
  security_group_ids  = [aws_security_group.vpc_endpoints[0].id]
  private_dns_enabled = true

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-ecr-dkr-endpoint"
  })
}

# =============================================================================
# SECURITY GROUP FOR VPC ENDPOINTS
# =============================================================================

resource "aws_security_group" "vpc_endpoints" {
  count = var.vpc_id == "" ? 1 : 0

  name        = "${var.project_prefix}-vpc-endpoints-sg-${var.environment}"
  description = "Security group for VPC endpoints"
  vpc_id      = aws_vpc.main[0].id

  ingress {
    description = "HTTPS from VPC"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = [var.vpc_cidr]
  }

  egress {
    description = "Allow all outbound"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-vpc-endpoints-sg"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# =============================================================================
# VPC FLOW LOGS
# =============================================================================

resource "aws_flow_log" "main" {
  count = var.vpc_id == "" && var.enable_vpc_flow_logs ? 1 : 0

  iam_role_arn    = aws_iam_role.flow_logs[0].arn
  log_destination = aws_cloudwatch_log_group.flow_logs[0].arn
  traffic_type    = "ALL"
  vpc_id          = aws_vpc.main[0].id

  tags = var.common_tags
}

resource "aws_cloudwatch_log_group" "flow_logs" {
  count = var.vpc_id == "" && var.enable_vpc_flow_logs ? 1 : 0

  name              = "/aws/vpc/${var.project_prefix}"
  retention_in_days = var.cloudwatch_retention_days

  tags = var.common_tags
}

resource "aws_iam_role" "flow_logs" {
  count = var.vpc_id == "" && var.enable_vpc_flow_logs ? 1 : 0

  name = "${var.project_prefix}-flow-logs-role-${var.environment}"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "vpc-flow-logs.amazonaws.com"
        }
      }
    ]
  })

  tags = var.common_tags
}

resource "aws_iam_role_policy" "flow_logs" {
  count = var.vpc_id == "" && var.enable_vpc_flow_logs ? 1 : 0

  name = "flow-logs-policy"
  role = aws_iam_role.flow_logs[0].id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "logs:CreateLogGroup",
          "logs:CreateLogStream",
          "logs:PutLogEvents",
          "logs:DescribeLogGroups",
          "logs:DescribeLogStreams"
        ]
        Resource = "*"
      }
    ]
  })
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "vpc_id" {
  description = "ID of the VPC"
  value       = var.vpc_id != "" ? var.vpc_id : aws_vpc.main[0].id
}

output "vpc_cidr" {
  description = "CIDR block of the VPC"
  value       = var.vpc_id != "" ? data.aws_vpc.existing[0].cidr_block : aws_vpc.main[0].cidr_block
}

output "public_subnet_ids" {
  description = "IDs of public subnets"
  value       = var.vpc_id != "" ? var.public_subnet_ids : aws_subnet.public[*].id
}

output "private_subnet_ids" {
  description = "IDs of private subnets"
  value       = var.vpc_id != "" ? var.private_subnet_ids : aws_subnet.private[*].id
}

output "database_subnet_ids" {
  description = "IDs of database subnets"
  value       = aws_subnet.database[*].id
}

# Data source for existing VPC if provided
data "aws_vpc" "existing" {
  count = var.vpc_id != "" ? 1 : 0
  id    = var.vpc_id
}