# Dockerfile Transcription Service Optimization Plan

## Overview
This document outlines the implementation plan for optimizing the transcription service Docker container by pre-downloading WhisperX models during the build process, eliminating runtime model downloads and improving startup performance.

## Current State Analysis

### Existing Architecture
- **Base Image**: `pytorch/pytorch:2.1.2-cuda12.1-cudnn8-runtime`
- **Model Loading**: On-demand via [`whisperx_models.py`](app/services/transcription/whisperx_models.py)
- **Cache Directories**: Pre-configured but empty at build time
- **Model Types**: WhisperX transcription, alignment, and diarization models

### Current Model Usage by Preset
- **Fast**: `tiny` model + basic alignment
- **Balanced**: `small` model + basic alignment  
- **High**: `medium` model + advanced alignment + diarization
- **Premium**: `large-v3` model + advanced alignment + diarization

### Performance Issues
- First-time model loading causes 30-60 second delays
- Network dependency for model downloads
- Unpredictable startup times
- Potential timeout issues in production

## Optimization Goals

### Primary Objectives
1. **Eliminate Runtime Downloads**: Pre-download all required models during build
2. **Improve Startup Performance**: Reduce container startup time by 80%+
3. **Enable Offline Operation**: Remove internet dependency for model access
4. **Enhance Production Reliability**: Predictable container behavior

### Success Metrics
- Container startup time: < 10 seconds (vs current 30-60s)
- Model loading time: < 2 seconds per model (vs current 10-30s)
- Build time increase: Acceptable trade-off for runtime performance
- Image size increase: Monitored but acceptable for performance gains

## Implementation Strategy

### Phase 1: Core Model Pre-downloading

#### 1.1 WhisperX Transcription Models
```dockerfile
# Pre-download WhisperX models used by presets
RUN python -c "
import whisperx
import torch
import os

# Configuration
device = 'cuda' if torch.cuda.is_available() else 'cpu'
compute_type = 'float16' if device == 'cuda' else 'int8'
models = ['tiny', 'small', 'medium', 'large-v3']

for model_name in models:
    print(f'Pre-downloading WhisperX model: {model_name}')
    try:
        model = whisperx.load_model(
            model_name, 
            device=device, 
            compute_type=compute_type,
            download_root='/app/models'
        )
        print(f'✓ Successfully pre-downloaded {model_name}')
        del model
        torch.cuda.empty_cache() if torch.cuda.is_available() else None
    except Exception as e:
        print(f'✗ Failed to pre-download {model_name}: {str(e)}')
"
```

#### 1.2 Alignment Models
```dockerfile
# Pre-download alignment models
RUN python -c "
import whisperx

languages = ['en']  # Expand as needed
for lang in languages:
    print(f'Pre-downloading alignment model for: {lang}')
    try:
        align_model, metadata = whisperx.load_align_model(
            language_code=lang, 
            device=device,
            model_dir='/app/models'
        )
        print(f'✓ Successfully pre-downloaded alignment for {lang}')
        del align_model
    except Exception as e:
        print(f'✗ Failed: {str(e)}')
"
```

#### 1.3 Diarization Models
```dockerfile
# Pre-download diarization pipeline
RUN python -c "
import whisperx

try:
    diarize_model = whisperx.DiarizationPipeline(
        use_auth_token=None, 
        device=device
    )
    print('✓ Diarization model pre-downloaded')
    del diarize_model
except Exception as e:
    print(f'✗ Diarization failed: {str(e)}')
    print('Note: May require HuggingFace token')
"
```

### Phase 2: Build Optimization

#### 2.1 Multi-Stage Build Strategy
```dockerfile
# Stage 1: Model Download
FROM pytorch/pytorch:2.1.2-cuda12.1-cudnn8-runtime AS model-downloader
# ... install dependencies and download models

# Stage 2: Runtime Image
FROM pytorch/pytorch:2.1.2-cuda12.1-cudnn8-runtime AS runtime
# ... copy pre-downloaded models and application code
```

#### 2.2 Selective Model Downloading
- **Development**: Download only essential models (`small`, `medium`)
- **Production**: Download all models for full preset support
- **Build Arguments**: Control which models to download

```dockerfile
ARG DOWNLOAD_MODELS="small,medium"
ARG ENVIRONMENT="development"
```

