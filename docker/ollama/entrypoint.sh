#!/bin/bash
set -e

echo "🚀 Starting Ollama service..."

# Model configuration - use environment variable or smart defaults
DEFAULT_MODELS="llama3.2:3b,phi3.5:latest"
MODELS_TO_PULL="${OLLAMA_MODELS:-$DEFAULT_MODELS}"

echo "🎯 Configured to pull models: $MODELS_TO_PULL"
echo "📊 Model size reference:"
echo "   Small Models (1-4GB):"
echo "   - llama3.2:3b     ~2.0GB   (3B parameters) - Fast, good for basic tasks"
echo "   - phi3.5:latest   ~2.2GB   (3.8B parameters) - Microsoft's latest phi model"
echo "   - mistral:7b-instruct ~4.1GB (7B parameters) - Excellent instruction following"
echo ""
echo "   Medium Models (4-15GB):"
echo "   - qwen2.5:7b      ~4.7GB   (7B parameters) - Strong reasoning capabilities"
echo "   - phi3:medium     ~7.9GB   (14B parameters) - Microsoft's larger phi model"
echo "   - llama3.1:latest ~4.7GB   (8B parameters) - Latest Meta model"
echo ""
echo "   Large Models (15GB+):"
echo "   - qwen2.5:14b     ~8.7GB   (14B parameters) - Excellent for complex analysis"
echo "   - llama3.1:70b    ~40GB    (70B parameters) - Frontier-level capabilities"

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

    # Function to pull model with progress and error handling
    pull_model() {
        local model=$1
        echo "📥 Checking for model: $model"
        
        if ollama list | grep -q "$model"; then
            echo "✅ $model is already available"
            return 0
        else
            echo "📥 Pulling $model model..."
            echo "⚠️  Large model downloads may take 15-60+ minutes depending on size"
            
            # Show estimated download time based on model
            case $model in
                *"14b"*) echo "⏱️  Estimated download time: 5-15 minutes (~9.3GB)" ;;
                *"32b"*) echo "⏱️  Estimated download time: 10-30 minutes (~20GB)" ;;
                *"70b"*) echo "⏱️  Estimated download time: 20-60 minutes (~43GB)" ;;
                *"235b"*) echo "⏱️  Estimated download time: 60-180+ minutes (~142GB)" ;;
                *) echo "⏱️  Download time varies by model size" ;;
            esac
            
            # Pull with generous timeout for large models
            if timeout 3600 ollama pull "$model"; then  # 1 hour timeout
                echo "✅ Successfully pulled $model"
                
                # Quick test of the model
                echo "🧪 Testing $model..."
                if echo "Is 'fretboard' related to guitar? YES or NO." | timeout 30 ollama run "$model" >/dev/null 2>&1; then
                    echo "✅ $model is working correctly"
                else
                    echo "⚠️  $model test inconclusive but model is available"
                fi
                return 0
            else
                echo "❌ Failed to pull $model (timeout or error)"
                return 1
            fi
        fi
    }

    # Pull models (supports comma-separated list)
    IFS=',' read -ra MODEL_ARRAY <<< "$MODELS_TO_PULL"
    pulled_models=()
    failed_models=()
    
    for model in "${MODEL_ARRAY[@]}"; do
        model=$(echo "$model" | xargs)  # trim whitespace
        if pull_model "$model"; then
            pulled_models+=("$model")
        else
            failed_models+=("$model")
        fi
    done

    echo "🎉 Model initialization complete!"
    echo "📊 Available models:"
    ollama list
    
    # Summary
    if [ ${#pulled_models[@]} -gt 0 ]; then
        echo "✅ Successfully pulled models: ${pulled_models[*]}"
    fi
    if [ ${#failed_models[@]} -gt 0 ]; then
        echo "❌ Failed to pull models: ${failed_models[*]}"
        echo "💡 You can try smaller models or check your internet connection"
    fi
    
    echo "🔗 Ollama is ready for advanced guitar term evaluation!"
    echo "🎸 Larger models provide significantly better musical terminology recognition"

) &

# Wait for the Ollama process to finish
echo "⚙️  Ollama service is running (PID: $OLLAMA_PID)"
echo "🌐 Service available at: http://ollama-service:11434"
echo "🎸 Ready for guitar term evaluation requests!"

wait $OLLAMA_PID 