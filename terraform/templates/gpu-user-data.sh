#!/bin/bash
# GPU instance initialization script for ECS

set -e

# Variables from Terraform
ECS_CLUSTER="${ecs_cluster_name}"
REGION="${region}"
EFS_ID="${efs_id}"

# Update system
echo "Updating system packages..."
yum update -y

# Configure ECS agent
echo "Configuring ECS agent..."
echo "ECS_CLUSTER=$ECS_CLUSTER" >> /etc/ecs/ecs.config
echo "ECS_ENABLE_GPU_SUPPORT=true" >> /etc/ecs/ecs.config
echo "ECS_ENABLE_SPOT_INSTANCE_DRAINING=true" >> /etc/ecs/ecs.config
echo "ECS_CONTAINER_STOP_TIMEOUT=120s" >> /etc/ecs/ecs.config
echo "ECS_ENABLE_CONTAINER_METADATA=true" >> /etc/ecs/ecs.config

# Enable ECS Exec
echo "ECS_ENABLE_TASK_IAM_ROLE=true" >> /etc/ecs/ecs.config
echo "ECS_ENABLE_TASK_IAM_ROLE_NETWORK_HOST=true" >> /etc/ecs/ecs.config

# Install CloudWatch agent
echo "Installing CloudWatch agent..."
wget https://s3.amazonaws.com/amazoncloudwatch-agent/amazon_linux/amd64/latest/amazon-cloudwatch-agent.rpm
rpm -U ./amazon-cloudwatch-agent.rpm

# Configure CloudWatch agent for GPU metrics
cat > /opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json << EOF
{
  "agent": {
    "metrics_collection_interval": 60,
    "run_as_user": "root"
  },
  "metrics": {
    "namespace": "TFS-AI/GPU",
    "metrics_collected": {
      "nvidia_gpu": {
        "measurement": [
          {
            "name": "utilization_gpu",
            "rename": "GPUUtilization",
            "unit": "Percent"
          },
          {
            "name": "utilization_memory",
            "rename": "GPUMemoryUtilization",
            "unit": "Percent"
          },
          {
            "name": "temperature_gpu",
            "rename": "GPUTemperature",
            "unit": "None"
          },
          {
            "name": "power_draw",
            "rename": "GPUPowerDraw",
            "unit": "None"
          }
        ],
        "metrics_collection_interval": 60
      },
      "cpu": {
        "measurement": [
          {
            "name": "cpu_usage_idle",
            "rename": "CPU_USAGE_IDLE",
            "unit": "Percent"
          },
          {
            "name": "cpu_usage_iowait",
            "rename": "CPU_USAGE_IOWAIT",
            "unit": "Percent"
          }
        ],
        "metrics_collection_interval": 60,
        "resources": [
          "*"
        ],
        "totalcpu": false
      },
      "disk": {
        "measurement": [
          {
            "name": "used_percent",
            "rename": "DISK_USED_PERCENT",
            "unit": "Percent"
          }
        ],
        "metrics_collection_interval": 60,
        "resources": [
          "*"
        ]
      },
      "mem": {
        "measurement": [
          {
            "name": "mem_used_percent",
            "rename": "MEM_USED_PERCENT",
            "unit": "Percent"
          }
        ],
        "metrics_collection_interval": 60
      }
    }
  }
}
EOF

# Start CloudWatch agent
/opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl \
    -a fetch-config \
    -m ec2 \
    -s \
    -c file:/opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json

# Install nvidia-docker2 for GPU support
echo "Installing NVIDIA Docker support..."
distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
curl -s -L https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.repo | \
    tee /etc/yum.repos.d/nvidia-docker.repo
yum clean expire-cache
yum install -y nvidia-docker2

# Restart Docker
systemctl restart docker

# Mount EFS for shared model storage
echo "Mounting EFS..."
yum install -y amazon-efs-utils
mkdir -p /mnt/efs
echo "$EFS_ID:/ /mnt/efs efs defaults,_netdev 0 0" >> /etc/fstab
mount -a

# Create directories for models
mkdir -p /mnt/efs/models/whisper
mkdir -p /mnt/efs/models/spacy
chmod -R 755 /mnt/efs/models

