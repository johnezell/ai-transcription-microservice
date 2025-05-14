#!/usr/bin/env python3
import os

import aws_cdk as cdk

from cdk_infra.cdk_infra_stack import CdkInfraStack
from cdk_infra.laravel_service_stack import LaravelServiceStack

# Define the AWS environment (account and region)
# Using your Account ID and the region from plan.md
aws_env = cdk.Environment(account="542876199144", region="us-east-1")

app = cdk.App()

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

# Add a dependency to ensure main infrastructure is created before the Laravel service
laravel_service_stack.add_dependency(main_infra_stack)

app.synth()
