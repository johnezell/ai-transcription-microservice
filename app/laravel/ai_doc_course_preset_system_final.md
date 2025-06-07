# Course-Level Audio Extraction Preset System - Final Documentation

## System Overview

The course-level audio extraction preset system provides a robust, scalable solution for managing audio extraction quality settings at the course level within the AI Transcription Microservice. This system uses a **local database architecture** with **pivot tables** to avoid direct modifications to TrueFire's production database while maintaining full functionality.

### Architecture Benefits

- **Database Isolation**: Uses local SQLite database instead of modifying TrueFire's production tables
- **Pivot Table Design**: Clean separation of concerns with dedicated `course_audio_presets` table
- **Local Data Synchronization**: Maintains local copies of TrueFire course data for performance and reliability
- **Scalable Processing**: Supports batch processing of entire courses with configurable quality presets

## Database Schema

### Primary Tables

#### `course_audio_presets` Table
```sql
CREATE TABLE course_audio_presets (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    truefire_course_id BIGINT NOT NULL,
    audio_extraction_preset ENUM('fast', 'balanced', 'high', 'premium') DEFAULT 'balanced',
    settings JSON NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    UNIQUE KEY unique_course_preset (truefire_course_id),
    INDEX idx_course_preset (truefire_course_id, audio_extraction_preset)
);
```

**Purpose**: Stores audio extraction presets and settings for each TrueFire course.

**Key Features**:
- One preset per course (enforced by unique constraint)
- JSON settings field for extensible configuration
- Indexed for efficient lookups
- Default 'balanced' preset for new courses

#### `local_truefire_courses` Table
```sql
CREATE TABLE local_truefire_courses (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    audio_extraction_preset VARCHAR(255) NULL,
    title VARCHAR(255) NULL,
    -- ... (complete TrueFire course schema replica)
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**Purpose**: Local copy of TrueFire course data to avoid cross-database dependencies.

**Key Features**:
- Exact replica of TrueFire course table structure
- Performance indexes on commonly queried fields
- Maintains data integrity without external dependencies

### Relationships

```php
// CourseAudioPreset Model
public function truefireCourse(): BelongsTo
{
    return $this->belongsTo(TruefireCourse::class, 'truefire_course_id');
}

// TruefireCourse Model  
public function audioPreset(): HasOne
{
    return $this->hasOne(CourseAudioPreset::class, 'truefire_course_id');
}
```

## API Endpoints

### 1. Set Course Audio Preset
**Endpoint**: `PUT /truefire-courses/{id}/audio-preset`

**Purpose**: Set or update the audio extraction preset for a specific course.

**Request Body**:
```json
{
    "preset": "high",
    "settings": {
        "custom_param": "value"
    }
}
```

**Response**:
```json
{
    "success": true,
    "message": "Audio extraction preset updated to high",
    "data": {
        "course_id": 123,
        "preset": "high",
        "previous_preset": "balanced"
    }
}
```

**Implementation**: [`TruefireCourseController::setAudioPreset()`](app/Http/Controllers/TruefireCourseController.php:2548)

### 2. Get Course Audio Preset
**Endpoint**: `GET /truefire-courses/{id}/audio-preset`

**Purpose**: Retrieve the current audio extraction preset and settings for a course.

**Response**:
```json
{
    "success": true,
    "data": {
        "course_id": 123,
        "preset": "high",
        "settings": {},
        "available_presets": {
            "fast": "Fast - Quick processing with basic quality",
            "balanced": "Balanced - Good quality with reasonable processing time",
            "high": "High - High quality with longer processing time",
            "premium": "Premium - Maximum quality with extended processing time"
        }
    }
}
```

**Implementation**: [`TruefireCourseController::getAudioPreset()`](app/Http/Controllers/TruefireCourseController.php:2609)

### 3. Process All Course Videos
**Endpoint**: `POST /truefire-courses/{id}/process-all-videos`

**Purpose**: Start batch processing of all videos in a course using the configured preset.

**Request Body**:
```json
{
    "for_transcription": true,
    "settings": {
        "priority": "high",
        "notification_email": "user@example.com"
    }
}
```

**Response**:
```json
{
    "success": true,
    "message": "Course audio extraction processing started",
    "data": {
        "course_id": 123,
        "course_title": "Advanced Guitar Techniques",
        "preset": "high",
        "for_transcription": true,
        "output_format": "mp3",
        "total_segments": 45,
        "available_segments": 42,
        "missing_segments": 3,
        "job_id": "course-audio-extraction-123-1704672000",
        "processing_started_at": "2024-01-08T00:00:00.000Z"
    }
}
```

**Implementation**: [`TruefireCourseController::processAllVideos()`](app/Http/Controllers/TruefireCourseController.php:2650)

### 4. Get Audio Extraction Progress
**Endpoint**: `GET /truefire-courses/{id}/audio-extraction-progress`

**Purpose**: Monitor the progress of batch audio extraction for a course.

**Response**:
```json
{
    "success": true,
    "data": {
        "course_id": 123,
        "job_id": "course-audio-extraction-123-1704672000",
        "status": "processing",
        "progress_percentage": 65,
        "total_segments": 45,
        "completed_segments": 29,
        "failed_segments": 1,
        "remaining_segments": 15,
        "estimated_completion": "2024-01-08T02:30:00.000Z",
        "current_segment": {
            "id": 456,
            "title": "Segment 30",
            "status": "processing"
        }
    }
}
```

**Implementation**: [`TruefireCourseController::getCourseAudioExtractionProgress()`](app/Http/Controllers/TruefireCourseController.php:2800)

## Usage Examples

### Setting a Course Preset

```bash
# Set course to use high quality preset
curl -X PUT "http://localhost/truefire-courses/123/audio-preset" \
  -H "Content-Type: application/json" \
  -d '{"preset": "high"}'
