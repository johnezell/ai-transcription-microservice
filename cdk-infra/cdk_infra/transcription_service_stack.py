# cdk-infra/cdk_infra/transcription_service_stack.py
from aws_cdk import (
    Stack,
    aws_ec2 as ec2,
    aws_ecs as ecs,
    aws_iam as iam,
    aws_logs as logs,
    aws_ecr_assets as ecr_assets,
    aws_s3 as s3,
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

        # Fargate Service with Service Discovery
        self.fargate_service = ecs.FargateService(self, "TranscriptionFargateService",
            cluster=cluster,
            task_definition=transcription_task_definition,
            security_groups=[internal_services_sg],
            vpc_subnets=ec2.SubnetSelection(subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS),
            assign_public_ip=False,
            desired_count=1, 
            service_name=f"{app_name_context}-transcription-service",
            cloud_map_options=ecs.CloudMapOptions(
                name="transcription-service", 
                dns_record_type=servicediscovery.DnsRecordType.A,
                dns_ttl=Duration.seconds(60)
            ),
            enable_execute_command=True
        ) 