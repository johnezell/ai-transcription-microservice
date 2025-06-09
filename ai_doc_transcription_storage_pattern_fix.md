# Transcription Service File Storage Pattern Fix

## Overview
Fixed the transcription service file storage pattern to match the audio-extraction service pattern, using segment-based directories instead of job-based directories.

## Problem Identified
- **Transcription Service** was saving files to `/var/www/storage/app/public/s3/jobs/{job_id}/`
- **Audio-Extraction Service** was saving files to segment-based directories like `/mnt/d_drive/truefire-courses/1/{segment_id}.wav`
- This inconsistency caused file organization issues and made it difficult to locate related files

## Solution Implemented

### Changes Made to [`app/services/transcription/service.py`](app/services/transcription/service.py)

1. **Added D Drive Base Path Constant**
   ```python
   D_DRIVE_BASE = '/mnt/d_drive'  # D drive mount point for segment-based storage
   ```

2. **Updated `/process` Endpoint**
   - Added support for `audio_path` parameter in request payload
   - Implemented segment-based path construction when `audio_path` is provided
   - Maintained backward compatibility with legacy job-based storage

3. **Updated `/transcribe` Endpoint**
   - Enhanced to support segment-based file storage for production mode
   - Maintains test mode compatibility with job-based storage

### File Storage Pattern Changes

#### Before (Job-Based)
```
/var/www/storage/app/public/s3/jobs/{job_id}/
├── audio.wav
├── transcript.txt
├── transcript.srt
└── transcript.json
```

#### After (Segment-Based)
```
/mnt/d_drive/truefire-courses/{course_id}/
├── {segment_id}.wav                    # Audio file (from audio-extraction)
├── {segment_id}.wav.analysis          # Analysis file (from audio-extraction)
├── {segment_id}_transcript.txt        # Transcript text (from transcription)
├── {segment_id}_transcript.srt        # SRT subtitles (from transcription)
└── {segment_id}_transcript.json       # Full transcription data (from transcription)
```

### Key Improvements

1. **Consistent File Organization**
   - All files for a segment are now stored in the same directory
   - Files are organized by segment ID rather than job ID
   - Matches the audio-extraction service pattern exactly

2. **Improved File Naming**
   - Transcription files use segment ID prefix: `{segment_id}_transcript.{ext}`
   - Clear distinction between audio files and transcription files
   - Easier to identify related files

3. **Backward Compatibility**
   - Legacy job-based storage still works for existing integrations
   - Automatic fallback when `audio_path` parameter is not provided

4. **Enhanced Error Handling**
   - Better directory creation logic
   - Improved UTF-8 encoding for JSON files
   - More robust path validation

## Integration with Laravel

The transcription service now properly handles the payload from [`TruefireSegmentTranscriptionJob`](app/laravel/app/Jobs/TruefireSegmentTranscriptionJob.php):

```php
$requestData = [
    'job_id' => "truefire_segment_{$this->processing->segment_id}",
    'audio_path' => $this->processing->audio_path,  // e.g., "truefire-courses/1/7959.wav"
    'settings' => $settings
];
```

The service automatically:
1. Constructs full path: `/mnt/d_drive/truefire-courses/1/7959.wav`
2. Extracts segment ID: `7959`
3. Creates output files in same directory with segment ID prefix

## Testing Results

✅ **Path Construction Logic Test**: All path constructions match expected audio-extraction service pattern
✅ **Service Integration Test**: Compatible with existing Laravel job system
✅ **Legacy Compatibility Test**: Backward compatibility maintained for existing integrations

### Test Output Summary
```
Audio-Extraction Service saves files as:
  - Audio: /mnt/d_drive/truefire-courses/1/7959.wav
  - Analysis: /mnt/d_drive/truefire-courses/1/7959.wav.analysis

Transcription Service now saves files as:
  - Audio: /mnt/d_drive/truefire-courses/1/7959.wav (input)
  - Transcript: /mnt/d_drive/truefire-courses/1/7959_transcript.txt
  - SRT: /mnt/d_drive/truefire-courses/1/7959_transcript.srt
  - JSON: /mnt/d_drive/truefire-courses/1/7959_transcript.json
```

## Benefits

1. **Unified File Organization**: Both services now use the same directory structure
2. **Easier File Management**: All segment-related files are co-located
3. **Improved Scalability**: Better organization for large numbers of segments
4. **Simplified Debugging**: Easier to locate and troubleshoot files
5. **Maintained Compatibility**: No breaking changes to existing functionality

## Files Modified

- [`app/services/transcription/service.py`](app/services/transcription/service.py) - Updated file storage logic

## Files Created

- `ai_doc_transcription_storage_pattern_fix.md` - This documentation
- `ai_roo_test_transcription_storage.py` - Test script (temporary, will be cleaned up)

---

**Status**: ✅ **COMPLETED**  
**Date**: 2025-06-08  
**Tested**: ✅ All tests passed successfully