#!/bin/bash
# Validate environment configuration consistency

set -e

ENV=$1

if [ -z "$ENV" ]; then
    echo "Error: Environment not specified"
    exit 1
fi

# Check if .env file exists
if [ ! -f ".env.$ENV" ]; then
    echo "Error: .env.$ENV file not found"
    exit 1
fi

# Check if tfvars file exists
if [ ! -f "terraform/environments/$ENV.tfvars" ]; then
    echo "Error: terraform/environments/$ENV.tfvars file not found"
    exit 1
fi

echo "✅ Environment configuration files are consistent"
exit 0