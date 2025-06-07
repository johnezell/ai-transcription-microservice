# FFmpeg Optimization Implementation Plan
**Target:** [`app/services/audio-extraction/service.py`](app/services/audio-extraction/service.py) - Improve Whisper AI transcription accuracy by 5-15%

## Phase 1: Audio Normalization (Week 1-2, Low Risk)

### Changes Required
**File:** [`app/services/audio-extraction/service.py`](app/services/audio-extraction/service.py:29)

**Current FFmpeg command (line 34-42):**
```python
command = [
    "ffmpeg", "-y", "-i", str(input_path), "-vn", 
    "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1", 
    str(output_path)
]
```

**Enhanced command:**
```python
command = [
    "ffmpeg", "-y", "-i", str(input_path), "-vn",
    "-af", "dynaudnorm=p=0.9:s=5",  # Audio normalization
    "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
    "-sample_fmt", "s16", str(output_path)
]
```

### New Functions to Add
```python
def validate_audio_input(input_path):
    """Validate audio file with ffprobe before processing."""
    probe_command = [
        "ffprobe", "-v", "error", "-select_streams", "a:0",
        "-show_entries", "stream=codec_name,sample_rate,channels,duration",
        "-of", "json", str(input_path)
    ]
    result = subprocess.run(probe_command, capture_output=True, text=True)
    if result.returncode != 0:
        raise ValueError(f"Invalid audio file: {result.stderr}")
    return json.loads(result.stdout)

def assess_audio_quality(audio_path):
    """Assess converted audio quality."""
    command = [
        "ffprobe", "-v", "error", "-show_entries", 
        "stream=bit_rate,sample_rate,channels", "-select_streams", "a:0",
        "-of", "json", str(audio_path)
    ]
    result = subprocess.run(command, capture_output=True, text=True)
    return json.loads(result.stdout)
```

### Success Criteria
- All existing audio files process without errors
- Audio normalization reduces volume variance by >80%
- Processing time increase <20%
- Zero regression in transcription accuracy

## Phase 2: Quality Enhancement (Week 3-4, Medium Risk)

### Enhanced Processing Function
```python
def preprocess_for_whisper(input_path, output_path, quality_level="balanced"):
    """Preprocess audio for Whisper with configurable quality levels."""
    quality_configs = {
        "fast": {
            "filters": ["dynaudnorm=p=0.9:s=5"],
            "threads": 2
        },
        "balanced": {
            "filters": ["highpass=f=80", "lowpass=f=8000", "dynaudnorm=p=0.9:s=5"],
            "threads": 4
        },
        "high": {
            "filters": [
                "highpass=f=80", "lowpass=f=8000", 
                "dynaudnorm=p=0.9:s=5:r=0.9", "afftdn=nf=-25"
            ],
            "threads": 6
        }
    }
    
    config = quality_configs[quality_level]
    command = [
        "ffmpeg", "-y", "-threads", str(config["threads"]),
        "-i", str(input_path), "-vn",
        "-af", ",".join(config["filters"]),
        "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
        "-sample_fmt", "s16", str(output_path)
    ]
    return command
```

### Configuration Management
```python
# Add after line 25
AUDIO_PROCESSING_CONFIG = {
    "default_quality": os.environ.get('AUDIO_QUALITY_LEVEL', 'balanced'),
    "enable_normalization": os.environ.get('ENABLE_NORMALIZATION', 'true').lower() == 'true',
    "max_threads": int(os.environ.get('FFMPEG_THREADS', '4'))
}
```

### Docker Environment Variables
**File:** [`Dockerfile.audio-service`](Dockerfile.audio-service)
```dockerfile
ENV AUDIO_QUALITY_LEVEL=balanced
ENV ENABLE_NORMALIZATION=true
ENV FFMPEG_THREADS=4
```

### Success Criteria
- 5-10% improvement in noisy audio transcription
- Processing time increase <40% for balanced mode
- Configurable quality levels working correctly

