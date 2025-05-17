#!/bin/bash

# Script to export database username, password, and endpoint from AWS CDK outputs.

# Check if stack name is provided
if [ -z "$1" ]; then
  echo "Usage: $0 <cdk_stack_name>"
  echo "Example: $0 CdkInfraStack"
  exit 1
fi

STACK_NAME=$1
echo "Fetching outputs for stack: $STACK_NAME..."

# Get stack outputs
STACK_OUTPUTS=$(aws cloudformation describe-stacks --stack-name "$STACK_NAME" --profile tfs-shared-services --region us-east-1 --query "Stacks[0].Outputs" --output json)

if [ -z "$STACK_OUTPUTS" ] || [ "$STACK_OUTPUTS" == "null" ]; then
  echo "Error: Could not retrieve outputs for stack '$STACK_NAME'."
  echo "Please check if the stack name is correct and you have the necessary AWS permissions."
  exit 1
fi

# Function to extract output value by key
get_output_value() {
  local key="$1"
  local value=$(echo "$STACK_OUTPUTS" | jq -r ".[] | select(.OutputKey==\"$key\") | .OutputValue")
  if [ -z "$value" ] || [ "$value" == "null" ]; then
    echo "Error: OutputKey '$key' not found in stack '$STACK_NAME'."
    exit 1
  fi
  echo "$value"
}

# Extract database endpoint and secret ARN
DB_ENDPOINT=$(get_output_value "DbClusterEndpointOutput")
if [ $? -ne 0 ]; then exit 1; fi

DB_SECRET_ARN=$(get_output_value "DbClusterSecretArnOutput")
if [ $? -ne 0 ]; then exit 1; fi

echo "Database Endpoint: $DB_ENDPOINT"
echo "Database Secret ARN: $DB_SECRET_ARN"
echo "Fetching database credentials from Secrets Manager..."

# Get secret value from Secrets Manager
SECRET_VALUE_JSON=$(aws secretsmanager get-secret-value --secret-id "$DB_SECRET_ARN" --profile tfs-shared-services --region us-east-1 --query SecretString --output text)

if [ -z "$SECRET_VALUE_JSON" ] || [ "$SECRET_VALUE_JSON" == "null" ]; then
  echo "Error: Could not retrieve secret value for ARN '$DB_SECRET_ARN'."
  echo "Please check if the Secret ARN is correct and you have the necessary AWS permissions."
  exit 1
fi

# Parse secret JSON to get username and password
DB_USERNAME=$(echo "$SECRET_VALUE_JSON" | jq -r '.username')
DB_PASSWORD=$(echo "$SECRET_VALUE_JSON" | jq -r '.password')

if [ -z "$DB_USERNAME" ] || [ "$DB_USERNAME" == "null" ] || [ -z "$DB_PASSWORD" ] || [ "$DB_PASSWORD" == "null" ]; then
  echo "Error: Could not parse username or password from secret."
  echo "Secret JSON: $SECRET_VALUE_JSON"
  exit 1
fi

echo "----------------------------------------"
echo "Database Connection Details:"
echo "----------------------------------------"
echo "Endpoint: $DB_ENDPOINT"
echo "Username: $DB_USERNAME"
echo "Password: $DB_PASSWORD"
echo "----------------------------------------"

echo "Script completed successfully." 