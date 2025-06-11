# Ollama Docker Integration

## Overview

This directory contains the Docker configuration for running Ollama (Large Language Model server) as a containerized service within the AI Transcription Microservice application. Ollama is used by the Guitar Terminology Evaluator to intelligently identify and boost confidence scores for musical terminology in transcription results.

## Components

### Files
- `entrypoint.sh` - Custom entrypoint script that starts Ollama and automatically pulls the llama3 model
- `init-models.sh` - Standalone initialization script (for reference)
- `README.md` - This documentation file

### Docker Service Configuration
The Ollama service is configured in `docker-compose.yml` with:
- **Image**: `ollama/ollama:latest`
- **GPU Support**: NVIDIA GPU access for optimal performance
- **Persistent Storage**: Model storage in Docker volume `ollama_data`
- **Network**: Connected to `app-network` for internal service communication
- **Port**: Exposed on `11434` for API access

## Features

### ✅ Automatic Model Management
- Automatically pulls `llama3:latest` model on first startup
- Persistent model storage prevents re-downloading on container restarts
- Model availability verification and health checks

### ✅ GPU Acceleration
- NVIDIA GPU support for fast model inference
- Configurable GPU allocation via `CUDA_VISIBLE_DEVICES`

### ✅ Service Integration
- Seamless integration with transcription service
- Docker networking eliminates need for `host.docker.internal`
- Health checks ensure service readiness

### ✅ Production Ready
- Proper signal handling for graceful shutdowns
- Comprehensive logging and status reporting
- Error handling and fallback mechanisms

## Usage

### Starting the Service
```bash
# Start all services including Ollama
docker-compose up -d

# Start only Ollama service
docker-compose up -d ollama-service

# View Ollama logs
docker-compose logs -f ollama-service
```

### Verifying Service Status
```bash
# Check if Ollama is running
curl http://localhost:11434/api/generate \
  -H "Content-Type: application/json" \
  -d '{
    "model": "llama3:latest",
    "prompt": "Test prompt",
    "stream": false,
    "options": {
      "temperature": 0.1,
      "num_predict": 5
    }
  }'

# List available models
docker exec ollama-service ollama list

# Check service health
docker exec ollama-service ollama --version
```

### Testing Guitar Term Evaluation
```bash
# Test the guitar term evaluator endpoint
curl -X POST http://localhost:5051/test-guitar-term-evaluator \
  -H "Content-Type: application/json" \
  -d '{
    "llm_endpoint": "http://ollama-service:11434/api/generate",
    "model_name": "llama3:latest"
  }'
```

## Configuration

### Environment Variables
The Ollama service is configured via environment variables in `docker-compose.yml`:

```yaml
environment:
  - OLLAMA_HOST=0.0.0.0          # Listen on all interfaces
  - OLLAMA_ORIGINS=*             # Allow all origins (internal network)
  - CUDA_VISIBLE_DEVICES=0       # Use first GPU
```

### Transcription Service Integration
The transcription service automatically uses the containerized Ollama:

```yaml
environment:
  - LLM_ENDPOINT=http://ollama-service:11434/api/generate
  - LLM_MODEL=llama3:latest
  - LLM_ENABLED=true
```

## Performance Considerations

### Model Size
- **llama3:latest**: ~4.7GB download
- **First startup**: May take 5-15 minutes depending on internet speed
- **Subsequent startups**: Fast (models are cached)

### Resource Requirements
- **RAM**: 8GB+ recommended for llama3
- **GPU**: NVIDIA GPU recommended for optimal performance
- **Storage**: 10GB+ for model storage

### Optimization Tips
1. **Persistent Storage**: The `ollama_data` volume prevents model re-downloading
2. **GPU Allocation**: Adjust `CUDA_VISIBLE_DEVICES` if multiple GPUs available
3. **Model Selection**: Consider smaller models (llama3:8b) for resource-constrained environments

## Troubleshooting

### Common Issues

#### Model Download Fails
```bash
# Manually pull model
docker exec ollama-service ollama pull llama3:latest

# Check available space
docker exec ollama-service df -h
```

#### Service Not Ready
```bash
# Check logs for errors
docker-compose logs ollama-service

# Verify health check
docker exec ollama-service ollama list

# Restart service
docker-compose restart ollama-service
```

#### GPU Not Available
```bash
# Check GPU availability
docker exec ollama-service nvidia-smi

# Run without GPU (CPU mode)
# Remove the deploy.resources section from docker-compose.yml
```

### Log Analysis
```bash
# View initialization logs
docker-compose logs ollama-service | grep -E "(🚀|✅|❌|🎉)"

# Monitor real-time logs
docker-compose logs -f ollama-service
```

## Migration from Local Ollama

If you were previously running Ollama locally:

1. **Stop Local Ollama**: `pkill ollama` or stop the local service
2. **Remove Host Networking**: The docker-compose.yml has been updated to remove `host.docker.internal`
3. **Use Containerized Service**: All requests now go to `http://ollama-service:11434`

### Configuration Changes
- ✅ `LLM_ENDPOINT`: `http://host.docker.internal:11434` → `http://ollama-service:11434`
- ✅ `LLM_MODEL`: `llama3` → `llama3:latest`
- ✅ **Dependencies**: Added `ollama-service` to transcription service dependencies
- ✅ **Volumes**: Added `ollama_data` for persistent model storage

## API Endpoints

### Available Endpoints
- `POST /api/generate` - Generate text completion
- `GET /api/tags` - List available models
- `POST /api/pull` - Pull/download models
- `DELETE /api/delete` - Delete models

### Example Usage
```bash
# Text generation
curl http://localhost:11434/api/generate \
  -d '{
    "model": "llama3:latest",
    "prompt": "Is the word '\''fretboard'\'' related to guitar instruction?",
    "stream": false
  }'
```

## Security Considerations

- **Internal Network**: Ollama runs on isolated Docker network
- **No External Exposure**: Only accessible within Docker network by default
- **GPU Access**: Containers have controlled GPU access
- **Model Integrity**: Models are verified during download

## Monitoring

### Health Checks
- **Interval**: 30 seconds
- **Timeout**: 10 seconds
- **Start Period**: 60 seconds (allows for model download)
- **Command**: `ollama list`

### Key Metrics to Monitor
- Model download progress
- GPU utilization
- Response times for guitar term evaluation
- Memory usage

---

## Integration Status

✅ **Completed**: Docker service configuration  
✅ **Completed**: Automatic model initialization  
✅ **Completed**: Transcription service integration  
✅ **Completed**: Health checks and monitoring  
✅ **Completed**: Documentation and troubleshooting guides  

The containerized Ollama service is now ready for guitar terminology evaluation in the AI Transcription Microservice! 