## Phase 3: Advanced Features (Week 5-6, Higher Risk)

### Voice Activity Detection
```python
def apply_vad_preprocessing(input_path, output_path):
    """Apply Voice Activity Detection preprocessing."""
    command = [
        "ffmpeg", "-y", "-i", str(input_path), "-vn",
        "-af", "silenceremove=start_periods=1:start_duration=1:start_threshold=-60dB:detection=peak,aformat=dblp,areverse,silenceremove=start_periods=1:start_duration=1:start_threshold=-60dB:detection=peak,aformat=dblp,areverse",
        "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
        str(output_path)
    ]
    return command
```

### Success Criteria
- 8-15% overall transcription accuracy improvement
- Production deployment with monitoring
- Performance optimization targets met

## Testing Strategy

### Unit Tests (ai_roo_ prefixed files)
```python
# ai_roo_test_ffmpeg_optimization.py
def test_quality_levels():
    """Test different quality levels produce expected filters."""
    for quality in ["fast", "balanced", "high"]:
        command = preprocess_for_whisper("input.mp4", "output.wav", quality)
        assert "-af" in command
        
def test_audio_validation():
    """Test audio input validation."""
    # Test with valid and invalid audio files
    
def test_processing_performance():
    """Test processing time within acceptable limits."""
    # Benchmark processing times
```

### PowerShell Testing Commands
```powershell
# Run tests in Docker container
docker exec aws-transcription-laravel python -m pytest ai_roo_test_*.py -v

# Test service health
docker exec aws-transcription-laravel curl http://audio-extraction:5000/health

# Cleanup test files
docker exec aws-transcription-laravel python -c "
import os, glob
for file in glob.glob('ai_roo_*'):
    os.remove(file)
    print(f'Removed {file}')
"
```

## Risk Mitigation

### Phase 1 Risks
- **Audio normalization breaks existing files** → Comprehensive testing with existing samples
- **Performance degradation** → Benchmarking and rollback capability

### Phase 2 Risks  
- **Noise reduction artifacts** → A/B testing with sample files
- **Increased processing time** → Configurable quality levels

### Phase 3 Risks
- **VAD preprocessing failures** → Extensive testing with diverse audio
- **Production performance impact** → Staged rollout with monitoring

### Rollback Strategy
Maintain original [`convert_to_wav()`](app/services/audio-extraction/service.py:29) function as fallback:
```python
def convert_to_wav_original(input_path, output_path):
    """Original conversion function for rollback."""
    # Keep original implementation
```

## Success Metrics

### Quality Improvements
- **Target:** 5-15% transcription accuracy improvement
- **Measurement:** A/B testing with sample audio files
- **Validation:** Compare transcription results before/after optimization

### Performance Targets
- **Processing Time:** <40% increase for balanced mode
- **Memory Usage:** <20% increase
- **Error Rate:** <1% processing failures

### Monitoring
```python
@app.route('/metrics', methods=['GET'])
def processing_metrics():
    """Expose processing metrics."""
    return jsonify({
        'avg_processing_time': calculate_avg_processing_time(),
        'quality_score': calculate_quality_score(),
        'error_rate': calculate_error_rate()
    })
```

## Implementation Timeline

**Week 1-2:** Phase 1 (Normalization)  
**Week 3-4:** Phase 2 (Quality Enhancement)  
**Week 5-6:** Phase 3 (Advanced Features)

## Deployment Commands

```powershell
# Deploy changes
docker-compose down
docker-compose build audio-extraction  
docker-compose up -d

# Validate deployment
docker exec aws-transcription-laravel curl http://audio-extraction:5000/health
```

## Expected Outcomes

- **5-15% improvement** in Whisper AI transcription accuracy
- **Robust audio preprocessing** with configurable quality levels
- **Production-ready monitoring** and error handling
- **Backward compatibility** with existing service architecture
- **Scalable configuration** management for different use cases

This plan provides a structured approach to FFmpeg optimization while maintaining focus on measurable improvements and risk mitigation.