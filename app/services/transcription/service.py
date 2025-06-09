#!/usr/bin/env python3
import warnings
import torch
from flask import Flask, request, jsonify
import os
import requests
import json
import logging
import subprocess
from datetime import datetime
import tempfile
import uuid
from pathlib import Path

# Suppress specific warnings
warnings.filterwarnings("ignore", category=FutureWarning)
warnings.filterwarnings("ignore", category=UserWarning)

# Set torch settings
torch.set_grad_enabled(False)
if torch.cuda.is_available():
    torch.set_default_tensor_type(torch.cuda.FloatTensor)
else:
    torch.set_default_tensor_type(torch.FloatTensor)

# Import Whisper for transcription
import whisper
from functools import lru_cache
import re
from typing import Dict, List, Union, Optional, Any, Tuple

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Set environment constants for cleaner path handling
S3_BASE_DIR = '/var/www/storage/app/public/s3'
S3_JOBS_DIR = os.path.join(S3_BASE_DIR, 'jobs')
D_DRIVE_BASE = '/mnt/d_drive'  # D drive mount point for segment-based storage

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://laravel/api')

# Ensure base directory exists
os.makedirs(S3_JOBS_DIR, exist_ok=True)

# Lazy-load Whisper model to save memory
@lru_cache(maxsize=1)
def load_whisper_model(model_name="base"):
    """Load the Whisper model with caching for efficiency."""
    logger.info(f"Loading Whisper model: {model_name}")
    return whisper.load_model(model_name)

def calculate_confidence(segments):
    """Calculate the overall confidence score for a transcription."""
    probabilities = []
    
    # Extract probabilities from word segments if available
    for segment in segments:
        for word_info in segment.get('words', []):
            probability = word_info.get('probability', None)
            if probability is not None:
                probabilities.append(probability)
    
    if not probabilities:
        return 0.0  # No probabilities available
    
    # Calculate mean probability
    return sum(probabilities) / len(probabilities)

def detect_voice_start_time(audio_path: str, silence_threshold: float = -40.0, min_voice_duration: float = 0.5) -> Optional[float]:
    """
    Detect the actual start time of voice in the audio file using FFmpeg's silencedetect.
    
    Args:
        audio_path: Path to audio file
        silence_threshold: dB threshold for silence detection (default: -40dB)
        min_voice_duration: Minimum duration to consider as voice (default: 0.5s)
        
    Returns:
        float: Voice start time in seconds, or None if detection fails
    """
    try:
        logger.info(f"Detecting voice start time in: {audio_path}")
        
        # Use ffmpeg's silencedetect filter to find voice start
        command = [
            "ffmpeg", "-i", str(audio_path),
            "-af", f"silencedetect=noise={silence_threshold}dB:duration={min_voice_duration}",
            "-f", "null", "-"
        ]
        
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            logger.error(f"FFmpeg silencedetect failed: {result.stderr}")
            return None
        
        # Parse silence detection output
        stderr_output = result.stderr
        voice_start_time = None
        
        # Look for silence_end which indicates voice start
        for line in stderr_output.split('\n'):
            if 'silence_end:' in line:
                # Extract time: [silencedetect @ ...] silence_end: 5.123 | silence_duration: 5.123
                parts = line.split('silence_end:')
                if len(parts) > 1:
                    try:
                        time_part = parts[1].split('|')[0].strip()
                        voice_start_time = float(time_part)
                        logger.info(f"Detected voice start at: {voice_start_time}s")
                        break
                    except (ValueError, IndexError):
                        continue
        
        # If no silence_end found, assume voice starts at beginning
        if voice_start_time is None:
            logger.info("No initial silence detected, voice starts at 0s")
            voice_start_time = 0.0
        
        return voice_start_time
        
    except Exception as e:
        logger.error(f"Error detecting voice start time: {str(e)}")
        return None

