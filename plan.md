# AWS Transcription Service Deployment Plan

## Overview

This document outlines the plan for deploying the AWS Transcription Service application to Amazon ECS using AWS CDK (Cloud Development Kit). The application consists of multiple microservices that need to be containerized and deployed to AWS infrastructure.

## Application Components

The application consists of the following components:
1. **Laravel Web Application** - PHP Laravel application serving both web UI and API endpoints
2. **Audio Extraction Service** - Python service that extracts audio from video files
3. **Transcription Service** - Python service that interfaces with AWS Transcription API
4. **Music Term Recognition Service** - Python service for music term recognition

## Infrastructure Requirements

1. **Container Repositories** - Amazon ECR for storing Docker images
2. **Container Orchestration** - Amazon ECS for running containers
3. **Database** - Amazon RDS MySQL (Aurora Serverless v2) for the application database
4. **Shared Storage** - Amazon S3 for storing videos, intermediate files, and final transcriptions/data.
5. **Load Balancing** - N/A (Direct IP access for Laravel prototype)
6. **Networking** - Using existing VPC and subnets

## Pre-existing Resources

We will use the following pre-existing network infrastructure:

- **VPC ID**: vpc-09422297ced61f9d2
- **Public Subnets**:
  - subnet-0460f66368d31fd0d (Availability Zone A)
  - subnet-02355996f055ea5ac (Availability Zone B)
- **Private Subnets**:
  - subnet-096caf8b193f1d108 (Availability Zone A)
  - subnet-0afef54f7c422ecab (Availability Zone B)
- **AWS Credentials**: Using the AWS profile `tfs-shared-services` configured in ~/.aws
- **AWS Region**: us-east-1

## Deployment Progress Checklist

| Component | Status | Notes/Findings |
|-----------|--------|----------------|
| **Setup & Configuration** |  |  |
| ✅ Create deployment plan | Completed | Initial plan document created |
| ✅ Set up CDK project structure | Completed | `cdk init app --language python` in `cdk-infra` dir |
| ✅ Configure CDK environment (bootstrap) | Completed | `cdk bootstrap` run with trust for lookups |
| ⬜ Create deployment menu script | In Progress | Basic structure created, CDK commands to be added |
| ⬜ Configure Docker for application services | In Progress | Dockerfiles for services, ECR push pending |
| **Infrastructure Deployment** |  |  |
| ✅ Network configuration | Completed | VPC imported via `Vpc.from_lookup` |
| ✅ Security groups | Completed | Laravel, Internal Services, RDS SGs defined and deployed. VPN access configured. EFS SG removed. |
| ✅ ECR repositories | Completed | All 4 ECR repos defined with lifecycle policies and deployed. |
| ✅ S3 Bucket | Completed | Application data S3 bucket defined and deployed. |
| ⬜ EFS file system | N/A | Replaced by S3 for primary data storage. |
| ✅ RDS database | Completed | Aurora Serverless v2 deployed and connection verified. |
| ✅ ECS cluster | Completed | ECS Cluster defined and deployed. |
| ⬜ Load balancer | N/A | Decided to use direct IP access for prototype. |
| ✅ IAM roles and policies | Completed | ECS Task Execution Role and Shared App Task Role (with S3 bucket-specific permissions) defined and deployed. |
| **Service Deployment** |  |  |
| ⬜ Laravel service |  |  |
| ⬜ Audio extraction service |  |  |
| ⬜ Transcription service |  |  |
| ⬜ Music term recognition service |  |  |
| **Testing & Validation** |  |  |
| ⬜ Infrastructure validation |  |  |
| ⬜ Service discovery testing |  |  |
| ⬜ End-to-end functionality test |  |  |
| ⬜ Performance testing |  |  |
| ⬜ Security validation |  |  |
| **Documentation & Handover** |  |  |
| ⬜ Update documentation |  |  |
| ⬜ Create operational runbook |  |  |
| ⬜ Document CI/CD pipeline |  |  |

## CDK Implementation Plan

