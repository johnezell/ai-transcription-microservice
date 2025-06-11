#!/bin/bash
set -e

echo "🚀 Ollama initialization script starting..."

# Wait for Ollama to be ready
echo "⏳ Waiting for Ollama service to be ready..."
timeout=300  # 5 minutes timeout
elapsed=0
interval=5

while ! ollama list >/dev/null 2>&1; do
    if [ $elapsed -ge $timeout ]; then
        echo "❌ Timeout waiting for Ollama to become ready"
        exit 1
    fi
    echo "⏳ Ollama not ready yet... waiting ${interval}s (${elapsed}/${timeout}s elapsed)"
    sleep $interval
    elapsed=$((elapsed + interval))
done

echo "✅ Ollama service is ready!"

# Check if llama3:latest is already available
if ollama list | grep -q "llama3:latest"; then
    echo "✅ llama3:latest is already available"
else
    echo "📥 Pulling llama3:latest model..."
    echo "⚠️  This may take a while (several GB download)..."
    
    # Pull the model with progress output
    if ollama pull llama3:latest; then
        echo "✅ Successfully pulled llama3:latest"
    else
        echo "❌ Failed to pull llama3:latest"
        exit 1
    fi
fi

# Verify the model is working
echo "🧪 Testing model functionality..."
if echo "Test prompt" | ollama run llama3:latest >/dev/null 2>&1; then
    echo "✅ llama3:latest model is working correctly"
else
    echo "⚠️  Model test failed, but model is installed"
fi

echo "🎉 Ollama initialization complete!"
echo "📊 Available models:"
ollama list

echo "🔗 Ollama is ready for guitar term evaluation!"
echo "   Endpoint: http://ollama-service:11434/api/generate"
echo "   Model: llama3:latest" 