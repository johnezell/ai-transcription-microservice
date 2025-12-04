# Cross-Account Route53 Setup

This document explains how to set up cross-account access for Route53 DNS management.

## Overview

The Route53 hosted zone (tfs.services) is managed in the shared services account (542876199144), but we need to create DNS records from the AI services account (087439708020).

## Required Setup in Shared Services Account

### 1. Create IAM Policy for Route53 Access

Run this command using the `tfs-shared-services` profile:

```bash
aws iam create-policy \
  --policy-name tfs-ai-route53-access \
  --policy-document '{
    "Version": "2012-10-17",
    "Statement": [
      {
        "Effect": "Allow",
        "Action": [
          "route53:ChangeResourceRecordSets",
          "route53:ListResourceRecordSets",
          "route53:GetHostedZone",
          "route53:GetChange"
        ],
        "Resource": [
          "arn:aws:route53:::hostedzone/Z07716653GDXJUDL4P879",
          "arn:aws:route53:::change/*"
        ]
      }
    ]
  }' \
  --profile tfs-shared-services
```

### 2. Attach Policy to Cross-Account Role

```bash
aws iam attach-role-policy \
  --role-name tfs-ai-cross-account-access \
  --policy-arn arn:aws:iam::542876199144:policy/tfs-ai-route53-access \
  --profile tfs-shared-services
```

### 3. Update Trust Relationship (if not already done)

Ensure the role trusts the AI services account:

```bash
aws iam update-assume-role-policy \
  --role-name tfs-ai-cross-account-access \
  --policy-document '{
    "Version": "2012-10-17",
    "Statement": [
      {
        "Effect": "Allow",
        "Principal": {
          "AWS": "arn:aws:iam::087439708020:root"
        },
        "Action": "sts:AssumeRole",
        "Condition": {}
      }
    ]
  }' \
  --profile tfs-shared-services
```

## Required Setup in AI Services Account

### 1. Create IAM Policy to Assume Cross-Account Role

```bash
aws iam create-policy \
  --policy-name assume-shared-services-role \
  --policy-document '{
    "Version": "2012-10-17",
    "Statement": [
      {
        "Effect": "Allow",
        "Action": "sts:AssumeRole",
        "Resource": "arn:aws:iam::542876199144:role/tfs-ai-cross-account-access"
      }
    ]
  }' \
  --profile tfs-ai-staging
```

### 2. Attach Policy to Terraform Execution Role

Attach this policy to whatever role/user is running Terraform.

## Terraform Provider Configuration

To use cross-account access in Terraform, configure a provider alias:

```hcl
# Provider for cross-account Route53 access
provider "aws" {
  alias  = "shared_services"
  region = var.aws_region

  assume_role {
    role_arn     = "arn:aws:iam::542876199144:role/tfs-ai-cross-account-access"
    session_name = "terraform-route53-access"
  }
}

# Use in resources like this:
resource "aws_route53_record" "example" {
  provider = aws.shared_services
  zone_id  = var.route53_zone_id
  # ... rest of configuration
}
```

## Domain Structure

- **Production**: thoth.tfs.services
- **Staging**: thoth-staging.tfs.services
- **Bastion**: bastion.thoth-staging.tfs.services

## Testing Access

Test cross-account access:

```bash
# From AI account, assume role in shared account
aws sts assume-role \
  --role-arn arn:aws:iam::542876199144:role/tfs-ai-cross-account-access \
  --role-session-name test-route53-access \
  --profile tfs-ai-staging

# Use the temporary credentials to list records
aws route53 list-resource-record-sets \
  --hosted-zone-id Z07716653GDXJUDL4P879 \
  --profile <use-temp-creds>
```