### 1. CDK Project Setup and Configuration
- Initialize a new CDK project (e.g., using TypeScript or Python).
- Configure AWS provider details (region, account) in the CDK app or via environment variables/AWS profile.
- CDK handles state management via CloudFormation stacks, typically stored in an S3 bucket created during `cdk bootstrap`.
- Define common configurations, context values (`cdk.json`, `cdk.context.json`), and stack properties.
- Reference existing VPC and subnet resources using CDK lookup methods (e.g., `Vpc.fromLookup`) or by importing them with their IDs.

### 2. Networking Infrastructure (using `aws-cdk-lib/aws-ec2`)
- Import the existing VPC and subnets using `Vpc.fromLookup` or by providing their IDs.
- Create security groups (`ec2.SecurityGroup`) within the existing VPC.
- Create an Application Load Balancer (`elbv2.ApplicationLoadBalancer`) in the public subnets.
- Configure listeners and target groups for the ALB.

### 3. ECR Repositories (using `aws-cdk-lib/aws-ecr`)
- Create ECR repositories (`ecr.Repository`) for each service:
  - `aws-transcription-laravel`
  - `aws-audio-extraction`
  - `aws-transcription-service`
  - `aws-music-term-recognition`
- Configure lifecycle policies for image retention.
- Optionally, integrate with `aws-cdk-lib/aws-ecr-assets` for building and publishing images from local Dockerfiles during `cdk deploy`.

### 4. ECS Infrastructure (using `aws-cdk-lib/aws-ecs` and `aws-cdk-lib/aws-ecs-patterns`)
- Create an ECS cluster (`ecs.Cluster`) in the us-east-1 region, configured to use the existing VPC.
- Set up task execution IAM role (`iam.Role`) and task IAM roles with appropriate permissions.
- Create CloudWatch log groups (`logs.LogGroup`) for container logs.
- Consider using higher-level patterns like `ecs_patterns.ApplicationLoadBalancedFargateService` for simplicity.

### 5. Database (using `aws-cdk-lib/aws-rds`)
- Create an RDS MySQL instance or Aurora Serverless cluster (`rds.DatabaseInstance` or `rds.ServerlessCluster`) in the private subnets.
- Configure security groups to allow access from ECS services and VPN.
- Set up parameter groups, option groups, and backup policies as needed.

### 6. Shared Storage (using `aws-cdk-lib/aws-s3`)
- Create an S3 bucket (`s3.Bucket`) for application data (videos, intermediate files, results).
- Configure bucket policies, public access block, versioning, and lifecycle rules as appropriate.
- Ensure IAM task roles have appropriate permissions to read/write to this bucket.

### 7. Task Definitions (as part of ECS constructs)
- Define task definitions implicitly or explicitly within ECS service constructs:
  - Container definitions (image from ECR, CPU, memory, port mappings).
  - Environment variables (including secrets from AWS Secrets Manager or Systems Manager Parameter Store, S3 bucket name).
  - Volume configurations (EFS mounts).
  - Logging configuration (to CloudWatch Logs).

### 8. ECS Services (using `aws-cdk-lib/aws-ecs` or `aws-cdk-lib/aws-ecs-patterns`)
- Create ECS services (`ecs.FargateService` or via patterns) for each component in the private subnets.
- Configure service discovery (e.g., AWS Cloud Map, integrated with ECS).
- Set up autoscaling policies.
- Define health checks and deployment configurations (rolling updates, blue/green).

### 9. CI/CD Pipeline
- Create scripts for building and pushing Docker images to ECR (if not handled by `ecr-assets`).
- Configure AWS CodePipeline/CodeBuild or GitHub Actions for automated `cdk synth` and `cdk deploy` operations.

## Iterative Testing Approach

We'll use a modular, incremental approach to build and test the infrastructure:

### 1. Stack-by-Stack / Construct-by-Construct Testing
- Divide CDK configurations into logical Stacks and Constructs.
- Test each Stack or key Construct independently before integration.
- Use the following testing sequence:
  1. Network configuration (VPC import, SGs)
  2. ECR repositories
  3. EFS file system
  4. RDS database
  5. ECS cluster and base infrastructure
  6. Individual services one at a time by deploying their respective stacks or updating the main application stack.