```

### Getting Current Preset

```bash
# Get current preset for course
curl "http://localhost/truefire-courses/123/audio-preset"
```

### Starting Batch Processing

```bash
# Process all videos in course for transcription (MP3 output)
curl -X POST "http://localhost/truefire-courses/123/process-all-videos" \
  -H "Content-Type: application/json" \
  -d '{"for_transcription": true}'

# Process all videos for testing (WAV output)
curl -X POST "http://localhost/truefire-courses/123/process-all-videos" \
  -H "Content-Type: application/json" \
  -d '{"for_transcription": false}'
```

### Monitoring Progress

```bash
# Check processing progress
curl "http://localhost/truefire-courses/123/audio-extraction-progress"
```

## Technical Implementation

### Model Architecture

#### CourseAudioPreset Model
**File**: [`app/Models/CourseAudioPreset.php`](app/Models/CourseAudioPreset.php)

**Key Methods**:
- `getOrCreateForCourse()` - Get or create preset with defaults
- `updateForCourse()` - Update preset and settings
- `getPresetForCourse()` - Retrieve preset for a course
- `getSettingsForCourse()` - Retrieve settings for a course
- `isValidPreset()` - Validate preset values
- `getAvailablePresets()` - Get all available presets with descriptions

#### TruefireCourse Model
**File**: [`app/Models/TruefireCourse.php`](app/Models/TruefireCourse.php)

**Key Relationships**:
```php
public function audioPreset(): HasOne
{
    return $this->hasOne(CourseAudioPreset::class, 'truefire_course_id');
}

