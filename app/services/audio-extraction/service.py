#!/usr/bin/env python3
from flask import Flask, request, jsonify
import os
import requests
import json
import logging
import subprocess
from datetime import datetime
import tempfile
import uuid
from typing import Dict, List, Optional, Union

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Audio Processing Configuration with Environment Variable Support
AUDIO_PROCESSING_CONFIG = {
    "default_quality": os.environ.get('AUDIO_QUALITY_LEVEL', 'balanced'),
    "enable_normalization": os.environ.get('ENABLE_NORMALIZATION', 'true').lower() == 'true',
    "enable_vad": os.environ.get('ENABLE_VAD', 'false').lower() == 'true',
    "max_threads": int(os.environ.get('FFMPEG_THREADS', '4'))
}

# Create Flask app
app = Flask(__name__)

# Set environment constants for cleaner path handling
S3_BASE_DIR = '/var/www/storage/app/public/s3'
S3_JOBS_DIR = os.path.join(S3_BASE_DIR, 'jobs')

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://laravel/api')

# Ensure base directory exists
os.makedirs(S3_JOBS_DIR, exist_ok=True)

def validate_audio_input(input_path):
    """Validate audio input using ffprobe."""
    try:
        logger.info(f"Validating audio input: {input_path}")
        
        command = [
            "ffprobe", "-v", "error",
            "-select_streams", "a:0",
            "-show_entries", "stream=codec_name,sample_rate,channels,duration",
            "-of", "json",
            str(input_path)
        ]
        
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            raise RuntimeError(f"FFprobe validation error: {result.stderr}")
        
        probe_data = json.loads(result.stdout)
        
        if not probe_data.get('streams'):
            raise RuntimeError("No audio streams found in input file")
        
        stream = probe_data['streams'][0]
        validation_info = {
            'codec': stream.get('codec_name', 'unknown'),
            'sample_rate': int(stream.get('sample_rate', 0)),
            'channels': int(stream.get('channels', 0)),
            'duration': float(stream.get('duration', 0))
        }
        
        logger.info(f"Audio validation successful: {validation_info}")
        return validation_info
        
    except Exception as e:
        logger.error(f"Audio validation failed: {str(e)}")
        raise

def assess_audio_quality(audio_path):
    """Assess audio quality metrics using ffprobe."""
    try:
        logger.info(f"Assessing audio quality: {audio_path}")
        
        command = [
            "ffprobe", "-v", "error",
            "-select_streams", "a:0",
            "-show_entries", "stream=bit_rate,sample_rate,channels",
            "-show_entries", "format=bit_rate,duration",
            "-of", "json",
            str(audio_path)
        ]
        
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            logger.warning(f"Quality assessment failed: {result.stderr}")
            return None
        
        probe_data = json.loads(result.stdout)
        stream = probe_data.get('streams', [{}])[0]
        format_info = probe_data.get('format', {})
        
        quality_metrics = {
            'bit_rate': int(stream.get('bit_rate', 0)) or int(format_info.get('bit_rate', 0)),
            'sample_rate': int(stream.get('sample_rate', 0)),
            'channels': int(stream.get('channels', 0)),
            'duration': float(format_info.get('duration', 0))
        }
        
        logger.info(f"Audio quality assessment: {quality_metrics}")
        return quality_metrics
        
    except Exception as e:
        logger.warning(f"Quality assessment error: {str(e)}")
        return None

def apply_vad_preprocessing(input_path: str, output_path: str) -> bool:
    """
    Apply Voice Activity Detection preprocessing with advanced silence removal.
    
    Args:
        input_path: Path to input audio file
        output_path: Path to output audio file
        
    Returns:
        bool: True if successful, False otherwise
        
    Raises:
        RuntimeError: If VAD preprocessing fails
    """
    try:
        logger.info(f"Applying VAD preprocessing: {input_path} -> {output_path}")
        
        # Advanced silence removal with bidirectional processing
        command = [
            "ffmpeg", "-y", "-i", str(input_path), "-vn",
            "-af", "silenceremove=start_periods=1:start_duration=1:start_threshold=-60dB:detection=peak,aformat=dblp,areverse,silenceremove=start_periods=1:start_duration=1:start_threshold=-60dB:detection=peak,aformat=dblp,areverse",
            "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
            str(output_path)
        ]
        
        logger.info(f"VAD FFmpeg command: {' '.join(command)}")
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            raise RuntimeError(f"VAD preprocessing failed: {result.stderr}")
        
        # Verify output file exists and has content
        if not os.path.exists(output_path) or os.path.getsize(output_path) == 0:
            raise RuntimeError("VAD preprocessing produced empty or missing output file")
        
        logger.info(f"VAD preprocessing completed successfully: {output_path}")
        return True
        
    except Exception as e:
        logger.error(f"VAD preprocessing failed: {str(e)}")
        raise

