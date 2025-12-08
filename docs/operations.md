# Operations

## Local Development

```bash
# Start local dev (Laravel + Redis only)
make local

# View logs
make local-logs

# Stop services
make local-stop
```

Access at http://localhost:8080

**Local architecture**: Laravel runs locally, dispatches jobs to SQS. ECS workers in AWS process jobs. This means you need AWS connectivity even for local dev.

**Containers**:
- `thoth-local` - Laravel app
- `thoth-redis` - Cache/session storage

## Terraform Commands

All terraform operations use Docker for version consistency.

```bash
# Initialize (run once or after provider changes)
make init

# Plan changes
make plan ENV=staging

# Apply changes (requires plan first)
make apply ENV=staging

# Target specific resource
make plan-target ENV=staging TARGET=aws_ecs_service.laravel
make apply-target ENV=staging

# Destroy (requires confirmation)
make destroy ENV=staging

# Format and validate
make fmt
make validate
```

## Environment Setup

```bash
# Set AWS profile
export AWS_PROFILE=tfs-ai-staging

# Verify credentials
aws sts get-caller-identity
# Should show account: 087439708020
```

## Health Checks

```bash
# Local Laravel
curl http://localhost:8080/api/health

# Staging (all services behind ALB)
curl https://thoth-staging.tfs.services/api/health
```

## Queue

Local dev uses SQS queue (`QUEUE_CONNECTION=sqs`). Jobs are processed by ECS workers in AWS, not locally. No local queue worker needed.

## Common Issues

| Problem | Cause | Fix |
|---------|-------|-----|
| Jobs not processing | SQS/ECS not running | Check ECS service status in AWS console |
| Can't connect to external DB | VPC peering or creds | Verify `.env.local` has correct creds |
| 503 from ALB | ECS task unhealthy | Check CloudWatch logs |
| Redis connection refused | Container not running | `make local` to restart |

## Logs

```bash
# Local Docker logs
docker logs thoth-local
docker logs thoth-redis

# ECS (via bastion or AWS CLI)
aws logs tail /ecs/thoth-staging --follow
```

## Monitoring

- **CloudWatch**: GPU utilization, queue depth, error rates
- **Target metrics**: GPU >70% during transcription, queue <100 jobs

