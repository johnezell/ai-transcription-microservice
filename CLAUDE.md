# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with the AI Transcription Microservice infrastructure.

## 🔥 CRITICAL REMINDERS

**TERRAFORM BEST PRACTICES**
- ✅ **ALWAYS** use Make commands (which use Docker internally)
- ✅ **ALWAYS** use AWS profiles, never hardcode credentials
- ✅ **ALWAYS** use environment-based configurations
- ✅ **ALWAYS** ensure consistent terraform version via Docker
- ❌ **NEVER** commit sensitive data or credentials
- ❌ **NEVER** use direct terraform commands outside of Make/Docker

## Project Overview

The AI Transcription Microservice (Thoth) is a GPU-accelerated transcription system using:
- **Whisper AI** for speech-to-text conversion
- **spaCy** for NLP and term recognition
- **Laravel** for API and job orchestration
- **ECS with GPU** for scalable ML workload processing

**Domain**: thoth.tfs.services (production) / thoth-staging.tfs.services (staging)

## Architecture Decisions

### Multi-Account Strategy
- **Separate AWS Account**: tfs-ai-services
- **Rationale**: 
  - Isolated billing for ML workloads
  - Independent service quotas for GPU instances
  - Security boundary for AI services
  - Simplified cost attribution

### Service Deployment Strategy
- **Laravel/API**: ECS Fargate (no GPU needed)
- **Audio Extraction**: ECS Fargate (CPU-only FFmpeg)
- **Transcription**: ECS EC2 with GPU (g4dn instances)
- **Music Term Recognition**: ECS EC2 or Fargate (flexible)

### Storage Architecture
- **EFS**: Shared storage for active job files
- **S3**: Long-term storage and archival
- **RDS**: Production database (replace SQLite)

## Terraform Structure

```
ai-transcription-microservice/
├── .env.example              # Template for environment configuration
├── .env.staging             # Staging environment config (git-ignored)
├── .env.production          # Production environment config (git-ignored)
├── .gitignore              # Ensures .env files are not committed
├── terraform/
│   ├── environments/       # Terraform variable files
│   │   ├── dev.tfvars
│   │   ├── staging.tfvars
│   │   └── production.tfvars
│   ├── modules/
│   │   ├── ecs-gpu-cluster/
│   │   ├── ai-services/
│   │   └── shared-storage/
│   ├── 00-backend.tf
│   ├── 01-providers.tf
│   ├── 02-variables.tf
│   ├── 03-locals.tf
│   ├── 10-networking.tf
│   ├── 20-compute-ecs.tf
│   ├── 21-compute-gpu.tf
│   ├── 30-storage.tf
│   ├── 40-iam.tf
│   ├── 50-monitoring.tf
│   └── 99-outputs.tf
├── scripts/
│   └── load-env.sh         # Helper to load .env files
├── Makefile
└── terraform-docker.sh
```

## Development Commands

### Terraform Operations

Primary commands using Make (recommended):
```bash
# Show help menu
make help

# Initialize terraform
make init

# Plan changes for an environment
make plan ENV=staging
make plan ENV=production

# Apply changes
make apply ENV=staging

# Target specific resources
make plan-target ENV=staging TARGET=aws_ecs_service.transcription

# Destroy resources (requires confirmation)
make destroy ENV=staging

# Utility commands
make fmt        # Format terraform files
make validate   # Validate configuration
make clean      # Clean up .terraform and plan files

# Service management
make start-services ENV=staging     # Start all ECS services
make stop-services ENV=staging      # Stop all ECS services
make restart-service ENV=staging SERVICE=transcription

# Monitoring
make logs ENV=staging SERVICE=transcription
make gpu-status ENV=staging
```

The Makefile internally uses Docker for Terraform consistency:
```makefile
# Example Makefile snippet
TERRAFORM_VERSION := 1.7.0
AWS_PROFILE_STAGING := tfs-ai-staging
AWS_PROFILE_PROD := tfs-ai-production

plan: check-env
	@echo "Planning infrastructure for $(ENV)..."
	@docker run --rm -it \
		-v $(PWD)/terraform:/terraform \
		-v ~/.aws:/root/.aws:ro \
		-w /terraform \
		-e AWS_PROFILE=$(AWS_PROFILE_$(shell echo $(ENV) | tr a-z A-Z)) \
		hashicorp/terraform:$(TERRAFORM_VERSION) \
		plan -var-file=environments/$(ENV).tfvars -out=tfplan-$(ENV).out
```

### Environment Configuration

**Setting up environment files:**
```bash
# Copy the example file
cp .env.example .env.staging

# Edit with your values
vim .env.staging

# Load environment variables
source scripts/load-env.sh staging
```

**Key configuration items:**
- **AWS_ACCOUNT_ID**: Your dedicated AI services AWS account
- **VPC_ID**: Leave empty to create new VPC, or use existing from main account
- **GPU_INSTANCE_TYPE**: g4dn.xlarge for dev/staging, g4dn.2xlarge for production
- **SPOT_ENABLED**: Use spot instances to reduce GPU costs by ~70%

