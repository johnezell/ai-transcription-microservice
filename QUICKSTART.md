# Quick Start Guide - TFS AI Infrastructure

This guide gets you up and running with the bastion host in under 10 minutes.

## Prerequisites

- Docker installed
- AWS CLI installed  
- AWS credentials configured for `tfs-ai-staging` profile

## One-Line Deployment

```bash
# Run the interactive deployment script
./scripts/deploy-bastion.sh
```

This script will:
1. Check prerequisites
2. Update security settings
3. Create backend resources
4. Initialize Terraform
5. Plan and apply infrastructure
6. Verify deployment
7. Show connection instructions

## Manual Steps

If you prefer manual control:

```bash
# 1. Set AWS profile
export AWS_PROFILE=tfs-ai-staging

# 2. Update security (replace with your IP)
YOUR_IP=$(curl -s https://api.ipify.org)
sed -i "s|0.0.0.0/0|${YOUR_IP}/32|g" terraform/environments/staging.tfvars

# 3. Create backend
./scripts/create-backend.sh staging

# 4. Initialize Terraform
make init
cd terraform && ../terraform-docker.sh init -backend-config=backend-staging.hcl && cd ..

# 5. Deploy
make plan ENV=staging
make apply ENV=staging

# 6. Connect
make ssh-bastion ENV=staging
```

## Monitoring

Check deployment status anytime:

```bash
# Full monitoring report
make monitor ENV=staging

# Quick bastion check
aws ec2 describe-instances \
  --filters "Name=tag:Name,Values=tfs-ai-staging-bastion" \
  --query "Reservations[0].Instances[0].[InstanceId,State.Name,PublicIpAddress]" \
  --output table \
  --profile tfs-ai-staging
```

## Troubleshooting

### Cannot connect to bastion

1. Check your IP is allowed:
   ```bash
   grep bastion_allowed_cidrs terraform/environments/staging.tfvars
   ```

2. Update if needed:
   ```bash
   YOUR_IP=$(curl -s https://api.ipify.org)
   # Edit terraform/environments/staging.tfvars
   # Then: make apply ENV=staging
   ```

### AWS credentials issues

```bash
# Verify profile
aws sts get-caller-identity --profile tfs-ai-staging

# Should show account: 087439708020
```

### Terraform state locked

```bash
# Force unlock (use with caution)
cd terraform
../terraform-docker.sh force-unlock <LOCK_ID>
```

## Next Steps

Once connected to bastion:

1. Explore the VPC:
   ```bash
   aws ec2 describe-vpcs --region us-east-1
   ```

2. Check available commands:
   ```bash
   alias
   ```

3. View logs:
   ```bash
   sudo tail -f /var/log/messages
   ```

## Useful Commands

```bash
# All-in-one deployment
make deploy-bastion

# Check status
make monitor ENV=staging

# Connect to bastion
make ssh-bastion ENV=staging

# View all outputs
cd terraform && ../terraform-docker.sh output

# Destroy (when done)
make destroy ENV=staging
```

## Support

- Check `docs/bastion-setup.md` for detailed information
- Review `CLAUDE.md` for project guidelines
- Monitor CloudWatch logs in AWS console

Happy deploying! 🚀