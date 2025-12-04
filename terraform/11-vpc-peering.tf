# TFS AI Infrastructure - VPC Peering Configuration
# Enables connectivity between AI VPC and main TFS VPC

# =============================================================================
# VARIABLES
# =============================================================================

variable "enable_vpc_peering" {
  description = "Enable VPC peering with main TFS account"
  type        = bool
  default     = false
}

variable "peer_vpc_id" {
  description = "VPC ID in main TFS account to peer with"
  type        = string
  default     = ""
}

variable "peer_vpc_cidr" {
  description = "CIDR block of peer VPC"
  type        = string
  default     = ""
}

variable "peer_account_id" {
  description = "AWS account ID of peer VPC"
  type        = string
  default     = ""
}

variable "peer_region" {
  description = "AWS region of peer VPC"
  type        = string
  default     = "us-east-1"
}

# =============================================================================
# VPC PEERING CONNECTION
# =============================================================================

resource "aws_vpc_peering_connection" "main" {
  count = var.enable_vpc_peering && var.peer_vpc_id != "" ? 1 : 0

  vpc_id        = var.vpc_id != "" ? var.vpc_id : aws_vpc.main[0].id
  peer_vpc_id   = var.peer_vpc_id
  peer_owner_id = var.peer_account_id
  peer_region   = var.peer_region
  auto_accept   = false # Requires manual acceptance in peer account

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-to-main-tfs-peering"
    Side = "requester"
  })
}

# =============================================================================
# ROUTES FOR PEERING
# =============================================================================

# Add route to peer VPC in public route table
resource "aws_route" "public_to_peer" {
  count = var.enable_vpc_peering && var.peer_vpc_id != "" ? 1 : 0

  route_table_id            = aws_route_table.public[0].id
  destination_cidr_block    = var.peer_vpc_cidr
  vpc_peering_connection_id = aws_vpc_peering_connection.main[0].id

  depends_on = [aws_vpc_peering_connection.main]
}

# Add route to peer VPC in private route tables
resource "aws_route" "private_to_peer" {
  count = var.enable_vpc_peering && var.peer_vpc_id != "" ? length(var.availability_zones) : 0

  route_table_id            = aws_route_table.private[count.index].id
  destination_cidr_block    = var.peer_vpc_cidr
  vpc_peering_connection_id = aws_vpc_peering_connection.main[0].id

  depends_on = [aws_vpc_peering_connection.main]
}

# Add route to peer VPC in database route table
resource "aws_route" "database_to_peer" {
  count = var.enable_vpc_peering && var.peer_vpc_id != "" ? 1 : 0

  route_table_id            = aws_route_table.database[0].id
  destination_cidr_block    = var.peer_vpc_cidr
  vpc_peering_connection_id = aws_vpc_peering_connection.main[0].id

  depends_on = [aws_vpc_peering_connection.main]
}

# =============================================================================
# SECURITY GROUP RULES FOR PEERING
# =============================================================================

# Allow traffic from peer VPC
resource "aws_security_group_rule" "allow_from_peer" {
  count = var.enable_vpc_peering && var.peer_vpc_cidr != "" ? 1 : 0

  type              = "ingress"
  from_port         = 0
  to_port           = 65535
  protocol          = "tcp"
  cidr_blocks       = [var.peer_vpc_cidr]
  security_group_id = aws_security_group.vpc_endpoints[0].id
  description       = "Allow traffic from peer VPC"
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "vpc_peering_connection_id" {
  description = "ID of the VPC peering connection"
  value       = var.enable_vpc_peering && var.peer_vpc_id != "" ? aws_vpc_peering_connection.main[0].id : null
}

output "vpc_peering_status" {
  description = "Status of the VPC peering connection"
  value       = var.enable_vpc_peering && var.peer_vpc_id != "" ? aws_vpc_peering_connection.main[0].accept_status : null
}

# =============================================================================
# INSTRUCTIONS FOR PEER ACCOUNT
# =============================================================================

# Output instructions for accepting peering in the peer account
output "peering_accept_instructions" {
  description = "Instructions for accepting VPC peering in peer account"
  value       = var.enable_vpc_peering && var.peer_vpc_id != "" ? "To complete VPC peering setup:\n\n1. In the peer account (${var.peer_account_id}), accept the peering connection:\n   - Connection ID: ${aws_vpc_peering_connection.main[0].id}\n\n2. Add routes in the peer VPC route tables:\n   - Destination: ${var.vpc_cidr}\n   - Target: ${aws_vpc_peering_connection.main[0].id}\n\n3. Update security groups in peer VPC to allow traffic from ${var.vpc_cidr}" : null
}