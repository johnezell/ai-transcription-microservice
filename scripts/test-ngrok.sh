#!/bin/bash

echo "🧪 Testing Ngrok Integration"
echo "=================================="

# Check if ngrok.env exists
if [ ! -f "ngrok.env" ]; then
    echo "❌ ngrok.env file not found"
    echo "   Run setup script first: ./scripts/setup-ngrok.sh"
    exit 1
fi

# Load environment variables
source ngrok.env

echo "📋 Configuration Check:"
echo "   Authtoken configured: $([ -n "$NGROK_AUTHTOKEN" ] && [ "$NGROK_AUTHTOKEN" != "your_ngrok_authtoken_here" ] && echo "✅ Yes" || echo "❌ No")"
echo "   Ngrok URL: ${NGROK_URL:-"Not set"}"
echo ""

# Check if containers are running
echo "🐳 Docker Container Status:"
NGROK_STATUS=$(docker inspect -f '{{.State.Status}}' ngrok-tunnel 2>/dev/null || echo "not found")
LARAVEL_STATUS=$(docker inspect -f '{{.State.Status}}' laravel-app 2>/dev/null || echo "not found")

echo "   Ngrok container: $NGROK_STATUS"
echo "   Laravel container: $LARAVEL_STATUS"
echo ""

# Test ngrok API
echo "🔍 Ngrok API Test:"
NGROK_API_RESPONSE=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null)
if [ $? -eq 0 ]; then
    TUNNEL_COUNT=$(echo "$NGROK_API_RESPONSE" | jq -r '.tunnels | length' 2>/dev/null || echo "0")
    echo "   API accessible: ✅ Yes"
    echo "   Active tunnels: $TUNNEL_COUNT"
    
    if [ "$TUNNEL_COUNT" -gt 0 ]; then
        PUBLIC_URL=$(echo "$NGROK_API_RESPONSE" | jq -r '.tunnels[0].public_url' 2>/dev/null)
        echo "   Public URL: $PUBLIC_URL"
    fi
else
    echo "   API accessible: ❌ No"
fi
echo ""

# Test Laravel accessibility via ngrok (if URL is available)
if [ -n "$NGROK_URL" ] && [ "$NGROK_URL" != "" ]; then
    echo "🌐 Laravel Application Test:"
    echo "   Testing: $NGROK_URL"
    
    HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$NGROK_URL" --connect-timeout 10 2>/dev/null)
    if [ "$HTTP_STATUS" = "200" ]; then
        echo "   Laravel accessible: ✅ Yes (HTTP $HTTP_STATUS)"
    else
        echo "   Laravel accessible: ❌ No (HTTP $HTTP_STATUS)"
    fi
else
    echo "🌐 Laravel Application Test:"
    echo "   Ngrok URL not available for testing"
fi
echo ""

# Test local Laravel accessibility
echo "🏠 Local Laravel Test:"
LOCAL_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080" --connect-timeout 5 2>/dev/null)
if [ "$LOCAL_STATUS" = "200" ]; then
    echo "   Local Laravel accessible: ✅ Yes (HTTP $LOCAL_STATUS)"
else
    echo "   Local Laravel accessible: ❌ No (HTTP $LOCAL_STATUS)"
fi
echo ""

# Summary
echo "📊 Test Summary:"
if [ "$NGROK_STATUS" = "running" ] && [ "$LARAVEL_STATUS" = "running" ] && [ "$TUNNEL_COUNT" -gt 0 ]; then
    echo "   Overall status: ✅ All systems operational"
    echo ""
    echo "🎉 Ngrok integration is working correctly!"
    echo "   🌐 Public URL: ${PUBLIC_URL:-$NGROK_URL}"
    echo "   🏠 Local URL: http://localhost:8080"
    echo "   🔧 Monitor: http://localhost:4040"
else
    echo "   Overall status: ⚠️  Some issues detected"
    echo ""
    echo "🔧 Troubleshooting suggestions:"
    echo "   1. Check container logs: docker logs ngrok-tunnel"
    echo "   2. Restart services: docker-compose --env-file ngrok.env up -d"
    echo "   3. Verify authtoken in ngrok.env"
    echo "   4. Check ngrok web interface: http://localhost:4040"
fi 