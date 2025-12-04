# AI Transcription Microservice ECS Implementation Plan

## Overview
This plan outlines the migration of the ai-transcription-microservice from Docker Compose to AWS ECS using EC2 instances with GPU support for optimal Whisper model performance.

## Architecture Components

### 1. **Service Architecture**
- **Laravel Web/API Service** (Fargate)
  - No GPU requirements
  - Handles UI and job orchestration
  - SQLite database (migrate to RDS for production)
  
- **Audio Extraction Service** (Fargate)
  - CPU-based FFmpeg processing
  - No GPU requirements
  
- **Transcription Service** (EC2 with GPU)
  - Requires GPU for Whisper model
  - PyTorch with CUDA support
  - High memory requirements
  
- **Music Term Recognition Service** (EC2 or Fargate)
  - spaCy NLP processing
  - Can benefit from GPU but not required

### 2. **Infrastructure Requirements**

#### GPU-Enabled EC2 Instances
- Instance types: g4dn.xlarge or g4dn.2xlarge for cost efficiency
- AMI: Deep Learning AMI with CUDA support
- Auto-scaling based on queue depth

#### Shared Storage
- EFS for shared file storage (`/var/www/storage/app/public/s3`)
- S3 for long-term storage and archival

#### Networking
- Service discovery for inter-service communication
- Application Load Balancer for Laravel service

## Implementation Steps

### Phase 1: Terraform Infrastructure Setup

1. **Create GPU-enabled ECS capacity provider**
   - Add new capacity provider for GPU instances
   - Configure launch template with GPU instance types
   - Set up auto-scaling group with proper scaling policies

2. **Update ECS task definitions**
   - Laravel service (Fargate)
   - Audio extraction service (Fargate)
   - Transcription service (EC2 + GPU)
   - Music term recognition service (EC2/Fargate)

3. **Configure shared storage**
   - Create EFS file system for shared data
   - Configure mount points for all services
   - Set up access points with proper permissions

4. **Set up service discovery**
   - Create Cloud Map namespace
   - Register services for internal DNS resolution

### Phase 2: Application Configuration

1. **Environment variables**
   - Update service URLs to use service discovery
   - Configure AWS credentials for services
   - Set up database connection (migrate from SQLite)

2. **Container images**
   - Build and push images to ECR
   - Optimize Dockerfiles for ECS deployment
   - Add GPU support to transcription service image

3. **Queue configuration**
   - Set up SQS for job queuing (replace Laravel database queue)
   - Configure dead letter queues
   - Implement visibility timeout for long-running jobs

### Phase 3: GPU Optimization

1. **CUDA configuration**
   - Ensure proper CUDA version compatibility
   - Configure GPU memory allocation
   - Set up model caching for efficiency

2. **Auto-scaling policies**
   - Scale based on SQS queue depth
   - Configure instance warm-up time
   - Set appropriate cooldown periods

3. **Cost optimization**
   - Use spot instances where possible
   - Implement job batching
   - Configure instance hibernation

### Phase 4: Monitoring and Observability

1. **CloudWatch integration**
   - GPU utilization metrics
   - Queue depth monitoring
   - Service health checks

2. **Application logging**
   - Centralized logging to CloudWatch
   - Structured logging for better searchability
   - Error tracking and alerting

3. **Performance monitoring**
   - Transcription job duration tracking
   - Success/failure rates
   - Resource utilization patterns

## Multi-Account Architecture

### New AWS Account Setup
- **Account Name**: tfs-ai-services
- **Purpose**: Isolated environment for AI/ML workloads
- **Benefits**:
  - Clear cost attribution
  - Security isolation
  - Independent service quotas
  - Simplified compliance

### Cross-Account Access
1. **Shared Resources**:
   - ECR repositories (cross-account pull)
   - S3 buckets (cross-account access)
   - Secrets Manager (cross-account read)

2. **Networking**:
   - VPC peering or Transit Gateway
   - PrivateLink endpoints where applicable
   - Security group rules for cross-account

## File Structure Updates

```
tfs-terraform/
├── terraform/
│   ├── 24-compute-ai-transcription.tf      # New file for AI services
│   ├── 25-compute-gpu-capacity.tf          # GPU capacity provider
│   └── 35-storage-ai-efs.tf                # EFS for shared storage
├── modules/
│   └── ai-transcription/
│       ├── main.tf
│       ├── variables.tf
│       ├── outputs.tf
│       └── task-definitions/
│           ├── laravel.json
│           ├── audio-extraction.json
│           ├── transcription.json
│           └── music-term.json
└── config/
    └── ai-transcription/
        ├── shared.env
        └── service-specific.env
```

## Security Considerations

1. **Network isolation**
   - Place GPU instances in private subnets
   - Use security groups for service-to-service communication
   - Enable VPC flow logs

2. **Secrets management**
   - Store API keys in AWS Secrets Manager
   - Use IAM roles for AWS service access
   - Rotate credentials regularly

3. **Data protection**
   - Encrypt EFS at rest
   - Use SSL/TLS for inter-service communication
   - Implement data retention policies

## Migration Strategy

1. **Parallel deployment**
   - Deploy to staging environment first
   - Run both systems in parallel initially
   - Gradual traffic migration

2. **Data migration**
   - Migrate SQLite to RDS
   - Copy existing files to EFS
   - Maintain backward compatibility

3. **Rollback plan**
   - Keep Docker Compose setup operational
   - Document rollback procedures
   - Test rollback scenarios

## Cost Estimates

- **GPU instances**: ~$0.526/hour for g4dn.xlarge
- **EFS storage**: ~$0.30/GB/month
- **Data transfer**: Minimal between services in same AZ
- **Estimated monthly cost**: $200-400 depending on usage

## Success Criteria

1. All services running on ECS
2. GPU utilization > 70% during transcription
3. Job processing time comparable or better
4. Zero data loss during migration
5. Monitoring and alerting functional

This plan provides a comprehensive approach to migrating the AI transcription microservice to ECS with proper GPU support while maintaining reliability and cost efficiency.