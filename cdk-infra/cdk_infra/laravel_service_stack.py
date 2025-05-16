from aws_cdk import (
    Stack,
    aws_ec2 as ec2,
    aws_ecs as ecs,
    aws_iam as iam,
    aws_logs as logs,
    aws_ecr_assets as ecr_assets,
    aws_secretsmanager as secretsmanager,
    aws_s3 as s3,
    aws_sqs as sqs,
    aws_servicediscovery as servicediscovery,
    aws_elasticloadbalancingv2 as elbv2,
    aws_route53 as route53,
    aws_route53_targets as route53_targets,
    aws_applicationautoscaling as appscaling,
    Duration,
    CfnOutput
)
from constructs import Construct
import datetime

class LaravelServiceStack(Stack):
    def __init__(self, scope: Construct, construct_id: str, 
                 vpc: ec2.IVpc,
                 cluster: ecs.ICluster,
                 laravel_ecs_task_sg: ec2.ISecurityGroup,
                 ecs_task_execution_role: iam.IRole,
                 shared_task_role: iam.IRole,
                 laravel_log_group: logs.ILogGroup,
                 db_secret: secretsmanager.ISecret,
                 app_data_bucket: s3.IBucket,
                 audio_extraction_queue: sqs.IQueue,
                 transcription_queue: sqs.IQueue,
                 terminology_queue: sqs.IQueue,
                 callback_queue: sqs.IQueue,
                 private_hosted_zone_id: str = "Z01552481DZW7076I1OSY",  # Private hosted zone ID
                 public_hosted_zone_id: str = "Z07716653GDXJUDL4P879",  # Public hosted zone ID
                 domain_name: str = "tfs.services",
                 app_subdomain: str = "thoth",  # "thoth.tfs.services"
                 **kwargs) -> None:
        super().__init__(scope, construct_id, **kwargs)

        app_name = "aws-transcription" # Consistent with CdkInfraStack

        # Docker Image Asset for Laravel
        # Assumes Dockerfile.laravel is at the root of the workspace, 
        # and the cdk is run from cdk-infra directory.
        # The path for 'directory' is relative to where 'cdk deploy' is run.
        # Given workspace root is /Users/john/code/aws-transcription-service-je
        # and CDK commands are run from /Users/john/code/aws-transcription-service-je/cdk-infra,
        # the path to the Dockerfile from cdk-infra is ../Dockerfile.laravel
        # The DockerImageAsset's 'directory' parameter is the build context.
        laravel_image_asset = ecr_assets.DockerImageAsset(self, "LaravelDockerImageAsset",
            directory="..", # Go up one level from 'cdk-infra' to the workspace root
            file="Dockerfile.laravel", # Name of the Dockerfile
            platform=ecr_assets.Platform.LINUX_AMD64, # Specify the target platform
            build_args={
                "CDK_BUILD_TIMESTAMP": str(datetime.datetime.utcnow().timestamp())
            }
        )

        # ECS Task Definition for Laravel
        laravel_task_definition = ecs.FargateTaskDefinition(self, "LaravelTaskDef",
            memory_limit_mib=512,  # Placeholder, adjust as needed
            cpu=256,  # Placeholder, adjust as needed
            execution_role=ecs_task_execution_role,
            task_role=shared_task_role
        )

        # Add container to the task definition
        laravel_container = laravel_task_definition.add_container("LaravelWebAppContainer",
            image=ecs.ContainerImage.from_docker_image_asset(laravel_image_asset),
            logging=ecs.LogDrivers.aws_logs(
                stream_prefix=f"{app_name}-laravel-container",
                log_group=laravel_log_group
            ),
            port_mappings=[ecs.PortMapping(container_port=80)],
            environment={
                "APP_NAME": "Laravel Transcription Service",
                "APP_ENV": "production", # Set to "local" or "development" if needed for debugging
                "APP_KEY": "base64:N2Zq9+SyE/2dYCwtKpuDMgh0rTPoxZFbST7XqF8GRYA=", # IMPORTANT: Replace with your actual Laravel App Key from .env
                "APP_DEBUG": "false",
                "APP_URL": f"http://{app_subdomain}.{domain_name}", # Use custom domain instead of localhost
                
                "LOG_CHANNEL": "stderr",
                "LOG_LEVEL": "debug",

                "DB_CONNECTION": "mysql",
                # DB_HOST will be injected by SecretsManager - using custom_host if available, falling back to host
                # DB_PORT will be injected by SecretsManager
                # DB_DATABASE will be injected by SecretsManager
                # DB_USERNAME will be injected by SecretsManager
                # DB_PASSWORD will be injected by SecretsManager

                "AWS_BUCKET": app_data_bucket.bucket_name,
                "AWS_DEFAULT_REGION": self.region,
                "AWS_USE_PATH_STYLE_ENDPOINT": "false",

                # SQS queue URLs for asynchronous communication
                "AUDIO_EXTRACTION_QUEUE_URL": audio_extraction_queue.queue_url,
                "TRANSCRIPTION_QUEUE_URL": transcription_queue.queue_url,
                "TERMINOLOGY_QUEUE_URL": terminology_queue.queue_url,
                "CALLBACK_QUEUE_URL": callback_queue.queue_url,

                # Keep service URLs for backward compatibility and health checks
                "AUDIO_SERVICE_URL": "http://audio-extraction-service.local:5000",
                "TRANSCRIPTION_SERVICE_URL": "http://transcription-service.local:5000",
                "TERMINOLOGY_SERVICE_URL": "http://terminology-service.local:5000" # New service URL
            },
            secrets={
                # Prefer custom_host if it exists and is not empty, otherwise fall back to host
                "DB_HOST": ecs.Secret.from_secrets_manager(db_secret, "custom_host", "host"),
                "DB_PORT": ecs.Secret.from_secrets_manager(db_secret, "port"),
                "DB_DATABASE": ecs.Secret.from_secrets_manager(db_secret, "dbname"),
                "DB_USERNAME": ecs.Secret.from_secrets_manager(db_secret, "username"),
                "DB_PASSWORD": ecs.Secret.from_secrets_manager(db_secret, "password")
            }
        )

        # Grant SQS permissions to the Laravel task
        audio_extraction_queue.grant_send_messages(laravel_task_definition.task_role)
        transcription_queue.grant_send_messages(laravel_task_definition.task_role)
        terminology_queue.grant_send_messages(laravel_task_definition.task_role)
        callback_queue.grant_consume_messages(laravel_task_definition.task_role)

        # Create an internal Network Load Balancer
        self.nlb = elbv2.NetworkLoadBalancer(self, "LaravelNlb",
            vpc=vpc,
            internet_facing=False, # Internal NLB
            load_balancer_name=f"{app_name}-laravel-nlb",
            vpc_subnets=ec2.SubnetSelection(
                subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS,
                one_per_az=True # Crucial for NLB subnet selection
            )
        )

        # Add a listener on port 80
        listener = self.nlb.add_listener("LaravelNlbListener",
            port=80,
            protocol=elbv2.Protocol.TCP
        )

        # ECS Fargate Service for Laravel
        # Using private subnets for the service as it's accessed via VPN/internal services
        laravel_fargate_service = ecs.FargateService(self, "LaravelFargateService",
            cluster=cluster,
            task_definition=laravel_task_definition,
            security_groups=[laravel_ecs_task_sg],
            vpc_subnets=ec2.SubnetSelection(subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS),
            assign_public_ip=False, # No public IP needed as per plan (VPN access)
            desired_count=2, # Increased for handling SQS callback messages
            service_name=f"{app_name}-laravel-service",
            cloud_map_options=ecs.CloudMapOptions(
                name=f"{app_name}-laravel-service", # Register with this name in the .local namespace
                # The 'local' namespace is assumed from the cluster default or should be passed if separately created
                dns_record_type=servicediscovery.DnsRecordType.A,
                dns_ttl=Duration.seconds(60)
            ),
            enable_execute_command=True # Enable ECS Exec
        )

        # Add the Fargate service as a target to the NLB listener
        listener.add_targets("LaravelFargateTarget",
            port=80,
            targets=[laravel_fargate_service.load_balancer_target(
                container_name="LaravelWebAppContainer",
                container_port=80
            )],
            # Optional: configure health checks for the target group
            health_check=elbv2.HealthCheck(
                protocol=elbv2.Protocol.TCP,
                port="80",
                interval=Duration.seconds(30),
                timeout=Duration.seconds(10),
                healthy_threshold_count=3,
                unhealthy_threshold_count=3
            )
        )
        
        # Ensure Laravel ECS Task Security Group allows traffic from the NLB (VPC CIDR)
        # This allows any resource in the VPC to reach the NLB, which then reaches the tasks.
        # A more restrictive rule would be to allow traffic only from NLB's specific IPs/prefix list, 
        # but for internal NLB, VPC CIDR is often acceptable for simplicity.
        laravel_ecs_task_sg.add_ingress_rule(
            peer=ec2.Peer.ipv4(vpc.vpc_cidr_block),
            connection=ec2.Port.tcp(80),
            description="Allow TCP traffic from anywhere in the VPC to Laravel tasks via NLB"
        )

        # Auto-scaling for Laravel based on SQS queue depth (for the callback queue)
        scaling = laravel_fargate_service.auto_scale_task_count(
            min_capacity=2,
            max_capacity=10
        )
        
        scaling.scale_on_metric("CallbackQueueMessagesVisibleScaling",
            metric=callback_queue.metric_approximate_number_of_messages_visible(),
            scaling_steps=[
                {"upper": 0, "change": 0},  # Scale to base capacity when queue is empty
                {"lower": 1, "change": +1},  # Add 1 task when there's at least 1 message
                {"lower": 10, "change": +2},  # Add 2 tasks when there are at least 10 messages
                {"lower": 20, "change": +4},  # Add 4 tasks when there are at least 20 messages
            ],
            adjustment_type=appscaling.AdjustmentType.CHANGE_IN_CAPACITY
        )

        # Function to create DNS record in a hosted zone
        def create_dns_record(hosted_zone_id, zone_type):
            if hosted_zone_id and domain_name and app_subdomain:
                # Look up the hosted zone
                hosted_zone = route53.HostedZone.from_hosted_zone_id(
                    self, 
                    f"Imported{zone_type}HostedZoneForNLB", 
                    hosted_zone_id
                )
                
                # Create a CNAME record for the custom domain
                app_record = route53.CnameRecord(
                    self,
                    f"Laravel{zone_type}CnameRecord",
                    zone=hosted_zone,
                    record_name=app_subdomain,
                    domain_name=self.nlb.load_balancer_dns_name,
                    ttl=Duration.minutes(5)  # Short TTL for prototype
                )
                
                # Create output for the custom domain
                CfnOutput(self, f"Laravel{zone_type}CustomDomain",
                    value=f"{app_subdomain}.{domain_name}",
                    description=f"Custom domain for the Laravel application in {zone_type} zone",
                    export_name=f"{app_name}-{zone_type.lower()}-app-endpoint"
                )
        
        # Create records in both hosted zones if provided
        if private_hosted_zone_id:
            create_dns_record(private_hosted_zone_id, "Private")
            
        if public_hosted_zone_id:
            create_dns_record(public_hosted_zone_id, "Public")
            # Add a note about VPN requirement for public DNS
            CfnOutput(self, "PublicDnsVpnNote",
                value="NOTE: Even with public DNS, VPN connection to the VPC is required since the NLB is internal",
                description="Important note about VPN requirement"
            )

        CfnOutput(self, "LaravelNlbDnsName",
            value=self.nlb.load_balancer_dns_name,
            description="DNS name of the Network Load Balancer for the Laravel service"
        )

        # Add any outputs if needed, e.g., service name
        # cdk.CfnOutput(self, "LaravelServiceNameOutput", value=laravel_fargate_service.service_name)
        # cdk.CfnOutput(self, "LaravelImageUri", value=laravel_image_asset.image_uri) 