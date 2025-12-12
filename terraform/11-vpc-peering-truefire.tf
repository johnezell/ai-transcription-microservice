# TFS AI Infrastructure - VPC Peering to TrueFire Production
# Enables connectivity to TrueFire's production database for course data access

# =============================================================================
# VPC PEERING CONNECTION
# =============================================================================

# Cross-account VPC peering from Thoth (AI) to TrueFire Production
resource "aws_vpc_peering_connection" "truefire" {
  count = var.vpc_id == "" ? 1 : 0

  vpc_id        = aws_vpc.main[0].id
  peer_vpc_id   = var.truefire_vpc_id
  peer_owner_id = var.truefire_account_id
  peer_region   = var.aws_region

  auto_accept = false # Cross-account peering must be accepted on the peer side

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-to-truefire-peering"
    Side = "requester"
  })
}

# =============================================================================
# ROUTES TO TRUEFIRE VPC
# =============================================================================

# Route from private subnets to TrueFire VPC (for ECS tasks)
resource "aws_route" "private_to_truefire" {
  count = var.vpc_id == "" ? length(var.availability_zones) : 0

  route_table_id            = aws_route_table.private[count.index].id
  destination_cidr_block    = var.truefire_vpc_cidr
  vpc_peering_connection_id = aws_vpc_peering_connection.truefire[0].id
}

# Route from database subnets to TrueFire VPC (if needed for replication)
resource "aws_route" "database_to_truefire" {
  count = var.vpc_id == "" ? 1 : 0

  route_table_id            = aws_route_table.database[0].id
  destination_cidr_block    = var.truefire_vpc_cidr
  vpc_peering_connection_id = aws_vpc_peering_connection.truefire[0].id
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "vpc_peering_truefire_id" {
  description = "VPC peering connection ID to TrueFire"
  value       = var.vpc_id == "" ? aws_vpc_peering_connection.truefire[0].id : null
}

output "vpc_peering_truefire_status" {
  description = "VPC peering connection status"
  value       = var.vpc_id == "" ? aws_vpc_peering_connection.truefire[0].accept_status : null
}





