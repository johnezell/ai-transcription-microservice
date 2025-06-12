# Ngrok Integration Setup

This guide explains how to set up ngrok to expose your AI Transcription Microservice to the internet, allowing external access and sharing.

## 🌐 What is Ngrok?

Ngrok creates secure tunnels from public URLs to localhost, allowing you to:
- Share your local development environment with others
- Test webhooks and external integrations
- Provide remote access to your application
- Demo your application without deploying to a server

## 📋 Prerequisites

1. **Ngrok Account**: Sign up at [ngrok.com](https://ngrok.com) (free tier available)
2. **Docker & Docker Compose**: Already set up in this project
3. **Ngrok Authtoken**: Get yours from [dashboard.ngrok.com](https://dashboard.ngrok.com/get-started/your-authtoken)

## 🚀 Quick Setup

### Step 1: Get Your Ngrok Authtoken

1. Visit [https://dashboard.ngrok.com/get-started/your-authtoken](https://dashboard.ngrok.com/get-started/your-authtoken)
2. Copy your authtoken (it looks like: `2abcdefg_1A2B3C4D5E6F7G8H9I0J`)

### Step 2: Run the Setup Script

Choose your platform:

#### Windows (PowerShell) - Recommended
```powershell
.\scripts\setup-ngrok.ps1
```

#### Windows (Batch)
```cmd
.\scripts\setup-ngrok.bat
```

#### Linux/Mac (Bash)
```bash
./scripts/setup-ngrok.sh
```

### Step 3: Configure Your Authtoken

The script will create a `ngrok.env` file. Edit it and add your authtoken:

```env
NGROK_AUTHTOKEN=your_actual_authtoken_here
```

### Step 4: Run the Script Again

After adding your authtoken, run the setup script again. It will:
- Start the ngrok tunnel
- Get your public URL
- Update configuration files
- Restart Laravel with the new URL

## 📊 What Gets Set Up

### Docker Services
- **ngrok service**: Runs ngrok container with tunnel configuration
- **Laravel service**: Updated to use ngrok URL as APP_URL

### Configuration Files
- `docker/ngrok/ngrok.yml`: Ngrok tunnel configuration
- `ngrok.env`: Environment variables for ngrok
- Updated service URLs in validation scripts

### Network Architecture
```
Internet → Ngrok Tunnel → Laravel Container (Port 80)
                       ↓
                   Internal Docker Network
                       ↓
              Audio/Transcription Services
```

## 🔧 Configuration Details

### Ngrok Configuration (`docker/ngrok/ngrok.yml`)
```yaml
version: "2"
authtoken: ${NGROK_AUTHTOKEN}

tunnels:
  laravel-app:
    addr: laravel:80
    proto: http
    bind_tls: true
    inspect: false
    host_header: rewrite
```

### Environment Variables
- `NGROK_AUTHTOKEN`: Your ngrok authentication token
- `NGROK_URL`: Automatically set to your tunnel URL (e.g., `https://abc123.ngrok-free.app`)
- `APP_URL`: Laravel application URL (uses ngrok URL when available)

## 📱 Accessing Your Application

Once setup is complete, you'll get:

### Public URL
Your application will be available at: `https://yourcode.ngrok-free.app`

### Ngrok Web Interface
Monitor your tunnels at: `http://localhost:4040`

### Local Access
Your application is still available locally at: `http://localhost:8080`

## 🔍 Monitoring and Debugging

### Ngrok Web Interface
Visit `http://localhost:4040` to:
- View tunnel status
- Monitor HTTP requests
- Inspect request/response data
- View tunnel URLs

### Docker Logs
```bash
# Check ngrok container logs
docker logs ngrok-tunnel

# Check Laravel container logs
docker logs laravel-app
```

### Container Status
```bash
# View all containers
docker-compose ps

# Check specific services
docker-compose logs ngrok
docker-compose logs laravel
```

## 🛠️ Manual Operations

### Start Ngrok Only
```bash
docker-compose up -d ngrok
```

### Restart with New Configuration
```bash
docker-compose --env-file ngrok.env up -d ngrok laravel
```

### Stop Ngrok
```bash
docker-compose down ngrok
```

### Get Current Tunnel URL
```bash
curl -s http://localhost:4040/api/tunnels | jq -r '.tunnels[0].public_url'
```

## 🔒 Security Considerations

### Free Account Limitations
- URLs change each time ngrok restarts
- Limited number of concurrent connections
- Ngrok branding on free tunnels

### Security Features
- HTTPS encryption by default
- Request inspection and filtering
- IP whitelisting (paid plans)
- Password protection (paid plans)

### Best Practices
1. **Don't commit authtokens**: Keep `ngrok.env` out of version control
2. **Monitor access**: Use ngrok web interface to watch requests
3. **Limit exposure**: Only run ngrok when needed for external access
4. **Use HTTPS**: All ngrok tunnels use HTTPS by default

## 🎯 Use Cases

### Development & Testing
- Share work-in-progress with team members
- Test integrations with external services
- Demo features to stakeholders

### External Integrations
- Webhook testing and debugging
- API integration development
- Mobile app testing against local backend

### Remote Access
- Work from different locations
- Provide access to clients or collaborators
- Remote debugging and troubleshooting

## ❗ Troubleshooting

### Common Issues

#### "Failed to retrieve ngrok URL"
1. Check if ngrok container is running: `docker logs ngrok-tunnel`
2. Verify authtoken is correct in `ngrok.env`
3. Check ngrok web interface: `http://localhost:4040`

#### "NGROK_AUTHTOKEN not configured"
1. Edit `ngrok.env` file
2. Replace `your_ngrok_authtoken_here` with your actual token
3. Run setup script again

#### Container dependency issues
1. Stop all containers: `docker-compose down`
2. Start with env file: `docker-compose --env-file ngrok.env up -d`

#### URL changes frequently
- This is normal with free ngrok accounts
- Upgrade to paid plan for persistent URLs
- Re-run setup script after restarts

### Reset Everything
```bash
# Stop all containers
docker-compose down

# Remove ngrok configuration
rm ngrok.env

# Run setup script again
.\scripts\setup-ngrok.ps1
```

## 📚 Advanced Configuration

### Custom Domain (Paid Plans)
Edit `docker/ngrok/ngrok.yml`:
```yaml
tunnels:
  laravel-app:
    addr: laravel:80
    proto: http
    hostname: your-custom-domain.com
```

### Multiple Tunnels
Add additional tunnels for services:
```yaml
tunnels:
  laravel-app:
    addr: laravel:80
    proto: http
  
  transcription-service:
    addr: transcription-service:5000
    proto: http
```

### Request Authentication
Add basic auth (paid plans):
```yaml
tunnels:
  laravel-app:
    addr: laravel:80
    proto: http
    auth: "username:password"
```

## 🔗 Useful Links

- [Ngrok Documentation](https://ngrok.com/docs)
- [Ngrok Dashboard](https://dashboard.ngrok.com)
- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [Laravel Environment Configuration](https://laravel.com/docs/configuration)

## 📞 Support

If you encounter issues with ngrok setup:

1. Check the troubleshooting section above
2. Review ngrok logs: `docker logs ngrok-tunnel`
3. Visit ngrok web interface: `http://localhost:4040`
4. Consult ngrok documentation for advanced configuration 