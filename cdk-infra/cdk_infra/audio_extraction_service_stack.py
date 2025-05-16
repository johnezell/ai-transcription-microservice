from aws_cdk import (
    Stack,
    aws_ec2 as ec2,
    aws_ecs as ecs,
    aws_iam as iam,
    aws_logs as logs,
    aws_ecr_assets as ecr_assets,
    aws_s3 as s3,
    aws_sqs as sqs,
    aws_servicediscovery as servicediscovery,
    Duration
)
from constructs import Construct

class AudioExtractionServiceStack(Stack):
    def __init__(self, scope: Construct, construct_id: str,
                 vpc: ec2.IVpc,
                 cluster: ecs.ICluster, # This cluster should have the .local CloudMap namespace
                 internal_services_sg: ec2.ISecurityGroup,
                 ecs_task_execution_role: iam.IRole,
                 shared_task_role: iam.IRole, # For S3 and potentially RDS access
                 app_data_bucket: s3.IBucket,
                 audio_extraction_log_group: logs.ILogGroup,
                 laravel_service_discovery_name: str, # e.g., "aws-transcription-laravel.local"
                 audio_extraction_queue: sqs.IQueue, # SQS queue for audio extraction jobs
                 callback_queue: sqs.IQueue, # SQS queue for callbacks to Laravel
                 **kwargs) -> None:
        super().__init__(scope, construct_id, **kwargs)

        app_name_context = "aws-transcription" # Consistent app prefix

        # Docker Image Asset for Audio Extraction Service
        audio_image_asset = ecr_assets.DockerImageAsset(self, "AudioExtractionDockerImageAsset",
            directory="..",  # Relative to cdk-infra, points to workspace root
            file="Dockerfile.audio-service", # Name of the Dockerfile
            platform=ecr_assets.Platform.LINUX_AMD64
        )

        # Task Definition
        audio_task_definition = ecs.FargateTaskDefinition(self, "AudioExtractionTaskDef",
            memory_limit_mib=1024, # Adjust as needed, ffmpeg can be memory intensive
            cpu=512,               # Adjust as needed
            execution_role=ecs_task_execution_role,
            task_role=shared_task_role # Role with S3 access
        )

        # Container Definition
        audio_container = audio_task_definition.add_container("AudioExtractionServiceContainer",
            image=ecs.ContainerImage.from_docker_image_asset(audio_image_asset),
            logging=ecs.LogDrivers.aws_logs(
                stream_prefix=f"{app_name_context}-audio-extraction",
                log_group=audio_extraction_log_group
            ),
            port_mappings=[ecs.PortMapping(container_port=5000)], # Flask app runs on port 5000
            environment={
                "AWS_BUCKET": app_data_bucket.bucket_name,
                "AWS_DEFAULT_REGION": self.region,
                "LARAVEL_API_URL": f"http://{laravel_service_discovery_name}:80/api", # Service discovery name for Laravel
                "FLASK_ENV": "production", # Override development default from Dockerfile for deployed env
                "AUDIO_EXTRACTION_QUEUE_URL": audio_extraction_queue.queue_url,
                "CALLBACK_QUEUE_URL": callback_queue.queue_url
            }
        )
        # Example Health Check (if your /health endpoint is on the service port)
        audio_container.health_check = ecs.HealthCheck(
            command=["CMD-SHELL", "curl -f http://localhost:5000/health || exit 1"],
            interval=Duration.seconds(30),
            timeout=Duration.seconds(10),
            retries=3,
            start_period=Duration.seconds(60)
        )

        # SQS Queue Access Policies
        audio_extraction_queue.grant_consume_messages(audio_task_definition.task_role)
        callback_queue.grant_send_messages(audio_task_definition.task_role)

        # Fargate Service with Service Discovery
        self.fargate_service = ecs.FargateService(self, "AudioExtractionFargateService",
            cluster=cluster,
            task_definition=audio_task_definition,
            security_groups=[internal_services_sg], # Uses the internal services SG
            vpc_subnets=ec2.SubnetSelection(subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS),
            assign_public_ip=False,
            # Increased to 2 instances for better scalability with SQS
            desired_count=2,
            service_name=f"{app_name_context}-audio-extraction-service", # Will be part of the discovery name
            cloud_map_options=ecs.CloudMapOptions(
                name="audio-extraction-service", # The service name part of "audio-extraction-service.local"
                # The 'local' namespace comes from the cluster's default_cloud_map_namespace
                dns_record_type=servicediscovery.DnsRecordType.A,
                dns_ttl=Duration.seconds(60)
            ),
            enable_execute_command=True
        )

        # Auto-scaling based on SQS queue depth
        scaling = self.fargate_service.auto_scale_task_count(
            min_capacity=2,
            max_capacity=10
        )
        
        scaling.scale_on_metric("QueueMessagesVisibleScaling",
            metric=audio_extraction_queue.metric_approximate_number_of_messages_visible(),
            scaling_steps=[
                {"upper": 0, "change": 0},  # Scale to base capacity when queue is empty
                {"lower": 1, "change": +1},  # Add 1 task when there's at least 1 message
                {"lower": 10, "change": +2},  # Add 2 tasks when there are at least 10 messages
                {"lower": 50, "change": +5},  # Add 5 tasks when there are at least 50 messages
                {"lower": 100, "change": +8}  # Add 8 tasks when there are at least 100 messages
            ],
            adjustment_type=ecs.AdjustmentType.CHANGE_IN_CAPACITY
        ) 