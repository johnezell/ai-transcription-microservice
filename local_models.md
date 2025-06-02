# Local AI Models for Code Development

A comprehensive guide to setting up and using local AI models for enhanced coding productivity with Docker Model Runner, Cursor IDE, and Continue extension.

## Table of Contents

- [Introduction](#introduction)
- [Prerequisites](#prerequisites)
- [Docker Model Runner Setup](#docker-model-runner-setup)
- [Available Code Models](#available-code-models)
- [IDE Integration](#ide-integration)
  - [Cursor IDE](#cursor-ide)
  - [Continue Extension (VS Code)](#continue-extension-vs-code)
  - [Alternative: Ollama Setup](#alternative-ollama-setup)
- [Configuration Examples](#configuration-examples)
- [Troubleshooting](#troubleshooting)
- [Best Practices](#best-practices)
- [Hardware Requirements](#hardware-requirements)

## Introduction

Local AI models offer several advantages for developers:

- **Privacy & Security**: Your code never leaves your machine
- **No API Costs**: One-time hardware investment vs. recurring subscription fees
- **Faster Response Times**: No network latency
- **Offline Capability**: Works without internet connection
- **Complete Control**: Choose models and configurations that fit your needs

This guide covers setting up local AI models using Docker Model Runner and integrating them with popular IDEs.

## Prerequisites

### Required Software
- Docker Desktop 4.40 or later
- One of the following IDEs:
  - Cursor IDE
  - VS Code with Continue extension
- Git (for cloning repositories)

### Hardware Requirements
- **Minimum**: 8GB GPU VRAM, 16GB RAM, SSD storage
- **Recommended**: 16GB+ GPU VRAM (RTX 4090, RTX 6000), 32GB+ RAM, NVMe SSD
- **Platform Support**: 
  - Docker Desktop for Mac with Apple Silicon
  - Windows with NVIDIA GPUs

## Docker Model Runner Setup

### 1. Enable Docker Model Runner

1. Open Docker Desktop settings
2. Navigate to **Features in development** → **Experimental features**
3. Enable **Access experimental features**
4. Click **Apply and restart**
5. Go to **Features in development** → **Beta** tab
6. Enable **Docker Model Runner**
7. (Windows) Enable **GPU-backed inference** if available

### 2. Verify Installation

```bash
# Test Docker Model Runner
docker model --help

# If command not found, create symlink (macOS)
ln -s /Applications/Docker.app/Contents/Resources/cli-plugins/docker-model ~/.docker/cli-plugins/docker-model
```

### 3. Pull and Test a Model

```bash
# Pull a code model
docker model pull ai/codellama

# Test the model
docker model run ai/codellama "Write a Python function to reverse a string"

# List available models
docker model ls
```

### 4. Enable TCP Access (for IDE integration)

```bash
# Enable TCP access on port 12434
docker desktop enable model-runner --tcp 12434

# Verify API is accessible
curl http://localhost:12434/engines/v1/models
```

## Available Code Models

### Docker Hub AI Namespace Models

| Model | Size | Description | Best For |
|-------|------|-------------|----------|
| `ai/codellama` | 7B-34B | Meta's code-specialized model | General coding, multiple languages |
| `ai/starcoder` | 3B-15B | Strong open-source code model | Code completion, generation |
| `ai/deepseek-coder` | 1.3B-33B | Excellent reasoning and debugging | Complex coding problems |
| `ai/smollm2` | 1.7B | Lightweight coding assistant | Quick tasks, limited hardware |

### Model Selection Guide

- **For beginners**: Start with `ai/smollm2` or `ai/codellama:7b`
- **For production use**: `ai/deepseek-coder:33b` or `ai/codellama:34b`
- **For limited hardware**: `ai/starcoder:3b` or `ai/smollm2`
- **For code completion**: `ai/starcoder` models

## IDE Integration

### Cursor IDE

Cursor has limitations with local models, but here are workarounds:

#### Method 1: Using ngrok Proxy

```bash
# Install ngrok from https://ngrok.com/download

# Expose local Docker Model Runner
ngrok http 12434
```

**Cursor Configuration:**
1. Open Cursor Settings → Models
2. Enable "Override OpenAI Base URL"
3. Set base URL to your ngrok URL: `https://abc123.ngrok.io/engines/v1`
4. Use any dummy API key
5. Select desired models

#### Method 2: Custom Proxy (Advanced)

```bash
# Clone proxy project
git clone https://github.com/danilofalcao/cursor-deepseek.git
cd cursor-deepseek

# Set environment variables
cp .env.example .env
# Edit .env with your configuration

# Run proxy
go run proxy.go
```

### Continue Extension (VS Code)

**Recommended approach for local models**

#### 1. Install Continue Extension

1. Open VS Code
2. Go to Extensions (Ctrl+Shift+X)
3. Search for "Continue"
4. Install the extension

#### 2. Configure Continue

Create or edit `~/.continue/config.json`:

```json
{
  "models": [
    {
      "title": "CodeLlama Local",
      "model": "ai/codellama",
      "provider": "openai",
      "apiBase": "http://localhost:12434/engines/v1",
      "apiKey": "dummy-key",
      "systemMessage": "You are an expert software developer. Provide concise, helpful responses."
    },
    {
      "title": "DeepSeek Coder",
      "model": "ai/deepseek-coder",
      "provider": "openai", 
      "apiBase": "http://localhost:12434/engines/v1",
      "apiKey": "dummy-key"
    }
  ],
  "tabAutocompleteModel": {
    "title": "StarCoder Autocomplete",
    "model": "ai/starcoder",
    "provider": "openai",
    "apiBase": "http://localhost:12434/engines/v1",
    "apiKey": "dummy-key"
  }
}
```

### Alternative: Ollama Setup

For users preferring Ollama over Docker Model Runner:

#### 1. Install and Run Ollama

```bash
# Using Docker
docker run -d --name ollama -p 11434:11434 ollama/ollama

# Pull code models
docker exec ollama ollama pull deepseek-coder
docker exec ollama ollama pull codellama
docker exec ollama ollama pull starcoder2
```

#### 2. Configure Continue for Ollama

```json
{
  "models": [
    {
      "title": "DeepSeek Coder",
      "model": "deepseek-coder",
      "provider": "ollama",
      "apiBase": "http://localhost:11434"
    },
    {
      "title": "CodeLlama", 
      "model": "codellama",
      "provider": "ollama",
      "apiBase": "http://localhost:11434"
    }
  ],
  "tabAutocompleteModel": {
    "title": "StarCoder2",
    "model": "starcoder2",
    "provider": "ollama",
    "apiBase": "http://localhost:11434"
  }
}
```

## Configuration Examples

### Basic Docker Model Runner API Usage

```bash
# Chat completion example
curl http://localhost:12434/engines/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{
    "model": "ai/codellama",
    "messages": [
      {
        "role": "system",
        "content": "You are a helpful coding assistant."
      },
      {
        "role": "user", 
        "content": "Write a Python function to calculate factorial"
      }
    ]
  }'
```

### RTX 4080 16GB Optimized Configuration

For RTX 4080 with 16GB VRAM, this configuration maximizes performance:

```json
{
  "models": [
    {
      "title": "DeepSeek Coder 33B",
      "model": "ai/deepseek-coder:33b",
      "provider": "openai",
      "apiBase": "http://localhost:12434/engines/v1",
      "apiKey": "dummy",
      "systemMessage": "You are an expert software developer. Provide detailed, accurate code solutions.",
      "contextLength": 8192,
      "maxTokens": 2048
    },
    {
      "title": "CodeLlama 13B Fast",
      "model": "ai/codellama:13b",
      "provider": "openai",
      "apiBase": "http://localhost:12434/engines/v1",
      "apiKey": "dummy",
      "systemMessage": "You are a helpful coding assistant focused on speed and efficiency."
    }
  ],
  "tabAutocompleteModel": {
    "title": "StarCoder Fast Autocomplete",
    "model": "ai/starcoder:7b",
    "provider": "openai",
    "apiBase": "http://localhost:12434/engines/v1",
    "apiKey": "dummy"
  }
}
```

### Advanced Continue Configuration

```json
{
  "models": [
    {
      "title": "Local CodeLlama",
      "model": "ai/codellama",
      "provider": "openai",
      "apiBase": "http://localhost:12434/engines/v1",
      "apiKey": "dummy",
      "systemMessage": "You are an expert software developer focusing on clean, efficient code.",
      "contextLength": 4096,
      "maxTokens": 1024
    }
  ],
  "tabAutocompleteModel": {
    "title": "Fast Autocomplete",
    "model": "ai/starcoder",
    "provider": "openai",
    "apiBase": "http://localhost:12434/engines/v1", 
    "apiKey": "dummy"
  },
  "customCommands": [
    {
      "name": "comment",
      "prompt": "Write clear, concise comments for this code:\n\n{{{ input }}}"
    },
    {
      "name": "test",
      "prompt": "Write comprehensive unit tests for this function:\n\n{{{ input }}}"
    }
  ]
}
```

## Troubleshooting

### Common Issues

#### Docker Model Runner Not Found
```bash
# Check if Docker Model Runner is enabled
docker model --version

# Create symlink if needed (macOS)
ln -s /Applications/Docker.app/Contents/Resources/cli-plugins/docker-model ~/.docker/cli-plugins/docker-model
```

#### API Connection Issues
```bash
# Verify TCP access is enabled
curl http://localhost:12434/engines/v1/models

# Check if port is listening
netstat -an | grep 12434

# Restart Docker Desktop if needed
```

#### Model Loading Errors
```bash
# Check available system resources
docker stats

# Verify model is pulled
docker model ls

# Try pulling model again
docker model pull ai/codellama
```

#### Continue Extension Issues
1. Check config.json syntax with a JSON validator
2. Restart VS Code after configuration changes
3. Check the Continue output panel for error messages
4. Verify API endpoint is accessible

### Performance Issues

#### Slow Response Times
- **Reduce model size**: Use smaller variants (7B instead of 34B)
- **Increase GPU memory**: Close other GPU-intensive applications
- **Check thermal throttling**: Ensure adequate cooling
- **Use SSD storage**: Models load faster from solid-state drives

#### Out of Memory Errors
- **Switch to smaller models**: `ai/smollm2` or `ai/starcoder:3b`
- **Close other applications**: Free up system RAM
- **Reduce context length**: Lower `contextLength` in configuration
- **Use quantized models**: Look for Q4 or Q8 variants

## Best Practices

### Model Selection Strategy

1. **Start Small**: Begin with lightweight models like `ai/smollm2`
2. **Test Different Models**: Each model has strengths for different tasks
3. **Monitor Performance**: Use system monitoring to track resource usage
4. **Upgrade Gradually**: Move to larger models as hardware allows

### Configuration Tips

1. **Use Descriptive Titles**: Name models clearly in configurations
2. **Set Appropriate Context Lengths**: Balance capability with performance
3. **Configure System Messages**: Tailor AI behavior to your coding style
4. **Regular Updates**: Keep Docker Model Runner and models updated

### Security Considerations

1. **Local Network Only**: Don't expose model APIs to the internet without authentication
2. **Firewall Rules**: Configure appropriate network access controls
3. **Regular Updates**: Keep all software components updated
4. **Backup Configurations**: Save working configurations before changes

### Development Workflow Integration

1. **Use for Code Review**: Ask models to review and suggest improvements
2. **Documentation Generation**: Generate comments and documentation
3. **Test Case Creation**: Have models write unit tests
4. **Debugging Assistance**: Explain complex code sections
5. **Learning Tool**: Ask questions about unfamiliar code patterns

## Hardware Requirements

### GPU Memory Requirements by Model Size

| Model Size | Minimum VRAM | Recommended VRAM | Performance |
|------------|-------------|------------------|-------------|
| 1-3B | 4GB | 8GB | Fast |
| 7B | 8GB | 12GB | Good |
| 13B | 12GB | 16GB | Better |
| 33B+ | 20GB | 24GB+ | Best |

### System Requirements

- **CPU**: Modern multi-core processor (Intel i5/AMD Ryzen 5 or better)
- **RAM**: 16GB minimum, 32GB+ recommended
- **Storage**: NVMe SSD with 100GB+ free space
- **Network**: Gigabit ethernet for model downloads

### Recommended Hardware Configurations

#### Budget Setup ($1,000-2,000)
- RTX 4060 Ti 16GB or RTX 4070
- 32GB RAM
- AMD Ryzen 7 or Intel i7
- 1TB NVMe SSD

#### Professional Setup ($3,000-5,000)
- RTX 4090 24GB or RTX 6000 Ada
- 64GB RAM  
- AMD Ryzen 9 or Intel i9
- 2TB NVMe SSD

#### Enterprise Setup ($10,000+)
- Multiple RTX 6000 Ada or H100
- 128GB+ RAM
- High-end workstation CPU
- Enterprise NVMe storage

## Conclusion

Local AI models provide a powerful, private, and cost-effective solution for AI-assisted coding. While setup requires some technical knowledge, the benefits of privacy, performance, and control make it worthwhile for serious developers.

Start with a basic setup using Continue extension and gradually expand as you become comfortable with the workflow. The investment in local AI infrastructure pays dividends in improved productivity and coding capabilities.

## Additional Resources

- [Docker Model Runner Documentation](https://docs.docker.com/model-runner/)
- [Continue Extension Documentation](https://continue.dev/docs)
- [Ollama Documentation](https://ollama.ai/docs)
- [Hardware Recommendations](https://www.exxactcorp.com/blog/deep-learning/run-llms-locally-with-continue-vs-code-extension)

---

**Last Updated**: January 2025  
**Version**: 1.0 