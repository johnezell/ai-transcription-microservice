# SQS Polling Configuration

This Laravel application uses AWS SQS queues for processing transcription tasks and receiving callbacks. To prevent local development environments from processing production SQS messages, the polling mechanism is environment-aware.

## How It Works

SQS polling will **only run in the production environment**. This behavior is controlled by the `APP_ENV` environment variable:

- When `APP_ENV=production`: SQS polling is enabled
- When `APP_ENV` is any other value (like "local", "development", "testing"): SQS polling is disabled

## Affected Components

Three main components are modified to respect this environment setting:

1. **Console Kernel** (`app/Console/Kernel.php`):
   - Only schedules the SQS polling job when in production
   - Logs whether polling is enabled/disabled on application start

2. **ListenCallbackQueueCommand** (`app/Console/Commands/ListenCallbackQueueCommand.php`):
   - Checks environment before executing
   - Shows warning and exits when not in production

3. **ProcessCallbackQueueJob** (`app/Jobs/ProcessCallbackQueueJob.php`):
   - Checks environment before processing any messages
   - Quietly exits in non-production environments

## Local Development

For local development, set your `.env` file to use:

```
APP_ENV=local
```

With this setting, your local Laravel instance will:
- Never attempt to poll SQS queues
- Never process messages from production queues
- Avoid conflicts with your production environment

## Production Deployment

For production deployment, ensure your environment variables include:

```
APP_ENV=production
```

This will enable SQS polling and processing on your production servers.

## Forcing SQS Polling in Development (Not Recommended)

If you need to test SQS functionality in a non-production environment, you can temporarily modify the environment check in the code. However, this is **not recommended** as it may cause your development environment to process production messages.

A safer approach is to set up separate SQS queues for development/testing purposes. 