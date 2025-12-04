# Bastion Host Setup Guide

This guide walks through setting up and accessing the bastion host for the TFS AI infrastructure.

## Prerequisites

1. AWS CLI configured with the `tfs-ai-staging` profile
2. Docker installed on your local machine
3. Terraform 1.7.0 (handled automatically via Docker)

## Step 1: Create Backend Resources

First, create the S3 bucket and DynamoDB table for Terraform state:

```bash
# Run from project root
./scripts/create-backend.sh staging
```

This creates:
- S3 bucket: `tfs-ai-terraform-state-087439708020`
- DynamoDB table: `tfs-ai-terraform-locks`

## Step 2: Initialize Terraform

```bash
# Set AWS profile
export AWS_PROFILE=tfs-ai-staging

# Initialize terraform with backend
make init
cd terraform
../terraform-docker.sh init -backend-config=backend-staging.hcl
cd ..
```

## Step 3: Update Security Group

**IMPORTANT**: Update the `bastion_allowed_cidrs` in `terraform/environments/staging.tfvars` with your public IP address:

```hcl
# Replace 0.0.0.0/0 with your IP
bastion_allowed_cidrs = [
  "YOUR.IP.ADDRESS.HERE/32"
]
```

To find your public IP:
```bash
curl -s https://api.ipify.org
```

## Step 4: Plan Infrastructure

```bash
# Plan the bastion infrastructure
make plan ENV=staging
```

Review the plan output. It should show:
- 1 VPC to be created
- 3 public subnets
- 3 private subnets  
- 3 database subnets
- 1 Internet Gateway
- 3 NAT Gateways
- Route tables
- Security groups
- 1 Bastion EC2 instance
- 1 Elastic IP
- SSL certificate and Route53 records

## Step 5: Apply Infrastructure

```bash
# Apply the infrastructure
make apply ENV=staging
```

This will take about 5-10 minutes to complete.

## Step 6: Get Bastion IP

After the apply completes:

```bash
# Get the bastion public IP
cd terraform
../terraform-docker.sh output bastion_public_ip
cd ..

# Or use the domain name (after DNS propagates)
# bastion.thoth-staging.tfs.services
```

## Step 7: SSH to Bastion

```bash
# Using IP address
ssh -i keys/bastion-staging ec2-user@<BASTION_IP>

# Using domain (after DNS propagates)
ssh -i keys/bastion-staging ec2-user@bastion.thoth-staging.tfs.services

# Or use the Makefile command
make ssh-bastion ENV=staging
```

## SSH Configuration

Add this to your `~/.ssh/config` for easier access:

```
Host thoth-bastion-staging
    HostName bastion.thoth-staging.tfs.services
    User ec2-user
    IdentityFile ~/code/TFS/ai-transcription-microservice/keys/bastion-staging
    StrictHostKeyChecking no
    UserKnownHostsFile /dev/null
```

Then you can simply:
```bash
ssh thoth-bastion-staging
```

## Bastion Features

Once connected, the bastion includes:
- AWS CLI pre-configured
- Docker installed
- Common networking tools (nmap, dig, tcpdump)
- Custom aliases for ECS and GPU management
- CloudWatch agent for monitoring

## Useful Commands on Bastion

```bash
# Check GPU status on ECS instances (when deployed)
gpu-status

# Connect to ECS container (when deployed)
ecs-exec <cluster> <service> [container]

# View AWS logs
awslog /aws/ecs/cluster-name
```

## Troubleshooting

### Cannot connect to bastion
1. Check your IP is whitelisted in `bastion_allowed_cidrs`
2. Verify the bastion is running: `make show ENV=staging`
3. Check security group rules in AWS console

### Permission denied (publickey)
1. Ensure you're using the correct key: `keys/bastion-staging`
2. Check key permissions: `chmod 600 keys/bastion-staging`

### DNS not resolving
1. DNS propagation can take up to 5 minutes
2. Use the IP address directly while waiting

## Security Notes

- The bastion is in a public subnet but only allows SSH from whitelisted IPs
- All SSH sessions are logged to CloudWatch
- The bastion can access private subnets for managing internal resources
- Remember to restrict `bastion_allowed_cidrs` to only necessary IPs

## Next Steps

With the bastion running, you can:
1. Access private resources in the VPC
2. Manage ECS tasks and services
3. Debug networking issues
4. Run administrative commands

The bastion serves as your secure entry point to the AI infrastructure.