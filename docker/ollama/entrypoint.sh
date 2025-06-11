#!/bin/bash
set -e

echo "🚀 Starting Ollama service..."

# Start Ollama in the background
echo "🔥 Starting Ollama daemon..."
/bin/ollama serve &
OLLAMA_PID=$!

# Function to cleanup on exit
cleanup() {
    echo "🔄 Cleaning up..."
    if kill -0 $OLLAMA_PID 2>/dev/null; then
        echo "🛑 Stopping Ollama daemon..."
        kill $OLLAMA_PID
        wait $OLLAMA_PID
    fi
    exit 0
}

# Setup signal handlers
trap cleanup SIGTERM SIGINT

# Wait for Ollama to be ready and then initialize models in background
(
    echo "⏳ Waiting for Ollama to be ready for model initialization..."
    timeout=120  # 2 minutes timeout for daemon startup
    elapsed=0
    interval=2

    while ! ollama list >/dev/null 2>&1; do
        if [ $elapsed -ge $timeout ]; then
            echo "❌ Timeout waiting for Ollama daemon to start"
            exit 1
        fi
        echo "⏳ Ollama daemon not ready... waiting ${interval}s (${elapsed}/${timeout}s elapsed)"
        sleep $interval
        elapsed=$((elapsed + interval))
    done

    echo "✅ Ollama daemon is ready for model operations!"

    # Check if llama3:latest is already available
    if ollama list | grep -q "llama3:latest"; then
        echo "✅ llama3:latest is already available"
    else
        echo "📥 Pulling llama3:latest model in background..."
        echo "⚠️  This may take a while (several GB download)..."
        
        # Pull the model
        if ollama pull llama3:latest; then
            echo "✅ Successfully pulled llama3:latest"
        else
            echo "❌ Failed to pull llama3:latest - will continue with service running"
        fi
    fi

    echo "🎉 Model initialization complete!"
    echo "📊 Available models:"
    ollama list
    echo "🔗 Ollama is ready for guitar term evaluation!"

) &

# Wait for the Ollama process to finish
echo "⚙️  Ollama service is running (PID: $OLLAMA_PID)"
echo "🌐 Service available at: http://ollama-service:11434"
echo "🎸 Ready for guitar term evaluation requests!"

wait $OLLAMA_PID 