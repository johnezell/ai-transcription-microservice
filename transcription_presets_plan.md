# Transcription Presets Implementation Plan

## Overview
This plan outlines the implementation of transcription presets for the AI transcription microservice, following the existing pattern established by the audio extraction presets system. The transcription presets will control Whisper model selection, processing parameters, and quality/speed tradeoffs for the transcription service.

## Current System Analysis

### Audio Extraction Presets (Reference Implementation)
- **Model**: `CourseAudioPreset` with preset values: fast, balanced, high, premium
- **Controller Logic**: Handled in `TruefireCourseController` 
- **Frontend**: `CoursePresetManager.vue` component for UI management
- **Storage**: Presets stored per course with settings in JSON format

### Transcription Service Current State
- **Service**: Python Flask service (`app/services/transcription/service.py`)
- **Current Parameters**: model_name="base", initial_prompt, temperature=0, word_timestamps=True
- **Models Available**: Whisper models (tiny, base, small, medium, large, large-v2, large-v3)
- **Controller**: `TranscriptionController` in Laravel
- **Tracking**: `TranscriptionLog` model for job tracking

## Implementation Plan

### Phase 1: Database Schema and Models

#### 1.1 Create CourseTranscriptionPreset Model
**File**: `app/laravel/app/Models/CourseTranscriptionPreset.php`

**Fields**:
- `id` (primary key)
- `truefire_course_id` (foreign key to courses)
- `transcription_preset` (enum: fast, balanced, high, premium)
- `settings` (JSON field for additional parameters)
- `timestamps`

**Methods**:
- `getOrCreateForCourse($courseId, $defaultPreset = 'balanced')`
- `updateForCourse($courseId, $preset, $settings = [])`
- `getPresetForCourse($courseId, $defaultPreset = 'balanced')`
- `getSettingsForCourse($courseId)`
- `isValidPreset($preset)`
- `getAvailablePresets()`

#### 1.2 Create Migration
**File**: `app/laravel/database/migrations/YYYY_MM_DD_create_course_transcription_presets_table.php`

```sql
Schema::create('course_transcription_presets', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('truefire_course_id');
    $table->enum('transcription_preset', ['fast', 'balanced', 'high', 'premium'])
          ->default('balanced');
    $table->json('settings')->nullable();
    $table->timestamps();
    
    $table->foreign('truefire_course_id')->references('id')->on('local_truefire_courses');
    $table->unique('truefire_course_id');
    $table->index(['truefire_course_id', 'transcription_preset']);
});
```

#### 1.3 Add Transcription Preset Field to Videos Table
**Migration**: Add `transcription_preset_used` field to videos table for tracking which preset was used per video.

### Phase 2: Preset Configurations

#### 2.1 Define Preset Configurations
**Presets with Whisper Model Mapping**:

- **Fast**: 
  - Whisper Model: `tiny` or `base`
  - Temperature: 0
  - Initial Prompt: Generic
  - Word Timestamps: false
  - Use Case: Quick transcription, basic accuracy

- **Balanced**: 
  - Whisper Model: `small`
  - Temperature: 0
  - Initial Prompt: Music/guitar specific
  - Word Timestamps: true
  - Use Case: Standard transcription quality

- **High**: 
  - Whisper Model: `medium`
  - Temperature: 0.2 (for better creativity in music terms)
  - Initial Prompt: Detailed music instruction context
  - Word Timestamps: true
  - Use Case: Professional transcription

- **Premium**: 
  - Whisper Model: `large-v3`
  - Temperature: 0.3
  - Initial Prompt: Comprehensive music education context
  - Word Timestamps: true
  - Additional processing options
  - Use Case: Maximum accuracy for complex content

#### 2.2 Configuration File
**File**: `app/laravel/config/transcription_presets.php`

Store detailed preset configurations including:
- Whisper model selection
- Processing parameters
- Quality expectations
- Estimated processing times
- Use case descriptions

### Phase 3: Service Integration

#### 3.1 Update Transcription Service
**File**: `app/services/transcription/service.py`

**Changes Required**:
- Accept preset parameter in `/process` endpoint
- Map preset to Whisper model and parameters
- Update `process_audio()` function to use preset-based settings
- Add preset information to response metadata

**New Parameters Structure**:
```python
def get_preset_config(preset_name):
    presets = {
        'fast': {
            'model_name': 'tiny',
            'temperature': 0,
            'initial_prompt': 'Guitar lesson audio transcription.',
            'word_timestamps': False
        },
        'balanced': {
            'model_name': 'small', 
            'temperature': 0,
            'initial_prompt': 'Guitar lesson with music theory and techniques.',
            'word_timestamps': True
        },
        # ... etc for high and premium
    }
    return presets.get(preset_name, presets['balanced'])
```

#### 3.2 Update Laravel Transcription Job
**File**: `app/laravel/app/Jobs/ProcessTranscriptionJob.php`

**Changes**:
- Accept transcription preset parameter
- Pass preset to Python service
- Store preset used in video record and transcription log

### Phase 4: Controller Updates

#### 4.1 Add Transcription Preset Endpoints
**File**: `app/laravel/app/Http/Controllers/TruefireCourseController.php`

**New Methods**:
- `getTranscriptionPreset($courseId)` - GET endpoint
- `updateTranscriptionPreset($courseId)` - PUT endpoint  
- `getTranscriptionPresetOptions()` - GET available presets

