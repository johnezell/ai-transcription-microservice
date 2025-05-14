from aws_cdk import (
    Stack,
    aws_ec2 as ec2,
    aws_ecs as ecs,
    aws_iam as iam,
    aws_logs as logs,
    aws_ecr_assets as ecr_assets,
    aws_secretsmanager as secretsmanager,
    aws_s3 as s3,
    aws_servicediscovery as servicediscovery,
    aws_elasticloadbalancingv2 as elbv2,
    Duration,
    CfnOutput
)
from constructs import Construct

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
            platform=ecr_assets.Platform.LINUX_AMD64 # Specify the target platform
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
                "APP_URL": "http://localhost", # This will be the service's internal DNS, not actually localhost
                
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
            desired_count=1, # Start with one task
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

        CfnOutput(self, "LaravelNlbDnsName",
            value=self.nlb.load_balancer_dns_name,
            description="DNS name of the Network Load Balancer for the Laravel service"
        )

        # Add any outputs if needed, e.g., service name
        # cdk.CfnOutput(self, "LaravelServiceNameOutput", value=laravel_fargate_service.service_name)
        # cdk.CfnOutput(self, "LaravelImageUri", value=laravel_image_asset.image_uri) 