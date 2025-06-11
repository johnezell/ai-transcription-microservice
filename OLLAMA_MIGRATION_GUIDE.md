# Ollama Containerization Migration Guide

## Overview

This guide walks you through migrating from your local Ollama installation to a fully containerized Ollama service integrated with your AI Transcription Microservice.

## What Changed

### ✅ **Before (Local Setup)**
- Ollama running locally on your host machine
- Service endpoint: `http://localhost:11434` or `http://host.docker.internal:11434`
- Manual model management
- Potential networking issues between Docker and host

### ✅ **After (Containerized Setup)**
- Ollama running as a Docker service
- Service endpoint: `http://ollama-service:11434` (internal Docker network)
- Automatic model downloading and management
- Seamless Docker service integration

## Migration Steps

### Step 1: Stop Local Ollama Service

**On Windows:**
```powershell
# Find Ollama processes
Get-Process | Where-Object { $_.Name -like "*ollama*" }

# Stop Ollama service if running
Stop-Service -Name "Ollama" -ErrorAction SilentlyContinue

# Kill any remaining processes
Get-Process | Where-Object { $_.Name -like "*ollama*" } | Stop-Process -Force
```

**On Linux/Mac:**
```bash
# Stop Ollama service
pkill ollama

# Or if installed as systemd service
sudo systemctl stop ollama
sudo systemctl disable ollama
```

### Step 2: Verify Configuration Changes

Check that your `docker-compose.yml` has been updated:

```yaml
transcription-service:
  environment:
    # ✅ Updated to use containerized Ollama
    - LLM_ENDPOINT=http://ollama-service:11434/api/generate
    - LLM_MODEL=llama3:latest
    - LLM_ENABLED=true
  depends_on:
    - ollama-service  # ✅ New dependency

ollama-service:  # ✅ New service
  image: ollama/ollama:latest
  # ... full configuration
```

### Step 3: Start the Containerized Stack

```bash
# Start all services including the new Ollama service
docker-compose up -d

# Monitor the startup process
docker-compose logs -f ollama-service
```

**Expected startup sequence:**
1. 🚀 Ollama daemon starts
2. ⏳ Waiting for service to be ready
3. 📥 Pulling llama3:latest model (first time only)
4. ✅ Service ready for requests

### Step 4: Verify Service Integration

**Test Ollama directly:**
```bash
# Check if Ollama is running
curl http://localhost:11434/api/generate \
  -H "Content-Type: application/json" \
  -d '{
    "model": "llama3:latest",
    "prompt": "Test",
    "stream": false,
    "options": {"num_predict": 5}
  }'
```

**Test guitar term evaluation:**
```bash
# Test the integrated guitar term evaluator
curl -X POST http://localhost:5051/test-guitar-term-evaluator \
  -H "Content-Type: application/json" \
  -d '{}'
```

### Step 5: Clean Up Local Ollama (Optional)

If you no longer need local Ollama:

**Windows:**
```powershell
# Uninstall via Programs and Features or
# Delete Ollama directory if manually installed
```

**Linux/Mac:**
```bash
# Remove Ollama binary
sudo rm -f /usr/local/bin/ollama

# Remove models directory
rm -rf ~/.ollama

# Remove systemd service files
sudo rm -f /etc/systemd/system/ollama.service
sudo systemctl daemon-reload
```

## Verification Checklist

- [ ] Local Ollama service stopped
- [ ] Docker containers started successfully
- [ ] `ollama-service` container shows "ready" in logs
- [ ] llama3:latest model downloaded (check with `docker exec ollama-service ollama list`)
- [ ] Guitar term evaluator test endpoint responds successfully
- [ ] Transcription service can communicate with Ollama

## Troubleshooting

### Issue: Model Download Takes Too Long

**Symptoms:** Ollama container keeps restarting or times out during first startup

**Solutions:**
```bash
# Increase health check start_period in docker-compose.yml
healthcheck:
  start_period: 300s  # 5 minutes instead of 60s

# Or manually pull model
docker exec ollama-service ollama pull llama3:latest
```

### Issue: GPU Not Available

**Symptoms:** Slow model performance or CUDA errors

**Solutions:**
```bash
# Check GPU availability
docker exec ollama-service nvidia-smi

# If no GPU, remove GPU configuration from docker-compose.yml
# Comment out the deploy.resources.reservations section
```

### Issue: Service Not Responding

**Symptoms:** Curl requests timeout or connection refused

**Solutions:**
```bash
# Check service logs
docker-compose logs ollama-service

# Restart service
docker-compose restart ollama-service

# Check network connectivity
docker exec transcription-service ping ollama-service
```

### Issue: Old Environment Variables

**Symptoms:** Guitar term evaluator still trying to connect to host.docker.internal

**Solutions:**
```bash
# Verify environment variables in running container
docker exec transcription-service env | grep LLM

# Should show:
# LLM_ENDPOINT=http://ollama-service:11434/api/generate
# LLM_MODEL=llama3:latest
```

## Performance Comparison

### Resource Usage

| Aspect | Local Ollama | Containerized Ollama |
|--------|--------------|---------------------|
| **Isolation** | Shared host resources | Dedicated container resources |
| **GPU Access** | Direct | Through Docker GPU passthrough |
| **Memory** | Shared with host | Configurable container limits |
| **Storage** | Host filesystem | Docker volumes (persistent) |

### Benefits of Containerization

✅ **Consistent Environment**: Same Ollama version across all deployments  
✅ **Resource Control**: Docker resource limits and monitoring  
✅ **Service Discovery**: Native Docker networking  
✅ **Backup/Restore**: Easy volume backup for models  
✅ **Scaling**: Can easily add multiple Ollama instances  
✅ **Security**: Network isolation and controlled access  

## Monitoring Commands

```bash
# Monitor all services
docker-compose ps

# Check Ollama health
docker exec ollama-service ollama list

# Monitor resource usage
docker stats ollama-service

# View real-time logs
docker-compose logs -f ollama-service

# Check disk usage for models
docker exec ollama-service du -h /root/.ollama
```

## Rollback Plan

If you need to rollback to local Ollama:

1. **Stop containers:**
   ```bash
   docker-compose down
   ```

2. **Revert docker-compose.yml:**
   ```yaml
   transcription-service:
     environment:
       - LLM_ENDPOINT=http://host.docker.internal:11434/api/generate
     extra_hosts:
       - "host.docker.internal:host-gateway"
   ```

3. **Start local Ollama:**
   ```bash
   ollama serve
   ollama pull llama3:latest
   ```

4. **Restart services:**
   ```bash
   docker-compose up -d
   ```

## Next Steps

Once migration is complete:

1. **Monitor Performance**: Check guitar term evaluation accuracy and response times
2. **Optimize Resources**: Adjust container resource limits as needed
3. **Consider Additional Models**: Add specialized models for different domains
4. **Backup Strategy**: Set up automated backups of the `ollama_data` volume

---

## Summary

✅ **Migration Complete!** Your Ollama service is now fully containerized and integrated with your AI Transcription Microservice. The guitar terminology evaluator will continue to work seamlessly with improved reliability and easier management.

For any issues, refer to the troubleshooting section above or check the logs with `docker-compose logs ollama-service`. 