#!/usr/bin/env python3
from flask import Flask, request, jsonify
import os
import requests
import logging
import subprocess
from datetime import datetime
import tempfile
import boto3 # Added for S3 interaction

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://aws-transcription-laravel.local:80/api') # Ensure this matches Laravel's service discovery name
AWS_BUCKET = os.environ.get('AWS_BUCKET')
AWS_DEFAULT_REGION = os.environ.get('AWS_DEFAULT_REGION', 'us-east-1')

# Initialize S3 client only if AWS_BUCKET is set
s3_client = None
if AWS_BUCKET:
    s3_client = boto3.client('s3', region_name=AWS_DEFAULT_REGION)
else:
    logger.error("AWS_BUCKET environment variable not set. S3 operations will fail.")

def convert_to_wav(input_path, output_path):
    """Convert media to WAV format optimized for transcription."""
    try:
        logger.info(f"Converting media to WAV: {input_path} -> {output_path}")
        command = [
            "ffmpeg", "-y", "-i", str(input_path),
            "-vn", "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1", str(output_path)
        ]
        logger.debug(f"FFmpeg command: {' '.join(command)}")
        result = subprocess.run(command, capture_output=True, text=True, check=True)
        logger.info(f"Successfully converted to WAV: {output_path}")
        return True
    except subprocess.CalledProcessError as e:
        logger.error(f"FFmpeg error during conversion: {e.stderr}")
        raise RuntimeError(f"FFmpeg error: {e.stderr}") from e
    except Exception as e:
        logger.error(f"Conversion failed: {str(e)}")
        raise

def get_audio_duration(audio_path):
    """Get audio duration using ffprobe."""
    try:
        command = [
            "ffprobe", "-v", "error", "-show_entries", "format=duration", 
            "-of", "default=noprint_wrappers=1:nokey=1", audio_path
        ]
        result = subprocess.run(command, capture_output=True, text=True, check=True)
        duration = float(result.stdout.strip())
        return duration
    except subprocess.CalledProcessError as e:
        logger.warning(f"Failed to get duration with ffprobe: {e.stderr}")
        return None
    except Exception as e:
        logger.warning(f"Error getting duration: {str(e)}")
        return None

def update_job_status(job_id, status, response_data=None, error_message=None):
    """Update the job status in Laravel."""
    if not LARAVEL_API_URL:
        logger.error("LARAVEL_API_URL not set, cannot update job status.")
        return
    try:
        url = f"{LARAVEL_API_URL}/transcription/{job_id}/status"
        logger.info(f"Sending status update to Laravel: {url} with status: {status}")
        
        payload = {
            'status': status,
            'response_data': response_data,
            'error_message': error_message,
            'completed_at': datetime.now().isoformat() if status in ['completed', 'failed', 'audio_extracted'] else None
        }
        
        headers = {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        }
        
        response = requests.post(url, json=payload, headers=headers, timeout=10) # Added headers
        response.raise_for_status() # Raise an exception for HTTP errors (4xx or 5xx)
        logger.info(f"Successfully updated job status in Laravel for job {job_id} to {status}")
            
    except requests.exceptions.HTTPError as http_err:
        logger.error(f"HTTP error updating job status in Laravel: {http_err} - Response: {http_err.response.text}")
    except requests.exceptions.RequestException as req_err:
        logger.error(f"Request exception updating job status in Laravel: {req_err}")
    except Exception as e:
        logger.error(f"Generic error updating job status in Laravel: {str(e)}", exc_info=True)

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint."""
    return jsonify({
        'status': 'healthy',
        'service': 'audio-extraction-service',
        'timestamp': datetime.now().isoformat(),
        'laravel_api_url_set': bool(LARAVEL_API_URL),
        'aws_bucket_set': bool(AWS_BUCKET)
    })

@app.route('/process', methods=['POST'])
def process_audio_extraction():
    """Extract audio from a video file from S3, process, and upload audio to S3."""
    data = request.json
    if not data or 'job_id' not in data or 'video_path' not in data:
        logger.error(f"Invalid request data: {data}")
        return jsonify({'success': False, 'message': 'Invalid request: job_id and video_path required.'}), 400
    
    job_id = data['job_id']
    s3_video_key = data['video_path'] # This is the S3 key from Laravel
    
    logger.info(f"Processing job {job_id} for S3 video key: {s3_video_key}")

    if not s3_client or not AWS_BUCKET:
        logger.error("S3 client or AWS_BUCKET not configured properly.")
        update_job_status(job_id, 'failed', None, "Audio service S3 configuration error.")
        return jsonify({'success': False, 'message': 'Audio service S3 configuration error.'}), 500

    with tempfile.TemporaryDirectory() as tmpdir:
        local_video_path = os.path.join(tmpdir, os.path.basename(s3_video_key) or "downloaded_video")
        local_audio_path = os.path.join(tmpdir, "audio.wav")
        s3_audio_key = os.path.join(os.path.dirname(s3_video_key), "audio.wav") # e.g., s3/jobs/UUID/audio.wav

        try:
            logger.info(f"Downloading video from S3: bucket={AWS_BUCKET}, key={s3_video_key} to {local_video_path}")
            s3_client.download_file(AWS_BUCKET, s3_video_key, local_video_path)
            logger.info(f"Successfully downloaded {s3_video_key} to {local_video_path}")

            update_job_status(job_id, 'extracting_audio')
            
            convert_to_wav(local_video_path, local_audio_path)
            
            audio_size = os.path.getsize(local_audio_path)
            duration = get_audio_duration(local_audio_path)

            logger.info(f"Uploading extracted audio to S3: bucket={AWS_BUCKET}, key={s3_audio_key} from {local_audio_path}")
            s3_client.upload_file(local_audio_path, AWS_BUCKET, s3_audio_key)
            logger.info(f"Successfully uploaded {local_audio_path} to S3 key {s3_audio_key}")
            
            response_data = {
                'message': 'Audio extraction completed successfully and uploaded to S3.',
                'service_timestamp': datetime.now().isoformat(),
                'audio_path': s3_audio_key, # Send S3 key back
                'audio_size_bytes': audio_size,
                'duration_seconds': duration,
                'metadata': {
                    'service': 'audio-extraction-service',
                    'processed_by': 'FFmpeg audio extraction',
                    'format': 'WAV', 'sample_rate': '16000 Hz', 'channels': '1 (Mono)', 'codec': 'PCM 16-bit'
                }
            }
            
            # Update job status in Laravel - status 'audio_extracted' signals completion of this step
            update_job_status(job_id, 'audio_extracted', response_data)
            
            return jsonify({'success': True, 'job_id': job_id, 'data': response_data})
            
        except Exception as e:
            logger.error(f"Error processing job {job_id}: {str(e)}", exc_info=True)
            update_job_status(job_id, 'failed', None, f"Audio extraction failed: {str(e)}")
            return jsonify({'success': False, 'job_id': job_id, 'message': f'Audio extraction failed: {str(e)}'}), 500

# Removed connectivity_test and test_laravel_connectivity as they are less relevant for S3 flow
# and depend on local filesystem assumptions or direct Laravel access which might not be configured in ECS.

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=os.environ.get('FLASK_ENV') == 'development') 
