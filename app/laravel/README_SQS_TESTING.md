# Testing SQS Connectivity

This document explains how to test SQS connectivity from the Laravel application, either in local development or in Docker.

## Using the Built-in Test Command

We've added a helpful SQS testing command to verify your connectivity to AWS SQS queues:

```bash
# Basic usage - tests connection to audio extraction queue (default)
php artisan sqs:test

# Test a specific queue by specifying the environment variable name
php artisan sqs:test TRANSCRIPTION_QUEUE_URL
php artisan sqs:test TERMINOLOGY_QUEUE_URL
php artisan sqs:test CALLBACK_QUEUE_URL
```

## Running from Docker

If you're using Docker for local development:

```bash
# First make sure your container is built with the latest code
docker-compose build laravel

# Start the container if not already running
docker-compose up -d laravel

# Execute the command inside the container
docker-compose exec laravel php artisan sqs:test
```

## Troubleshooting Common Issues

1. **Missing SQS Queue URL Environment Variables**:
   - Ensure your `.env` file contains all required SQS queue URLs:
   ```
   AUDIO_EXTRACTION_QUEUE_URL=https://sqs.us-east-1.amazonaws.com/542876199144/aws-transcription-audio-extraction-queue
   TRANSCRIPTION_QUEUE_URL=https://sqs.us-east-1.amazonaws.com/542876199144/aws-transcription-transcription-queue
   TERMINOLOGY_QUEUE_URL=https://sqs.us-east-1.amazonaws.com/542876199144/aws-transcription-terminology-queue
   CALLBACK_QUEUE_URL=https://sqs.us-east-1.amazonaws.com/542876199144/aws-transcription-callback-queue
   ```

2. **AWS Credentials**:
   - In local development, ensure you have valid AWS credentials in either:
     - `~/.aws/credentials` file with a proper profile
     - Environment variables in your `.env` file (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY)
   
   - In Docker:
     - Mount your AWS credentials directory into the container:
     ```yaml
     # In docker-compose.yml
     volumes:
       - ~/.aws:/mnt/aws_creds_mounted:ro
     ```
     
     - Set the AWS_SHARED_CREDENTIALS_FILE environment variable:
     ```
     AWS_SHARED_CREDENTIALS_FILE=/mnt/aws_creds_mounted/credentials
     ```

3. **AWS Region Issues**:
   - Make sure you're using the right region in your .env file:
   ```
   AWS_DEFAULT_REGION=us-east-1
   ```

4. **Permission Issues**:
   - The AWS credentials you're using must have SQS permissions.
   - Check that your IAM policy includes:
     - `sqs:SendMessage`
     - `sqs:ReceiveMessage` 
     - `sqs:DeleteMessage` (if needed)
     - `sqs:GetQueueUrl`

5. **Network Access**:
   - If your SQS queues are in a VPC or behind private networking, you may need a VPN connection.

## Checking SQS Logs

If experiencing issues, check Laravel logs for SQS-related errors:

```bash
tail -f storage/logs/laravel.log | grep -i sqs
```

For Docker:

```bash
docker-compose exec laravel tail -f storage/logs/laravel.log | grep -i sqs
``` 