public function getAudioExtractionPreset(): string
{
    return $this->audioPreset?->audio_extraction_preset ?? 'balanced';
}
```

### Job Processing Workflow

#### ProcessCourseAudioExtractionJob
**Purpose**: Handles batch processing of all course videos

**Workflow**:
1. **Initialization**: Load course and validate preset
2. **Segment Discovery**: Find all available video segments
3. **Job Dispatch**: Create individual extraction jobs for each segment
4. **Progress Tracking**: Monitor and update processing status
5. **Completion**: Notify when all segments are processed

**Key Features**:
- **Parallel Processing**: Multiple segments processed simultaneously
- **Error Handling**: Failed segments don't stop entire batch
- **Progress Reporting**: Real-time status updates
- **Output Format Control**: MP3 for transcription, WAV for testing

### Output Formats

#### For Transcription (MP3)
- **Format**: MP3 with optimized settings for Whisper
- **Quality**: Based on selected preset (fast/balanced/high/premium)
- **Use Case**: Direct input to transcription service
- **Storage**: Efficient compression for long-term storage

#### For Testing (WAV)
- **Format**: Uncompressed WAV for quality analysis
- **Quality**: Multiple quality levels for comparison
- **Use Case**: Audio quality testing and validation
- **Storage**: Temporary files for analysis purposes

### Quality Presets

| Preset | Processing Time | Quality Level | Use Case |
|--------|----------------|---------------|----------|
| **Fast** | ~30s per segment | Basic | Quick testing, draft transcriptions |
| **Balanced** | ~60s per segment | Good | Standard production use |
| **High** | ~120s per segment | High | Premium content, important recordings |
| **Premium** | ~300s per segment | Maximum | Critical content, archival quality |

### Error Handling

#### Validation Errors
- Invalid preset values
- Missing course data
- Insufficient permissions

#### Processing Errors
- Missing video files
- FFmpeg processing failures
- Storage/disk space issues
- Network connectivity problems

#### Recovery Mechanisms
- **Automatic Retry**: Failed jobs automatically retry with exponential backoff
- **Partial Success**: Completed segments preserved even if some fail
- **Manual Retry**: Individual failed segments can be reprocessed
- **Fallback Quality**: Automatic fallback to lower quality on processing failure

## System Status Verification

### Migration Status
All required database migrations are applied:
- ✅ `2025_06_07_225213_create_local_truefire_courses_table`
- ✅ `2025_06_07_234500_create_course_audio_presets_table`
- ✅ Related audio testing and batch processing tables

### API Endpoints Status
All course preset endpoints are active:
- ✅ `PUT /truefire-courses/{id}/audio-preset`
- ✅ `GET /truefire-courses/{id}/audio-preset`
- ✅ `POST /truefire-courses/{id}/process-all-videos`
- ✅ `GET /truefire-courses/{id}/audio-extraction-progress`

### Local Data Synchronization
- ✅ Local TrueFire course data structure implemented
- ✅ Pivot table relationships established
- ✅ Data integrity constraints in place

## Benefits Over Direct TrueFire Modification

### 1. **Database Isolation**
- No risk of corrupting production TrueFire data
- Independent schema evolution
- Simplified backup and recovery

### 2. **Performance Optimization**
- Local queries avoid cross-database joins
- Optimized indexes for specific use cases
- Reduced network latency

### 3. **Flexibility**
- Custom fields and relationships
- Extensible JSON settings storage
- Independent deployment cycles

### 4. **Reliability**
- Isolated failure domains
- Independent scaling
- Simplified monitoring and debugging

## Future Enhancements

### Planned Features
1. **Preset Templates**: Predefined configurations for different content types
2. **Batch Operations**: Bulk preset updates across multiple courses
3. **Quality Analytics**: Automatic quality assessment and recommendations
4. **Integration APIs**: External system integration for preset management

### Scalability Considerations
1. **Horizontal Scaling**: Job queue distribution across multiple workers
2. **Storage Optimization**: Intelligent caching and cleanup policies
3. **Performance Monitoring**: Real-time metrics and alerting
4. **Resource Management**: Dynamic resource allocation based on load

## Conclusion

The course-level audio extraction preset system successfully provides:

- ✅ **Clean Architecture**: Local database with pivot tables
- ✅ **Full API Coverage**: Complete CRUD operations for presets
- ✅ **Batch Processing**: Efficient course-wide audio extraction
- ✅ **Quality Control**: Multiple preset levels with clear use cases
- ✅ **Error Resilience**: Comprehensive error handling and recovery
- ✅ **Production Ready**: All migrations applied, endpoints tested

The system is ready for production use and provides a solid foundation for future audio processing enhancements.

---

**Documentation Version**: 1.0  
**Last Updated**: June 7, 2025  
**System Status**: ✅ Production Ready