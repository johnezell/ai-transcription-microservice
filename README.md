# AWS Transcription Service

## Overview

The AWS Transcription Service is a microservices-based application designed to transcribe audio/video content using AWS Transcription services. The application features a web interface and API for managing transcription jobs.

## Architecture

The application is built with a microservices architecture consisting of:

1. **Laravel Application**: Serves both web UI and API endpoints
2. **Transcription Service**: Python-based service that interfaces with AWS Transcription API

## Key Features

- Web interface for managing transcription jobs
- RESTful API for programmatic access
- AWS Transcription integration
- Containerized deployment with Docker

## Getting Started

### Prerequisites
- Docker and Docker Compose
- AWS Credentials (for transcription services)

### Setup and Installation

1. Clone the repository
2. Configure environment variables
3. Run with Docker Compose:
   ```bash
   docker-compose up -d
   ```

### Accessing the Application
- **Web Interface**: Access through your browser at `http://localhost:8080`
- **API Endpoints**: Access at `http://localhost:8080/api`

## Technical Stack

- **Web & API**: PHP Laravel 12
- **Transcription Service**: Python
- **Database**: SQLite (for local development)
- **Deployment**: Docker Compose

## License

MIT

## Contact

John Ezell
@johnezell
