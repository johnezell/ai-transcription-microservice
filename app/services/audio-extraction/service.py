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

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Set environment constants for cleaner path handling
S3_BASE_DIR = '/var/www/storage/app/public/s3'
S3_JOBS_DIR = os.path.join(S3_BASE_DIR, 'jobs')

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://laravel/api')

# Ensure base directory exists
os.makedirs(S3_JOBS_DIR, exist_ok=True)

def convert_to_wav(input_path, output_path):
    """Convert media to WAV format optimized for transcription."""
    try:
        logger.info(f"Converting media to WAV: {input_path} -> {output_path}")
        
        command = [
            "ffmpeg", "-y",  # Overwrite output
            "-i", str(input_path),
            "-vn",  # Disable video
            "-acodec", "pcm_s16le",  # Force pcm format
            "-ar", "16000",  # Sample rate
            "-ac", "1",  # Mono
            str(output_path)
        ]
        logger.debug(f"FFmpeg command: {' '.join(command)}")
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg error: {result.stderr}")
            
        logger.info(f"Successfully converted to WAV: {output_path}")
        return True
        
    except Exception as e:
        logger.error(f"Conversion failed: {str(e)}")
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
