import json
import boto3
import os
import logging
from datetime import datetime, timedelta

# Set up logging
logger = logging.getLogger()
logger.setLevel(logging.INFO)

# Initialize clients
cloudwatch = boto3.client('cloudwatch')
ce = boto3.client('ce')  # Cost Explorer client

# Constants
NAMESPACE = 'TranscriptionService'
COST_METRICS_NAMESPACE = 'TranscriptionServiceCosts'

# Define service costs (updated periodically)
# These are approximate costs and should be updated based on actual AWS pricing
COST_MAP = {
    'fargate_cpu': 0.04048,       # Per vCPU-hour
    'fargate_memory': 0.004445,   # Per GB-hour
    's3_storage': 0.023,          # Per GB-month
    's3_requests': 0.0000004,     # Per PUT/GET request
    'sqs_requests': 0.0000004,    # Per SQS request
}

def lambda_handler(event, context):
    """
    This Lambda calculates estimated costs for completed transcription jobs
    by analyzing their processing metrics and resource usage.
    """
    # Get completed jobs from the last 24 hours
    try:
        completed_jobs = get_completed_jobs(24)
        logger.info(f"Found {len(completed_jobs)} completed jobs to analyze")
        
        for job in completed_jobs:
            job_id = job['JobId']
            logger.info(f"Calculating costs for job: {job_id}")
            
            # Get processing metrics for the job
            metrics = get_job_metrics(job_id)
            
            if not metrics:
                logger.warning(f"No metrics found for job {job_id}")
                continue
            
            # Calculate costs based on metrics
            cost_estimate = calculate_job_costs(job_id, metrics)
            
            # Store cost data in CloudWatch for future reference
            publish_cost_metrics(job_id, cost_estimate)
            
        return {
            'statusCode': 200,
            'body': json.dumps(f'Processed costs for {len(completed_jobs)} jobs')
        }
        
    except Exception as e:
        logger.error(f"Error calculating job costs: {str(e)}", exc_info=True)
        return {
            'statusCode': 500,
            'body': json.dumps(f'Error calculating job costs: {str(e)}')
        }

def get_completed_jobs(hours_back):
    """Get list of completed transcription jobs from CloudWatch metrics"""
    try:
        # Query for completed jobs based on the JobsProcessed metric
        response = cloudwatch.get_metric_data(
            MetricDataQueries=[
                {
                    'Id': 'completedJobs',
                    'MetricStat': {
                        'Metric': {
                            'Namespace': NAMESPACE,
                            'MetricName': 'JobsProcessed',
                            'Dimensions': [
                                {
                                    'Name': 'ServiceType',
                                    'Value': 'AudioExtraction'
                                }
                            ]
                        },
                        'Period': 3600,  # 1 hour
                        'Stat': 'SampleCount'
                    },
                    'ReturnData': True
                }
            ],
            StartTime=datetime.now() - timedelta(hours=hours_back),
            EndTime=datetime.now()
        )
        
        # TODO: Extract actual job IDs from CloudWatch logs or a database
        # For this example, we're returning a mock list of jobs
        # In a real implementation, you would query a database or parse logs
        
        # Mock job list for demonstration
        jobs = [
            {'JobId': 'job-123456', 'CompletedAt': datetime.now().isoformat()},
            {'JobId': 'job-789012', 'CompletedAt': datetime.now().isoformat()}
        ]
        
        return jobs
        
    except Exception as e:
        logger.error(f"Error getting completed jobs: {str(e)}")
        return []

def get_job_metrics(job_id):
    """Retrieve all relevant metrics for a specific job"""
    try:
        # Define the metrics we want to collect
        metric_names = [
            'AudioExtractionProcessingTime',
            'TranscriptionProcessingTime',
            'TerminologyProcessingTime',
            'S3StorageBytes',
            'TotalProcessingTime'
        ]
        
        metrics = {}
        
        # Query each metric for the specific job
        for metric_name in metric_names:
            response = cloudwatch.get_metric_statistics(
                Namespace=NAMESPACE,
                MetricName=metric_name,
                Dimensions=[
                    {
                        'Name': 'JobId',
                        'Value': job_id
                    }
                ],
                StartTime=datetime.now() - timedelta(days=7),  # Look back 7 days
                EndTime=datetime.now(),
                Period=3600,  # 1 hour
                Statistics=['Average', 'Sum']
            )
            
            # Extract the metric value if available
            datapoints = response.get('Datapoints', [])
            if datapoints:
                # Sort by timestamp and get the most recent
                datapoints.sort(key=lambda x: x['Timestamp'], reverse=True)
                metrics[metric_name] = datapoints[0]
            
        return metrics
        
    except Exception as e:
        logger.error(f"Error getting metrics for job {job_id}: {str(e)}")
        return {}

