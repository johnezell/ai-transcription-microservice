import aws_cdk
from aws_cdk import (
    # Duration,
    Stack,
    # aws_sqs as sqs,
    aws_ec2 as ec2,
    aws_ecr as ecr,
    aws_ecs as ecs,
    aws_iam as iam,
    aws_logs as logs,
    aws_rds as rds,
)
from constructs import Construct

class CdkInfraStack(Stack):

    def __init__(self, scope: Construct, construct_id: str, **kwargs) -> None:
        super().__init__(scope, construct_id, **kwargs)

        app_name = "aws-transcription"

        # Import the existing VPC using its ID
        # This lookup will require context to be populated in cdk.context.json
        # by running 'cdk synth' or 'cdk diff' once.
        vpc = ec2.Vpc.from_lookup(self, "ImportedVpc",
            vpc_id="vpc-09422297ced61f9d2" # Your VPC ID from plan.md
        )

        # Import existing VPN Security Group
        vpn_sg = ec2.SecurityGroup.from_security_group_id(self, "ImportedVpnSg",
            security_group_id="sg-0cb48fd65f65e8829"
        )

        # Laravel ECS Task Security Group (for private access to Laravel)
        laravel_ecs_task_sg = ec2.SecurityGroup(self, "LaravelEcsTaskSecurityGroup",
            vpc=vpc,
            description="Security group for the Laravel ECS Fargate tasks (private access)",
            allow_all_outbound=True
        )
        
        # Internal Services Security Group (for Python microservices)
        internal_services_sg = ec2.SecurityGroup(self, "InternalServicesSecurityGroup",
            vpc=vpc,
            description="Security group for internal Python microservices",
            allow_all_outbound=True
        )

        # Now that both SGs are defined, add cross-referencing ingress rules:

        # Allow traffic from Internal Services to Laravel (e.g., on port 80)
        laravel_ecs_task_sg.add_ingress_rule(
            peer=internal_services_sg, 
            connection=ec2.Port.tcp(80),
            description="Allow HTTP traffic from Internal Services to Laravel"
        )
        # Allow traffic from VPN Security Group to Laravel (e.g., on port 80)
        laravel_ecs_task_sg.add_ingress_rule(
            peer=vpn_sg,
            connection=ec2.Port.tcp(80),
            description="Allow HTTP traffic from VPN Security Group to Laravel"
        )

        # Allow traffic from Laravel tasks to internal services on port 5000
        internal_services_sg.add_ingress_rule(
            peer=laravel_ecs_task_sg,
            connection=ec2.Port.tcp(5000),
            description="Allow traffic from Laravel to internal services on port 5000"
        )
        # Allow traffic between internal services themselves on port 5000
        internal_services_sg.add_ingress_rule(
            peer=internal_services_sg,
            connection=ec2.Port.tcp(5000),
            description="Allow service-to-service communication on port 5000"
        )

        # RDS Security Group
        rds_sg = ec2.SecurityGroup(self, "RdsSecurityGroup",
            vpc=vpc,
            description="Security group for the RDS database",
            allow_all_outbound=True # Typically true, adjust if DB needs restricted outbound
        )
        # Allow MySQL traffic from Laravel tasks
        rds_sg.add_ingress_rule(
            peer=laravel_ecs_task_sg,
            connection=ec2.Port.tcp(3306),
            description="Allow MySQL traffic from Laravel tasks"
        )
        # Allow MySQL traffic from Internal services
        rds_sg.add_ingress_rule(
            peer=internal_services_sg,
            connection=ec2.Port.tcp(3306),
            description="Allow MySQL traffic from Internal services"
        )

        # EFS Security Group
        efs_sg = ec2.SecurityGroup(self, "EfsSecurityGroup",
            vpc=vpc,
            description="Security group for the EFS file system",
            allow_all_outbound=True
        )
        # Allow NFS traffic from Laravel tasks
        efs_sg.add_ingress_rule(
            peer=laravel_ecs_task_sg,
            connection=ec2.Port.tcp(2049), # NFS port
            description="Allow NFS traffic from Laravel tasks"
        )
        # Allow NFS traffic from Internal services
        efs_sg.add_ingress_rule(
            peer=internal_services_sg,
            connection=ec2.Port.tcp(2049), # NFS port
            description="Allow NFS traffic from Internal services"
        )

        # ECR Repositories
        laravel_repo = ecr.Repository(self, "LaravelEcrRepo",
            repository_name=f"{app_name}-laravel",
            image_scan_on_push=True,
            lifecycle_rules=[
                ecr.LifecycleRule(description="Keep only 10 untagged images", max_image_count=10, tag_status=ecr.TagStatus.UNTAGGED),
                ecr.LifecycleRule(description="Keep last 30 tagged images", max_image_count=30, tag_status=ecr.TagStatus.ANY)
            ],
            removal_policy=aws_cdk.RemovalPolicy.DESTROY
        )

        audio_extraction_repo = ecr.Repository(self, "AudioExtractionEcrRepo",
            repository_name=f"{app_name}-audio-extraction",
            image_scan_on_push=True,
            lifecycle_rules=[
                ecr.LifecycleRule(max_image_count=10, tag_status=ecr.TagStatus.UNTAGGED),
                ecr.LifecycleRule(max_image_count=30, tag_status=ecr.TagStatus.ANY)
            ],
            removal_policy=aws_cdk.RemovalPolicy.DESTROY
        )

        transcription_service_repo = ecr.Repository(self, "TranscriptionServiceEcrRepo",
            repository_name=f"{app_name}-transcription-service",
            image_scan_on_push=True,
            lifecycle_rules=[
                ecr.LifecycleRule(max_image_count=10, tag_status=ecr.TagStatus.UNTAGGED),
                ecr.LifecycleRule(max_image_count=30, tag_status=ecr.TagStatus.ANY)
            ],
            removal_policy=aws_cdk.RemovalPolicy.DESTROY
        )

        music_term_repo = ecr.Repository(self, "MusicTermEcrRepo",
            repository_name=f"{app_name}-music-term-recognition",
            image_scan_on_push=True,
            lifecycle_rules=[
                ecr.LifecycleRule(max_image_count=10, tag_status=ecr.TagStatus.UNTAGGED),
                ecr.LifecycleRule(max_image_count=30, tag_status=ecr.TagStatus.ANY)
            ],
            removal_policy=aws_cdk.RemovalPolicy.DESTROY
        )

        # ECS Cluster
        cluster = ecs.Cluster(self, "EcsCluster",
            vpc=vpc,
            cluster_name=f"{app_name}-cluster"
        )

        # ECS Task Execution Role (common for all services)
        # This role is used by the ECS agent to make calls to AWS services on your behalf
        ecs_task_execution_role = iam.Role(self, "EcsTaskExecutionRole",
            assumed_by=iam.ServicePrincipal("ecs-tasks.amazonaws.com"),
            managed_policies=[
                iam.ManagedPolicy.from_aws_managed_policy_name("service-role/AmazonECSTaskExecutionRolePolicy")
            ]
        )

        # Shared IAM Task Role for application services
        shared_task_role = iam.Role(self, "SharedAppTaskRole",
            assumed_by=iam.ServicePrincipal("ecs-tasks.amazonaws.com"),
            description="Shared task role for Laravel and Python services"
        )
        shared_task_role.add_to_policy(iam.PolicyStatement(
            actions=[
                "s3:GetObject",
                "s3:PutObject",
                "s3:ListBucket",
                "s3:DeleteObject"
            ],
            resources=["arn:aws:s3:::*/*"] # Consider scoping this down to specific buckets
        ))
        # Transcribe permissions removed as per user request for self-hosted Whisper AI

        # CloudWatch Log Groups
        log_retention = logs.RetentionDays.ONE_MONTH

        laravel_log_group = logs.LogGroup(self, "LaravelLogGroup",
            log_group_name=f"/ecs/{app_name}-laravel",
            retention=log_retention,
            removal_policy=aws_cdk.RemovalPolicy.DESTROY
        )
        audio_extraction_log_group = logs.LogGroup(self, "AudioExtractionLogGroup",
            log_group_name=f"/ecs/{app_name}-audio-extraction",
            retention=log_retention,
            removal_policy=aws_cdk.RemovalPolicy.DESTROY
        )
        transcription_service_log_group = logs.LogGroup(self, "TranscriptionServiceLogGroup",
            log_group_name=f"/ecs/{app_name}-transcription-service",
            retention=log_retention,
            removal_policy=aws_cdk.RemovalPolicy.DESTROY
        )
        music_term_log_group = logs.LogGroup(self, "MusicTermLogGroup",
            log_group_name=f"/ecs/{app_name}-music-term-recognition",
            retention=log_retention,
            removal_policy=aws_cdk.RemovalPolicy.DESTROY
        )

        # RDS Aurora Serverless v2 MySQL Database Cluster
        db_cluster = rds.DatabaseCluster(self, "AuroraServerlessMySqlCluster",
            engine=rds.DatabaseClusterEngine.aurora_mysql(
                version=rds.AuroraMysqlEngineVersion.VER_3_05_0  # MySQL 8.0.32 compatible
            ),
            credentials=rds.Credentials.from_generated_secret(f"{app_name}DbAdmin"), # Creates a new secret in Secrets Manager
            vpc=vpc,
            vpc_subnets=ec2.SubnetSelection(subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS),
            security_groups=[rds_sg],
            default_database_name=f"{app_name}DB",
            serverless_v2_min_capacity=0.5,  # Min ACUs
            serverless_v2_max_capacity=1.0,   # Max ACUs for prototype, adjust as needed
            backup_retention=aws_cdk.Duration.days(1),    # Minimal backup retention for prototype
            removal_policy=aws_cdk.RemovalPolicy.DESTROY # Deletes DB when stack is destroyed (for prototype ONLY)
        )

        # We can add outputs or use 'vpc' variable later to pass to other constructs
        # For example, to see the VPC ID after synthesis/deployment:
        aws_cdk.CfnOutput(self, "VpcIdOutput",
            value=vpc.vpc_id,
            description="ID of the imported VPC"
        )
        aws_cdk.CfnOutput(self, "LaravelEcsTaskSecurityGroupIdOutput",
            value=laravel_ecs_task_sg.security_group_id,
            description="ID of the Laravel ECS Task Security Group"
        )
        aws_cdk.CfnOutput(self, "InternalServicesSecurityGroupIdOutput",
            value=internal_services_sg.security_group_id,
            description="ID of the Internal Services Security Group"
        )
        aws_cdk.CfnOutput(self, "RdsSecurityGroupIdOutput",
            value=rds_sg.security_group_id,
            description="ID of the RDS Security Group"
        )
        aws_cdk.CfnOutput(self, "EfsSecurityGroupIdOutput",
            value=efs_sg.security_group_id,
            description="ID of the EFS Security Group"
        )
        aws_cdk.CfnOutput(self, "LaravelRepoUriOutput",
            value=laravel_repo.repository_uri,
            description="URI of the Laravel ECR repository"
        )
        aws_cdk.CfnOutput(self, "AudioExtractionRepoUriOutput",
            value=audio_extraction_repo.repository_uri,
            description="URI of the Audio Extraction ECR repository"
        )
        aws_cdk.CfnOutput(self, "TranscriptionServiceRepoUriOutput",
            value=transcription_service_repo.repository_uri,
            description="URI of the Transcription Service (Whisper AI) ECR repository"
        )
        aws_cdk.CfnOutput(self, "MusicTermRepoUriOutput",
            value=music_term_repo.repository_uri,
            description="URI of the Music Term Recognition ECR repository"
        )
        aws_cdk.CfnOutput(self, "EcsClusterNameOutput",
            value=cluster.cluster_name,
            description="Name of the ECS Cluster"
        )
        aws_cdk.CfnOutput(self, "EcsTaskExecutionRoleArnOutput",
            value=ecs_task_execution_role.role_arn,
            description="ARN of the ECS Task Execution Role"
        )
        aws_cdk.CfnOutput(self, "SharedAppTaskRoleArnOutput",
            value=shared_task_role.role_arn,
            description="ARN of the Shared Application Task Role"
        )

        # The code that defines your stack goes here

        # example resource
        # queue = sqs.Queue(
        #     self, "CdkInfraQueue",
        #     visibility_timeout=Duration.seconds(300),
        # )