def correct_transcription_timestamps(transcription_result: Dict, audio_path: str) -> Dict:
    """
    Correct Whisper timestamps by adding the actual voice start offset.
    
    This fixes the issue where Whisper reports voice starting at 0 seconds
    when it actually starts later in the audio due to initial silence.
    
    Args:
        transcription_result: Original Whisper transcription result
        audio_path: Path to the audio file that was transcribed
        
    Returns:
        Dict: Corrected transcription result with adjusted timestamps
    """
    try:
        logger.info("Applying timestamp correction for Whisper transcription")
        
        # Detect actual voice start time
        voice_start_time = detect_voice_start_time(audio_path)
        
        if voice_start_time is None:
            logger.warning("Could not detect voice start time, returning original timestamps")
            transcription_result['timing_correction'] = {
                'applied': False,
                'error': 'Voice start detection failed',
                'correction_timestamp': datetime.now().isoformat()
            }
            return transcription_result
        
        if voice_start_time <= 0.1:  # Less than 100ms offset
            logger.info(f"Voice start time is {voice_start_time}s, no correction needed")
            transcription_result['timing_correction'] = {
                'applied': False,
                'reason': 'No significant silence detected',
                'voice_start_offset': voice_start_time,
                'correction_timestamp': datetime.now().isoformat()
            }
            return transcription_result
        
        logger.info(f"Applying timestamp correction: adding {voice_start_time}s offset")
        
        # Create corrected result
        corrected_result = transcription_result.copy()
        corrected_result['timing_correction'] = {
            'applied': True,
            'voice_start_offset': voice_start_time,
            'correction_timestamp': datetime.now().isoformat()
        }
        
        # Correct segment timestamps
        if 'segments' in corrected_result:
            corrected_segments = []
            for segment in corrected_result['segments']:
                corrected_segment = segment.copy()
                
                # Adjust segment start and end times
                if 'start' in corrected_segment:
                    corrected_segment['start'] += voice_start_time
                if 'end' in corrected_segment:
                    corrected_segment['end'] += voice_start_time
                
                # Adjust word timestamps if present
                if 'words' in corrected_segment:
                    corrected_words = []
                    for word in corrected_segment['words']:
                        corrected_word = word.copy()
                        if 'start' in corrected_word:
                            corrected_word['start'] += voice_start_time
                        if 'end' in corrected_word:
                            corrected_word['end'] += voice_start_time
                        corrected_words.append(corrected_word)
                    corrected_segment['words'] = corrected_words
                
                corrected_segments.append(corrected_segment)
            
            corrected_result['segments'] = corrected_segments
        
        logger.info(f"Timestamp correction applied successfully: +{voice_start_time}s offset")
        return corrected_result
        
    except Exception as e:
        logger.error(f"Error correcting timestamps: {str(e)}")
        # Return original result with error info if correction fails
        transcription_result['timing_correction'] = {
            'applied': False,
            'error': str(e),
            'correction_timestamp': datetime.now().isoformat()
        }
        return transcription_result

def get_preset_config(preset_name: str) -> Dict[str, Any]:
    """
    Get preset configuration for transcription settings.
    
    Args:
        preset_name: Name of the preset ('fast', 'balanced', 'high', 'premium')
        
    Returns:
        Dictionary containing preset configuration parameters
    """
    presets = {
        'fast': {
            'model_name': 'tiny',
            'temperature': 0,
            'initial_prompt': 'This is a guitar lesson with music instruction.',
            'word_timestamps': True
        },
        'balanced': {
            'model_name': 'small',
            'temperature': 0,
            'initial_prompt': 'This is a guitar lesson with music instruction. The instructor discusses guitar techniques, chords, scales, and musical concepts.',
            'word_timestamps': True
        },
        'high': {
            'model_name': 'medium',
            'temperature': 0.2,
            'initial_prompt': 'This is a detailed guitar lesson with comprehensive music instruction. The instructor covers guitar techniques, music theory, chord progressions, scales, fingerpicking patterns, strumming techniques, and musical terminology. Listen carefully for technical terms, note names, chord names, and specific musical instructions.',
            'word_timestamps': True
        },
        'premium': {
            'model_name': 'large-v3',
            'temperature': 0.3,
            'initial_prompt': 'This is a comprehensive guitar lesson with advanced music instruction and education content. The instructor provides detailed explanations of guitar techniques, advanced music theory concepts, chord progressions, scale patterns, fingerpicking and strumming techniques, musical terminology, and educational guidance. Pay special attention to technical musical terms, note names, chord names, scale degrees, time signatures, key signatures, musical intervals, and specific instructional language. The content may include references to musical styles, artists, songs, and educational methodologies.',
            'word_timestamps': True
        }
    }
    
    # Return the requested preset or default to 'balanced'
    return presets.get(preset_name, presets['balanced'])

