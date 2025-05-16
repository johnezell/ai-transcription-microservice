# AWS Transcription Service - User Manual

## Table of Contents
1. [Architecture Overview](#architecture-overview)
2. [Deployment Guide](#deployment-guide)
3. [Usage Instructions](#usage-instructions)
4. [Monitoring & Scaling](#monitoring--scaling)
5. [Troubleshooting](#troubleshooting)
6. [Cost Management](#cost-management)

## Architecture Overview

The AWS Transcription Service is a scalable microservices-based application designed to process large volumes of videos for transcription and terminology recognition. 

### Key Components

#### Infrastructure
- **VPC**: All services run in a private VPC with VPN access
- **ECS Cluster**: Hosts all containerized services using Fargate
- **Aurora Serverless v2**: MySQL 8.0 compatible database with termination protection
- **Custom Domain Names**: Consistent access via domains like `thoth.tfs.services` and `db-thoth.tfs.services`
- **S3 Bucket**: Stores videos, audio files, and transcripts
- **SQS Queues**: Message queues for asynchronous service communication
- **CloudWatch Dashboard**: Monitoring and alerting

#### Services
1. **Laravel Application**
   - Web UI and REST API
   - Job scheduling and management
   - Storage interfacing through S3
   - Accesses through a Network Load Balancer

2. **Audio Extraction Service**
   - Python Flask application
   - Extracts audio from videos using FFmpeg
   - Consumes messages from `audio-extraction-queue`
   - Sends callbacks to `callback-queue`

3. **Transcription Service**
   - Python Flask application with GPU support
   - Uses OpenAI Whisper model for transcription
   - Uses G4dn.xlarge EC2 instances for GPU acceleration
   - Consumes messages from `transcription-queue`
   - Sends callbacks to `callback-queue`

4. **Terminology Recognition Service**
   - Python Flask application
   - Analyzes transcripts for specialized terminology
   - Consumes messages from `terminology-queue`
   - Sends callbacks to `callback-queue`

### Communication Flow

1. User uploads video via Laravel application
2. Laravel stores video in S3 and sends message to `audio-extraction-queue`
3. Audio extraction service processes video, stores audio in S3, and sends message to `callback-queue`
4. Laravel processes callback and sends message to `transcription-queue`
5. Transcription service processes audio, stores transcript in S3, and sends message to `callback-queue`
6. Laravel processes callback and sends message to `terminology-queue`
7. Terminology service processes transcript, identifies terms, and sends message to `callback-queue`
8. Laravel processes final callback and updates the UI with results

## Deployment Guide

### Prerequisites

- AWS Account with appropriate permissions
- AWS CLI configured with profile `tfs-shared-services`
- Node.js version 20.x or higher
- Python 3.9 or higher
- AWS CDK installed globally (`npm install -g aws-cdk`)

### Deployment Steps

1. **Clone the repository**
   ```bash
   git clone https://github.com/your-org/aws-transcription-service.git
   cd aws-transcription-service
   ```

2. **Recommended: Use the deployment script**
   
   The easiest way to deploy the entire stack is using the provided deployment script:
   ```bash
   # Make the script executable (if needed)
   chmod +x ./scripts/deploy.sh
   
   # Run the deployment script (deploys all stacks by default)
   ./scripts/deploy.sh
   ```
   
   The script handles:
   - Building Laravel frontend assets
   - Setting up the Python virtual environment
   - Installing dependencies
   - Deploying all CDK stacks

   To deploy a specific stack, pass it as an argument:
   ```bash
   # Example: Deploy only the Laravel service
   ./scripts/deploy.sh LaravelServiceStack
   ```

3. **Alternative: Manual CDK deployment**
   
   If you prefer to run the deployment commands manually:
   
   ```bash
   # Set up CDK environment
   cd cdk-infra
   python -m venv .venv
   source .venv/bin/activate
   pip install -r requirements.txt
   
   # Bootstrap CDK (if not already done)
   AWS_PROFILE=tfs-shared-services cdk bootstrap
   
   # Deploy all stacks
   AWS_PROFILE=tfs-shared-services cdk deploy --all
   ```

4. **Update environment variables**
   
   After deployment, update the local `.env.docker-aws` file in the Laravel application with output values from CDK deployment:
   ```bash
   # Run the setup script to generate the environment file
   ./scripts/setup_local_env.sh
   ```

### Service-Specific Deployment

To deploy individual services with the deployment script:

```bash
# Deploy only the Laravel service
./scripts/deploy.sh LaravelServiceStack

# Deploy only the audio extraction service
./scripts/deploy.sh AudioExtractionServiceStack

# Deploy only the transcription service
./scripts/deploy.sh TranscriptionServiceStack

# Deploy only the terminology service
./scripts/deploy.sh TerminologyServiceStack

# Deploy only the monitoring stack
./scripts/deploy.sh MonitoringStack
```

Note: The database is now integrated into the main infrastructure stack (CdkInfraStack) with termination protection enabled.

## Usage Instructions

### Accessing the Application

1. Connect to the VPN
2. Access the Laravel application through the custom domain `thoth.tfs.services` or the NLB DNS name (available in CDK outputs)
   ```bash
   # Get the NLB DNS name
   AWS_PROFILE=tfs-shared-services cdk list-exports | grep LaravelNlbDnsName
   ```

### Uploading Videos

1. Log in to the Laravel application
2. Navigate to the "Upload Video" page
3. Select the video file and click "Upload"
4. The system will automatically process the video through the pipeline
5. Status updates appear in real-time on the video detail page

### Viewing Transcripts

1. Navigate to the "Videos" page
2. Click on a processed video to view details
3. The transcript is available in multiple formats:
   - Text format (plain text)
   - SRT format (for subtitles)
   - JSON format (for advanced processing)
4. Identified terminology is highlighted in the transcript

### Batch Processing

For processing large batches of videos (100+ videos):

1. Use the batch upload feature in the Laravel UI
2. Alternatively, place multiple videos in an S3 folder and use the "Process S3 Folder" feature
3. Monitor progress through the dashboard
4. The system will automatically scale to handle the load

## Monitoring & Scaling

### CloudWatch Dashboard

The system includes a custom CloudWatch dashboard displaying:

1. **Queue Metrics**: 
   - Queue depths for all services
   - Processing time per job
   - Average costs per job

2. **Service Metrics**:
   - Running task counts
   - CPU and memory utilization
   - Error rates

Access the dashboard:
```bash
# Get the dashboard URL
AWS_PROFILE=tfs-shared-services cdk list-exports | grep DashboardURL
```

### Auto-Scaling Configuration

All services auto-scale based on SQS queue depth:

1. **Audio Extraction Service**:
   - Min: 3 instances
   - Max: 25 instances
   - Scaling steps:
     - 1+ messages: +1 task
     - 10+ messages: +3 tasks
     - 50+ messages: +7 tasks
     - 100+ messages: +12 tasks
     - 200+ messages: +20 tasks

2. **Transcription Service**:
   - Min: 0 instances
   - Max: 10 instances
   - Uses G4dn.xlarge EC2 instances with NVIDIA T4 GPUs
   - Spot instances for cost savings (~70% cheaper than on-demand)
   - Scaling steps:
     - 0 messages: scale to 0
     - 1+ messages: +1 task
     - 10+ messages: +2 tasks
     - 20+ messages: +4 tasks

3. **Terminology Service**:
   - Min: 3 instances
   - Max: 20 instances
   - Scaling steps tailored to terminology processing needs

The system scales down automatically when queue depth decreases, maintaining minimum capacity at idle to control costs.

### Customizing Scaling

To modify scaling parameters:

1. Edit the respective service stack in `cdk-infra/cdk_infra/`
2. Update min/max capacity and scaling steps
3. Deploy the modified stack:
   ```bash
   AWS_PROFILE=tfs-shared-services cdk deploy [ServiceName]ServiceStack
   ```

## Troubleshooting

### Common Issues

#### 1. Failed Video Processing

**Symptoms**: Video status shows "Failed" in UI

**Troubleshooting steps**:
- Check CloudWatch logs for the specific service:
  ```bash
  # For audio extraction failures
  aws logs filter-log-events --log-group-name "/ecs/aws-transcription-audio-extraction" --filter-pattern "ERROR" --profile tfs-shared-services
  
  # For transcription failures
  aws logs filter-log-events --log-group-name "/ecs/aws-transcription-transcription" --filter-pattern "ERROR" --profile tfs-shared-services
  ```
- Verify video format is supported
- Check S3 permissions for the task role

#### 2. Service Not Scaling

**Symptoms**: Processing is slow, queues are growing

**Troubleshooting steps**:
- Check service health:
  ```bash
  aws ecs describe-services --cluster aws-transcription-cluster --services aws-transcription-transcription-service --profile tfs-shared-services
  ```
- Verify auto-scaling metrics:
  ```bash
  aws cloudwatch list-metrics --namespace AWS/ApplicationAutoScaling --profile tfs-shared-services
  ```
- Check for service errors preventing new tasks

#### 3. SQS Callback Issues

**Symptoms**: Jobs stuck in "Processing" state

**Troubleshooting steps**:
- Check the callback queue for depth:
  ```bash
  aws sqs get-queue-attributes --queue-url $(aws sqs get-queue-url --queue-name aws-transcription-callback-queue --profile tfs-shared-services --query 'QueueUrl' --output text) --attribute-names ApproximateNumberOfMessages --profile tfs-shared-services
  ```
- Verify Laravel is processing the callback queue:
  ```bash
  aws logs filter-log-events --log-group-name "/ecs/aws-transcription-laravel" --filter-pattern "ProcessCallbackQueueJob" --profile tfs-shared-services
  ```
- Check for Laravel callback processor errors

#### 4. Database Connectivity Issues

**Symptoms**: Laravel application shows database connectivity errors

**Troubleshooting steps**:
- Check connectivity using the direct RDS endpoint:
  ```bash
  # Get the RDS endpoint
  aws rds describe-db-clusters --db-cluster-identifier CdkInfraStack-AppDatabaseCluster --profile tfs-shared-services --query 'DBClusters[0].Endpoint'
  ```
- Try the custom domain: `db-thoth.tfs.services`
- Verify the security group allows traffic from the Laravel service
- Check credentials in Secrets Manager:
  ```bash
  aws secretsmanager get-secret-value --secret-id aws-transcription-db-credentials --profile tfs-shared-services
  ```

### Service Testing

Use these REST endpoints to test individual services:

#### Audio Extraction Service Health
```bash
curl http://audio-extraction-service.local:5000/health
```

#### Transcription Service Health
```bash
curl http://transcription-service.local:5000/health
```

#### Terminology Service Health
```bash
curl http://terminology-service.local:5000/health
```

### Manual SQS Testing

To manually test SQS queue functionality:

```bash
# Send a test message to the audio extraction queue
aws sqs send-message --queue-url $(aws sqs get-queue-url --queue-name aws-transcription-audio-extraction-queue --profile tfs-shared-services --query 'QueueUrl' --output text) --message-body '{"job_id": "test-123", "video_s3_key": "path/to/test/video.mp4"}' --profile tfs-shared-services
```

## Cost Management

The system includes cost tracking features that calculate expenses based on:

1. Base infrastructure costs:
   - Aurora Serverless database: ~$64.73/month (0.5-1.0 ACU range)
   - Other services (Load balancers, SQS, etc.): ~$66.77/month
   - Total base cost: ~$131.50/month

2. Variable costs per minute of video processed:
   - Fargate tasks (CPU, memory): ~$0.00074/minute
   - GPU spot instances (when used): ~$0.00246/minute
   - S3 storage: ~$0.00023/minute
   - Total variable cost: ~$0.00343/minute of video processed

This represents significant savings vs. SaaS alternatives:
- At 5,000 minutes/month: $0.02973/minute vs. $0.25-0.35/minute for SaaS options

### Viewing Cost Metrics

1. Access the CloudWatch dashboard
2. View the "Job Costs" widget showing:
   - Total cost per job
   - Breakdown of compute, storage, and network costs
   - Cost trends over time

### Cost Optimization Strategies

1. **Adjust scaling parameters**:
   - Modify the min/max capacity to match your usage patterns
   - Adjust scaling step thresholds based on observed queue behavior

2. **Optimize transcription performance**:
   - Use the appropriate Whisper model size for your needs
   - Consider pre-processing audio to reduce transcription time

3. **Implement S3 lifecycle policies**:
   - Configure rules to move older transcripts to cheaper storage tiers
   - Set up retention policies to delete temporary files

4. **Monitor the cost dashboard**:
   - Review job costs regularly
   - Identify outliers that might indicate inefficiencies

### Cost Limits

To prevent runaway costs when processing large batches:

1. Implement a maximum batch size in the Laravel UI
2. Set up CloudWatch Alarms to notify when costs exceed thresholds
3. Configure auto-scaling max-capacity limits conservatively

For more detailed cost control, refer to the AWS Cost Explorer for actual spending analysis.

---

## Additional Resources

- [AWS CDK Documentation](https://docs.aws.amazon.com/cdk/latest/guide/home.html)
- [Laravel Documentation](https://laravel.com/docs)
- [Whisper AI Documentation](https://github.com/openai/whisper)
- [AWS Fargate Documentation](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/AWS_Fargate.html)
- [AWS SQS Documentation](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/welcome.html) 