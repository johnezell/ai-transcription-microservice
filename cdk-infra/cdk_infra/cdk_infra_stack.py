import aws_cdk
from aws_cdk import (
    # Duration,
    Stack,
    aws_sqs as sqs,
    aws_ec2 as ec2,
    aws_ecr as ecr,
    aws_ecs as ecs,
    aws_iam as iam,
    aws_logs as logs,
    aws_rds as rds,
    aws_secretsmanager as secretsmanager,
    aws_s3 as s3,
    Duration,
    RemovalPolicy,
)
from constructs import Construct

class CdkInfraStack(Stack):

    def __init__(self, scope: Construct, construct_id: str, **kwargs) -> None:
        super().__init__(scope, construct_id, **kwargs)

        app_name = "aws-transcription"
        db_name = "appdb" # Database name for Aurora
        # These will be fetched from cdk.json or context shortly.
        # For now, ensure they are defined for clarity if used directly below.
        truefire_vpn_gateway_ip = self.node.try_get_context("truefire_vpn_gateway_ip") or "72.239.107.152/32" # Example, adjust as needed
        user_vpn_client_ip = self.node.try_get_context("user_vpn_client_ip") or "10.209.27.93/32" # Example, adjust as needed

        # Import the existing VPC using its ID
        # This lookup will require context to be populated in cdk.context.json
        # by running 'cdk synth' or 'cdk diff' once.
        vpc = ec2.Vpc.from_lookup(self, "ImportedVpc",
            vpc_id="vpc-09422297ced61f9d2" # Your VPC ID from plan.md
        )
        self.vpc = vpc

        # Removed import of sg-09a118ae14a5c0955 as a peer SG
        # vpn_sg_inter_account = ec2.SecurityGroup.from_security_group_id(self, "ImportedVpnInterAccountSg",
        #     security_group_id="sg-09a118ae14a5c0955" 
        # )

        # Laravel ECS Task Security Group (for private access to Laravel)
        laravel_ecs_task_sg = ec2.SecurityGroup(self, "LaravelEcsTaskSecurityGroup",
            vpc=vpc,
            description="Security group for the Laravel ECS Fargate tasks (private access)",
            allow_all_outbound=True
        )
        self.laravel_ecs_task_sg = laravel_ecs_task_sg
        
        # Internal Services Security Group (for Python microservices)
        internal_services_sg = ec2.SecurityGroup(self, "InternalServicesSecurityGroup",
            vpc=vpc,
            description="Security group for internal Python microservices",
            allow_all_outbound=True
        )
        self.internal_services_sg = internal_services_sg

        # Now that base SGs are defined, add cross-referencing ingress rules:

        # Allow traffic from Internal Services to Laravel (e.g., on port 80)
        laravel_ecs_task_sg.add_ingress_rule(
            peer=internal_services_sg, 
            connection=ec2.Port.tcp(80),
            description="Allow HTTP traffic from Internal Services to Laravel"
        )
        # Allow traffic from the TrueFire VPN Gateway IP to Laravel (e.g., on port 80)
        laravel_ecs_task_sg.add_ingress_rule(
            peer=ec2.Peer.ipv4(truefire_vpn_gateway_ip), 
            connection=ec2.Port.tcp(80),
            description="Allow HTTP from TrueFire VPN Gateway IP to Laravel"
        )
        # Allow traffic from specific user VPN client IP to Laravel (as a fallback or alternative path)
        laravel_ecs_task_sg.add_ingress_rule(
            peer=ec2.Peer.ipv4(user_vpn_client_ip),
            connection=ec2.Port.tcp(80),
            description="Allow HTTP from user VPN client IP to Laravel"
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

        # RDS Security Group - Reverted to only allow internal service access pending VPN diagnosis
        rds_sg = ec2.SecurityGroup(self, "RdsSecurityGroup",
            vpc=vpc,
            description="Security group for the RDS database",
            allow_all_outbound=True
        )
        self.rds_sg = rds_sg
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
        # Allow MySQL traffic from the TrueFire VPN Gateway IP
        rds_sg.add_ingress_rule(
            peer=ec2.Peer.ipv4(truefire_vpn_gateway_ip), 
            connection=ec2.Port.tcp(3306),
            description="Allow MySQL from TrueFire VPN Gateway IP"
        )
        # Allow MySQL traffic from specific user VPN client IP (as a fallback or alternative path)
        rds_sg.add_ingress_rule(
            peer=ec2.Peer.ipv4(user_vpn_client_ip),
            connection=ec2.Port.tcp(3306),
            description="Allow MySQL from user VPN client IP"
        )

        # EFS Security Group and related EFS resources are removed as per decision to use S3.

        # SQS Queues for asynchronous microservice communication
        # Audio extraction queue
        audio_extraction_queue = sqs.Queue(self, "AudioExtractionQueue",
            queue_name=f"{app_name}-audio-extraction-queue",
            visibility_timeout=Duration.seconds(3600),  # 1 hour visibility for long-running extractions
            retention_period=Duration.days(14),
            dead_letter_queue=sqs.DeadLetterQueue(
                max_receive_count=5,
                queue=sqs.Queue(self, "AudioExtractionDLQ",
                    queue_name=f"{app_name}-audio-extraction-dlq",
                    retention_period=Duration.days(14)
                )
            )
        )
        self.audio_extraction_queue = audio_extraction_queue

        # Transcription queue
        transcription_queue = sqs.Queue(self, "TranscriptionQueue",
            queue_name=f"{app_name}-transcription-queue", 
            visibility_timeout=Duration.seconds(7200),  # 2 hours visibility for long-running transcriptions
            retention_period=Duration.days(14),
            dead_letter_queue=sqs.DeadLetterQueue(
                max_receive_count=3,
                queue=sqs.Queue(self, "TranscriptionDLQ",
                    queue_name=f"{app_name}-transcription-dlq",
                    retention_period=Duration.days(14)
                )
            )
        )
        self.transcription_queue = transcription_queue

        # Terminology recognition queue
        terminology_queue = sqs.Queue(self, "TerminologyQueue",
            queue_name=f"{app_name}-terminology-queue",
            visibility_timeout=Duration.seconds(1800),  # 30 minutes visibility
            retention_period=Duration.days(14),
            dead_letter_queue=sqs.DeadLetterQueue(
                max_receive_count=3,
                queue=sqs.Queue(self, "TerminologyDLQ",
                    queue_name=f"{app_name}-terminology-dlq",
                    retention_period=Duration.days(14)
                )
            )
        )
        self.terminology_queue = terminology_queue

        # Callback queue (for microservices to send results back to Laravel)
        callback_queue = sqs.Queue(self, "CallbackQueue",
            queue_name=f"{app_name}-callback-queue",
            visibility_timeout=Duration.seconds(300),  # 5 minutes visibility
            retention_period=Duration.days(14),
            dead_letter_queue=sqs.DeadLetterQueue(
                max_receive_count=5,
                queue=sqs.Queue(self, "CallbackDLQ",
                    queue_name=f"{app_name}-callback-dlq",
                    retention_period=Duration.days(14)
                )
            )
        )
        self.callback_queue = callback_queue

        # ECR Repositories
        laravel_repo = ecr.Repository(self, "LaravelEcrRepo",
            repository_name=f"{app_name}-laravel",
            image_scan_on_push=True,
            removal_policy=RemovalPolicy.DESTROY, # For prototype
            empty_on_delete=True, # For prototype (replaced auto_delete_images)
            lifecycle_rules=[
                ecr.LifecycleRule(description="Keep only 10 untagged images", max_image_count=10, tag_status=ecr.TagStatus.UNTAGGED),
                ecr.LifecycleRule(description="Keep last 30 tagged images", max_image_count=30, tag_status=ecr.TagStatus.ANY)
            ]
        )
        self.laravel_repo = laravel_repo

        audio_extraction_repo = ecr.Repository(self, "AudioExtractionEcrRepo",
            repository_name=f"{app_name}-audio-extraction",
            image_scan_on_push=True,
            removal_policy=RemovalPolicy.DESTROY, # For prototype
            empty_on_delete=True, # For prototype (replaced auto_delete_images)
            lifecycle_rules=[
                ecr.LifecycleRule(max_image_count=10, tag_status=ecr.TagStatus.UNTAGGED),
                ecr.LifecycleRule(max_image_count=30, tag_status=ecr.TagStatus.ANY)
            ]
        )
        self.audio_extraction_repo = audio_extraction_repo

        transcription_service_repo = ecr.Repository(self, "TranscriptionServiceEcrRepo",
            repository_name=f"{app_name}-transcription-service",
            image_scan_on_push=True,
            removal_policy=RemovalPolicy.DESTROY, # For prototype
            empty_on_delete=True, # For prototype (replaced auto_delete_images)
            lifecycle_rules=[
                ecr.LifecycleRule(max_image_count=10, tag_status=ecr.TagStatus.UNTAGGED),
                ecr.LifecycleRule(max_image_count=30, tag_status=ecr.TagStatus.ANY)
            ]
        )
        self.transcription_service_repo = transcription_service_repo

        music_term_repo = ecr.Repository(self, "MusicTermEcrRepo",
            repository_name=f"{app_name}-music-term-recognition",
            image_scan_on_push=True,
            removal_policy=RemovalPolicy.DESTROY, # For prototype
            empty_on_delete=True, # For prototype (replaced auto_delete_images)
            lifecycle_rules=[
                ecr.LifecycleRule(max_image_count=10, tag_status=ecr.TagStatus.UNTAGGED),
                ecr.LifecycleRule(max_image_count=30, tag_status=ecr.TagStatus.ANY)
            ]
        )
        self.music_term_repo = music_term_repo

        terminology_service_repo = ecr.Repository(self, "TerminologyServiceEcrRepo",
            repository_name=f"{app_name}-terminology-service",
            image_scan_on_push=True,
            removal_policy=RemovalPolicy.DESTROY,
            empty_on_delete=True,
            lifecycle_rules=[
                ecr.LifecycleRule(max_image_count=10, tag_status=ecr.TagStatus.UNTAGGED),
                ecr.LifecycleRule(max_image_count=30, tag_status=ecr.TagStatus.ANY)
            ]
        )
        self.terminology_service_repo = terminology_service_repo

        # S3 Bucket for application data (videos, transcripts, etc.)
        app_data_bucket = s3.Bucket(self, "AppDataBucket",
            bucket_name=f"{app_name}-data-{self.account}-{self.region}", # Globally unique name
            block_public_access=s3.BlockPublicAccess.BLOCK_ALL,
            versioned=True,
            removal_policy=RemovalPolicy.DESTROY,
            auto_delete_objects=True, # Correct property for S3 Bucket to empty on delete
            object_ownership=s3.ObjectOwnership.BUCKET_OWNER_PREFERRED, # Enable ACLs, bucket owner still preferred for ownership
            cors=[
                s3.CorsRule(
                    allowed_methods=[s3.HttpMethods.GET, s3.HttpMethods.HEAD, s3.HttpMethods.PUT, s3.HttpMethods.POST, s3.HttpMethods.DELETE],
                    allowed_origins=['*'], # Allow all origins
                    allowed_headers=["*"] 
                    # expose_headers= [], 
                    # max_age=3000
                )
            ]
        )
        self.app_data_bucket = app_data_bucket

        # ECS Cluster
        # Add a default Cloud Map namespace for service discovery (e.g., servicename.local)
        self.cluster = ecs.Cluster(self, "EcsCluster",
            vpc=vpc,
            cluster_name=f"{app_name}-cluster",
            default_cloud_map_namespace=ecs.CloudMapNamespaceOptions(
                name="local", # Services will be discoverable at <service_name>.local
                vpc=vpc
            )
        )

        # ECS Task Execution Role (common for all services)
        # This role is used by the ECS agent to make calls to AWS services on your behalf
        ecs_task_execution_role = iam.Role(self, "EcsTaskExecutionRole",
            assumed_by=iam.ServicePrincipal("ecs-tasks.amazonaws.com"),
            managed_policies=[
                iam.ManagedPolicy.from_aws_managed_policy_name("service-role/AmazonECSTaskExecutionRolePolicy")
            ]
        )
        self.ecs_task_execution_role = ecs_task_execution_role

        # Shared IAM Task Role for application services
        shared_task_role = iam.Role(self, "SharedAppTaskRole",
            assumed_by=iam.ServicePrincipal("ecs-tasks.amazonaws.com"),
            description="Shared task role for Laravel and Python services"
        )
        # Grant S3 permissions to the specific app data bucket
        app_data_bucket.grant_read_write(shared_task_role)
        
        # Grant SQS permissions to shared task role
        audio_extraction_queue.grant_send_messages(shared_task_role)
        audio_extraction_queue.grant_consume_messages(shared_task_role)
        transcription_queue.grant_send_messages(shared_task_role)
        transcription_queue.grant_consume_messages(shared_task_role)
        terminology_queue.grant_send_messages(shared_task_role)
        terminology_queue.grant_consume_messages(shared_task_role)
        callback_queue.grant_send_messages(shared_task_role)
        callback_queue.grant_consume_messages(shared_task_role)
        
        # Transcribe permissions removed as per user request for self-hosted Whisper AI
        self.shared_task_role = shared_task_role

        # CloudWatch Log Groups
        log_retention = logs.RetentionDays.ONE_MONTH

        laravel_log_group = logs.LogGroup(self, "LaravelLogGroup",
            log_group_name=f"/ecs/{app_name}-laravel",
            retention=log_retention,
            removal_policy=RemovalPolicy.DESTROY # For prototype
        )
        self.laravel_log_group = laravel_log_group
        audio_extraction_log_group = logs.LogGroup(self, "AudioExtractionLogGroup",
            log_group_name=f"/ecs/{app_name}-audio-extraction",
            retention=log_retention,
            removal_policy=RemovalPolicy.DESTROY # For prototype
        )
        self.audio_extraction_log_group = audio_extraction_log_group
        transcription_service_log_group = logs.LogGroup(self, "TranscriptionServiceLogGroup",
            log_group_name=f"/ecs/{app_name}-transcription-service", # For Whisper AI container logs
            retention=log_retention,
            removal_policy=RemovalPolicy.DESTROY # For prototype
        )
        self.transcription_service_log_group = transcription_service_log_group
        music_term_log_group = logs.LogGroup(self, "MusicTermLogGroup",
            log_group_name=f"/ecs/{app_name}-music-term-recognition",
            retention=log_retention,
            removal_policy=RemovalPolicy.DESTROY # For prototype
        )
        self.music_term_log_group = music_term_log_group

        terminology_log_group = logs.LogGroup(self, "TerminologyLogGroup",
            log_group_name=f"/ecs/{app_name}-terminology-service",
            retention=log_retention,
            removal_policy=RemovalPolicy.DESTROY
        )
        self.terminology_log_group = terminology_log_group

        # RDS Aurora Serverless v2 MySQL Cluster
        db_cluster = rds.DatabaseCluster(self, "AppDatabaseCluster",
            engine=rds.DatabaseClusterEngine.aurora_mysql(
                version=rds.AuroraMysqlEngineVersion.VER_3_04_0  # Changed to MySQL 8.0.28 compatible
            ),
            credentials=rds.Credentials.from_generated_secret("dbAdmin"), # Stores master credentials in Secrets Manager
            writer=rds.ClusterInstance.serverless_v2("writerInstance"), # Defines a Serverless v2 writer instance
            serverless_v2_min_capacity=0.5, # Min ACUs
            serverless_v2_max_capacity=1.0, # Max ACUs for prototype, can be increased
            vpc=vpc,
            vpc_subnets=ec2.SubnetSelection(subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS),
            security_groups=[rds_sg],
            default_database_name=db_name,
            removal_policy=RemovalPolicy.RETAIN,  # Changed from DESTROY to RETAIN for production use
            backup=rds.BackupProps(
                retention=aws_cdk.Duration.days(7)  # Increased from 1 day to 7 days for better protection
            ),
            deletion_protection=True  # Add deletion protection to prevent accidental deletion
        )
        self.db_cluster = db_cluster
        # Expose the secret for the Laravel stack to use
        self.db_cluster_secret = db_cluster.secret

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
        aws_cdk.CfnOutput(self, "AppDataBucketNameOutput",
            value=app_data_bucket.bucket_name,
            description="Name of the application data S3 bucket"
        )
        
        # SQS Queue ARN outputs
        aws_cdk.CfnOutput(self, "AudioExtractionQueueUrlOutput",
            value=audio_extraction_queue.queue_url,
            description="URL of the Audio Extraction SQS Queue"
        )
        aws_cdk.CfnOutput(self, "TranscriptionQueueUrlOutput",
            value=transcription_queue.queue_url,
            description="URL of the Transcription SQS Queue"
        )
        aws_cdk.CfnOutput(self, "TerminologyQueueUrlOutput",
            value=terminology_queue.queue_url,
            description="URL of the Terminology SQS Queue"
        )
        aws_cdk.CfnOutput(self, "CallbackQueueUrlOutput",
            value=callback_queue.queue_url,
            description="URL of the Callback SQS Queue"
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
            description="URI of the Music Term Recognition ECR repository (OLD - to be removed/replaced)"
        )
        aws_cdk.CfnOutput(self, "TerminologyServiceRepoUriOutput",
            value=terminology_service_repo.repository_uri,
            description="URI of the Terminology Service ECR repository"
        )
        aws_cdk.CfnOutput(self, "EcsClusterNameOutput",
            value=self.cluster.cluster_name,
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
        aws_cdk.CfnOutput(self, "DbClusterEndpointOutput",
            value=db_cluster.cluster_endpoint.hostname,
            description="Hostname of the DB Cluster Endpoint"
        )
        aws_cdk.CfnOutput(self, "DbClusterReadEndpointOutput",
            value=db_cluster.cluster_read_endpoint.hostname,
            description="Hostname of the DB Cluster Read Endpoint"
        )
        aws_cdk.CfnOutput(self, "DbClusterSecretArnOutput",
            value=db_cluster.secret.secret_arn if db_cluster.secret else "N/A",
            description="ARN of the DB Cluster master credentials secret in Secrets Manager"
        )

        # The code that defines your stack goes here

        # example resource
        # queue = sqs.Queue(
        #     self, "CdkInfraQueue",
        #     visibility_timeout=Duration.seconds(300),
        # )
