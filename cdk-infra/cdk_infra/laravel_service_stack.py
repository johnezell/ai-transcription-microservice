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
    aws_applicationautoscaling as appscaling,
    aws_route53 as route53,
    aws_route53_targets as targets,
    aws_certificatemanager as acm,
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
                 **kwargs) -> None:
        super().__init__(scope, construct_id, **kwargs)

        app_name = "aws-transcription" # Consistent with CdkInfraStack
        # Define the subdomain for Laravel app
        domain_name = "transcription.tfs.services"

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

        # Import existing SSL certificate
        ssl_certificate = acm.Certificate.from_certificate_arn(
            self, "WildcardCertificate",
            certificate_arn="arn:aws:acm:us-east-1:542876199144:certificate/4e2a5475-3b1a-4c6a-b4e4-444201f3bfe0"
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
                "APP_URL": f"https://{domain_name}", # Updated to use HTTPS
                
                "LOG_CHANNEL": "stderr",
                "LOG_LEVEL": "debug",

                "DB_CONNECTION": "mysql",
                # DB_HOST will be injected by SecretsManager
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
                "DB_HOST": ecs.Secret.from_secrets_manager(db_secret, "host"),
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
            internet_facing=True, # Internet-facing for public access
            load_balancer_name=f"{app_name}-laravel-{int(datetime.datetime.now().timestamp())%1000}", # Shortened name with unique suffix
            vpc_subnets=ec2.SubnetSelection(
                subnet_type=ec2.SubnetType.PUBLIC, # Public subnets for internet access
                one_per_az=True # Crucial for NLB subnet selection
            )
        )

        # Add a listener for HTTP on port 80
        http_listener = self.nlb.add_listener("LaravelNlbHttpListener",
            port=80,
            protocol=elbv2.Protocol.TCP
        )
        
        # Add a listener for HTTPS on port 443 with TLS
        https_listener = self.nlb.add_listener("LaravelNlbHttpsListener",
            port=443,
            protocol=elbv2.Protocol.TLS,
            certificates=[ssl_certificate]
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

        # Create a target group for the HTTP listener
        http_target_group = http_listener.add_targets("LaravelHttpFargateTarget",
            port=80,
            targets=[laravel_fargate_service.load_balancer_target(
                container_name="LaravelWebAppContainer",
                container_port=80
            )],
            target_group_name=f"laravel-http-tg-{int(datetime.datetime.now().timestamp())%1000}", # Unique name
            health_check=elbv2.HealthCheck(
                protocol=elbv2.Protocol.TCP,
                port="80",
                interval=Duration.seconds(30),
                timeout=Duration.seconds(10),
                healthy_threshold_count=3,
                unhealthy_threshold_count=3
            )
        )

        # Create a target group for the HTTPS listener
        https_target_group = https_listener.add_targets("LaravelHttpsFargateTarget",
            port=80,
            targets=[laravel_fargate_service.load_balancer_target(
                container_name="LaravelWebAppContainer",
                container_port=80
            )],
            target_group_name=f"laravel-https-tg-{int(datetime.datetime.now().timestamp())%1000}", # Unique name
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

        CfnOutput(self, "LaravelNlbDnsName",
            value=self.nlb.load_balancer_dns_name,
            description="DNS name of the Network Load Balancer for the Laravel service"
        )

        # Import the existing hosted zone
        hosted_zone = route53.HostedZone.from_lookup(self, "TfsServicesHostedZone",
            domain_name="tfs.services"
        )

        # Create A record pointing to the NLB
        route53.ARecord(self, "LaravelCustomDomain",
            zone=hosted_zone,
            record_name="transcription",  # This creates transcription.tfs.services
            target=route53.RecordTarget.from_alias(
                targets.LoadBalancerTarget(self.nlb)
            )
        )

        # Add output with the custom URL
        CfnOutput(self, "LaravelCustomDomainUrl",
            value=f"https://{domain_name}",
            description="Custom domain URL for the Laravel service"
        )

        # Add any outputs if needed, e.g., service name
        # cdk.CfnOutput(self, "LaravelServiceNameOutput", value=laravel_fargate_service.service_name)
        # cdk.CfnOutput(self, "LaravelImageUri", value=laravel_image_asset.image_uri) 