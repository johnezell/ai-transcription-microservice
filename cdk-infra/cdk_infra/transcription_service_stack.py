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
    aws_applicationautoscaling as appscaling,
    Duration,
    Tags
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
        # When running inside the cdk-deployer container, WORKSPACE_ROOT is mounted to /workspace.
        transcription_image_asset = ecr_assets.DockerImageAsset(self, "TranscriptionServiceImageAsset",
            directory="/workspace",  # Changed from ".." for containerized CDK
            file="Dockerfile.transcription-service", # Assumes this Dockerfile is at /workspace/Dockerfile.transcription-service
            platform=ecr_assets.Platform.LINUX_AMD64 # Build for x86 architecture
        )

        # Create a launch template for GPU instances (G4dn)
        gpu_launch_template = ec2.LaunchTemplate(self, "GPULaunchTemplate",
            instance_type=ec2.InstanceType("g4dn.xlarge"),  # G4dn has NVIDIA T4 GPUs
            machine_image=ecs.EcsOptimizedImage.amazon_linux2(
                hardware_type=ecs.AmiHardwareType.GPU
            ),
            # Add user data to install NVIDIA drivers if needed
            user_data=ec2.UserData.for_linux()
        )

        # Task Definition for Transcription Service - use EC2 for GPU support
        transcription_task_definition = ecs.Ec2TaskDefinition(self, "TranscriptionTaskDef",
            execution_role=ecs_task_execution_role,
            task_role=shared_task_role, # Role with S3 access
            network_mode=ecs.NetworkMode.AWS_VPC,  # Use awsvpc networking mode for VPC integration
        )

        # Container Definition with GPU requirements
        transcription_container = transcription_task_definition.add_container("TranscriptionServiceContainer",
            image=ecs.ContainerImage.from_docker_image_asset(transcription_image_asset),
            memory_limit_mib=8192,  # 8GB memory for Whisper model
            logging=ecs.LogDrivers.aws_logs(
                stream_prefix=f"{app_name_context}-transcription",
                log_group=transcription_log_group
            ),
            gpu_count=1,  # Allocate 1 GPU to the container
            port_mappings=[ecs.PortMapping(container_port=5000)], # Flask app runs on port 5000
            environment={
                "AWS_BUCKET": app_data_bucket.bucket_name,
                "AWS_DEFAULT_REGION": self.region,
                "LARAVEL_API_URL": f"http://{laravel_service_discovery_name}:80/api",
                "FLASK_ENV": "production",
                "TRANSCRIPTION_QUEUE_URL": transcription_queue.queue_url,
                "CALLBACK_QUEUE_URL": callback_queue.queue_url,
                "CUDA_VISIBLE_DEVICES": "0",  # Make CUDA visible to the container
                "WHISPER_MODEL_NAME": "medium"  # Use a more accurate model with GPU power
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

        # Ensure the cluster has GPU-capable EC2 instances
        gpu_asg = cluster.add_capacity("GPUCapacity",
            instance_type=ec2.InstanceType("g4dn.xlarge"),
            machine_image=ecs.EcsOptimizedImage.amazon_linux2(hardware_type=ecs.AmiHardwareType.GPU),
            min_capacity=0,
            max_capacity=10,
            desired_capacity=1,  # Change from 0 to 1 to ensure one instance is always running
            vpc_subnets=ec2.SubnetSelection(subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS),
            spot_price="0.50"  # Optional: Use Spot instances to save cost (~70% discount)
        )
        
        # Tag the ASG so our placement constraint can find it
        Tags.of(gpu_asg).add("InstanceType", "g4dn.xlarge")
        
        # Add capacity provider strategy to prioritize GPU instances
        # This requires adding GPU instances to your ECS cluster
        self.ec2_service = ecs.Ec2Service(self, "TranscriptionGPUService",
            cluster=cluster,
            task_definition=transcription_task_definition,
            security_groups=[internal_services_sg],
            vpc_subnets=ec2.SubnetSelection(subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS),
            desired_count=1,  # Start with 1 GPU instance
            service_name=f"{app_name_context}-transcription-service",
            cloud_map_options=ecs.CloudMapOptions(
                name="transcription-service", 
                dns_record_type=servicediscovery.DnsRecordType.SRV,
                dns_ttl=Duration.seconds(60)
            ),
            enable_execute_command=True,
            # Ensure tasks are placed on instances with the required capacity
            placement_constraints=[
                ecs.PlacementConstraint.member_of("attribute:ecs.instance-type =~ g4dn.*")
            ]
        )
        
        # Auto-scaling based on SQS queue depth
        scaling = self.ec2_service.auto_scale_task_count(
            min_capacity=0,  # Allow scaling to zero when idle for cost savings
            max_capacity=10  # Limit GPU instances for cost control
        )
        
        scaling.scale_on_metric("TranscriptionQueueMessagesVisibleScaling",
            metric=transcription_queue.metric_approximate_number_of_messages_visible(),
            scaling_steps=[
                {"upper": 0, "change": -1},  # Scale down when queue is empty
                {"lower": 1, "change": +1},  # Add 1 task for just 1 message (faster scale-up)
                {"lower": 5, "change": +2},  # Add 2 tasks when there are at least 5 messages
                {"lower": 20, "change": +3}, # Add 3 tasks when there are at least 20 messages
                {"lower": 50, "change": +5}  # Add 5 tasks when there are at least 50 messages
            ],
            adjustment_type=appscaling.AdjustmentType.CHANGE_IN_CAPACITY
        ) 