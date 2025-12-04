#!/bin/bash
# Bastion Development Environment Setup
set -e

# Terraform variables
ENVIRONMENT="${environment}"
REGION="${region}"
PROJECT="${project}"
EIP_ALLOCATION_ID="${eip_allocation_id}"
ENABLE_DEV_TOOLS="${enable_dev_tools}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"; }
log "Starting bastion initialization..."

# Associate EIP if configured
if [ -n "$EIP_ALLOCATION_ID" ]; then
    TOKEN=$(curl -s -X PUT "http://169.254.169.254/latest/api/token" -H "X-aws-ec2-metadata-token-ttl-seconds: 21600")
    INSTANCE_ID=$(curl -s -H "X-aws-ec2-metadata-token: $$TOKEN" http://169.254.169.254/latest/meta-data/instance-id)
    aws ec2 associate-address --instance-id "$$INSTANCE_ID" --allocation-id "$$EIP_ALLOCATION_ID" --region "$$REGION" || true
    log "EIP associated"
fi

# System updates
dnf update -y && dnf upgrade -y

# Essential packages (curl-minimal is pre-installed, don't replace it)
dnf install -y --allowerasing htop vim git tmux aws-cli jq nmap-ncat bind-utils make gcc openssl-devel amazon-cloudwatch-agent wget unzip tar

if [ "$$ENABLE_DEV_TOOLS" = "true" ]; then
    # Docker
    dnf install -y docker && systemctl enable --now docker && usermod -aG docker ec2-user
    COMPOSE_VER="v2.24.5"
    mkdir -p /usr/local/lib/docker/cli-plugins
    curl -SL "https://github.com/docker/compose/releases/download/$$COMPOSE_VER/docker-compose-linux-x86_64" -o /usr/local/lib/docker/cli-plugins/docker-compose
    chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
    ln -sf /usr/local/lib/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose

    # Node.js 20
    curl -fsSL https://rpm.nodesource.com/setup_20.x | bash - && dnf install -y nodejs
    npm install -g npm@latest yarn

    # PHP (try available versions)
    dnf install -y php php-cli php-common php-mysqlnd php-pdo php-mbstring php-xml php-curl php-zip php-bcmath php-gd || true
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

    # Python 3.11
    dnf install -y python3.11 python3.11-pip || true
    python3 -m pip install --upgrade pip virtualenv

    # Terraform
    TF_VER="1.7.0"
    wget -q "https://releases.hashicorp.com/terraform/$$TF_VER/terraform_$${TF_VER}_linux_amd64.zip" -O /tmp/terraform.zip
    unzip -o /tmp/terraform.zip -d /usr/local/bin/ && rm /tmp/terraform.zip
fi

# Session Manager plugin
curl -s "https://s3.amazonaws.com/session-manager-downloads/plugin/latest/linux_64bit/session-manager-plugin.rpm" -o /tmp/ssm.rpm
dnf install -y /tmp/ssm.rpm && rm /tmp/ssm.rpm

# SSH config
cat >> /etc/ssh/sshd_config << 'EOF'
PermitRootLogin no
PasswordAuthentication no
ClientAliveInterval 300
AllowAgentForwarding yes
EOF
systemctl restart sshd

# MOTD
cat > /etc/motd << EOF
=== TFS AI Dev Bastion ($ENVIRONMENT) ===
Run 'thoth-info' to see installed tools
EOF

# Aliases - use $$ for Terraform to output literal $
cat > /etc/profile.d/thoth.sh << 'ALIASES'
alias ll='ls -la'
alias dc='docker compose'
alias tf='terraform'
thoth-clone() { git clone git@github.com:TrueFireStudios/ai-transcription-microservice.git ~/thoth && cd ~/thoth; }
thoth-up() { cd ~/thoth && docker compose up -d; }
thoth-down() { cd ~/thoth && docker compose down; }
ecs-exec() {
    [ $# -lt 2 ] && echo "Usage: ecs-exec <cluster> <service> [container]" && return 1
    TASK=$(aws ecs list-tasks --cluster $1 --service-name $2 --query 'taskArns[0]' --output text | awk -F'/' '{print $NF}')
    aws ecs execute-command --cluster $1 --task $TASK --container $3 --interactive --command "/bin/bash"
}
ecr-login() {
    REGION=$1
    ACCT=$2
    aws ecr get-login-password --region $REGION | docker login --username AWS --password-stdin $ACCT.dkr.ecr.$REGION.amazonaws.com
}
thoth-info() {
    docker --version 2>/dev/null || echo "Docker: N/A"
    node --version 2>/dev/null || echo "Node: N/A"
    php --version 2>/dev/null | head -1 || echo "PHP: N/A"
    python3 --version 2>/dev/null || echo "Python: N/A"
    terraform version 2>/dev/null | head -1 || echo "Terraform: N/A"
}
ALIASES

# SSH keys
mkdir -p /home/ec2-user/.ssh
cat >> /home/ec2-user/.ssh/authorized_keys << 'EOF'
${additional_ssh_keys}
EOF
chmod 600 /home/ec2-user/.ssh/authorized_keys
chown -R ec2-user:ec2-user /home/ec2-user/.ssh

# Git config
cat > /home/ec2-user/.gitconfig << 'EOF'
[user]
name = TFS AI Bastion
email = bastion@tfs.services
[init]
defaultBranch = main
EOF
chown ec2-user:ec2-user /home/ec2-user/.gitconfig

mkdir -p /home/ec2-user/workspace && chown ec2-user:ec2-user /home/ec2-user/workspace

log "Bastion initialization complete!"