def calculate_job_costs(job_id, metrics):
    """Calculate estimated costs based on the job metrics"""
    try:
        cost_breakdown = {
            'compute_cost': 0.0,
            'storage_cost': 0.0,
            'network_cost': 0.0,
            'api_cost': 0.0,
            'total_cost': 0.0
        }
        
        # Calculate Fargate compute costs (CPU and memory)
        audio_extraction_time = metrics.get('AudioExtractionProcessingTime', {}).get('Sum', 0) / 3600  # Convert to hours
        transcription_time = metrics.get('TranscriptionProcessingTime', {}).get('Sum', 0) / 3600  # Convert to hours
        terminology_time = metrics.get('TerminologyProcessingTime', {}).get('Sum', 0) / 3600  # Convert to hours
        
        # Assuming a certain amount of CPU and memory for each service
        # This should be adjusted based on actual task definitions
        compute_cost = (
            (audio_extraction_time * COST_MAP['fargate_cpu'] * 0.5) +  # 0.5 vCPU
            (audio_extraction_time * COST_MAP['fargate_memory'] * 1) +  # 1 GB memory
            (transcription_time * COST_MAP['fargate_cpu'] * 1) +  # 1 vCPU
            (transcription_time * COST_MAP['fargate_memory'] * 2) +  # 2 GB memory
            (terminology_time * COST_MAP['fargate_cpu'] * 0.5) +  # 0.5 vCPU
            (terminology_time * COST_MAP['fargate_memory'] * 1)   # 1 GB memory
        )
        
        cost_breakdown['compute_cost'] = compute_cost
        
        # Calculate S3 storage costs
        storage_bytes = metrics.get('S3StorageBytes', {}).get('Sum', 0)
        storage_gb = storage_bytes / (1024 * 1024 * 1024)  # Convert to GB
        # Assuming storage for a month, prorate for actual time
        storage_cost = storage_gb * COST_MAP['s3_storage'] / 30  # Pro-rate for a day
        
        cost_breakdown['storage_cost'] = storage_cost
        
        # Assuming some API and network costs (this would need to be more accurate in production)
        cost_breakdown['api_cost'] = 0.01  # Placeholder
        cost_breakdown['network_cost'] = 0.02  # Placeholder
        
        # Calculate total cost
        cost_breakdown['total_cost'] = sum([
            cost_breakdown['compute_cost'],
            cost_breakdown['storage_cost'],
            cost_breakdown['api_cost'],
            cost_breakdown['network_cost']
        ])
        
        logger.info(f"Calculated costs for job {job_id}: {cost_breakdown}")
        return cost_breakdown
        
    except Exception as e:
        logger.error(f"Error calculating costs for job {job_id}: {str(e)}")
        return {'total_cost': 0.0}

def publish_cost_metrics(job_id, cost_estimate):
    """Publish the cost estimates to CloudWatch metrics for visualization"""
    try:
        # Publish each cost component as a separate metric
        cloudwatch.put_metric_data(
            Namespace=COST_METRICS_NAMESPACE,
            MetricData=[
                {
                    'MetricName': 'ComputeCost',
                    'Dimensions': [{'Name': 'JobId', 'Value': job_id}],
                    'Value': cost_estimate['compute_cost'],
                    'Unit': 'None'
                },
                {
                    'MetricName': 'StorageCost',
                    'Dimensions': [{'Name': 'JobId', 'Value': job_id}],
                    'Value': cost_estimate['storage_cost'],
                    'Unit': 'None'
                },
                {
                    'MetricName': 'NetworkCost',
                    'Dimensions': [{'Name': 'JobId', 'Value': job_id}],
                    'Value': cost_estimate['network_cost'],
                    'Unit': 'None'
                },
                {
                    'MetricName': 'ApiCost',
                    'Dimensions': [{'Name': 'JobId', 'Value': job_id}],
                    'Value': cost_estimate['api_cost'],
                    'Unit': 'None'
                },
                {
                    'MetricName': 'TotalCost',
                    'Dimensions': [{'Name': 'JobId', 'Value': job_id}],
                    'Value': cost_estimate['total_cost'],
                    'Unit': 'None'
                }
            ]
        )
        
        logger.info(f"Published cost metrics for job {job_id}")
        return True
        
    except Exception as e:
        logger.error(f"Error publishing cost metrics for job {job_id}: {str(e)}")
        return False 