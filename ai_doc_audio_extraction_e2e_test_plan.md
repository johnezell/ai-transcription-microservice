# Audio Extraction Workflow - End-to-End Testing Plan

## Overview
This document outlines the comprehensive end-to-end testing strategy for the audio extraction workflow, covering the complete system from TrueFire interface through to WAV file creation.

## Test Environment
- **Docker Container**: aws-transcription-laravel
- **Laravel Application**: Web interface and job processing
- **Python Service**: Audio extraction microservice
- **Storage**: D-drive mounted storage for video/audio files
- **Queue System**: Laravel database queue

## Workflow Components Identified

### 1. TrueFire Interface Integration
- **Controller**: `TruefireCourseController@testAudioExtraction`
- **Route**: `POST /truefire-courses/{courseId}/test-audio-extraction/{segmentId}`
- **Frontend**: Vue.js components for audio extraction testing

### 2. Laravel Job Processing
- **Job Class**: `AudioExtractionTestJob`
- **Queue**: Database-based queue system
- **Logging**: Comprehensive workflow step tracking
- **Database**: TranscriptionLog model for tracking

### 3. Python Service Communication
- **Service**: `audio-extraction-service` on port 5000
- **Endpoint**: `POST /process`
- **Features**: Quality levels, VAD, normalization
- **Communication**: HTTP requests from Laravel to Python

### 4. WAV File Creation
- **Input**: MP4 video files from TrueFire courses
- **Output**: WAV audio files (16kHz, mono, PCM 16-bit)
- **Location**: Same directory as source video
- **Quality Levels**: fast, balanced, high, premium

## Test Cases

### Test Case 1: Basic Audio Extraction Test
**Objective**: Verify complete workflow from web interface to WAV file creation

**Prerequisites**:
- TrueFire course with downloaded video segments
- Laravel queue worker running
- Python audio extraction service running

**Test Steps**:
1. Access TrueFire course interface
2. Select a segment for audio extraction testing
3. Configure quality level (balanced)
4. Trigger audio extraction test
5. Monitor Laravel logs for workflow progression
6. Verify job queuing and processing
7. Check Python service receives request
8. Confirm WAV file creation
9. Validate audio file properties

**Expected Results**:
- Job successfully queued without ModelNotFoundException
- Python service processes request
- WAV file created with correct specifications
- Comprehensive logging throughout workflow

### Test Case 2: Python Service Connectivity
**Objective**: Test direct connectivity and functionality of audio extraction service

**Test Steps**:
1. Test service health endpoint
2. Verify service configuration
3. Test direct audio processing
4. Validate service response format
5. Check error handling

**Expected Results**:
- Service responds to health checks
- Correct configuration reported
- Audio processing works independently
- Proper error responses for invalid inputs

### Test Case 3: Error Handling
**Objective**: Verify graceful failure handling and error reporting

**Test Scenarios**:
- Invalid file paths
- Missing video files
- Corrupted MP4 files
- Service unavailability
- Queue processing failures

**Expected Results**:
- Appropriate error messages
- Proper status updates in TranscriptionLog
- No system crashes
- Clear error reporting to users

### Test Case 4: Queue System Verification
**Objective**: Verify job serialization, processing, and status tracking

**Test Steps**:
1. Queue multiple audio extraction jobs
2. Monitor job serialization/deserialization
3. Check TranscriptionLog updates
4. Verify concurrent processing
5. Test job retry mechanisms

**Expected Results**:
- Jobs properly serialized without ModelNotFoundException
- Status tracking works correctly
- Concurrent processing functions
- Failed jobs can be retried

### Test Case 5: Quality Level Testing
**Objective**: Test different audio quality levels and processing options

**Quality Levels to Test**:
- fast: Basic processing
- balanced: Standard processing with filters
- high: Advanced processing with noise reduction
- premium: Full processing with VAD

