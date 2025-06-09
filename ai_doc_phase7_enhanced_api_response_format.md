# WhisperX Enhanced API Response Format Documentation
**Phase 7: Frontend Integration - Enhanced API Response Format**

## Overview

The WhisperX service provides enhanced transcription capabilities with improved timing accuracy, speaker diarization, and comprehensive metadata. This document details the enhanced API response format for frontend integration.

## Enhanced API Response Structure

### Base Response Format

```json
{
  "success": true,
  "message": "Transcription completed successfully",
  "service_timestamp": "2025-06-09T15:27:04.506731",
  "transcript_text": "Full transcription text...",
  "confidence_score": 0.92,
  "segments": [...],
  "metadata": {...},
  "whisperx_processing": {...},
  "speaker_info": {...},
  "alignment_info": {...},
  "performance_metrics": {...}
}
```

### Core Fields

#### `transcript_text` (string)
Complete transcription text as a single string.

#### `confidence_score` (float, 0.0-1.0)
Overall transcription confidence score calculated from word-level probabilities.

#### `segments` (array)
Array of transcription segments with enhanced timing and metadata:

```json
{
  "start": 12.34,
  "end": 15.67,
  "text": "This is a guitar lesson segment.",
  "speaker": "SPEAKER_00",
  "words": [
    {
      "word": "This",
      "start": 12.34,
      "end": 12.56,
      "probability": 0.95
    },
    {
      "word": "is",
      "start": 12.57,
      "end": 12.68,
      "probability": 0.98
    }
  ]
}
```

### Enhanced Metadata

#### `metadata` Object
Service and processing information:

```json
{
  "service": "transcription-service",
  "processed_by": "WhisperX Enhanced Transcription",
  "model": "medium",
  "preset": "high",
  "settings": {
    "model_name": "medium",
    "enable_alignment": true,
    "enable_diarization": true,
    "performance_profile": "quality_optimized",
    "batch_size": 8,
    "chunk_size": 30,
    "return_char_alignments": true,
    "vad_onset": 0.400,
    "vad_offset": 0.300
  }
}
```

#### `whisperx_processing` Object
WhisperX-specific processing status and metadata:

```json
{
  "transcription": "completed",
  "alignment": "completed",
  "diarization": "completed",
  "processed_by": "WhisperX with enhanced alignment and diarization support",
  "performance_profile": "quality_optimized",
  "processing_times": {
    "transcription_seconds": 15.2,
    "alignment_seconds": 8.5,
    "diarization_seconds": 12.3,
    "total_seconds": 36.0
  }
}
```

### Speaker Diarization Data

#### `speaker_info` Object (when diarization enabled)
Speaker identification and labeling information:

```json
{
  "detected_speakers": 2,
  "speaker_labels": ["SPEAKER_00", "SPEAKER_01"],
  "min_speakers_configured": 1,
  "max_speakers_configured": 3
}
```

#### Speaker Labels in Segments
When speaker diarization is enabled, segments include speaker identification:

```json
{
  "start": 12.34,
  "end": 15.67,
  "text": "This is the first speaker.",
  "speaker": "SPEAKER_00",
  "words": [...]
}
```

### Enhanced Timing Data

#### `alignment_info` Object (when alignment enabled)
Word-level alignment quality and configuration:

```json
{
  "char_alignments_enabled": true,
  "alignment_model": "wav2vec2-large-960h-lv60-self",
  "language": "en"
}
```

#### Word-Level Timestamps
Enhanced word-level timing with improved accuracy:

```json
{
  "word": "guitar",
  "start": 23.45,
  "end": 23.89,
  "probability": 0.94
}
```

### Performance Metrics

#### `performance_metrics` Object
Detailed processing performance data:

```json
{
  "transcription_time": 15.2,
  "alignment_time": 8.5,
  "diarization_time": 12.3,
  "total_processing_time": 36.0
}
```

## Preset-Specific Response Variations

### Fast Preset Response
- Model: `tiny`
- Alignment: Enabled
- Diarization: Disabled
- Processing Profile: `speed_optimized`