def render_template_prompt(preset_name: str, course_id: int = None, segment_id: int = None) -> str:
    """
    Get rendered prompt for a preset using Laravel's template rendering service.
    
    Args:
        preset_name: Name of the preset
        course_id: Optional course ID for context
        segment_id: Optional segment ID for context
        
    Returns:
        Rendered prompt string
    """
    try:
        # Make request to Laravel's template rendering API
        url = f"{LARAVEL_API_URL}/transcription-presets/{preset_name}/render"
        payload = {}
        
        if course_id:
            payload['course_id'] = course_id
        if segment_id:
            payload['segment_id'] = segment_id
            
        response = requests.post(url, json=payload, timeout=10)
        
        if response.status_code == 200:
            data = response.json()
            if data.get('success') and data.get('rendered_prompt'):
                logger.info(f"Template rendered for preset '{preset_name}': {data['rendered_prompt']}")
                return data['rendered_prompt']
        
        logger.warning(f"Failed to render template for preset '{preset_name}', falling back to static prompt")
        
    except Exception as e:
        logger.error(f"Error rendering template prompt: {e}")
    
    # Fallback to static preset configuration
    preset_config = get_preset_config(preset_name)
    return preset_config.get('initial_prompt', '')

def process_audio(audio_path, model_name="base", initial_prompt=None, preset_config=None, course_id=None, segment_id=None, preset_name=None):
    """
    Process audio with Whisper and extract detailed information.
    
    Args:
        audio_path: Path to the audio file
        model_name: Whisper model name (used for backward compatibility)
        initial_prompt: Initial prompt for transcription (used for backward compatibility)
        preset_config: Dictionary containing preset configuration parameters
        course_id: Optional course ID for template rendering
        segment_id: Optional segment ID for template rendering
        preset_name: Optional preset name for template rendering
        
    Returns:
        Dictionary containing transcription results and metadata
    """
    # Use preset config if provided, otherwise use legacy parameters
    if preset_config:
        effective_model_name = preset_config['model_name']
        effective_temperature = preset_config['temperature']
        effective_word_timestamps = preset_config['word_timestamps']
        
        # Try to render template prompt if we have the necessary information
        if preset_name:
            effective_initial_prompt = render_template_prompt(preset_name, course_id, segment_id)
        else:
            effective_initial_prompt = preset_config['initial_prompt']
            
        logger.info(f"Processing audio with preset configuration: {audio_path} using prompt: {effective_initial_prompt[:100]}...")
    else:
        # Backward compatibility: use provided parameters or defaults
        effective_model_name = model_name
        effective_initial_prompt = initial_prompt
        effective_temperature = 0
        effective_word_timestamps = True
        logger.info(f"Processing audio with legacy parameters: {audio_path} with model: {model_name}")
    
    model = load_whisper_model(effective_model_name)
    
    # Configure transcription settings
    settings = {
        "model_name": effective_model_name,
        "initial_prompt": effective_initial_prompt,
        "temperature": effective_temperature,
        "word_timestamps": effective_word_timestamps,
        "condition_on_previous_text": False,
        "language": "en",
    }
    
    # Perform transcription
    result = model.transcribe(
        str(audio_path),
        initial_prompt=effective_initial_prompt,
        language=settings["language"],
        temperature=effective_temperature,
        word_timestamps=effective_word_timestamps,
        condition_on_previous_text=settings["condition_on_previous_text"]
    )
    
    # Include settings in result
    result["settings"] = settings
    
    # Calculate confidence score
    confidence_score = calculate_confidence(result["segments"])
    result["confidence_score"] = confidence_score
    
    # Apply timestamp correction to fix Whisper's timing offset issue
    result = correct_transcription_timestamps(result, audio_path)
    
    logger.info(f"Transcription completed with confidence score: {confidence_score:.2f}")
    return result

def save_transcript_to_file(transcript, file_path):
    """Save transcript to a text file."""
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(transcript)
    logger.info(f"Transcript saved to: {file_path}")

