#!/usr/bin/env python3
import os

import aws_cdk as cdk

from cdk_infra.cdk_infra_stack import CdkInfraStack

# Define the AWS environment (account and region)
# Using your Account ID and the region from plan.md
aws_env = cdk.Environment(account="542876199144", region="us-east-1")

app = cdk.App()
CdkInfraStack(app, "CdkInfraStack",
    # Pass the environment to the stack
    env=aws_env
    # If you don't specify 'env', this stack will be environment-agnostic.
    # However, some features (like importing existing resources) require a
    # specific environment.
    # For more information, see https://docs.aws.amazon.com/cdk/latest/guide/environments.html
    )

app.synth()