def calculate_processing_metrics() -> Dict[str, float]:
    """
    Calculate processing performance metrics.
    
    Returns:
        Dict containing processing metrics
    """
    try:
        # TODO: Implement actual metrics calculation based on processing history
        # For now, return placeholder values that can be extended
        metrics = {
            'avg_processing_time': 0.0,  # Average processing time in seconds
            'quality_score': 0.0,        # Quality assessment score (0-100)
            'error_rate': 0.0             # Error rate percentage (0-100)
        }
        
        logger.info(f"Processing metrics calculated: {metrics}")
        return metrics
        
    except Exception as e:
        logger.error(f"Error calculating processing metrics: {str(e)}")
        return {
            'avg_processing_time': 0.0,
            'quality_score': 0.0,
            'error_rate': 0.0
        }

def preprocess_for_whisper(input_path: str, output_path: str, quality_level: str = "balanced") -> bool:
    """
    Enhanced audio preprocessing for Whisper transcription with configurable quality levels.
    
    Args:
        input_path: Path to input audio file
        output_path: Path to output WAV file
        quality_level: Quality level ('fast', 'balanced', 'high')
        
    Returns:
        bool: True if successful, False otherwise
        
    Raises:
        RuntimeError: If preprocessing fails
    """
    try:
        logger.info(f"Preprocessing audio for Whisper (quality: {quality_level}): {input_path} -> {output_path}")
        
        # Validate input first
        validation_info = validate_audio_input(input_path)
        logger.info(f"Input validation passed: {validation_info}")
        
        # Define quality-specific filter chains and thread counts
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
                "filters": ["highpass=f=80", "lowpass=f=8000", "dynaudnorm=p=0.9:s=5:r=0.9", "afftdn=nf=-25"],
                "threads": 6
            }
        }
        
        # Get configuration for the specified quality level
        if quality_level not in quality_configs:
            logger.warning(f"Unknown quality level '{quality_level}', falling back to 'balanced'")
            quality_level = "balanced"
            
        config = quality_configs[quality_level]
        filter_chain = ",".join(config["filters"])
        thread_count = min(config["threads"], AUDIO_PROCESSING_CONFIG["max_threads"])
        
        # Build FFmpeg command with quality-specific settings
        command = [
            "ffmpeg", "-y", "-threads", str(thread_count),
            "-i", str(input_path), "-vn",
            "-af", filter_chain,
            "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
            "-sample_fmt", "s16", str(output_path)
        ]
        
        logger.info(f"FFmpeg command (quality: {quality_level}, threads: {thread_count}): {' '.join(command)}")
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg preprocessing failed: {result.stderr}")
        
        # Assess output quality
        quality_metrics = assess_audio_quality(output_path)
        if quality_metrics:
            logger.info(f"Output quality metrics: {quality_metrics}")
            
        logger.info(f"Successfully preprocessed audio for Whisper (quality: {quality_level}): {output_path}")
        return True
        
    except Exception as e:
        logger.error(f"Audio preprocessing failed: {str(e)}")
        raise

def convert_to_wav_original(input_path, output_path):
    """Original convert media to WAV format (preserved for rollback)."""
    try:
        logger.info(f"Converting media to WAV (original): {input_path} -> {output_path}")
        
        command = [
            "ffmpeg", "-y",  # Overwrite output
            "-i", str(input_path),
            "-vn",  # Disable video
            "-acodec", "pcm_s16le",  # Force pcm format
            "-ar", "16000",  # Sample rate
            "-ac", "1",  # Mono
            str(output_path)
        ]
        logger.debug(f"FFmpeg command (original): {' '.join(command)}")
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg error: {result.stderr}")
            
        logger.info(f"Successfully converted to WAV (original): {output_path}")
        return True
        
    except Exception as e:
        logger.error(f"Conversion failed (original): {str(e)}")
        raise

