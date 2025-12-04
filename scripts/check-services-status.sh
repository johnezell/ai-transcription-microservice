#!/bin/bash

# Check ECS Services Status
# This script checks the status of all deployed ECS services

set -e

CLUSTER_NAME="tfs-ai-staging-cluster"
PROFILE="${AWS_PROFILE:-tfs-staging-terraform}"

echo "=== Checking ECS Services Status ==="
echo "Cluster: $CLUSTER_NAME"
echo ""

# List all services
services=$(aws ecs list-services --cluster $CLUSTER_NAME --profile $PROFILE --query 'serviceArns[*]' --output text)

if [ -z "$services" ]; then
    echo "No services found in cluster"
    exit 1
fi

# Get service details
aws ecs describe-services \
    --cluster $CLUSTER_NAME \
    --services $services \
    --profile $PROFILE \
    --query 'services[*].{Service:serviceName,Status:status,DesiredCount:desiredCount,RunningCount:runningCount,PendingCount:pendingCount,LaunchType:launchType}' \
    --output table

echo ""
echo "=== Task Status ==="

# Get running tasks
tasks=$(aws ecs list-tasks --cluster $CLUSTER_NAME --profile $PROFILE --query 'taskArns[*]' --output text)

if [ -z "$tasks" ]; then
    echo "No running tasks found"
else
    aws ecs describe-tasks \
        --cluster $CLUSTER_NAME \
        --tasks $tasks \
        --profile $PROFILE \
        --query 'tasks[*].{TaskArn:taskArn,TaskDefinition:taskDefinitionArn,LastStatus:lastStatus,DesiredStatus:desiredStatus,LaunchType:launchType}' \
        --output table
fi

echo ""
echo "=== Container Instances (EC2) ==="

# List container instances
instances=$(aws ecs list-container-instances --cluster $CLUSTER_NAME --profile $PROFILE --query 'containerInstanceArns[*]' --output text)

if [ -z "$instances" ]; then
    echo "No container instances found (normal for Fargate-only deployments)"
else
    aws ecs describe-container-instances \
        --cluster $CLUSTER_NAME \
        --container-instances $instances \
        --profile $PROFILE \
        --query 'containerInstances[*].{InstanceId:ec2InstanceId,Status:status,RunningTasks:runningTasksCount,RegisteredCPU:registeredResources[?name==`CPU`].integerValue|[0],RegisteredMemory:registeredResources[?name==`MEMORY`].integerValue|[0]}' \
        --output table
fi

echo ""
echo "=== Recent ECS Events ==="

# Get recent service events
for service_arn in $services; do
    service_name=$(echo $service_arn | awk -F'/' '{print $NF}')
    echo ""
    echo "Service: $service_name"
    aws ecs describe-services \
        --cluster $CLUSTER_NAME \
        --services $service_arn \
        --profile $PROFILE \
        --query 'services[0].events[0:5].{Time:createdAt,Message:message}' \
        --output table
done