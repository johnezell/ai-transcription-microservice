# Whisper AI Transcription Service

## Overview

The Whisper AI Transcription Service is a powerful application designed to transcribe video lessons, with a particular focus on guitar instruction content. Leveraging OpenAI's Whisper AI technology, this service not only provides accurate transcriptions but also enhances content with summaries, articles, and domain-specific analysis.

## Key Features

### Core Functionality
- **Audio/Video Transcription**: Converts spoken content to text using OpenAI's Whisper AI models
- **Content Enhancement**:
  - Formatted transcripts with timestamps
  - Concise summaries of the content
  - SEO-optimized articles based on the transcribed content
  - Automatic keyword extraction
  - SRT subtitle file generation

### Technical Capabilities
- **Dual Operation Modes**:
  - Web Interface: User-friendly browser interface for file uploads
  - Command-Line Mode: For batch processing with transcription IDs
- **Database Integration**: Stores and retrieves transcription data using SQLAlchemy/SQLModel
- **AWS Integration**:
  - Downloads videos from S3 buckets
  - Deployable through AWS Elastic Container Services (ECS)

### Domain-Specific Features
- **Guitar Instruction Focus**:
  - Detection of guitar-specific terminology
  - Classification by genre, instrument, level, and topic
  - Customized prompts for guitar instruction content
- **Educational Content Processing**: Optimized for educational video content

## User Interface

The web interface allows users to:
1. Upload media files (audio/video)
2. Select the Whisper model to use (Base or Turbo)
3. Customize prompts for transcript, summary, and article generation
4. View and download the generated content

## Getting Started

### Prerequisites
- **AWS Credentials**: Contact your DevOps team for access
- **Docker Desktop**: Ensure Docker is installed and running
- **VS Code**: With the Dev Containers extension installed

### Setup
1. **Configure AWS Credentials**:
   ```bash
   chmod +x getenv.sh
   ./getenv.sh
   ```
   Enter your AWS Key and Secret when prompted.

2. **Start the Dev Container**:
   - Open VS Code
   - Press `Cmd+Shift+P` (Mac) and select "Dev Containers: Reopen in Container"
   - First-time setup will take longer as it pulls all required images

### Running the Application
- **Web Interface Mode**: Access the application through your browser at `http://localhost:5100`
- **Command-Line Mode**: Run `python app/app.py [transcription_id]`

## Deployment

Deployment is handled through GitHub Actions and defined in `.github/workflows/aws.yml`. This is triggered when pushing to the main branch.

## Configuration

The application can be configured through:
- Environment variables in `.env` file
- Settings in `app/config.ini`

Key configuration options include:
- Whisper AI model selection
- Custom prompts for transcript, summary, and article generation
- Database connection settings
- AWS credentials and settings

## Technical Stack

- **Backend**: Python, Flask
- **AI Models**: 
  - OpenAI Whisper for transcription
  - GPT models for content enhancement
  - Specialized models for domain-specific analysis
- **Database**: SQLAlchemy/SQLModel
- **Deployment**: Docker, AWS ECS
- **Development**: Dev Containers

## License

MIT

## Contact

John Ezell
@johnezell