# Pre-download Whisper models to EFS (if not already present)
if [ ! -f "/mnt/efs/models/whisper/large-v3.pt" ]; then
    echo "Downloading Whisper models..."
    # This would be done by a container, not directly here
    echo "Models will be downloaded by containers on first run"
fi

# Install GPU monitoring tools
echo "Installing GPU monitoring tools..."
yum install -y python3 python3-pip
pip3 install nvidia-ml-py3 boto3

# Create GPU monitoring script
cat > /usr/local/bin/gpu-monitor.py << 'EOF'
#!/usr/bin/env python3
import nvidia_ml_py3 as nvml
import boto3
import time
import socket
from datetime import datetime

def get_gpu_metrics():
    nvml.nvmlInit()
    device_count = nvml.nvmlDeviceGetCount()
    
    metrics = []
    for i in range(device_count):
        handle = nvml.nvmlDeviceGetHandleByIndex(i)
        
        # Get GPU metrics
        util = nvml.nvmlDeviceGetUtilizationRates(handle)
        memory = nvml.nvmlDeviceGetMemoryInfo(handle)
        temp = nvml.nvmlDeviceGetTemperature(handle, nvml.NVML_TEMPERATURE_GPU)
        power = nvml.nvmlDeviceGetPowerUsage(handle) / 1000.0  # Convert to watts
        
        metrics.append({
            'MetricName': 'GPUUtilization',
            'Value': util.gpu,
            'Unit': 'Percent',
            'Dimensions': [
                {'Name': 'InstanceId', 'Value': socket.gethostname()},
                {'Name': 'GPUNumber', 'Value': str(i)}
            ]
        })
        
        metrics.append({
            'MetricName': 'GPUMemoryUtilization',
            'Value': (memory.used / memory.total) * 100,
            'Unit': 'Percent',
            'Dimensions': [
                {'Name': 'InstanceId', 'Value': socket.gethostname()},
                {'Name': 'GPUNumber', 'Value': str(i)}
            ]
        })
        
    nvml.nvmlShutdown()
    return metrics

def send_metrics():
    cloudwatch = boto3.client('cloudwatch', region_name='${region}')
    
    try:
        metrics = get_gpu_metrics()
        if metrics:
            cloudwatch.put_metric_data(
                Namespace='TFS-AI/GPU',
                MetricData=metrics
            )
    except Exception as e:
        print(f"Error sending metrics: {e}")

if __name__ == "__main__":
    while True:
        send_metrics()
        time.sleep(60)
EOF

chmod +x /usr/local/bin/gpu-monitor.py

# Create systemd service for GPU monitoring
cat > /etc/systemd/system/gpu-monitor.service << EOF
[Unit]
Description=GPU Metrics Monitor
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/gpu-monitor.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# Enable and start GPU monitor
systemctl enable gpu-monitor
systemctl start gpu-monitor

# Configure spot instance interruption handler
echo "Setting up spot instance interruption handler..."
cat > /usr/local/bin/spot-interrupt-handler.sh << 'EOF'
#!/bin/bash
while true; do
    # Check for spot interruption notice
    if curl -s http://169.254.169.254/latest/meta-data/spot/instance-action | grep -q terminate; then
        echo "Spot instance interruption notice detected"
        # Drain ECS tasks
        ECS_AGENT_URI=http://localhost:51678/v1/metadata
        CONTAINER_INSTANCE=$(curl -s $ECS_AGENT_URI | jq -r '.ContainerInstanceArn')
        aws ecs update-container-instances-state \
            --cluster ${ecs_cluster_name} \
            --container-instances $CONTAINER_INSTANCE \
            --status DRAINING \
            --region ${region}
    fi
    sleep 5
done
EOF

chmod +x /usr/local/bin/spot-interrupt-handler.sh

# Create systemd service for spot handler
cat > /etc/systemd/system/spot-interrupt-handler.service << EOF
[Unit]
Description=Spot Instance Interruption Handler
After=ecs.service

[Service]
Type=simple
ExecStart=/usr/local/bin/spot-interrupt-handler.sh
Restart=always

[Install]
WantedBy=multi-user.target
EOF

# Enable and start spot handler
systemctl enable spot-interrupt-handler
systemctl start spot-interrupt-handler

# Start ECS agent
systemctl enable ecs
systemctl start ecs

echo "GPU instance initialization complete!"