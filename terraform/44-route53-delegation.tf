# Route53 DNS Delegation Configuration
# Creates a delegated hosted zone in the AI account for thoth.tfs.services

# =============================================================================
# DELEGATED HOSTED ZONE (in AI account 087439708020)
# =============================================================================

# Create hosted zone for thoth.tfs.services in AI account
resource "aws_route53_zone" "thoth_subdomain" {
  name = "thoth.tfs.services"

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-subdomain-zone"
    Purpose = "Delegated zone for Thoth AI infrastructure"
  })
}

# =============================================================================
# ACM CERTIFICATE FOR thoth.tfs.services
# =============================================================================

# ACM certificate for thoth.tfs.services with wildcard
resource "aws_acm_certificate" "thoth_cert" {
  domain_name               = "thoth.tfs.services"
  subject_alternative_names = [
    "*.thoth.tfs.services"
  ]
  validation_method = "DNS"

  tags = merge(var.common_tags, {
    Name    = "${var.project_prefix}-thoth-cert"
    Purpose = "SSL certificate for Thoth AI infrastructure"
  })

  lifecycle {
    create_before_destroy = true
  }
}

# DNS validation records for ACM certificate (in Thoth zone)
resource "aws_route53_record" "thoth_cert_validation" {
  for_each = {
    for dvo in aws_acm_certificate.thoth_cert.domain_validation_options : dvo.domain_name => {
      name   = dvo.resource_record_name
      record = dvo.resource_record_value
      type   = dvo.resource_record_type
    }
  }

  zone_id         = aws_route53_zone.thoth_subdomain.zone_id
  name            = each.value.name
  type            = each.value.type
  records         = [each.value.record]
  ttl             = 60
  allow_overwrite = true
}

# Certificate validation
resource "aws_acm_certificate_validation" "thoth_cert" {
  certificate_arn         = aws_acm_certificate.thoth_cert.arn
  validation_record_fqdns = [for record in aws_route53_record.thoth_cert_validation : record.fqdn]
}

# =============================================================================
# DNS RECORDS IN THOTH ZONE
# =============================================================================

# A record for staging ALB
resource "aws_route53_record" "staging_alb" {
  count = var.create_alb && var.environment == "staging" ? 1 : 0

  zone_id = aws_route53_zone.thoth_subdomain.zone_id
  name    = "staging.thoth.tfs.services"
  type    = "A"

  alias {
    name                   = aws_lb.main[0].dns_name
    zone_id                = aws_lb.main[0].zone_id
    evaluate_target_health = true
  }
}

# Wildcard A record for staging (for future PR environments etc)
resource "aws_route53_record" "wildcard_staging" {
  count = var.create_alb && var.environment == "staging" ? 1 : 0

  zone_id = aws_route53_zone.thoth_subdomain.zone_id
  name    = "*.staging.thoth.tfs.services"
  type    = "A"

  alias {
    name                   = aws_lb.main[0].dns_name
    zone_id                = aws_lb.main[0].zone_id
    evaluate_target_health = true
  }
}

# Root domain points to staging for now
resource "aws_route53_record" "root" {
  count = var.create_alb && var.environment == "staging" ? 1 : 0

  zone_id = aws_route53_zone.thoth_subdomain.zone_id
  name    = "thoth.tfs.services"
  type    = "A"

  alias {
    name                   = aws_lb.main[0].dns_name
    zone_id                = aws_lb.main[0].zone_id
    evaluate_target_health = true
  }
}

# Local development record
resource "aws_route53_record" "local" {
  zone_id = aws_route53_zone.thoth_subdomain.zone_id
  name    = "local.thoth.tfs.services"
  type    = "A"
  ttl     = 300
  records = ["127.0.0.1"]
}

# =============================================================================
# OUTPUTS
# =============================================================================

output "thoth_zone_id" {
  description = "Zone ID for thoth.tfs.services"
  value       = aws_route53_zone.thoth_subdomain.zone_id
}

output "thoth_zone_nameservers" {
  description = "Nameservers for thoth.tfs.services - ADD THESE TO PARENT ZONE (tfs.services)"
  value       = aws_route53_zone.thoth_subdomain.name_servers
}

output "thoth_certificate_arn" {
  description = "ARN of the validated ACM certificate for thoth.tfs.services"
  value       = aws_acm_certificate_validation.thoth_cert.certificate_arn
}

output "thoth_certificate_status" {
  description = "Validation status of the ACM certificate"
  value       = aws_acm_certificate.thoth_cert.status
}

output "thoth_dns_records" {
  description = "DNS records created in thoth.tfs.services zone"
  value = {
    root    = var.create_alb && var.environment == "staging" ? try(aws_route53_record.root[0].fqdn, "") : ""
    staging = var.create_alb && var.environment == "staging" ? try(aws_route53_record.staging_alb[0].fqdn, "") : ""
    local   = aws_route53_record.local.fqdn
  }
}

