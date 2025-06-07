# Audio Extraction Service - Technical Design Document

## Table of Contents
1. [Service Overview](#service-overview)
2. [Architecture](#architecture)
3. [API Specification](#api-specification)
4. [Core Components](#core-components)
5. [Audio Processing Pipeline](#audio-processing-pipeline)
6. [Configuration Management](#configuration-management)
7. [Error Handling](#error-handling)
8. [Performance Considerations](#performance-considerations)
9. [Integration Points](#integration-points)
10. [Deployment](#deployment)
11. [Monitoring & Health Checks](#monitoring--health-checks)
12. [Future Enhancements](#future-enhancements)

---

## Service Overview

### Purpose
The Audio Extraction Service is a Flask-based microservice responsible for extracting and preprocessing audio from video files as part of the AI transcription microservice ecosystem. It serves as the first stage in the transcription pipeline, converting video content into optimized audio format suitable for speech recognition processing.

### Role in Ecosystem
- **Input**: Video files (MP4 format) from the Laravel backend
- **Output**: Preprocessed WAV audio files optimized for transcription
- **Integration**: Communicates with Laravel API for job status updates and coordinates with the transcription service

### Key Capabilities
- Multi-quality audio extraction with configurable processing levels
- Advanced audio preprocessing with FFmpeg integration
- Voice Activity Detection (VAD) for premium quality processing
- Dynamic audio normalization and noise reduction
- Real-time job status reporting to Laravel backend
- Health monitoring and metrics collection
- Fallback processing for enhanced reliability

---

## Architecture

### Service Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                Audio Extraction Service                      │
├─────────────────────────────────────────────────────────────┤
│  Flask Application (Port 5000)                             │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────┐ │
│  │   API Layer     │  │  Processing     │  │ Integration │ │
│  │                 │  │    Engine       │  │   Layer     │ │
│  │ • /health       │  │ • FFmpeg Ops   │  │ • Laravel   │ │
│  │ • /metrics      │  │ • Quality Mgmt │  │   API       │ │
│  │ • /process      │  │ • VAD Processing│  │ • Status    │ │
│  │ • /connectivity │  │ • Validation    │  │   Updates   │ │
│  └─────────────────┘  └─────────────────┘  └─────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### Dependencies
- **Core Framework**: Flask 2.3.3
- **HTTP Client**: requests 2.31.0
- **Audio Processing**: FFmpeg (system dependency)
- **Audio Libraries**: pydub 0.25.1, libsndfile1, sox
- **Testing**: pytest 7.4.3
- **Configuration**: python-dotenv 1.0.0

### Integration Points
- **Laravel Backend**: Job management and status updates
- **Shared Storage**: `/var/www/storage/app/public/s3` for file operations
- **Transcription Service**: Downstream processing coordination

---

## API Specification

### Base URL
- **Development**: `http://localhost:5050`
- **Container**: `http://audio-extraction-service:5000`

### Endpoints

#### 1. Health Check
```http
GET /health
```

**Response:**
```json
{
  "status": "healthy",
  "service": "audio-extraction-service",
  "version": "Phase 3",
  "timestamp": "2025-06-07T12:29:14.123456",
  "features": {
    "quality_levels": ["fast", "balanced", "high", "premium"],
    "vad_enabled": false,
    "normalization_enabled": true,
    "max_threads": 4,
    "default_quality": "balanced"
  },
  "capabilities": {
    "voice_activity_detection": true,
    "premium_quality_processing": true,
    "advanced_noise_reduction": true,
    "dynamic_audio_normalization": true,
    "processing_metrics": true
  }
}
```

#### 2. Processing Metrics
```http
GET /metrics
```

**Response:**
```json
{
  "success": true,
  "service": "audio-extraction-service",
  "timestamp": "2025-06-07T12:29:14.123456",
  "metrics": {
    "avg_processing_time": 0.0,
    "quality_score": 0.0,
    "error_rate": 0.0
  },
  "configuration": {
    "quality_levels": ["fast", "balanced", "high", "premium"],
    "vad_enabled": false,
    "normalization_enabled": true,
    "max_threads": 4,
    "default_quality": "balanced"
  }
}
```

#### 3. Connectivity Test
```http
GET /connectivity-test
```

**Response:**
```json
{
  "success": true,
  "results": {
    "laravel_api": true,
    "shared_directories": {
      "jobs_dir": true
    }
  },
  "timestamp": "2025-06-07T12:29:14.123456"
}
```

#### 4. Audio Processing
```http
POST /process
```

**Request:**
```json
{
  "job_id": "uuid-string"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "job_id": "uuid-string",
  "message": "Audio extraction processed successfully",
  "data": {
    "message": "Audio extraction completed successfully",
    "service_timestamp": "2025-06-07T12:29:14.123456",
    "audio_path": "/var/www/storage/app/public/s3/jobs/{job_id}/audio.wav",
    "audio_size_bytes": 1234567,
    "duration_seconds": 123.45,
    "metadata": {
      "service": "audio-extraction-service",
      "processed_by": "FFmpeg audio extraction",
      "format": "WAV",
      "sample_rate": "16000 Hz",
      "channels": "1 (Mono)",
      "codec": "PCM 16-bit"
    }
  }
}
```

**Error Response (400/404/500):**
```json
{
  "success": false,
  "job_id": "uuid-string",
  "message": "Error description"
}
```

---

## Core Components

### 1. Audio Validation Functions

#### `validate_audio_input(input_path)`
**Purpose**: Validates audio input using FFprobe before processing
**Parameters**: 
- `input_path` (str): Path to input audio/video file
**Returns**: Dict with validation info (codec, sample_rate, channels, duration)
**Raises**: RuntimeError if validation fails

```python
validation_info = {
    'codec': 'aac',
    'sample_rate': 44100,
    'channels': 2,
    'duration': 123.45
}
```

#### `assess_audio_quality(audio_path)`
**Purpose**: Assesses audio quality metrics using FFprobe
**Parameters**: 
- `audio_path` (str): Path to audio file
**Returns**: Dict with quality metrics or None if assessment fails

```python
quality_metrics = {
    'bit_rate': 128000,
    'sample_rate': 16000,
    'channels': 1,
    'duration': 123.45
}
```

### 2. Audio Processing Functions

#### `preprocess_for_whisper(input_path, output_path, quality_level)`
**Purpose**: Enhanced audio preprocessing with configurable quality levels
**Parameters**:
- `input_path` (str): Input file path
- `output_path` (str): Output WAV file path  
- `quality_level` (str): Quality level ('fast', 'balanced', 'high', 'premium')
**Returns**: bool (success/failure)
**Features**:
- Quality-specific filter chains
- Configurable thread counts
- VAD preprocessing for premium quality
- Comprehensive error handling with fallback

#### `apply_vad_preprocessing(input_path, output_path)`
**Purpose**: Apply Voice Activity Detection with advanced silence removal
**Parameters**:
- `input_path` (str): Input audio file path
- `output_path` (str): Output processed file path
**Returns**: bool (success/failure)
**Processing**: Bidirectional silence removal with -60dB threshold

#### `convert_to_wav(input_path, output_path, quality_level)`
**Purpose**: Main conversion function with quality level support
**Parameters**:
- `input_path` (str): Input media file path
- `output_path` (str): Output WAV file path
- `quality_level` (str, optional): Quality override
**Returns**: bool (success/failure)
**Features**: Automatic fallback to original method if enhanced processing fails

### 3. Utility Functions

#### `get_audio_duration(audio_path)`
**Purpose**: Extract audio duration using FFprobe
**Parameters**: 
- `audio_path` (str): Path to audio file
**Returns**: float (duration in seconds) or None

#### `calculate_processing_metrics()`
**Purpose**: Calculate processing performance metrics
**Returns**: Dict with processing metrics
**Status**: TODO - Currently returns placeholder values

### 4. Integration Functions

#### `update_job_status(job_id, status, response_data, error_message)`
**Purpose**: Update job status in Laravel backend
**Parameters**:
- `job_id` (str): Unique job identifier
- `status` (str): Job status ('extracting_audio', 'processing', 'completed', 'failed')
- `response_data` (dict, optional): Processing results
- `error_message` (str, optional): Error details
**Integration**: Posts to `{LARAVEL_API_URL}/transcription/{job_id}/status`

#### `test_laravel_connectivity()`
**Purpose**: Test connectivity to Laravel API
**Returns**: bool (connection status)
**Endpoint**: `{LARAVEL_API_URL}/hello`

---

## Audio Processing Pipeline

### Quality Levels Configuration

#### Fast Quality
- **Filters**: `["dynaudnorm=p=0.9:s=5"]`
- **Threads**: 2
- **Use Case**: Quick processing for testing/development

#### Balanced Quality (Default)
- **Filters**: `["highpass=f=80", "lowpass=f=8000", "dynaudnorm=p=0.9:s=5"]`
- **Threads**: 4
- **Use Case**: Production processing with good quality/speed balance

#### High Quality
- **Filters**: `["highpass=f=80", "lowpass=f=8000", "dynaudnorm=p=0.9:s=5:r=0.9", "afftdn=nf=-25"]`
- **Threads**: 6
- **Use Case**: High-quality processing with noise reduction

#### Premium Quality
- **Filters**: `["highpass=f=80", "lowpass=f=8000", "dynaudnorm=p=0.9:s=5:r=0.9", "afftdn=nf=-25", "compand=0.3|0.3:1|1:-90/-60|-60/-40|-40/-30|-20/-20:6:0:-90:0.2"]`
- **Threads**: 8
- **VAD**: Enabled (when configured)
- **Use Case**: Maximum quality with VAD preprocessing and dynamic range compression

### Processing Flow

```
Input Video (MP4)
       ↓
   Validation
       ↓
Quality Level Selection
       ↓
┌─────────────────┐
│ Premium Quality │ → VAD Preprocessing → Enhanced Filters
├─────────────────┤
│ High Quality    │ → Advanced Filters + Noise Reduction
├─────────────────┤
│ Balanced        │ → Standard Filters + Normalization
├─────────────────┤
│ Fast            │ → Basic Normalization
└─────────────────┘
       ↓
   FFmpeg Processing
       ↓
   Quality Assessment
       ↓
Output WAV (16kHz, Mono, PCM 16-bit)
```

### FFmpeg Command Structure

**Base Command:**
```bash
ffmpeg -y -threads {thread_count} -i {input} -vn -af {filter_chain} -acodec pcm_s16le -ar 16000 -ac 1 -sample_fmt s16 {output}
```

**VAD Command (Premium):**
```bash
ffmpeg -y -i {input} -vn -af "silenceremove=start_periods=1:start_duration=1:start_threshold=-60dB:detection=peak,aformat=dblp,areverse,silenceremove=start_periods=1:start_duration=1:start_threshold=-60dB:detection=peak,aformat=dblp,areverse" -acodec pcm_s16le -ar 16000 -ac 1 {output}
```

---

## Configuration Management

### Environment Variables

#### Core Configuration
- `LARAVEL_API_URL`: Laravel backend API URL (default: `http://laravel/api`)
- `TRANSCRIPTION_SERVICE_URL`: Transcription service URL

#### Audio Processing Configuration
- `AUDIO_QUALITY_LEVEL`: Default quality level (default: `balanced`)
- `ENABLE_NORMALIZATION`: Enable audio normalization (default: `true`)
- `ENABLE_VAD`: Enable Voice Activity Detection (default: `false`)
- `FFMPEG_THREADS`: Maximum FFmpeg threads (default: `4`)

#### Advanced Features (Phase 3)
- `VAD_THRESHOLD`: VAD silence threshold (default: `-60dB`)
- `PREMIUM_QUALITY_ENABLED`: Enable premium quality processing (default: `true`)

### Runtime Configuration

```python
AUDIO_PROCESSING_CONFIG = {
    "default_quality": os.environ.get('AUDIO_QUALITY_LEVEL', 'balanced'),
    "enable_normalization": os.environ.get('ENABLE_NORMALIZATION', 'true').lower() == 'true',
    "enable_vad": os.environ.get('ENABLE_VAD', 'false').lower() == 'true',
    "max_threads": int(os.environ.get('FFMPEG_THREADS', '4'))
}
```

### File System Configuration
- `S3_BASE_DIR`: `/var/www/storage/app/public/s3`
- `S3_JOBS_DIR`: `/var/www/storage/app/public/s3/jobs`
- **Job Structure**: `{S3_JOBS_DIR}/{job_id}/video.mp4` → `{S3_JOBS_DIR}/{job_id}/audio.wav`

---

## Error Handling

### Exception Handling Strategy

#### 1. Validation Errors
- **Trigger**: Invalid input files, missing audio streams
- **Response**: 404 error with descriptive message
- **Laravel Update**: Job status set to 'failed'

#### 2. Processing Errors
- **Trigger**: FFmpeg failures, file system issues
- **Response**: 500 error with error details
- **Fallback**: Automatic fallback to original conversion method
- **Laravel Update**: Job status set to 'failed' with error message

#### 3. Integration Errors
- **Trigger**: Laravel API communication failures
- **Response**: Logged warnings, processing continues
- **Handling**: Non-blocking error handling for status updates

### Error Response Format

```json
{
  "success": false,
  "job_id": "uuid-string",
  "message": "Detailed error description",
  "timestamp": "2025-06-07T12:29:14.123456"
}
```

### Logging Strategy

```python
# Logging configuration
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Log levels used:
# INFO: Normal processing flow, status updates
# WARNING: Non-critical errors, fallback usage
# ERROR: Critical errors, processing failures
# DEBUG: Detailed command information (FFmpeg commands)
```

---

## Performance Considerations

### Threading Configuration
- **Configurable Threads**: 2-8 threads based on quality level
- **Thread Limiting**: Respects `FFMPEG_THREADS` environment variable
- **Quality-Based Scaling**: Higher quality levels use more threads

### Processing Optimization
- **Quality-Specific Filters**: Optimized filter chains for each quality level
- **Fallback Processing**: Automatic fallback to simpler processing on failure
- **Resource Management**: Temporary file cleanup, memory-efficient processing

### Metrics Collection (Planned)
```python
def calculate_processing_metrics() -> Dict[str, float]:
    # TODO: Implement actual metrics calculation
    return {
        'avg_processing_time': 0.0,  # Average processing time in seconds
        'quality_score': 0.0,        # Quality assessment score (0-100)
        'error_rate': 0.0             # Error rate percentage (0-100)
    }
```

### File System Optimization
- **Shared Storage**: Efficient shared volume mounting
- **Directory Structure**: Organized job-based file structure
- **Cleanup Strategy**: Temporary file cleanup after processing

---

## Integration Points

### Laravel Backend Integration

#### Job Status Updates
**Endpoint**: `POST {LARAVEL_API_URL}/transcription/{job_id}/status`

**Status Flow**:
1. `extracting_audio` - Processing started
2. `processing` - Audio extraction completed, ready for transcription
3. `completed` - Full pipeline completed (set by transcription service)
4. `failed` - Processing failed with error details

**Payload Structure**:
```json
{
  "status": "processing",
  "response_data": {
    "message": "Audio extraction completed successfully",
    "service_timestamp": "2025-06-07T12:29:14.123456",
    "audio_path": "/path/to/audio.wav",
    "audio_size_bytes": 1234567,
    "duration_seconds": 123.45,
    "metadata": { /* processing metadata */ }
  },
  "error_message": null,
  "completed_at": "2025-06-07T12:29:14.123456"
}
```

#### Connectivity Testing
**Endpoint**: `GET {LARAVEL_API_URL}/hello`
**Purpose**: Health check for Laravel API connectivity

### Shared Storage Integration
- **Mount Point**: `/var/www/storage/app/public/s3`
- **Job Structure**: `jobs/{job_id}/video.mp4` → `jobs/{job_id}/audio.wav`
- **Access Pattern**: Read video files, write audio files
- **Permissions**: Read/write access required

### Transcription Service Coordination
- **Flow**: Audio extraction → Laravel status update → Transcription service triggered
- **Communication**: Indirect through Laravel backend
- **File Handoff**: Shared storage for audio file access

---

## Deployment

### Docker Configuration

#### Dockerfile Structure
```dockerfile
FROM python:3.11-slim

# System dependencies
RUN apt-get update && apt-get install -y \
    ffmpeg \
    curl \
    libsndfile1 \
    sox \
    && apt-get clean

# Python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Environment configuration
ENV PYTHONUNBUFFERED=1
ENV FLASK_APP=service.py
ENV FLASK_ENV=development

# Audio processing configuration
ENV AUDIO_QUALITY_LEVEL=balanced
ENV ENABLE_NORMALIZATION=true
ENV FFMPEG_THREADS=4
ENV ENABLE_VAD=false
```

#### Docker Compose Integration
```yaml
audio-extraction-service:
  build:
    context: .
    dockerfile: Dockerfile.audio-service
  container_name: aws-audio-extraction
  restart: unless-stopped
  volumes:
    - ./app/services/audio-extraction:/app
    - ./app/shared:/var/www/storage/app/public/s3:delegated
  networks:
    - app-network
  environment:
    - LARAVEL_API_URL=http://laravel/api
    - TRANSCRIPTION_SERVICE_URL=http://transcription-service:5000
  ports:
    - "5050:5000"
```

### Service Dependencies
- **System**: FFmpeg, libsndfile1, sox
- **Python**: Flask, requests, pydub
- **Network**: Laravel backend, shared storage access
- **Runtime**: Python 3.11, container networking

### Deployment Considerations
- **Resource Requirements**: CPU-intensive processing, configurable thread usage
- **Storage**: Shared volume for file operations
- **Network**: Internal service communication via Docker network
- **Scaling**: Stateless design allows horizontal scaling

---

## Monitoring & Health Checks

### Health Check Endpoint
**URL**: `GET /health`
**Features**:
- Service status verification
- Configuration reporting
- Feature capability listing
- Timestamp for monitoring

### Metrics Endpoint
**URL**: `GET /metrics`
**Current Status**: Basic structure implemented
**Planned Metrics**:
- Average processing time
- Quality assessment scores
- Error rate tracking
- Throughput metrics

### Connectivity Testing
**URL**: `GET /connectivity-test`
**Tests**:
- Laravel API connectivity
- Shared directory access
- File system permissions

### Logging Strategy
- **Level**: INFO for normal operations
- **Format**: Structured logging with timestamps
- **Coverage**: All processing stages, errors, and status updates
- **Integration**: Container log aggregation ready

### Monitoring Integration Points
- **Health Checks**: Regular health endpoint polling
- **Metrics Collection**: Prometheus-compatible metrics endpoint
- **Log Aggregation**: Structured logging for centralized collection
- **Error Tracking**: Comprehensive error logging with context

---

## Future Enhancements

### Identified TODOs and Improvements

#### 1. Metrics Implementation
**Current Status**: Placeholder implementation
**Enhancement**: 
```python
def calculate_processing_metrics() -> Dict[str, float]:
    # TODO: Implement actual metrics calculation based on processing history
    # - Track processing times per quality level
    # - Implement quality scoring algorithm
    # - Calculate error rates and success metrics
    # - Add throughput and performance tracking
```

#### 2. Advanced Audio Processing
**Potential Enhancements**:
- **Adaptive Quality Selection**: Automatic quality level selection based on input characteristics
- **Batch Processing**: Support for multiple file processing
- **Real-time Processing**: Streaming audio processing capabilities
- **Custom Filter Chains**: User-configurable audio processing pipelines

#### 3. Performance Optimization
**Areas for Improvement**:
- **Async Processing**: Convert to async/await for better concurrency
- **Resource Pooling**: FFmpeg process pooling for better resource utilization
- **Caching**: Processed audio caching for repeated requests
- **Progressive Processing**: Chunk-based processing for large files

#### 4. Enhanced VAD Processing
**Current**: Basic silence removal with fixed thresholds
**Enhancements**:
- **Machine Learning VAD**: ML-based voice activity detection
- **Adaptive Thresholds**: Dynamic threshold adjustment based on content
- **Multi-language Support**: Language-specific VAD optimization
- **Real-time VAD**: Streaming voice activity detection

#### 5. Quality Assessment
**Current**: Basic audio metrics collection
**Enhancements**:
- **Perceptual Quality Metrics**: PESQ, STOI quality assessment
- **Content Analysis**: Speech quality and intelligibility scoring
- **Automatic Quality Adjustment**: Feedback-based quality optimization
- **Quality Reporting**: Detailed quality reports for processed audio

#### 6. Integration Enhancements
**Potential Improvements**:
- **Event-Driven Architecture**: Message queue integration for better decoupling
- **API Versioning**: Support for multiple API versions
- **Authentication**: Service-to-service authentication
- **Rate Limiting**: Request rate limiting and throttling

#### 7. Monitoring and Observability
**Enhancements**:
- **Distributed Tracing**: Request tracing across services
- **Custom Metrics**: Business-specific metrics collection
- **Alerting**: Automated alerting for service issues
- **Performance Profiling**: Detailed performance analysis tools

#### 8. Configuration Management
**Improvements**:
- **Dynamic Configuration**: Runtime configuration updates
- **Configuration Validation**: Schema-based configuration validation
- **Environment-Specific Configs**: Multi-environment configuration management
- **Feature Flags**: Dynamic feature enabling/disabling

### Architectural Considerations

#### Scalability
- **Horizontal Scaling**: Stateless design supports multiple instances
- **Load Balancing**: Ready for load balancer integration
- **Resource Optimization**: Configurable resource usage per instance

#### Reliability
- **Circuit Breaker**: Implement circuit breaker pattern for external dependencies
- **Retry Logic**: Configurable retry mechanisms for transient failures
- **Graceful Degradation**: Fallback processing modes for service degradation

#### Security
- **Input Validation**: Enhanced input validation and sanitization
- **Service Authentication**: Mutual TLS for service communication
- **Audit Logging**: Security event logging and monitoring

---

## Conclusion

The Audio Extraction Service represents a robust, scalable solution for audio preprocessing in the AI transcription pipeline. With its multi-quality processing capabilities, comprehensive error handling, and seamless integration with the broader microservice ecosystem, it provides a solid foundation for high-quality audio extraction and preprocessing.

The service's modular design, extensive configuration options, and planned enhancements position it well for future growth and feature expansion while maintaining reliability and performance in production environments.

---

**Document Version**: 1.0  
**Last Updated**: June 7, 2025  
**Service Version**: Phase 3  
**Author**: Technical Documentation Team