### Balanced Preset Response
- Model: `small`
- Alignment: Enabled
- Diarization: Disabled
- Processing Profile: `balanced`

### High Preset Response
- Model: `medium`
- Alignment: Enabled
- Diarization: Enabled
- Processing Profile: `quality_optimized`

### Premium Preset Response
- Model: `large-v3`
- Alignment: Enabled
- Diarization: Enabled
- Processing Profile: `maximum_quality`

## Backward Compatibility

### Legacy Field Support
The enhanced API maintains backward compatibility with existing consumers:

- `text` field maps to `transcript_text`
- `segments` array maintains original structure with enhancements
- `confidence_score` remains consistent

### Optional Enhancement Flags
Enhanced features are additive and don't break existing implementations:

```json
{
  "enhanced_format": true,
  "whisperx_version": true,
  "backward_compatible": true
}
```

## Error Handling

### Processing Status Indicators
Each processing step includes status information:

- `"completed"` - Step completed successfully
- `"skipped"` - Step was not enabled for this preset
- `"failed"` - Step failed but processing continued

### Fallback Behavior
When enhanced features fail, the service provides:

1. Basic transcription results
2. Error metadata in respective sections
3. Fallback status indicators

## Frontend Integration Guidelines

### Detecting Enhanced Features
Check for enhanced capabilities:

```javascript
const hasEnhancedFeatures = response.whisperx_processing && response.enhanced_format;
const hasSpeakerDiarization = response.speaker_info && response.speaker_info.detected_speakers > 0;
const hasAlignment = response.whisperx_processing.alignment === 'completed';
```

### Handling Optional Features
Gracefully handle optional enhancements:

```javascript
// Check for speaker information
if (response.speaker_info) {
  // Display speaker-aware UI
  displaySpeakerLabels(response.speaker_info.speaker_labels);
}

// Check for enhanced timing
if (response.alignment_info) {
  // Use improved word-level timestamps
  enablePrecisionTiming(response.segments);
}
```

### Performance Monitoring
Access processing performance data:

```javascript
const processingTimes = response.whisperx_processing.processing_times;
const totalTime = processingTimes.total_seconds;
const alignmentTime = processingTimes.alignment_seconds;
```

## API Endpoints

### Primary Transcription Endpoint
- **URL**: `/transcribe`
- **Method**: POST
- **Content-Type**: `application/json`

### Health Check
- **URL**: `/health`
- **Method**: GET
- **Returns**: Service status and backend information

### Preset Information
- **URL**: `/presets/info`
- **Method**: GET
- **Returns**: Available presets and their configurations

### Service Capabilities
- **URL**: `/features/capabilities`
- **Method**: GET
- **Returns**: Comprehensive service feature information

## Quality Assurance

### Confidence Score Interpretation
- **0.9-1.0**: Excellent transcription quality
- **0.8-0.9**: Good transcription quality
- **0.7-0.8**: Acceptable transcription quality
- **Below 0.7**: Review recommended

### Timing Accuracy Indicators
- **Alignment Completed**: Word-level timestamps are highly accurate
- **Alignment Failed**: Fallback to segment-level timing
- **Character Alignments**: Enhanced precision for fine-grained timing

### Speaker Diarization Quality
- **Detected Speakers**: Number of unique speakers identified
- **Speaker Labels**: Consistent labeling across segments
- **Confidence**: Based on diarization model performance

## Migration Notes

### From Legacy Whisper
- Enhanced timing replaces timestamp correction
- Speaker diarization is new optional feature
- Performance metrics provide processing insights
- Backward compatibility maintained for existing consumers

### Gradual Adoption
- Start with basic enhanced features
- Progressively enable speaker diarization
- Utilize performance metrics for optimization
- Maintain fallback for legacy systems

---

**Generated**: 2025-06-09T11:33:18-04:00  
**Phase**: 7 - Frontend Integration  
**Version**: 1.0  
**Status**: Complete