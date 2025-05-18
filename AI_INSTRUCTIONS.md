# AI Assistant Instructions & Notes

This document contains key information and decisions to help the AI assistant maintain context across sessions for the AWS Transcription Service project.

## AWS Environment & CDK Project

*   **AWS Profile:** `tfs-shared-services`
*   **AWS Region:** `us-east-1`
*   **AWS Account ID:** `542876199144`
*   **CDK Project Language:** Python
*   **CDK Project Directory:** `cdk-infra` (relative to workspace root `/Users/john/code/aws-transcription-service-je`)
*   **CDK Virtual Env Activation:** `source .venv/bin/activate` (from within `cdk-infra`)
*   **CDK CLI Usage:** `npx aws-cdk <command>` (unless `aws-cdk` is installed globally)

## Key Architectural Decisions & Configurations

*   **Database:** Aurora Serverless v2 (MySQL 8.0 compatible). Credentials managed by AWS Secrets Manager. Successfully deployed and connection verified.
*   **Shared Storage:** Primary shared storage for videos, intermediate files, and final data will be Amazon S3. EFS will not be used for this purpose. S3 bucket deployed.
*   **Application Access (Laravel):** For the prototype, the Laravel application will NOT use an Application Load Balancer. Access will be via its private IP, accessible through the user's VPN connection.
*   **Transcription Service:** Will use a self-hosted Whisper AI container, not the AWS Transcribe service.
*   **Message Queuing:** SQS queues are used for inter-service communication, replacing direct HTTP calls to make the system more scalable and robust.
*   **Auto-Scaling:** All services scale based on SQS queue depth, with different configurations for each service to handle large-volume processing.
*   **Monitoring & Cost Analysis:** CloudWatch dashboard provides visibility into queue depths, service metrics, and per-job cost tracking.
*   **IAM Roles:**
    *   A common ECS Task Execution Role is used.
    *   A shared IAM Task Role is used for application services, granting S3 permissions (scoped to the application's data bucket).
    *   SQS permissions are added to each service's task role as needed.
*   **Networking:**
    *   Existing VPC is imported: `vpc-09422297ced61f9d2`
    *   VPN Access for User: Via IP `72.239.107.152/32` and/or IP `10.209.27.93/32` (from `truefire-vpn-sg` analysis) allowed in relevant SGs.
*   **Resource Naming:** Application prefix `aws-transcription` is used for many resources.
*   **Logging:** Explicit CloudWatch Log Groups are defined for each service with a default retention (e.g., ONE_MONTH).
*   **Prototype Cleanup:** Resources like ECR repositories, Log Groups, S3 Bucket, and the RDS cluster are configured with `RemovalPolicy.DESTROY` for easier cleanup. ECR repositories also use `auto_delete_images=True` (or `empty_on_delete=True`). S3 bucket uses `auto_delete_objects=True`.

## Laravel Cache Management

*   **Route Caching:** Laravel caches routes for performance in production environments.
*   **Important Note:** After making changes to routes (`web.php`, etc.), the route cache must be cleared for changes to take effect.
*   **Cache Clearing Commands:**
    *   Clear route cache only: `php artisan route:clear`
    *   Clear all caches (config, view, route): `php artisan optimize:clear`
*   **When to Clear Cache:**
    *   After modifying any route definition
    *   After changing controller methods referenced by routes
    *   When experiencing unexpected routing behavior
    *   When new routes don't appear to work despite correct code
*   **Route Cache Status Check:** `php artisan route:list` shows all registered routes
*   **Working Directory:** Execute these commands from within the Laravel project directory (`app/laravel`)

## Tooling Notes for AI Assistant

*   **AWS CLI Commands:** When running AWS CLI commands where output needs to be parsed (especially `describe-*` commands), use the `--no-cli-pager` option and request `--output json`. For example:
    `aws cloudformation describe-stacks --stack-name CdkInfraStack --profile tfs-shared-services --region us-east-1 --no-cli-pager --output json`
*   **CDK Commands:** 
    *   Always ensure the Python virtual environment is active (`source .venv/bin/activate` from `cdk-infra`) and use the correct AWS profile (`--profile tfs-shared-services` or `AWS_PROFILE=tfs-shared-services`).
    *   For non-interactive deployments (e.g., if the AI is running it and confident), use `cdk deploy --require-approval never`.

## User Preferences

*   Prefers explicit resource definitions in CDK where appropriate (e.g., CloudWatch Log Groups).
*   Values security considerations even for prototype environments.
*   Favors an iterative approach: define, synthesize, deploy, test, commit.
*   Likes to keep the `plan.md` document updated with progress.
*   Prefers health check endpoints in services for validation.

## Current Status & Recommended Commits

*   **Last Commit (Done by User):** `feat: Implement enhanced scaling and monitoring` (covers all infrastructure and code changes for autoscaling, monitoring, and cost tracking).
*   **Current State:** SQS-based inter-service communication with enhanced autoscaling for large volumes of videos. Cost tracking and monitoring dashboards implemented. Ready for enterprise-level processing.
*   **Next planned infrastructure step:** Final production readiness checks and optimizations.

## S3 Bucket Configuration & Object ACLs

*   **Initial Issue**: Laravel S3 uploads failed with `AccessControlListNotSupported` errors. This was because the S3 bucket (`aws-transcription-data-<ACCOUNT>-<REGION>`) was created with Object Ownership "Bucket owner enforced" (ACLs disabled), but Laravel's S3 driver was attempting to set an ACL.
*   **Resolution Steps Taken**:
    1.  Modified Laravel's `VideoController.php` to explicitly pass `['ACL' => 'bucket-owner-full-control']` with S3 `put()` operations after trying empty options `[]`.
    2.  Modified the S3 bucket definition in `CdkInfraStack` to set `object_ownership=s3.ObjectOwnership.BUCKET_OWNER_PREFERRED`. This enables ACLs on the bucket, allowing the `bucket-owner-full-control` ACL to be accepted and resolving the error.
*   **Current S3 Bucket Object Ownership**: `BUCKET_OWNER_PREFERRED` (ACLs are enabled).
*   **Application S3 Uploads**: Laravel now uses `Storage::disk('s3')->put(..., ['ACL' => 'bucket-owner-full-control'])` for video uploads, and this is working.

## Laravel IAM Credential Resolution

*   **Initial Issue**: Laravel Fargate tasks were attempting S3 operations using a hardcoded IAM user (`arn:aws:iam::542876199144:user/serverless`) instead of the assigned ECS Task Role (`CdkInfraStack-SharedAppTaskRole...`). This caused `AccessDenied` errors.
*   **Cause**: Static AWS credentials (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`) were present in the `.env` file copied into the Docker image.
*   **Resolution**:
    1.  Removed static AWS keys from the `app/laravel/.env` file that is part of the Docker image build (user confirmed this was done manually).
    2.  Ensured Laravel's `config/filesystems.php` relies on environment variables (`env('AWS_BUCKET')`, `env('AWS_DEFAULT_REGION')`) which are injected by ECS.
    3.  Updated `docker/scripts/entrypoint.sh` to aggressively clear and re-cache Laravel configurations (`config:clear`, `config:cache`, etc.) on container startup.
*   **Current State**: Laravel application now correctly uses the IAM Task Role for AWS SDK calls (confirmed by log showing assumed role ARN).

## SQS-Based Inter-Service Communication

*   **SQS Queues**: The following queues have been implemented:
    *   `audio_extraction_queue` - For audio extraction job requests
    *   `transcription_queue` - For transcription job requests
    *   `terminology_queue` - For terminology recognition job requests
    *   `callback_queue` - For service responses back to Laravel

*   **CDK Configuration**: Each service stack has been updated to define appropriate SQS queues with dead-letter queues and visibility timeouts appropriate for the processing time of each service type.

*   **Auto-Scaling**: ECS services are configured to scale based on queue depth, ensuring the system can handle varying loads efficiently.

*   **Service Updates**:
    *   All three Python microservices (audio extraction, transcription, terminology recognition) now consume messages from their respective SQS queues and run background listener threads.
    *   Services send callbacks via the callback queue instead of direct HTTP calls.
    *   Each service maintains HTTP endpoints for backward compatibility and local testing.

*   **Laravel Application Updates**:
    *   Job classes have been modified to send messages to SQS instead of making direct HTTP calls.
    *   A new `ProcessCallbackQueueJob` has been created to handle responses from microservices.
    *   A new Artisan command has been added for listening to the callback queue.

*   **Environment Variables**:
    *   `AUDIO_EXTRACTION_QUEUE_URL`, `TRANSCRIPTION_QUEUE_URL`, `TERMINOLOGY_QUEUE_URL`, and `CALLBACK_QUEUE_URL` are set for each service.
    *   Services check for these environment variables and fall back to HTTP communication if not present.

## Enhanced Auto-Scaling Configuration

*   **Audio Extraction Service**:
    *   Minimum capacity: 3 instances
    *   Maximum capacity: 25 instances
    *   Scaling steps:
        *   +1 task when queue has 1+ messages
        *   +3 tasks when queue has 10+ messages
        *   +7 tasks when queue has 50+ messages
        *   +12 tasks when queue has 100+ messages
        *   +20 tasks when queue has 200+ messages

*   **Transcription Service**:
    *   Minimum capacity: 3 instances
    *   Maximum capacity: 50 instances
    *   Scaling steps:
        *   +1 task when queue has 1+ messages
        *   +3 tasks when queue has 5+ messages
        *   +7 tasks when queue has 20+ messages
        *   +15 tasks when queue has 50+ messages
        *   +25 tasks when queue has 100+ messages
        *   +35 tasks when queue has 200+ messages

*   **Terminology Service**:
    *   Minimum capacity: 3 instances
    *   Maximum capacity: 20 instances
    *   Scaling steps:
        *   +1 task when queue has 1+ messages
        *   +2 tasks when queue has 5+ messages
        *   +5 tasks when queue has 15+ messages
        *   +8 tasks when queue has 30+ messages
        *   +12 tasks when queue has 60+ messages
        *   +15 tasks when queue has 120+ messages

*   **Scaling Behavior**: All services automatically scale down when queue depth decreases, returning to base capacity when idle to control costs.

## Monitoring and Cost Tracking

*   **CloudWatch Dashboard**: Comprehensive dashboard showing:
    *   SQS queue depths for all four queues
    *   ECS task counts for all services
    *   CPU and memory utilization by service
    *   Job cost metrics

*   **Custom Metrics**: Added to Python microservices to track:
    *   Processing time per job
    *   Audio extraction metrics
    *   Transcription real-time ratio
    *   Job completion counts

*   **Cost Tracking Lambda**:
    *   Runs every 6 hours to calculate costs for completed jobs
    *   Breaks down costs by compute, storage, network, and API usage
    *   Publishes metrics to CloudWatch for visualization
    *   Helps analyze cost per job for business reporting

*   **CloudWatch Alarms**: Can be set up to notify on:
    *   Excessive queue depth
    *   Service errors
    *   Cost thresholds
    *   Resource utilization anomalies

## ECS Service Discovery & Inter-Service Communication

*   **Cloud Map Namespace**: The ECS Cluster (`aws-transcription-cluster`) in `CdkInfraStack` has been configured with a default Cloud Map namespace: `local` (via `default_cloud_map_namespace` on `ecs.Cluster`).
*   **Service DNS Names (Anticipated)**:
    *   Laravel Service: `aws-transcription-laravel-service.local` (Port 80 for HTTP API)
    *   Audio Extraction Service: `audio-extraction-service.local` (Port 5000 for HTTP API)
*   **Environment Variables for Service URLs**:
    *   Laravel Service (`LaravelServiceStack`): `AUDIO_SERVICE_URL` is set to `http://audio-extraction-service.local:5000`.
    *   Audio Extraction Service (`AudioExtractionServiceStack`): `LARAVEL_API_URL` is set to `http://aws-transcription-laravel-service.local:80/api`.

## Docker Image Builds for Fargate

*   **Cross-Platform Builds**: Development on ARM (Apple Silicon M1/M2) requires Docker images for Fargate (AMD64) to be built targeting `linux/amd64`.
*   **CDK Configuration**: `DockerImageAsset` constructs in CDK stacks use `platform=ecr_assets.Platform.LINUX_AMD64`.
*   **`.dockerignore`**: A comprehensive `.dockerignore` file is in place at the workspace root to prevent unnecessary files (especially `cdk.out`, `.venv`) from being included in the Docker build context, which resolved `ENAMETOOLONG` errors during `cdk synth`.

## Laravel Application File Handling

*   **Video Uploads**: `VideoController.php` now correctly uploads video files to S3 using `Storage::disk('s3')->put()` with `['ACL' => 'bucket-owner-full-control']`.
*   **Video URL Accessors**: `app/Models/Video.php` accessors (`getUrlAttribute`, `getAudioUrlAttribute`, etc.) have been updated to generate S3 temporary pre-signed URLs for S3-stored files and to read JSON content from S3.
*   **AudioExtractionJob**: `app/Jobs/AudioExtractionJob.php` has been updated to check for video existence on S3 and passes the S3 key to the audio extraction service.

## Laravel Service Access (NLB)

*   A Network Load Balancer (NLB) has been added in front of the Laravel Fargate service.
*   **NLB DNS Name**: `aws-transcription-laravel-nlb-d602a8d13f41e935.elb.us-east-1.amazonaws.com` (This was an example, the actual one is in CDK outputs for `LaravelServiceStack`). *User should replace this with the actual DNS from their deployment if different for their own notes.*
*   This provides a stable DNS endpoint for accessing the Laravel application via VPN, instead of relying on dynamic Fargate task IPs.
*   The Laravel service security group (`laravel_ecs_task_sg`) allows TCP port 80 ingress from the VPC CIDR to facilitate NLB access.

## Transcription Service (Whisper AI)

*   **Status**: Deployed and successfully transcribing audio. Video playback in UI is now stable.
*   **Dockerfile**: `Dockerfile.transcription-service` (builds for `linux/amd64`).
*   **Service Logic (`app/services/transcription/service.py`)**:
    *   Receives `audio_s3_key` from Laravel's `TranscriptionJob` via SQS.
    *   Downloads audio from S3.
    *   Uses `openai-whisper` (e.g., "base" model) for transcription.
    *   Uploads transcript files (`.txt`, `.srt`, `.json`) to the same S3 "folder" as the source audio (e.g., `s3/jobs/<VIDEO_ID>/transcript.txt`).
    *   Sends a callback message to the callback queue with status `transcribed` and paths to the S3 transcript files.
*   **CDK Stack (`transcription_service_stack.py`)**: Defines Fargate service, task definition (with appropriate memory/CPU for Whisper), ECR image asset, IAM permissions for S3 and SQS, and CloudMap service discovery (`transcription-service.local`).
*   **Inter-Service Communication**:
    *   Laravel's `TranscriptionJob` sends a message to the transcription queue.
    *   Transcription service consumes the message and processes it.
    *   After processing, it sends a callback message to the callback queue.
*   **Current Model**: Using Whisper "base" model.

## Terminology Recognition Service

*   **Status**: Deployed and successfully extracting terminology from transcripts.
*   **Service Capabilities**:
    *   Processes transcripts to identify predefined terminology.
    *   Categorizes terms and provides counts and statistics.
    *   Uses SQS for job reception and callback messaging.
    *   Maintains HTTP endpoints for backward compatibility and health checks.

## Deployment Script

*   A `scripts/deploy.sh` script has been created and validated.
*   It handles `npm run build` for Laravel assets and `cdk deploy` (defaulting to `--all`, or taking a specific stack name as an argument).
*   This streamlines the deployment process.

## Next Steps (Current)

*   **Final Production Readiness**:
    *   Review security configurations before production deployment.
    *   Set up long-term monitoring and alerting.
    *   Document operational procedures in detail.
*   **User Training**:
    *   Comprehensive documentation for end users.
    *   Training sessions for system administrators.
*   **Future Enhancements**:
    *   Add support for additional languages.
    *   Implement batch processing optimization.
    *   Explore GPU-based transcription for further performance improvements.
*   **Commit all recent changes**.

## UX Improvements

The project is undergoing UX improvements as documented in `uxplan.md`. Key aspects include:

### UX Improvement Areas

* **Navigation Redesign**: Moving from a cluttered top bar to a hierarchical sidebar navigation
* **Video Card Components**: Enhanced visual presentation with thumbnails and better status indication
* **Job Presets**: Adding support for customizable transcription job configurations
* **Upload Workflow**: Creating a multi-step, guided process with real-time feedback
* **Dashboard View**: Providing a central overview of system status and recent activity

### UX-Infrastructure Integration Points

* **Thumbnail Generation**: Using ffmpeg in the audio extraction service to generate thumbnails stored in S3
* **Job Preset Configuration**: Adding database tables and updating transcription service to use preset settings
* **Real-time Updates**: Leveraging existing SQS communication for better progress visualization
* **Enhanced Metrics**: Adding UX-specific metrics to the existing CloudWatch dashboard

### Implementation Status

Implementation is following the priority order in `uxplan.md`. The project team should refer to this document for the current state of UX improvements and how they integrate with the AWS infrastructure.

*(This section will be updated as UX improvements are implemented)*

*(This file should be updated as the project progresses)* 