#### 2.3 Build Caching Strategy
- Layer optimization for Docker build cache efficiency
- Separate model download from application code copying
- Minimize rebuild frequency for model layers

### Phase 3: Integration with Existing System

#### 3.1 WhisperXModelManager Compatibility
- Existing [`WhisperXModelManager`](app/services/transcription/whisperx_models.py:25) will automatically detect pre-downloaded models
- No code changes required in model loading logic
- Cache hit rate should approach 100%

#### 3.2 Environment Variable Alignment
```dockerfile
ENV TORCH_HOME=/app/models
ENV HF_HOME=/app/models/huggingface
ENV WHISPERX_CACHE_DIR=/app/models/whisperx
ENV WHISPERX_MODELS_PRELOADED=true
```

#### 3.3 Startup Verification
Add startup checks to verify model availability:
```python
def verify_preloaded_models():
    """Verify all required models are pre-downloaded"""
    required_models = ['tiny', 'small', 'medium', 'large-v3']
    # Implementation to check model files exist
```

## Implementation Steps

### Step 1: Backup and Preparation
1. Create backup of current [`Dockerfile.transcription`](Dockerfile.transcription)
2. Document current build and startup times
3. Test current functionality as baseline

### Step 2: Basic Model Pre-downloading
1. Add WhisperX model pre-download section to Dockerfile
2. Test with single model (`small`) first
3. Verify model loading performance improvement
4. Measure build time impact

### Step 3: Complete Model Suite
1. Add alignment model pre-downloading
2. Add diarization model pre-downloading
3. Handle authentication requirements for restricted models
4. Test all preset configurations

### Step 4: Build Optimization
1. Implement multi-stage build if needed
2. Add build arguments for selective downloading
3. Optimize layer caching strategy
4. Document build process

### Step 5: Integration Testing
1. Test container startup performance
2. Verify all presets work correctly
3. Test offline operation (no internet access)
4. Performance benchmarking vs original

### Step 6: Production Deployment
1. Update CI/CD pipelines for longer build times
2. Update deployment documentation
3. Monitor production performance
4. Rollback plan if issues arise

## Risk Assessment and Mitigation

### High Risk Items
1. **Build Time Increase**: Models are large (3GB+ total)
   - **Mitigation**: Use build caching, consider selective downloading
   
2. **Image Size Growth**: Significant storage increase
   - **Mitigation**: Monitor registry storage, implement cleanup policies
   
3. **Authentication Requirements**: Some models need HuggingFace tokens
   - **Mitigation**: Document token requirements, provide fallback options

### Medium Risk Items
1. **Build Failures**: Network issues during model download
   - **Mitigation**: Retry logic, fallback to runtime download
   
2. **GPU/CPU Compatibility**: Different model formats for different devices
   - **Mitigation**: Build for CPU compatibility, runtime device detection

### Low Risk Items
1. **Model Version Updates**: Pre-downloaded models may become outdated
   - **Mitigation**: Regular rebuild schedule, version pinning

## Success Criteria

### Performance Metrics
- [ ] Container startup time reduced by 80%+
- [ ] Model loading time < 2 seconds per model
- [ ] 100% cache hit rate for pre-downloaded models
- [ ] Successful offline operation

### Functional Requirements
- [ ] All existing presets work correctly
- [ ] No regression in transcription quality
- [ ] Backward compatibility maintained
- [ ] Error handling for missing models

### Operational Requirements
- [ ] Build process documented and automated
- [ ] Monitoring and alerting for build failures
- [ ] Rollback procedure tested and documented
- [ ] Production deployment successful

## Monitoring and Maintenance

### Build Monitoring
- Track build times and success rates
- Monitor image size growth
- Alert on build failures

### Runtime Monitoring
- Container startup time metrics
- Model loading performance
- Cache hit rates
- Error rates for model loading

### Maintenance Schedule
- Monthly model updates check
- Quarterly full rebuild with latest models
- Annual review of model requirements

## Conclusion

This optimization will significantly improve the transcription service's startup performance and reliability by eliminating runtime model downloads. The implementation should be done incrementally with thorough testing at each phase to ensure no regression in functionality while achieving substantial performance gains.

The investment in longer build times will pay dividends in faster, more reliable container startups and improved user experience in production environments.