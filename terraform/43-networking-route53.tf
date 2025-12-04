# TFS AI Infrastructure - Route53 DNS Configuration
# Manages DNS records in shared services account

# =============================================================================
# VARIABLES
# =============================================================================

variable "domain_name" {
  description = "Domain name for the service (e.g., thoth-staging.tfs.services)"
  type        = string
}

# =============================================================================
# DATA SOURCES
# =============================================================================

# Get the hosted zone from shared services account
# TEMPORARILY COMMENTED - Requires cross-account role setup
# data "aws_route53_zone" "main" {
#   zone_id = var.route53_zone_id
#
#   # Note: This requires cross-account permissions to read the zone
#   # The AI account needs permission to create records in this zone
# }

# =============================================================================
# SSL CERTIFICATE
# =============================================================================

# Create ACM certificate in the AI account
resource "aws_acm_certificate" "main" {
  domain_name       = var.domain_name
  validation_method = "DNS"

  # Also create certificate for www subdomain
  subject_alternative_names = [
    "www.${var.domain_name}"
  ]

  lifecycle {
    create_before_destroy = true
  }

  tags = merge(var.common_tags, {
    Name = "${var.project_prefix}-acm-certificate"
  })
}

# =============================================================================
# CERTIFICATE VALIDATION
# =============================================================================

# Create validation records in Route53 (cross-account)
# TEMPORARILY COMMENTED - Requires cross-account role setup
# resource "aws_route53_record" "cert_validation" {
#   for_each = {
#     for dvo in aws_acm_certificate.main.domain_validation_options : dvo.domain_name => {
#       name   = dvo.resource_record_name
#       record = dvo.resource_record_value
#       type   = dvo.resource_record_type
#     }
#   }
#
#   zone_id = data.aws_route53_zone.main.zone_id
#   name    = each.value.name
#   type    = each.value.type
#   records = [each.value.record]
#   ttl     = 60
#
#   # Note: Creating records in cross-account zone requires proper IAM permissions
#   # The role in shared account must allow this AI account to create records
# }

# Wait for certificate validation
# TEMPORARILY COMMENTED - Requires cross-account role setup
# resource "aws_acm_certificate_validation" "main" {
#   certificate_arn         = aws_acm_certificate.main.arn
#   validation_record_fqdns = [for record in aws_route53_record.cert_validation : record.fqdn]
# }

# =============================================================================
# DNS RECORDS FOR SERVICES
# =============================================================================

# A record for ALB (will be created later)
# resource "aws_route53_record" "alb" {
#   count = var.create_alb ? 1 : 0

#   zone_id = data.aws_route53_zone.main.zone_id
#   name    = var.domain_name
#   type    = "A"

#   alias {
#     name                   = aws_lb.main[0].dns_name
#     zone_id                = aws_lb.main[0].zone_id
#     evaluate_target_health = true
#   }
# }

# # www redirect to main domain
# resource "aws_route53_record" "www" {
#   count = var.create_alb ? 1 : 0

#   zone_id = data.aws_route53_zone.main.zone_id
#   name    = "www.${var.domain_name}"
#   type    = "A"

#   alias {
#     name                   = aws_lb.main[0].dns_name
#     zone_id                = aws_lb.main[0].zone_id
#     evaluate_target_health = true
#   }
# }

# Bastion host DNS record
# TEMPORARILY COMMENTED - Requires cross-account role setup
# resource "aws_route53_record" "bastion" {
#   zone_id = data.aws_route53_zone.main.zone_id
#   name    = "bastion.${var.domain_name}"
#   type    = "A"
#   ttl     = 300
#   records = [aws_eip.bastion.public_ip]
# }


# =============================================================================
# OUTPUTS
# =============================================================================

output "acm_certificate_arn" {
  description = "ARN of the ACM certificate"
  value       = aws_acm_certificate.main.arn
}

output "certificate_validation_status" {
  description = "Status of certificate validation"
  value       = aws_acm_certificate.main.status
}

output "domain_name" {
  description = "Domain name for the service"
  value       = var.domain_name
}

output "bastion_fqdn" {
  description = "Fully qualified domain name for bastion host"
  value       = "bastion.${var.domain_name}"
}


# =============================================================================
# NOTES
# =============================================================================

# Cross-account Route53 permissions needed in shared account:
# {
#   "Version": "2012-10-17",
#   "Statement": [
#     {
#       "Effect": "Allow",
#       "Principal": {
#         "AWS": "arn:aws:iam::087439708020:root"
#       },
#       "Action": [
#         "route53:ChangeResourceRecordSets",
#         "route53:ListResourceRecordSets"
#       ],
#       "Resource": "arn:aws:route53:::hostedzone/Z07716653GDXJUDL4P879"
#     }
#   ]
# }