def format_timestamp(seconds):
    """Format time in seconds to SRT timestamp format."""
    hours = int(seconds // 3600)
    minutes = int((seconds % 3600) // 60)
    secs = int(seconds % 60)
    msecs = int((seconds % 1) * 1000)
    return f"{hours:02d}:{minutes:02d}:{secs:02d},{msecs:03d}"

def save_srt(segments, output_path):
    """Save transcription segments in SRT format."""
    with open(output_path, 'w', encoding='utf-8') as f:
        for i, segment in enumerate(segments, start=1):
            start = format_timestamp(segment['start'])
            end = format_timestamp(segment['end'])
            text = segment['text']
            f.write(f"{i}\n{start} --> {end}\n{text}\n\n")
    logger.info(f"SRT file saved to: {output_path}")

def update_job_status(job_id, status, response_data=None, error_message=None):
    """Update the job status in Laravel."""
    try:
        url = f"{LARAVEL_API_URL}/transcription/{job_id}/status"
        logger.info(f"Sending status update to Laravel: {url}")
        
        payload = {
            'status': status,
            'response_data': response_data,
            'error_message': error_message,
            'completed_at': datetime.now().isoformat() if status in ['completed', 'failed'] else None
        }
        
        response = requests.post(url, json=payload)
        
        if response.status_code != 200:
            logger.error(f"Failed to update job status in Laravel: {response.text}")
        else:
            logger.info(f"Successfully updated job status in Laravel for job {job_id}")
            
    except Exception as e:
        logger.error(f"Error updating job status in Laravel: {str(e)}")

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint."""
    return jsonify({
        'status': 'healthy',
        'service': 'transcription-service',
        'timestamp': datetime.now().isoformat()
    })

@app.route('/connectivity-test', methods=['GET'])
def connectivity_test():
    """Test connectivity to other services."""
    results = {
        'laravel_api': test_laravel_connectivity(),
        'shared_directories': {
            'jobs_dir': os.path.exists(S3_JOBS_DIR) and os.access(S3_JOBS_DIR, os.W_OK),
        }
    }
    
    return jsonify({
        'success': all(results.values()) if isinstance(results, dict) else False,
        'results': results,
        'timestamp': datetime.now().isoformat()
    })

def test_laravel_connectivity():
    """Test connectivity to Laravel API."""
    try:
        response = requests.get(f"{LARAVEL_API_URL}/hello")
        return response.status_code == 200
    except Exception as e:
        logger.error(f"Error connecting to Laravel API: {str(e)}")
        return False

@app.route('/process', methods=['POST'])
def process_transcription():
    """
    Process a transcription job with optional preset configuration.
    
    Expected JSON payload:
    {
        "job_id": "required_job_id",
        "audio_path": "optional_audio_path",  # Laravel Storage path like "truefire-courses/1/7959.wav"
        "preset": "optional_preset_name",  # 'fast', 'balanced', 'high', 'premium'
        "model_name": "optional_legacy_model",  # for backward compatibility
        "initial_prompt": "optional_legacy_prompt"  # for backward compatibility
    }
    """
    data = request.json
    
    if not data or 'job_id' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. job_id is required.'
        }), 400
    
    job_id = data['job_id']
    audio_path_param = data.get('audio_path')
    preset_name = data.get('preset', None)
    
    # Legacy parameters for backward compatibility
    model_name = data.get('model_name', 'base')
    initial_prompt = data.get('initial_prompt', None)
    
    # Validate preset if provided
    if preset_name and preset_name not in ['fast', 'balanced', 'high', 'premium']:
        return jsonify({
            'success': False,
            'message': f'Invalid preset "{preset_name}". Valid presets are: fast, balanced, high, premium.'
        }), 400
    
    # Log the job details
    if preset_name:
        logger.info(f"Received transcription job: {job_id} with preset: {preset_name}")
    else:
        logger.info(f"Received transcription job: {job_id} (legacy mode)")
    
    # Handle path construction - use segment-based storage if audio_path provided
    if audio_path_param:
        # Use segment-based storage pattern (matching audio-extraction service)
        # audio_path_param should be something like "truefire-courses/1/7959.wav"
        full_audio_path = os.path.join(D_DRIVE_BASE, audio_path_param)
        
        # Extract directory and filename for output files
        audio_dir = os.path.dirname(full_audio_path)
        audio_filename = os.path.basename(full_audio_path)
        
        # Extract segment ID from filename (e.g., "7959.wav" -> "7959")
        segment_id = os.path.splitext(audio_filename)[0]
        
        # Define output file paths in the same directory as audio file
        transcript_path = os.path.join(audio_dir, f'{segment_id}_transcript.txt')
        srt_path = os.path.join(audio_dir, f'{segment_id}_transcript.srt')
        json_path = os.path.join(audio_dir, f'{segment_id}_transcript.json')
        
        audio_path = full_audio_path
        logger.info(f"Using segment-based storage - Audio: {audio_path}, Segment ID: {segment_id}")
        
    else:
        # Fallback to legacy job-based storage
        logger.warning("No audio_path provided, falling back to legacy S3 structure")
        job_dir = os.path.join(S3_JOBS_DIR, job_id)
        
        # Define standard file paths
        audio_path = os.path.join(job_dir, 'audio.wav')
        transcript_path = os.path.join(job_dir, 'transcript.txt')
        srt_path = os.path.join(job_dir, 'transcript.srt')
        json_path = os.path.join(job_dir, 'transcript.json')
    
    try:
        # Check if audio file exists
        if not os.path.exists(audio_path):
            error_msg = f"Audio file not found at standard path: {audio_path}"
            logger.error(error_msg)
            update_job_status(job_id, 'failed', None, error_msg)
            return jsonify({
                'success': False,
                'message': error_msg
            }), 404
        
        # Update status to transcribing - now Laravel will handle this
        # but we'll still send an update to confirm we've started
        update_job_status(job_id, 'transcribing')
        
        # Get preset configuration if preset is specified
        preset_config = None
        if preset_name:
            preset_config = get_preset_config(preset_name)
            logger.info(f"Using preset '{preset_name}' with model: {preset_config['model_name']}")
        
        # Ensure output directory exists (important for segment-based storage)
        output_dir = os.path.dirname(transcript_path)
        if not os.path.exists(output_dir):
            logger.info(f"Creating output directory: {output_dir}")
            os.makedirs(output_dir, exist_ok=True)
        
        # Extract course_id and segment_id from data for template rendering
        course_id = data.get('course_id')
        segment_id = data.get('segment_id')
        
        # Process the audio with Whisper (using preset config or legacy parameters)
        transcription_result = process_audio(
            audio_path, 
            model_name, 
            initial_prompt, 
            preset_config,
            course_id=course_id,
            segment_id=segment_id,
            preset_name=preset_name
        )
        
        # Save the transcript to files
        save_transcript_to_file(transcription_result['text'], transcript_path)
        save_srt(transcription_result['segments'], srt_path)
        with open(json_path, 'w', encoding='utf-8') as f:
            json.dump(transcription_result, f, indent=2, ensure_ascii=False)
        
        # Determine effective model name for metadata
        effective_model_name = preset_config['model_name'] if preset_config else model_name
        
        # Prepare response data
        response_data = {
            'message': 'Transcription completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'transcript_path': transcript_path,
            'transcript_text': transcription_result['text'],
            'confidence_score': transcription_result.get('confidence_score', 0.0),
            'metadata': {
                'service': 'transcription-service',
                'processed_by': 'Whisper-based transcription',
                'model': effective_model_name,
                'preset': preset_name if preset_name else None,
                'settings': transcription_result.get('settings', {})
            }
        }
        
        # Update job status in Laravel
        update_job_status(job_id, 'completed', response_data)
        
        return jsonify({
            'success': True,
            'job_id': job_id,
            'message': 'Transcription processed successfully',
            'data': response_data
        })
        
    except Exception as e:
        logger.error(f"Error processing job {job_id}: {str(e)}")
        
        # Update job status in Laravel
        update_job_status(job_id, 'failed', None, str(e))
        
        return jsonify({
            'success': False,
            'job_id': job_id,
            'message': f'Transcription failed: {str(e)}'
        }), 500

@app.route('/transcribe', methods=['POST'])
def transcribe_audio():
    """
    Handle transcription test requests from TranscriptionTestJob.
    This endpoint is specifically for testing transcription with direct audio file paths.
    
    Expected JSON payload:
    {
        "job_id": "required_job_id",
        "audio_path": "path_to_audio_file",
        "preset": "optional_preset_name",
        "test_mode": true,
        "transcription_settings": {},
        "segment_id": "optional_segment_id",
        "course_id": "optional_course_id"
    }
    """
    data = request.json
    
    if not data or 'job_id' not in data or 'audio_path' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. job_id and audio_path are required.'
        }), 400
    
    job_id = data['job_id']
    audio_path = data['audio_path']
    preset_name = data.get('preset', 'balanced')
    test_mode = data.get('test_mode', False)
    segment_id = data.get('segment_id')
    course_id = data.get('course_id')
    
    # Validate preset
    if preset_name not in ['fast', 'balanced', 'high', 'premium']:
        return jsonify({
            'success': False,
            'message': f'Invalid preset "{preset_name}". Valid presets are: fast, balanced, high, premium.'
        }), 400
    
    logger.info(f"Received transcription test request: {job_id} for audio: {audio_path} with preset: {preset_name}")
    
    try:
        # For transcription tests, the audio path is provided directly from Laravel
        # Laravel provides the full system path via Storage::disk('d_drive')->path()
        full_audio_path = audio_path
        
        # Check if audio file exists
        if not os.path.exists(full_audio_path):
            error_msg = f"Audio file not found: {full_audio_path}"
            logger.error(error_msg)
            return jsonify({
                'success': False,
                'message': error_msg,
                'error': 'file_not_found'
            }), 404
        
        # Get preset configuration
        preset_config = get_preset_config(preset_name)
        logger.info(f"Using preset '{preset_name}' with model: {preset_config['model_name']}")
        
        # Process the audio with Whisper using preset configuration
        transcription_result = process_audio(
            full_audio_path, 
            preset_config=preset_config,
            course_id=course_id,
            segment_id=segment_id,
            preset_name=preset_name
        )
        
        # Handle output file creation - always use segment-based storage
        # Extract segment ID from audio path if not provided
        if not segment_id:
            # Extract segment ID from filename (e.g., "/mnt/d_drive/truefire-courses/1/7959.wav" -> "7959")
            audio_filename = os.path.basename(full_audio_path)
            segment_id = os.path.splitext(audio_filename)[0]
            logger.info(f"Extracted segment ID from audio path: {segment_id}")
        
        # Always save to segment-based directory (same directory as audio file)
        audio_dir = os.path.dirname(full_audio_path)
        
        # Define output file paths in the same directory as audio file
        transcript_path = os.path.join(audio_dir, f'{segment_id}_transcript.txt')
        srt_path = os.path.join(audio_dir, f'{segment_id}_transcript.srt')
        json_path = os.path.join(audio_dir, f'{segment_id}_transcript.json')
        
        # Ensure output directory exists
        if not os.path.exists(audio_dir):
            logger.info(f"Creating output directory: {audio_dir}")
            os.makedirs(audio_dir, exist_ok=True)
        
        save_transcript_to_file(transcription_result['text'], transcript_path)
        save_srt(transcription_result['segments'], srt_path)
        with open(json_path, 'w', encoding='utf-8') as f:
            json.dump(transcription_result, f, indent=2, ensure_ascii=False)
        
        
        # Prepare response data
        response_data = {
            'success': True,
            'message': 'Transcription completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'transcript_text': transcription_result['text'],
            'confidence_score': transcription_result.get('confidence_score', 0.0),
            'segments': transcription_result.get('segments', []),
            'metadata': {
                'service': 'transcription-service',
                'processed_by': 'Whisper-based transcription',
                'model': preset_config['model_name'],
                'preset': preset_name,
                'test_mode': test_mode,
                'segment_id': segment_id,
                'course_id': course_id,
                'audio_path': full_audio_path,
                'settings': transcription_result.get('settings', {})
            }
        }
        
        logger.info(f"Transcription test completed successfully for job: {job_id}")
        
        return jsonify(response_data)
        
    except Exception as e:
        logger.error(f"Error processing transcription test {job_id}: {str(e)}")
        
        return jsonify({
            'success': False,
            'job_id': job_id,
            'message': f'Transcription test failed: {str(e)}',
            'error': str(e)
        }), 500

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)