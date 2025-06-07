# Audio Quality Control System - Technical Design Document

## Table of Contents
1. [System Overview](#system-overview)
2. [Architecture](#architecture)
3. [Technical Requirements](#technical-requirements)
4. [Quality Assessment Framework](#quality-assessment-framework)
5. [API Specification](#api-specification)
6. [Data Models](#data-models)
7. [Integration Points](#integration-points)
8. [Error Handling](#error-handling)
9. [Performance Considerations](#performance-considerations)
10. [Security Considerations](#security-considerations)
11. [Monitoring and Logging](#monitoring-and-logging)
12. [Future Enhancements](#future-enhancements)

## System Overview

### Purpose
The Audio Quality Control System provides automated assessment of WAV audio files to determine their suitability for Whisper AI transcription. The system combines technical audio analysis with real-world Whisper performance testing to deliver comprehensive quality scoring and recommendations.

### Key Features
- **Technical Audio Analysis**: Evaluates 5 core metrics optimized for speech transcription
- **Whisper Confidence Testing**: Tests actual transcription performance and confidence scores
- **Comparative Analysis**: Ranks multiple audio files for optimal selection
- **Weighted Scoring System**: Combines technical (60%) and performance (40%) metrics
- **Detailed Recommendations**: Provides actionable insights for audio quality improvement

### Business Value
- Improves transcription accuracy by selecting optimal audio sources
- Reduces processing time by identifying poor-quality audio early
- Provides data-driven recommendations for audio preprocessing
- Enables automated quality control in transcription pipelines

## Architecture

### System Components

```
┌─────────────────────────────────────────────────────────────┐
│                  Audio Quality Control System               │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────┐  │
│  │              Demucs Source Separation                   │  │
│  │  ┌─────────────────────────────────────────────────────┐  │  │
│  │  │ AI-Powered Audio Stem Extraction                    │  │  │
│  │  │ - Vocals/Speech Isolation                           │  │  │
│  │  │ - Background Music Removal                          │  │  │
│  │  │ - Instrumental Component Separation                 │  │  │
│  │  │ - Signal-to-Noise Ratio Enhancement                 │  │  │
│  │  └─────────────────────────────────────────────────────┘  │  │
│  └─────────────────────────────────────────────────────────┘  │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────────┐    ┌─────────────────────────────┐  │
│  │  Speech Quality     │    │  Whisper Quality            │  │
│  │  Analyzer           │    │  Analyzer                   │  │
│  │  ┌───────────────┐  │    │  ┌───────────────────────┐  │  │
│  │  │ Technical     │  │    │  │ Transcription         │  │  │
│  │  │ Metrics       │  │    │  │ Service Client        │  │  │
│  │  │ - Sample Rate │  │    │  │ - HTTP Client         │  │  │
│  │  │ - Volume      │  │    │  │ - Confidence Analysis │  │  │
│  │  │ - Dynamic Rng │  │    │  │ - Performance Testing │  │  │
│  │  │ - Duration    │  │    │  └───────────────────────┘  │  │
│  │  │ - Bit Rate    │  │    │                             │  │
│  │  └───────────────┘  │    └─────────────────────────────┘  │
│  └─────────────────────┘                                     │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────┐  │
│  │           Audio Statistics Provider                     │  │
│  │  (ai_roo_audio_quality_validation integration)         │  │
│  └─────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘

External Dependencies:
┌─────────────────────┐    ┌─────────────────────────────────┐
│ Transcription       │    │ Audio Processing Libraries      │
│ Service             │    │ - Demucs AI Source Separation   │
│ (Docker Container)  │    │ - FFmpeg/librosa integration    │
└─────────────────────┘    └─────────────────────────────────┘
```

### Data Flow

```
Audio File Input
        │
        ▼
┌─────────────────┐
│ File Validation │
│ & Accessibility │
└─────────────────┘
        │
        ▼
┌─────────────────────────────────────┐
│      Demucs Source Separation       │
│   (Optional Preprocessing Step)     │
│  ┌─────────────────────────────────┐ │
│  │ • Extract vocals/speech track   │ │
│  │ • Remove background music       │ │
│  │ • Enhance signal-to-noise ratio │ │
│  │ • Compare original vs separated │ │
│  └─────────────────────────────────┘ │
└─────────────────────────────────────┘
        │
        ▼
┌─────────────────┐    ┌──────────────────────┐
│ Technical       │    │ Whisper Confidence   │
│ Analysis        │◄───┤ Testing (Optional)   │
│ (60% weight)    │    │ (40% weight)         │
│ • Original      │    │ • Test both versions │
│ • Separated     │    │ • Select best option │
└─────────────────┘    └──────────────────────┘
        │                        │
        ▼                        ▼
┌─────────────────────────────────────┐
│        Score Combination           │
│     (Weighted Average)             │
│   With Source Separation Results   │
└─────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────┐
│    Results & Recommendations       │
│  - Overall Score (0-100)           │
│  - Quality Grade                   │
│  - Best Audio Version (orig/sep)   │
│  - Source Separation Benefits      │
│  - Detailed Metrics               │
│  - Actionable Recommendations     │
└─────────────────────────────────────┘
```

## Technical Requirements

### Environment
- **Python**: 3.8+
- **Dependencies**: 
  - `requests` for HTTP communication
  - `logging` for system monitoring
  - Integration with existing `ai_roo_audio_quality_validation` module
- **Docker Integration**: Commands executed via `docker exec aws-transcription-laravel`

### Input Requirements
- **File Format**: WAV files
- **File Access**: Local filesystem access required
- **File Size**: No explicit limits (handled by underlying audio processing)

### Output Requirements
- **Response Format**: JSON/Dictionary structures
- **Response Time**: < 30 seconds per file (configurable timeout)
- **Accuracy**: Deterministic scoring with consistent results

## Quality Assessment Framework

### Technical Metrics (60% Weight)

#### 1. Sample Rate Analysis (25% weight)
- **Optimal Target**: 16kHz (Whisper's preferred rate)
- **Scoring Logic**:
  - 16kHz = 100 points
  - 44.1kHz/48kHz = 80 points (high quality, requires downsampling)
  - 8kHz = 60 points (acceptable but suboptimal)
  - Other rates = proportional scoring

#### 2. Volume Level Analysis (30% weight)
- **Optimal Range**: -30dB to -10dB
- **Scoring Logic**:
  - Within range = 100 points
  - Too quiet (< -40dB) = 30 points
  - Too loud (> -5dB) = 40 points
  - Gradual degradation for intermediate values

#### 3. Dynamic Range Analysis (20% weight)
- **Optimal Range**: 10-25dB difference between mean and max volume
- **Scoring Logic**:
  - Within range = 100 points
  - Too compressed (< 5dB) = 40 points
  - Too wide (> 35dB) = 60 points

#### 4. Duration Analysis (15% weight)
- **Optimal Range**: 5-30 seconds
- **Scoring Logic**:
  - Within range = 100 points
  - Too short (< 2s) = 30 points
  - Long clips (30-60s) = gradual degradation to 70 points

#### 5. Bit Rate Analysis (10% weight)
- **Minimum Threshold**: 256kbps
- **Scoring Logic**:
  - ≥ 256kbps = 100 points
  - 128-255kbps = 80 points
  - 64-127kbps = 60 points
  - < 64kbps = 40 points

### Whisper Performance Metrics (40% Weight)

#### Confidence Score Calculation
- **Primary Metric**: Word-level confidence from Whisper segments
- **Calculation**: `(avg_confidence * 0.7 + min_confidence * 0.3) * 100`
- **Quality Indicators**:
  - Excellent: ≥ 90% confidence
  - Good: 80-89% confidence
  - Fair: 70-79% confidence
  - Poor: < 70% confidence

#### Additional Performance Factors
- **Low Confidence Segments**: Count of words with < 70% confidence
- **Confidence Distribution**: Breakdown by quality tiers
- **Transcript Quality**: Length and coherence indicators

### Combined Scoring
```
Final Score = (Technical Score × 0.6) + (Whisper Score × 0.4)
```

### Quality Grades
- **Excellent**: 90-100 points
- **Good**: 80-89 points
- **Fair**: 70-79 points
- **Poor**: 60-69 points
- **Unacceptable**: < 60 points

## API Specification

### SpeechQualityAnalyzer Class

#### `analyze_speech_quality(audio_path: str) -> Dict`
**Purpose**: Analyze technical quality of a single audio file

**Parameters**:
- `audio_path` (str): Path to WAV file

**Returns**: Dictionary with structure:
```json
{
    "success": boolean,
    "audio_path": string,
    "overall_score": float,
    "grade": string,
    "timestamp": string,
    "metrics": {
        "sample_rate": {
            "score": float,
            "weight": float,
            "value": int,
            "reasoning": string
        },
        // ... other metrics
    },
    "raw_stats": {
        "audio_stats": object,
        "volume_stats": object
    }
}
```

#### `compare_audio_files(audio_files: List[str]) -> Dict`
**Purpose**: Compare multiple audio files and rank by quality

**Parameters**:
- `audio_files` (List[str]): List of paths to WAV files

**Returns**: Dictionary with comparative analysis and rankings

### WhisperQualityAnalyzer Class

#### `__init__(transcription_service_url: str = None, timeout: int = 30)`
**Purpose**: Initialize analyzer with transcription service configuration

**Parameters**:
- `transcription_service_url` (str, optional): URL of transcription service
- `timeout` (int, optional): Request timeout in seconds

#### `test_whisper_confidence(audio_path: str) -> Dict`
**Purpose**: Test actual Whisper transcription confidence

**Parameters**:
- `audio_path` (str): Path to WAV file

**Returns**: Dictionary with confidence metrics and transcription results

#### `analyze_with_whisper_testing(audio_path: str) -> Dict`
**Purpose**: Comprehensive analysis combining technical and Whisper metrics

**Returns**: Dictionary with combined scoring and detailed recommendations

#### `compare_with_whisper_testing(audio_files: List[str]) -> Dict`
**Purpose**: Compare multiple files using comprehensive analysis

**Returns**: Dictionary with rankings and detailed comparative analysis

### Convenience Functions

#### `analyze_speech_quality(audio_path: str) -> Dict`
Standalone function for technical analysis

#### `test_whisper_confidence(audio_path: str, transcription_service_url: str = None) -> Dict`
Standalone function for Whisper confidence testing

#### `analyze_with_whisper_testing(audio_path: str, transcription_service_url: str = None) -> Dict`
Standalone function for comprehensive analysis

## Data Models

### Audio Statistics Model
```python
{
    "sample_rate": int,      # Hz
    "duration": float,       # seconds
    "bit_rate": int,         # bps
    "channels": int,         # audio channels
    "format": string         # file format
}
```

### Volume Statistics Model
```python
{
    "mean_volume": string,   # "X.XdB" format
    "max_volume": string,    # "X.XdB" format
    "min_volume": string,    # "X.XdB" format
    "rms_volume": string     # "X.XdB" format
}
```

### Confidence Metrics Model
```python
{
    "overall_confidence": float,         # 0-100
    "avg_confidence": float,             # 0-1
    "min_confidence": float,             # 0-1
    "max_confidence": float,             # 0-1
    "low_confidence_segments": int,      # count
    "confidence_distribution": {
        "excellent": int,                # count ≥ 0.9
        "good": int,                     # count 0.8-0.89
        "fair": int,                     # count 0.7-0.79
        "poor": int                      # count < 0.7
    },
    "quality_indicators": List[string]
}
```

## Integration Points

### Audio Processing Integration
- **Module**: `ai_roo_audio_quality_validation`
- **Functions Used**:
  - `get_audio_stats(audio_path)`: Technical audio properties
  - `get_audio_volume_stats(audio_path)`: Volume analysis
- **Purpose**: Leverage existing audio analysis capabilities

### Transcription Service Integration
- **Default URL**: `http://transcription:5000`
- **Environment Variable**: `TRANSCRIPTION_SERVICE_URL`
- **Endpoint**: `POST /transcribe`
- **Request Format**:
  ```
  files: {'audio': file_data}
  data: {
      'return_confidence': 'true',
      'return_segments': 'true',
      'language': 'en'
  }
  ```
- **Response Format**: JSON with transcript, segments, and confidence data

### Docker Integration
- **Container**: `aws-transcription-laravel`
- **Execution**: All npm/node and php/composer commands via `docker exec`

## Error Handling

### File Access Errors
- **Missing Files**: Return error with clear message
- **Permission Issues**: Log error and return structured failure response
- **Invalid Format**: Detect and report unsupported audio formats

### Network Errors
- **Connection Timeout**: Configurable timeout with graceful degradation
- **Service Unavailable**: Fall back to technical analysis only
- **Invalid Response**: Parse errors handled with detailed logging

### Processing Errors
- **Audio Analysis Failure**: Return partial results when possible
- **Calculation Errors**: Default to conservative scoring
- **Memory Issues**: Handle large files with streaming where possible

### Error Response Format
```json
{
    "success": false,
    "error": "Detailed error message",
    "error_type": "file_access|network|processing",
    "overall_score": 0.0,
    "fallback_available": boolean
}
```

## Performance Considerations

### Processing Time
- **Target**: < 10 seconds for technical analysis
- **Target**: < 30 seconds for comprehensive analysis (including Whisper testing)
- **Optimization**: Parallel processing for multiple file comparisons

### Memory Usage
- **Audio Loading**: Stream processing for large files
- **Result Caching**: Consider caching for repeated analysis
- **Memory Cleanup**: Explicit cleanup of audio data after processing

### Scalability
- **Concurrent Analysis**: Thread-safe design for parallel processing
- **Batch Processing**: Efficient handling of multiple files
- **Resource Limits**: Configurable timeouts and limits

### Network Optimization
- **Connection Pooling**: Reuse connections to transcription service
- **Request Batching**: Potential for batch transcription requests
- **Retry Logic**: Exponential backoff for failed requests

## Security Considerations

### File Access Security
- **Path Validation**: Prevent directory traversal attacks
- **File Type Validation**: Restrict to supported audio formats
- **Size Limits**: Prevent resource exhaustion from large files

### Network Security
- **HTTPS Support**: Secure communication with transcription service
- **API Authentication**: Support for service authentication
- **Input Sanitization**: Validate all external inputs

### Data Privacy
- **Temporary Files**: Secure handling and cleanup
- **Audio Data**: No persistent storage of audio content
- **Logging**: Exclude sensitive information from logs

## Monitoring and Logging

### Logging Levels
- **INFO**: Analysis start/completion, performance metrics
- **WARNING**: Partial failures, degraded performance
- **ERROR**: Analysis failures, network issues
- **DEBUG**: Detailed metric calculations, timing information

### Key Metrics to Monitor
- **Analysis Success Rate**: Percentage of successful analyses
- **Average Processing Time**: Performance tracking
- **Whisper Service Availability**: Network health monitoring
- **Quality Score Distribution**: System effectiveness metrics

### Alerting Thresholds
- **High Failure Rate**: > 10% analysis failures
- **Slow Performance**: > 60 seconds average processing time
- **Service Unavailability**: Transcription service downtime

## Demucs Integration Enhancement

### Demucs Source Separation Layer

#### Implementation Strategy
The system now includes an optional **Demucs preprocessing layer** that can significantly improve transcription quality for mixed audio content.

#### Key Benefits for Guitar Instruction Content
- **Speech Isolation**: Extract clean vocal tracks from music-heavy instruction videos
- **Background Music Removal**: Separate speech from instrumental backing tracks
- **Improved SNR**: Enhanced signal-to-noise ratio for Whisper transcription
- **Quality Comparison**: Analyze both original and separated audio to select optimal version

#### Integration Points
```python
# New Demucs Integration Module
class DemucsSourceSeparator:
    def separate_vocals(audio_path: str) -> Dict:
        """Extract vocals/speech track using Demucs"""
        
    def compare_separation_quality(original_path: str, separated_path: str) -> Dict:
        """Compare original vs separated audio quality"""
        
    def should_use_separation(analysis_results: Dict) -> bool:
        """Determine if separated audio is better for transcription"""
```

#### Configuration Options
- **Model Selection**: `htdemucs_ft` for fine-tuned results, `htdemucs` for speed
- **Separation Mode**: `--two-stems=vocals` for speech extraction
- **Processing Options**: GPU acceleration with memory management
- **Quality Thresholds**: Configurable thresholds for separation benefits

#### Docker Integration
```bash
# Install Demucs in the transcription container
docker exec aws-transcription-laravel pip install demucs
docker exec aws-transcription-laravel demucs --two-stems=vocals audio.wav
```

## Future Enhancements

### Advanced Quality Metrics
- **Noise Detection**: Background noise analysis
- **Speech Clarity**: Frequency analysis for speech clarity
- **Voice Activity Detection**: Ratio of speech to silence
- **Multi-speaker Detection**: Analysis of speaker separation
- **Source Separation Quality**: Assess Demucs separation effectiveness

### Performance Improvements
- **Caching Layer**: Cache analysis results for identical files
- **Async Processing**: Non-blocking analysis with callbacks
- **GPU Acceleration**: Leverage GPU for both Demucs and audio processing
- **Streaming Analysis**: Process large files in chunks
- **Demucs Model Optimization**: Use quantized models for faster processing

### Integration Enhancements
- **REST API**: Web service wrapper for HTTP access
- **Webhook Support**: Callback notifications for completed analyses
- **Database Integration**: Persistent storage of analysis history
- **Metrics Dashboard**: Real-time monitoring interface
- **Demucs Pipeline API**: Automated source separation workflows

### Machine Learning Integration
- **Predictive Scoring**: ML models for quality prediction
- **Adaptive Thresholds**: Dynamic optimization of quality thresholds
- **Pattern Recognition**: Automated issue detection and recommendations
- **Transfer Learning**: Custom models for specific audio types
- **Separation Benefit Prediction**: ML models to predict when Demucs will help

### Additional Audio Formats
- **MP3 Support**: Extend beyond WAV files
- **Format Conversion**: Automatic conversion to optimal format
- **Batch Conversion**: Efficient processing of mixed formats
- **Quality-preserving Transcoding**: Lossless format optimization
- **Multi-stem Output**: Support for Demucs' multiple output formats

---

## Document Metadata
- **Version**: 1.0
- **Last Updated**: 2024
- **Authors**: AI Development Team
- **Review Status**: Draft
- **Next Review**: Quarterly 