def convert_to_wav(input_path, output_path, quality_level: Optional[str] = None):
    """
    Convert media to WAV format optimized for transcription with configurable quality levels.
    
    Args:
        input_path: Path to input media file
        output_path: Path to output WAV file
        quality_level: Quality level override ('fast', 'balanced', 'high')
    
    Returns:
        bool: True if successful, False otherwise
    """
    try:
        # Use provided quality level or default from configuration
        effective_quality = quality_level or AUDIO_PROCESSING_CONFIG["default_quality"]
        
        logger.info(f"Converting media to WAV (quality: {effective_quality}): {input_path} -> {output_path}")
        
        # Check if normalization is enabled
        if AUDIO_PROCESSING_CONFIG["enable_normalization"]:
            # Use enhanced preprocessing with quality levels
            return preprocess_for_whisper(input_path, output_path, effective_quality)
        else:
            logger.info("Audio normalization disabled, using original conversion method")
            return convert_to_wav_original(input_path, output_path)
        
    except Exception as e:
        logger.error(f"Enhanced conversion failed, attempting fallback: {str(e)}")
        # Fallback to original method if enhanced processing fails
        try:
            return convert_to_wav_original(input_path, output_path)
        except Exception as fallback_error:
            logger.error(f"Fallback conversion also failed: {str(fallback_error)}")
            raise

def get_audio_duration(audio_path):
    """Get audio duration using ffprobe."""
    try:
        command = [
            "ffprobe", 
            "-v", "error", 
            "-show_entries", "format=duration", 
            "-of", "default=noprint_wrappers=1:nokey=1", 
            audio_path
        ]
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            logger.warning(f"Failed to get duration: {result.stderr}")
            return None
            
        duration = float(result.stdout.strip())
        return duration
        
    except Exception as e:
        logger.warning(f"Error getting duration: {str(e)}")
        return None

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
        'service': 'audio-extraction-service',
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
def process_audio_extraction():
    """Extract audio from a video file."""
    data = request.json
    
    if not data or 'job_id' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. job_id is required.'
        }), 400
    
    job_id = data['job_id']
    
    logger.info(f"Processing job {job_id}")
    
    # Create job directory if it doesn't exist
    job_dir = os.path.join(S3_JOBS_DIR, job_id)
    os.makedirs(job_dir, exist_ok=True)
    
    # Standardized paths
    video_path = os.path.join(job_dir, 'video.mp4')
    audio_path = os.path.join(job_dir, 'audio.wav')
    
    # Basic directory structure check for debugging
    try:
        if os.path.exists(job_dir):
            dir_contents = os.listdir(job_dir)
            logger.info(f"Job directory contents: {dir_contents}")
        else:
            logger.error(f"Job directory {job_dir} does not exist")
            logger.info(f"Creating job directory: {job_dir}")
            os.makedirs(job_dir, exist_ok=True)
    except Exception as e:
        logger.error(f"Error with job directory: {str(e)}")
    
    try:
        # Check if the video file exists
        if not os.path.exists(video_path):
            error_msg = f"Video file not found at path: {video_path}"
            logger.error(error_msg)
            
            # Report error to Laravel
            update_job_status(job_id, 'failed', None, error_msg)
            return jsonify({
                'success': False,
                'message': error_msg
            }), 404
            
        # Update status to extracting_audio
        update_job_status(job_id, 'extracting_audio')
        
        # Extract audio using ffmpeg
        convert_to_wav(video_path, audio_path)
        
        # Get file size and other metadata
        audio_size = os.path.getsize(audio_path)
        duration = get_audio_duration(audio_path)
        
        # Prepare response data
        response_data = {
            'message': 'Audio extraction completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'audio_path': audio_path,
            'audio_size_bytes': audio_size,
            'duration_seconds': duration,
            'metadata': {
                'service': 'audio-extraction-service',
                'processed_by': 'FFmpeg audio extraction',
                'format': 'WAV',
                'sample_rate': '16000 Hz',
                'channels': '1 (Mono)',
                'codec': 'PCM 16-bit'
            }
        }
        
        # Update job status in Laravel - Laravel will initiate transcription
        update_job_status(job_id, 'processing', response_data)
        
        return jsonify({
            'success': True,
            'job_id': job_id,
            'message': 'Audio extraction processed successfully',
            'data': response_data
        })
        
    except Exception as e:
        logger.error(f"Error processing job {job_id}: {str(e)}")
        
        # Update job status in Laravel
        update_job_status(job_id, 'failed', None, str(e))
        
        return jsonify({
            'success': False,
            'job_id': job_id,
            'message': f'Audio extraction failed: {str(e)}'
        }), 500

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True) 
