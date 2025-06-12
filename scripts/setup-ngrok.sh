#!/bin/bash

echo "🌐 Ngrok Setup Script for AI Transcription Microservice"
echo "=================================================="

# Check if ngrok.env exists, if not create from template
if [ ! -f "ngrok.env" ]; then
    echo "📄 Creating ngrok.env from template..."
    cp ngrok.env.example ngrok.env
    echo "✅ Created ngrok.env file"
    echo ""
    echo "⚠️  IMPORTANT: Please edit ngrok.env and add your NGROK_AUTHTOKEN"
    echo "   Get your authtoken from: https://dashboard.ngrok.com/get-started/your-authtoken"
    echo ""
    echo "   After adding your authtoken, run this script again."
    exit 1
fi

# Load environment variables
source ngrok.env

# Check if authtoken is set
if [ "$NGROK_AUTHTOKEN" = "your_ngrok_authtoken_here" ] || [ -z "$NGROK_AUTHTOKEN" ]; then
    echo "❌ NGROK_AUTHTOKEN not configured in ngrok.env"
    echo "   Please edit ngrok.env and add your authtoken from:"
    echo "   https://dashboard.ngrok.com/get-started/your-authtoken"
    exit 1
fi

echo "🚀 Starting ngrok tunnel..."

# Start docker services with ngrok
docker-compose --env-file ngrok.env up -d ngrok laravel

echo "⏳ Waiting for ngrok to start..."
sleep 5

# Get the ngrok URL
echo "🔍 Retrieving ngrok tunnel URL..."
NGROK_URL=""
for i in {1..30}; do
    NGROK_URL=$(curl -s http://localhost:4040/api/tunnels | jq -r '.tunnels[0].public_url // empty' 2>/dev/null)
    if [ ! -z "$NGROK_URL" ] && [ "$NGROK_URL" != "null" ]; then
        break
    fi
    echo "   Attempt $i/30: Waiting for ngrok..."
    sleep 2
done

if [ -z "$NGROK_URL" ] || [ "$NGROK_URL" = "null" ]; then
    echo "❌ Failed to retrieve ngrok URL"
    echo "   Check if ngrok container is running: docker logs ngrok-tunnel"
    exit 1
fi

echo "✅ Ngrok tunnel active: $NGROK_URL"

# Update the ngrok.env file with the actual URL
echo "📝 Updating ngrok.env with tunnel URL..."
sed -i.bak "s|NGROK_URL=.*|NGROK_URL=$NGROK_URL|" ngrok.env

# Restart Laravel with updated environment
echo "🔄 Restarting Laravel with updated configuration..."
docker-compose --env-file ngrok.env up -d laravel

# Update service configurations that use localhost
echo "🔧 Updating service configurations..."

# Update validation scripts
if [ -f "validate_ollama_integration.py" ]; then
    sed -i.bak "s|http://localhost:5051|$NGROK_URL:5051|g" validate_ollama_integration.py
fi

if [ -f "test_guitar_evaluator.py" ]; then
    sed -i.bak "s|http://localhost:5051|$NGROK_URL:5051|g" test_guitar_evaluator.py
fi

echo ""
echo "🎉 Ngrok setup complete!"
echo "================================"
echo "🌐 Laravel Application: $NGROK_URL"
echo "🔧 Ngrok Web Interface: http://localhost:4040"
echo "📊 Monitor tunnels at: http://localhost:4040/inspect/http"
echo ""
echo "📋 Next steps:"
echo "   1. Share the ngrok URL with others to access your application"
echo "   2. The URL will change each time ngrok restarts (unless you have a paid plan)"
echo "   3. To stop ngrok: docker-compose down ngrok"
echo ""
echo "⚠️  Note: Free ngrok accounts have connection limits and the URL changes on restart" 