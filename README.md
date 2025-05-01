# AWS Transcription Service

## Overview

The AWS Transcription Service is a microservices-based application designed to transcribe audio/video content using AWS Transcription services. The application features a web interface and API for managing transcription jobs.

## Architecture

The application is built with a microservices architecture consisting of:

1. **Laravel Application**: Serves both web UI and API endpoints
2. **Audio Extraction Service**: Python-based service that extracts audio from video files
3. **Transcription Service**: Python-based service that interfaces with AWS Transcription API

## Storage Architecture

Files are organized using a job-based structure:

- All files are stored in `app/shared/jobs/{job_id}/`
- For each job, standardized filenames are used:
  - `video.mp4` - Original uploaded video
  - `audio.wav` - Extracted audio file
  - `transcript.txt` - Generated transcript

The shared storage directory is mounted to all containers at `/var/www/storage/app/public/s3`.

When videos are deleted through the system, all associated files (original video, extracted audio, and transcript) are automatically removed to prevent orphaned files and maintain storage efficiency.

## Key Features

- Web interface for managing transcription jobs
- RESTful API for programmatic access
- AWS Transcription integration
- Containerized deployment with Docker
- Automatic cleanup of all files when videos are deleted

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
- **Audio Extraction**: Python with FFmpeg
- **Transcription Service**: Python
- **Database**: SQLite (for local development)
- **Deployment**: Docker Compose

## License

MIT

## Contact

John Ezell
@johnezell
