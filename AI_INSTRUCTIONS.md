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
*   **IAM Roles:**
    *   A common ECS Task Execution Role is used.
    *   A shared IAM Task Role is used for application services, granting S3 permissions (scoped to the application's data bucket).
*   **Networking:**
    *   Existing VPC is imported: `vpc-09422297ced61f9d2`
    *   VPN Access for User: Via IP `72.239.107.152/32` and/or IP `10.209.27.93/32` (from `truefire-vpn-sg` analysis) allowed in relevant SGs.
*   **Resource Naming:** Application prefix `aws-transcription` is used for many resources.
*   **Logging:** Explicit CloudWatch Log Groups are defined for each service with a default retention (e.g., ONE_MONTH).
*   **Prototype Cleanup:** Resources like ECR repositories, Log Groups, S3 Bucket, and the RDS cluster are configured with `RemovalPolicy.DESTROY` for easier cleanup. ECR repositories also use `auto_delete_images=True` (or `empty_on_delete=True`). S3 bucket uses `auto_delete_objects=True`.

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

*   **Last Commit (Done by User):** `feat: Add Aurora DB, S3 bucket, and working VPN access for DB` (covered all infrastructure up to and including RDS & S3 bucket).
*   **Current State:** Database connectivity confirmed. Base infrastructure (VPC, SGs, ECR, ECS Cluster, IAM Roles, Log Groups, S3 Bucket, RDS) is deployed and committed.
*   **Next planned infrastructure step:** Define ECS Task Definitions and Fargate Services, starting with Laravel.

*(This file should be updated as the project progresses)* 