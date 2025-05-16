# cdk-infra/cdk_infra/transcription_service_stack.py
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

class TranscriptionServiceStack(Stack):
    def __init__(self, scope: Construct, construct_id: str,
                 vpc: ec2.IVpc,
                 cluster: ecs.ICluster, # Cluster with .local CloudMap namespace
                 internal_services_sg: ec2.ISecurityGroup,
                 ecs_task_execution_role: iam.IRole,
                 shared_task_role: iam.IRole, # For S3 access
                 app_data_bucket: s3.IBucket,
                 transcription_log_group: logs.ILogGroup, # Dedicated log group
                 laravel_service_discovery_name: str, # e.g., "aws-transcription-laravel.local"
                 transcription_queue: sqs.IQueue, # SQS queue for transcription jobs
                 callback_queue: sqs.IQueue, # SQS queue for callbacks to Laravel
                 **kwargs) -> None:
        super().__init__(scope, construct_id, **kwargs)

        app_name_context = "aws-transcription"

        # Docker Image Asset for the Transcription service
        transcription_image_asset = ecr_assets.DockerImageAsset(self, "TranscriptionServiceImageAsset",
            directory="..",  # Relative to 'cdk-infra', so it points to the workspace root
            file="Dockerfile.transcription-service", # Corrected Dockerfile name
            platform=ecr_assets.Platform.LINUX_AMD64 # Build for Fargate
        )

        # Task Definition for Transcription Service
        transcription_task_definition = ecs.FargateTaskDefinition(self, "TranscriptionTaskDef",
            memory_limit_mib=2048, # Increased memory for Whisper (e.g., base model)
            cpu=1024,              # 1 vCPU
            execution_role=ecs_task_execution_role,
            task_role=shared_task_role # Role with S3 access
        )

        # Container Definition
        transcription_container = transcription_task_definition.add_container("TranscriptionServiceContainer",
            image=ecs.ContainerImage.from_docker_image_asset(transcription_image_asset),
            logging=ecs.LogDrivers.aws_logs(
                stream_prefix=f"{app_name_context}-transcription",
                log_group=transcription_log_group
            ),
            port_mappings=[ecs.PortMapping(container_port=5000)], # Flask app runs on port 5000
            environment={
                "AWS_BUCKET": app_data_bucket.bucket_name,
                "AWS_DEFAULT_REGION": self.region,
                "LARAVEL_API_URL": f"http://{laravel_service_discovery_name}:80/api",
                "FLASK_ENV": "production",
                "TRANSCRIPTION_QUEUE_URL": transcription_queue.queue_url,
                "CALLBACK_QUEUE_URL": callback_queue.queue_url
                # "WHISPER_MODEL_NAME": "base" # Optional: to configure Whisper model via env var
            }
        )

        transcription_container.health_check = ecs.HealthCheck(
            command=["CMD-SHELL", "curl -f http://localhost:5000/health || exit 1"],
            interval=Duration.seconds(30),
            timeout=Duration.seconds(10),
            retries=3,
            start_period=Duration.seconds(120) # Longer start period if model loading takes time
        )

        # SQS Queue Access Policies
        transcription_queue.grant_consume_messages(transcription_task_definition.task_role)
        callback_queue.grant_send_messages(transcription_task_definition.task_role)

        # Fargate Service with Service Discovery
        self.fargate_service = ecs.FargateService(self, "TranscriptionFargateService",
            cluster=cluster,
            task_definition=transcription_task_definition,
            security_groups=[internal_services_sg],
            vpc_subnets=ec2.SubnetSelection(subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS),
            assign_public_ip=False,
            desired_count=2, # Increased for SQS-based processing
            service_name=f"{app_name_context}-transcription-service",
            cloud_map_options=ecs.CloudMapOptions(
                name="transcription-service", 
                dns_record_type=servicediscovery.DnsRecordType.A,
                dns_ttl=Duration.seconds(60)
            ),
            enable_execute_command=True
        )
        
        # Auto-scaling based on SQS queue depth
        scaling = self.fargate_service.auto_scale_task_count(
            min_capacity=2,
            max_capacity=20  # Higher capacity for transcription which can be more CPU/GPU intensive
        )
        
        scaling.scale_on_metric("TranscriptionQueueMessagesVisibleScaling",
            metric=transcription_queue.metric_approximate_number_of_messages_visible(),
            scaling_steps=[
                {"upper": 0, "change": 0},  # Scale to base capacity when queue is empty
                {"lower": 1, "change": +1},  # Add 1 task when there's at least 1 message
                {"lower": 5, "change": +2},  # Add 2 tasks when there are at least 5 messages
                {"lower": 20, "change": +5},  # Add 5 tasks when there are at least 20 messages
                {"lower": 50, "change": +10}  # Add 10 tasks when there are at least 50 messages
            ],
            adjustment_type=ecs.AdjustmentType.CHANGE_IN_CAPACITY
        ) 