### 2. Local Docker Testing
- Test service containers locally before pushing to ECR:
  ```bash
  # Example: docker-compose up audio-extraction-service
  docker build -t audio-extraction-service ./audio-extraction-service
  docker run -p 5000:5000 audio-extraction-service
  ```
- Validate individual container functionality.
- Test inter-service communication locally if feasible using Docker networks.

### 3. CDK Environments / Stacks for Isolation
- Use separate CDK stack instantiations or context parameters to isolate environments (e.g., `dev`, `staging`).
  ```typescript
  // Example in bin/my-app.ts
  // const devEnv = { account: 'ACCOUNTID', region: 'us-east-1' };
  // new MyServiceStack(app, 'DevService', { env: devEnv, /* other dev props */ });
  // new MyServiceStack(app, 'ProdService', { env: prodEnv, /* other prod props */ });
  ```
- Create a `dev` environment for testing before deploying to production-like environments.
- Use consistent naming with environment prefixes/suffixes in stack names to avoid resource conflicts if not using separate accounts.

### 4. Partial Deployments (Deploying Specific Stacks)
- Use targeted deploys to test specific stacks:
  ```bash
  cdk deploy MyEcrStack MyNetworkStack
  ```
- Build and validate ECR repositories stack first.
- Push container images to ECR before creating ECS services that depend on them (or use `ecr-assets`).
- Synthesize and deploy ECS task definitions and services stacks.

### 5. Test Environment Variables and Context
- Use `cdk.json` or `cdk.context.json` for environment-specific configurations or pass them as stack properties.
  ```json
  // cdk.json or cdk.context.json example
  // {
  //   "context": {
  //     "dev:dbInstanceType": "t3.micro",
  //     "prod:dbInstanceType": "m5.large"
  //   }
  // }
  ```
- Test with dev settings before applying production configuration.

### 6. Infrastructure Validation and Review
- Synthesize CloudFormation templates to review changes:
  ```bash
  cdk synth MyStack > template.yaml
  ```
- Run `cdk diff` frequently to check for potential changes before deploying:
  ```bash
  cdk diff MyStack
  ```
- Review planned changes carefully before deploying with `cdk deploy MyStack`.

## Using the AWS CDK CLI

The AWS CDK Command Line Interface (CLI) is the primary tool for interacting with your CDK applications. It's typically installed via npm.

### 1. CDK CLI Benefits
- Define infrastructure in familiar programming languages.
- Leverage high-level constructs for conciseness and best practices.
- Integrated with AWS CloudFormation for robust deployment and state management.
- Facilitates modular and reusable infrastructure components.

### 2. Common CDK Commands
- `cdk init app --language [typescript|python|java|csharp|go]`: Initializes a new CDK project.
- `cdk bootstrap`: Deploys the CDK toolkit stack (assets bucket, roles) to an AWS environment (once per account/region).
- `npm run build`: Compiles your CDK app (e.g., TypeScript to JavaScript).
- `cdk synth [StackName]`: Synthesizes and prints the CloudFormation template for the specified stack(s).
- `cdk diff [StackName]`: Compares the specified stack(s) with the deployed version and shows a diff.
- `cdk deploy [StackName|--all]`: Deploys the specified stack(s) or all stacks to your AWS account.
- `cdk destroy [StackName|--all]`: Destroys the specified stack(s).
- `cdk list` or `ls`: Lists the stacks in your CDK app.

### 3. Usage with Deployment Script
Our `deploy-menu.sh` script will be updated to:
- Check for Node.js and CDK CLI.
- Offer options to run `cdk bootstrap`, `cdk synth`, `cdk diff`, `cdk deploy`, and `cdk destroy`.
- Manage different environment configurations if applicable (e.g., by passing context or selecting different stack entry points).

### 4. CDK Version Management
- CDK CLI version and library versions (`aws-cdk-lib`) are managed in `package.json`.
- Keep CDK CLI and library versions in sync to avoid compatibility issues.

## Deployment Process