**Expected Results**:
- Each quality level produces different processing
- Higher quality levels take longer but produce better audio
- All quality levels create valid WAV files

### Test Case 6: Performance Testing
**Objective**: Measure processing times and resource usage

**Metrics to Collect**:
- Job queue time
- Audio extraction duration
- File size comparisons
- Memory usage
- CPU utilization

## Test Execution Strategy

### Phase 1: Environment Verification
1. Verify Docker containers are running
2. Check service connectivity
3. Validate file system access
4. Confirm queue worker status

### Phase 2: Individual Component Testing
1. Test TrueFire interface components
2. Test Laravel job processing
3. Test Python service independently
4. Test file system operations

### Phase 3: Integration Testing
1. Execute complete workflow tests
2. Test error scenarios
3. Validate logging and monitoring
4. Performance testing

### Phase 4: Regression Testing
1. Re-run all test cases
2. Validate fixes from Debug mode
3. Confirm no ModelNotFoundException errors
4. Verify complete workflow stability

## Success Criteria

### Functional Requirements
- ✅ Complete workflow functions without ModelNotFoundException
- ✅ Jobs successfully reach Python audio extraction service
- ✅ WAV files are created from MP4 inputs
- ✅ Comprehensive logging tracks entire process
- ✅ Error handling works properly for failure scenarios

### Performance Requirements
- Audio extraction completes within reasonable time limits
- System handles concurrent requests
- Memory usage remains within acceptable bounds
- No memory leaks or resource exhaustion

### Quality Requirements
- Generated WAV files meet specifications (16kHz, mono, PCM 16-bit)
- Audio quality is preserved during extraction
- Different quality levels produce measurable differences
- Error messages are clear and actionable

## Test Data Requirements

### Required TrueFire Course Data
- Course with multiple video segments
- Various video file sizes and durations
- Different video formats/codecs for compatibility testing

### Test Scenarios Data
- Valid segment IDs for testing
- Invalid segment IDs for error testing
- Missing video files for error scenarios
- Corrupted video files for robustness testing

## Monitoring and Logging

### Laravel Logs to Monitor
- `workflow_step` progress tracking
- Job serialization/deserialization
- HTTP requests to Python service
- TranscriptionLog updates
- Error conditions and exceptions

### Python Service Logs to Monitor
- Incoming request processing
- FFmpeg command execution
- File system operations
- Audio quality metrics
- Error conditions and exceptions

### System Metrics to Track
- Queue depth and processing rate
- File system usage
- Service response times
- Resource utilization

## Risk Assessment

### High Risk Areas
- Job serialization (previously caused ModelNotFoundException)
- File path handling between Laravel and Python service
- Service communication reliability
- Concurrent processing stability

### Mitigation Strategies
- Comprehensive error handling
- Retry mechanisms for failed jobs
- Health checks for service availability
- Monitoring and alerting for critical failures

## Test Automation Opportunities

### Automated Test Cases
- Service health checks
- Basic workflow validation
- Error scenario testing
- Performance regression testing

### Manual Test Cases
- User interface testing
- Complex error scenarios
- Performance analysis
- Quality assessment

## Deliverables

1. **Test Execution Report**: Detailed results for each test case
2. **Log Analysis**: Comprehensive review of workflow logging
3. **Performance Metrics**: Processing times and resource usage
4. **WAV File Validation**: Audio quality and specification compliance
5. **Issue Documentation**: Any problems found and recommendations
6. **Regression Test Suite**: Automated tests for ongoing validation

## Timeline

- **Environment Setup**: 30 minutes
- **Test Case Execution**: 2-3 hours
- **Analysis and Documentation**: 1 hour
- **Total Estimated Time**: 3-4 hours

## Notes

- All test files will be prefixed with `ai_roo_` for easy cleanup
- Docker container `aws-transcription-laravel` will be used for all commands
- PowerShell commands will be used for Windows terminal operations
- Test files will be removed after completion as per project rules