### AWS Profile Configuration

**Available AWS Profiles:**
- **tfs-ai-staging**: AI services account (087439708020) - staging environment
- **tfs-ai-production**: AI services account (087439708020) - production environment  
- **tfs-shared-services**: Shared services account (542876199144) - admin access

```bash
# Configure AI services profile
aws configure --profile tfs-ai-staging
aws configure --profile tfs-ai-production

# Set default profile for session
export AWS_PROFILE=tfs-ai-staging

# Or use the load-env.sh script which sets this automatically
source scripts/load-env.sh staging

# To access shared services account
export AWS_PROFILE=tfs-shared-services
```

**Account Information:**
- **AI Services Account**: 087439708020 (dedicated for AI/ML workloads)
- **Shared Services Account**: 542876199144 (main TFS infrastructure)
- **Region**: us-east-1

## GPU Instance Guidelines

### Instance Selection
- **Development**: g4dn.xlarge (1 GPU, 4 vCPU, 16 GB RAM)
- **Production**: g4dn.2xlarge (1 GPU, 8 vCPU, 32 GB RAM)
- **High Load**: g4dn.4xlarge (1 GPU, 16 vCPU, 64 GB RAM)

### Cost Optimization
- Use Spot instances for non-critical workloads
- Implement aggressive auto-scaling policies
- Schedule scale-down during off-hours
- Cache Whisper models in EFS to reduce startup time

## Security Requirements

### Network Security
- All services in private subnets
- ALB in public subnets with WAF
- Security groups with least privilege
- VPC flow logs enabled

### Data Security
- EFS encryption at rest
- S3 bucket encryption and versioning
- Secrets in AWS Secrets Manager
- IAM roles for service authentication

### Compliance
- GDPR considerations for transcription data
- Data retention policies
- Audit logging for all API access
- Regular security scanning

## Monitoring and Alerting

### Key Metrics
- GPU utilization (target: 70-80%)
- Queue depth (SQS)
- Job processing time
- Error rates
- Cost per transcription

### CloudWatch Alarms
- High GPU utilization (>90%)
- Queue backlog (>100 jobs)
- Failed jobs (>5% error rate)
- Instance health checks
- Budget alerts

## Cross-Account Access

**Account Structure:**
- **AI Services Account (087439708020)**: Hosts GPU workloads and AI infrastructure
- **Shared Services Account (542876199144)**: Contains shared resources like ECR, Route53

### Required Permissions
```hcl
# ECR Pull (from shared account 542876199144)
ecr:GetAuthorizationToken
ecr:BatchCheckLayerAvailability
ecr:GetDownloadUrlForLayer
ecr:BatchGetImage

# S3 Cross-Account (if needed)
s3:GetObject
s3:PutObject
s3:ListBucket

# Secrets Manager (cross-account)
secretsmanager:GetSecretValue

# VPC Peering
ec2:AcceptVpcPeeringConnection
ec2:CreateRoute
ec2:DescribeVpcs
ec2:DescribeVpcPeeringConnections
```

### Cross-Account Role Setup
```bash
# In shared account (542876199144), create role that trusts AI account:
aws iam create-role --role-name tfs-ai-cross-account-access \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": {
        "AWS": "arn:aws:iam::087439708020:root"
      },
      "Action": "sts:AssumeRole"
    }]
  }' --profile tfs-shared-services
```

## CI/CD Integration

### GitHub Actions
- Use OIDC for AWS authentication
- Separate deployment workflows per environment
- Manual approval for production
- Automated testing before deployment

### Deployment Process
1. Build and push Docker images to ECR
2. Update task definitions
3. Deploy with blue/green strategy
4. Run health checks
5. Rollback on failure

## Troubleshooting

### Common Issues
1. **GPU not detected**: Check CUDA version compatibility
2. **Out of memory**: Adjust model size or instance type
3. **Slow startup**: Pre-pull images, cache models
4. **Network timeouts**: Check security groups and NACLs

### Debug Commands
```bash
# Check GPU availability
nvidia-smi

# ECS Exec into container
aws ecs execute-command --cluster ai-cluster --task <task-id> --container transcription --interactive --command "/bin/bash"

# View CloudWatch logs
aws logs tail /ecs/ai-transcription --follow
```

## Best Practices

1. **Always use versioned task definitions**
2. **Tag all resources consistently**
3. **Use CloudWatch Logs Insights for debugging**
4. **Monitor costs daily during development**
5. **Test auto-scaling policies thoroughly**
6. **Document any manual configurations**
7. **Regular backup of EFS data**
8. **Implement circuit breakers for external APIs**

## Important Notes

- GPU instances take 5-10 minutes to provision
- Whisper model loading can take 2-3 minutes
- EFS performance scales with size (provision enough)
- Use gp3 EBS volumes for better cost/performance
- Regular AMI updates for security patches

## Contact

For questions about this infrastructure:
- Slack: #tfs-infrastructure
- Email: infrastructure@truefitestudios.com