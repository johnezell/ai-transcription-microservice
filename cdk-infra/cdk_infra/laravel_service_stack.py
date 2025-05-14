from aws_cdk import (
    Stack,
    aws_ec2 as ec2,
    aws_ecs as ecs,
    aws_iam as iam,
    aws_logs as logs,
    aws_ecr_assets as ecr_assets,
    aws_secretsmanager as secretsmanager,
    aws_s3 as s3,
    Duration
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
                "AWS_USE_PATH_STYLE_ENDPOINT": "false"
                # Add other necessary Laravel environment variables
            },
            secrets={
                "DB_HOST": ecs.Secret.from_secrets_manager(db_secret, "host"),
                "DB_PORT": ecs.Secret.from_secrets_manager(db_secret, "port"),
                "DB_DATABASE": ecs.Secret.from_secrets_manager(db_secret, "dbname"),
                "DB_USERNAME": ecs.Secret.from_secrets_manager(db_secret, "username"),
                "DB_PASSWORD": ecs.Secret.from_secrets_manager(db_secret, "password")
            }
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
            enable_execute_command=True # Enable ECS Exec
        )

        # Add any outputs if needed, e.g., service name
        # cdk.CfnOutput(self, "LaravelServiceNameOutput", value=laravel_fargate_service.service_name)
        # cdk.CfnOutput(self, "LaravelImageUri", value=laravel_image_asset.image_uri) 