#### 4.2 Integration with Existing Transcription Flow
**Files**: 
- `VideoController.php` - Update transcription request method
- `Api/TranscriptionController.php` - Pass preset to service

**Changes**:
- Retrieve course transcription preset before starting transcription
- Pass preset to transcription service
- Store preset used in transcription log and video record

### Phase 5: Frontend Implementation

#### 5.1 Create Transcription Preset Manager Component
**File**: `app/laravel/resources/js/Components/CourseTranscriptionPresetManager.vue`

**Based on**: `CoursePresetManager.vue` (audio presets)

**Features**:
- Preset selection (fast, balanced, high, premium)
- Visual preset comparison with:
  - Model information
  - Expected accuracy
  - Processing time estimates
  - Use case descriptions
- Save/update functionality
- Integration with batch transcription processing

#### 5.2 Update Existing Course Management UI
**Files to Update**:
- Course detail pages
- Video processing interfaces
- Batch processing components

**Integration Points**:
- Add transcription preset selection alongside audio preset selection
- Show preset information in video processing status
- Display preset used in completed transcription logs

### Phase 6: API Integration

#### 6.1 Update Transcription API Endpoints
**File**: `app/laravel/routes/api.php`

**New Routes**:
```php
// Transcription preset management
Route::get('/courses/{id}/transcription-preset', [TruefireCourseController::class, 'getTranscriptionPreset']);
Route::put('/courses/{id}/transcription-preset', [TruefireCourseController::class, 'updateTranscriptionPreset']);
Route::get('/transcription-presets', [TruefireCourseController::class, 'getTranscriptionPresetOptions']);
```

#### 6.2 Update Existing Transcription Endpoints
Modify existing transcription endpoints to:
- Accept preset parameter
- Return preset information in responses
- Include preset in job status updates

### Phase 7: Database Updates

#### 7.1 Add Preset Tracking Fields
**Tables to Update**:

**transcription_logs**:
- `transcription_preset_used` (string)
- `preset_settings` (JSON)

**videos**:
- `transcription_preset_used` (string)

#### 7.2 Migration for Existing Data
Create migration to:
- Set default preset for existing records
- Populate preset information for completed transcriptions

### Phase 8: Quality Assurance and Testing

#### 8.1 Unit Tests
**Files to Create**:
- `tests/Unit/Models/CourseTranscriptionPresetTest.php`
- `tests/Unit/Services/TranscriptionPresetServiceTest.php`

#### 8.2 Feature Tests
**Files to Create**:
- `tests/Feature/TranscriptionPresetManagementTest.php`
- `tests/Feature/TranscriptionWithPresetsTest.php`

#### 8.3 Integration Tests
- Test preset selection affects Whisper model choice
- Test preset persistence across batch processing
- Test UI preset management functionality

### Phase 9: Documentation and Configuration

#### 9.1 Update Documentation
- API documentation for new endpoints
- Preset configuration guide
- Migration guide for existing installations

#### 9.2 Configuration Management
- Environment variables for model availability
- Preset customization options
- Performance tuning guidelines

## Implementation Priority

### High Priority (Must Have)
1. Database schema and models (Phase 1)
2. Basic preset configurations (Phase 2.1)
3. Service integration (Phase 3)
4. Controller endpoints (Phase 4)

### Medium Priority (Should Have)
1. Frontend preset manager (Phase 5)
2. API integration (Phase 6)
3. Database updates for tracking (Phase 7)

### Low Priority (Nice to Have)
1. Advanced testing suite (Phase 8)
2. Comprehensive documentation (Phase 9)
3. Performance optimizations

## Technical Considerations

### Performance Impact
- Larger Whisper models (medium, large-v3) require more GPU memory and processing time
- Preset selection should include performance warnings
- Consider model caching and warm-up strategies

### Backward Compatibility
- Default to 'balanced' preset for existing courses
- Maintain existing transcription API compatibility
- Graceful degradation if preset model unavailable

### Error Handling
- Fallback to default preset if selected preset fails
- Model availability checking before job dispatch
- Clear error messages for preset-related failures

### Monitoring and Logging
- Track preset usage statistics
- Monitor processing time by preset
- Log preset effectiveness metrics

## Success Criteria

1. **Functional**: Users can select and save transcription presets per course
2. **Performance**: Different presets show measurable quality/speed tradeoffs
3. **Integration**: Presets work seamlessly with existing transcription workflow
4. **UI/UX**: Intuitive preset selection interface similar to audio presets
5. **Reliability**: Robust error handling and fallback mechanisms
6. **Scalability**: System handles concurrent transcription jobs with different presets

## Estimated Timeline

- **Phase 1-2**: 2-3 days (Models and configurations)
- **Phase 3**: 2-3 days (Service integration) 
- **Phase 4**: 1-2 days (Controller updates)
- **Phase 5**: 3-4 days (Frontend implementation)
- **Phase 6-7**: 1-2 days (API and database updates)
- **Phase 8-9**: 2-3 days (Testing and documentation)

**Total Estimated Time**: 11-17 days

This plan provides a comprehensive roadmap for implementing transcription presets that integrates seamlessly with the existing audio extraction presets system while providing users with fine-grained control over transcription quality and performance. 