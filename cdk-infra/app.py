#!/usr/bin/env python3
import os

import aws_cdk as cdk

from cdk_infra.cdk_infra_stack import CdkInfraStack
from cdk_infra.laravel_service_stack import LaravelServiceStack
from cdk_infra.audio_extraction_service_stack import AudioExtractionServiceStack
from cdk_infra.transcription_service_stack import TranscriptionServiceStack
from cdk_infra.terminology_service_stack import TerminologyServiceStack

# Define the AWS environment (account and region)
# Using your Account ID and the region from plan.md
aws_env = cdk.Environment(account=os.environ.get("CDK_DEFAULT_ACCOUNT"), region=os.environ.get("CDK_DEFAULT_REGION"))

app = cdk.App()

app_name_from_context = "aws-transcription"

# Instantiate the main infrastructure stack
main_infra_stack = CdkInfraStack(app, "CdkInfraStack",
    # Pass the environment to the stack
    env=aws_env
    # If you don't specify 'env', this stack will be environment-agnostic.
    # However, some features (like importing existing resources) require a
    # specific environment.
    # For more information, see https://docs.aws.amazon.com/cdk/latest/guide/environments.html
)

# Instantiate the Laravel service stack, passing resources from the main stack
laravel_service_stack = LaravelServiceStack(app, "LaravelServiceStack",
    vpc=main_infra_stack.vpc,
    cluster=main_infra_stack.cluster,
    laravel_ecs_task_sg=main_infra_stack.laravel_ecs_task_sg,
    ecs_task_execution_role=main_infra_stack.ecs_task_execution_role,
    shared_task_role=main_infra_stack.shared_task_role,
    laravel_log_group=main_infra_stack.laravel_log_group,
    db_secret=main_infra_stack.db_cluster_secret,
    app_data_bucket=main_infra_stack.app_data_bucket,
    env=aws_env
)

# Instantiate the Audio Extraction service stack
audio_extraction_service_stack = AudioExtractionServiceStack(app, "AudioExtractionServiceStack",
    vpc=main_infra_stack.vpc,
    cluster=main_infra_stack.cluster,
    internal_services_sg=main_infra_stack.internal_services_sg,
    ecs_task_execution_role=main_infra_stack.ecs_task_execution_role,
    shared_task_role=main_infra_stack.shared_task_role,
    app_data_bucket=main_infra_stack.app_data_bucket,
    audio_extraction_log_group=main_infra_stack.audio_extraction_log_group,
    laravel_service_discovery_name=f"{app_name_from_context}-laravel-service.local",
    env=aws_env
)

# Instantiate the Transcription service stack
transcription_service_stack = TranscriptionServiceStack(app, "TranscriptionServiceStack",
    vpc=main_infra_stack.vpc,
    cluster=main_infra_stack.cluster,
    internal_services_sg=main_infra_stack.internal_services_sg,
    ecs_task_execution_role=main_infra_stack.ecs_task_execution_role,
    shared_task_role=main_infra_stack.shared_task_role,
    app_data_bucket=main_infra_stack.app_data_bucket,
    transcription_log_group=main_infra_stack.transcription_service_log_group,
    laravel_service_discovery_name=f"{app_name_from_context}-laravel-service.local",
    env=aws_env
)

# Instantiate the Terminology service stack
terminology_service_stack = TerminologyServiceStack(app, "TerminologyServiceStack",
    vpc=main_infra_stack.vpc,
    cluster=main_infra_stack.cluster,
    internal_services_sg=main_infra_stack.internal_services_sg,
    ecs_task_execution_role=main_infra_stack.ecs_task_execution_role,
    shared_task_role=main_infra_stack.shared_task_role,
    app_data_bucket=main_infra_stack.app_data_bucket,
    terminology_log_group=main_infra_stack.terminology_log_group,
    laravel_service_discovery_name=f"{app_name_from_context}-laravel-service.local",
    env=aws_env
)

# Add dependencies
laravel_service_stack.add_dependency(main_infra_stack)
audio_extraction_service_stack.add_dependency(main_infra_stack)
audio_extraction_service_stack.add_dependency(laravel_service_stack)
transcription_service_stack.add_dependency(main_infra_stack)
transcription_service_stack.add_dependency(laravel_service_stack)
terminology_service_stack.add_dependency(main_infra_stack)
terminology_service_stack.add_dependency(laravel_service_stack)

app.synth()