1. **Bootstrap CDK (if first time for account/region)**
   ```bash
   cdk bootstrap aws://ACCOUNT-NUMBER/REGION --profile tfs-shared-services
   ```

2. **Build and Push Docker Images (if not using CDK for assets)**
   ```bash
   AWS_PROFILE=tfs-shared-services AWS_REGION=us-east-1 ./scripts/build-push.sh
   ```
   (Alternatively, if using `aws-cdk-lib/aws-ecr-assets`, this step is part of `cdk deploy`)

3. **Synthesize and Review Changes (Recommended)**
   ```bash
   # For TypeScript projects, compile first:
   # npm run build 
   cdk synth # To see templates for all stacks
   cdk diff # To see changes for all stacks or a specific stack
   ```

4. **Deploy Infrastructure and Services**
   ```bash
   cdk deploy --all --profile tfs-shared-services 
   # Or deploy specific stacks:
   # cdk deploy MyNetworkStack MyEcsClusterStack MyLaravelServiceStack --profile tfs-shared-services
   ```
   If deploying services that depend on images pushed in step 2, ensure that's done first, or structure your CDK app with `aws-ecr-assets` to handle image building and pushing.

## Key Considerations

1. **Environment Variables**: 
   - Store sensitive environment variables in AWS Systems Manager Parameter Store
   - Update environment configurations to use AWS services instead of local resources

2. **Service Communication**:
   - Use ECS Service Discovery or internal ALB for service-to-service communication
   - Update service URLs to use the discovered endpoints

3. **Security**:
   - Use IAM roles for service permissions
   - Restrict security group access
   - Encrypt data at rest and in transit

4. **Cost Optimization**:
   - Use Fargate Spot for non-critical services (configurable in ECS Fargate service constructs).
   - Implement autoscaling based on demand (configurable in ECS service constructs).
   - Use the appropriate instance types for RDS (configurable in RDS constructs).

5. **Monitoring and Logging**:
   - Set up CloudWatch alarms for service metrics
   - Configure log shipping to CloudWatch Logs
   - Set up tracing with X-Ray (optional, can be enabled for various services via CDK).

## CDK Project Structure (Example for TypeScript)

```
my-cdk-app/
├── bin/
│   └── my-cdk-app.ts    # Entry point of the CDK application, defines stacks
├── lib/
│   ├── my-network-stack.ts # Defines networking resources (VPC, SGs)
│   ├── my-ecr-stack.ts     # Defines ECR repositories
│   ├── my-ecs-cluster-stack.ts # Defines ECS Cluster, IAM Roles
│   ├── my-database-stack.ts # Defines RDS instance
│   ├── my-efs-stack.ts      # Defines EFS file system
│   └── my-laravel-service-stack.ts # Defines ECS Fargate service for Laravel
├── test/
│   └── my-cdk-app.test.ts # Unit and snapshot tests for stacks
├── cdk.json                 # Toolkit configuration, context values
├── package.json             # NPM dependencies (including aws-cdk-lib)
├── package-lock.json
├── tsconfig.json            # TypeScript configuration
└── README.md
```

## CDK Code Examples with Comments (TypeScript)

(This section will be populated with CDK code examples for key resources like VPC import, ECR, ECS Fargate Service, RDS, EFS, and Security Groups, replacing the old Terraform HCL examples. Due to length, these will be added incrementally or as a separate step if this initial update is too large.)

*Placeholder for CDK code examples. The following Terraform examples will be removed and replaced.*

### Provider and Backend Configuration (main.tf)
// ... existing code ...
resource "aws_security_group" "efs" {
  name        = "${var.app_name}-efs-sg"
  description = "Controls access to EFS"
  vpc_id      = data.aws_vpc.existing.id
  
  # Allow NFS traffic from ECS services only
  ingress {
    protocol        = "tcp"
    from_port       = 2049  # NFS port
    to_port         = 2049
    security_groups = [aws_security_group.ecs.id]
    description     = "Allow NFS from ECS services"
  }
  
  tags = merge(local.common_tags, {
    Name = "${var.app_name}-efs-sg"
  })
}
```

## Scripts
// ... existing code ...
``` 