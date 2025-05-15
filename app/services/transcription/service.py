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
import boto3

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
from typing import Dict, List, Union, Optional, Any

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Set environment constants for cleaner path handling
S3_BASE_DIR = '/var/www/storage/app/public/s3'
S3_JOBS_DIR = os.path.join(S3_BASE_DIR, 'jobs')

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://aws-transcription-laravel.local:80/api')
AWS_BUCKET = os.environ.get('AWS_BUCKET')
AWS_DEFAULT_REGION = os.environ.get('AWS_DEFAULT_REGION', 'us-east-1')

# Ensure base directory exists
os.makedirs(S3_JOBS_DIR, exist_ok=True)

s3_client = None
if AWS_BUCKET:
    s3_client = boto3.client('s3', region_name=AWS_DEFAULT_REGION)
else:
    logger.error("AWS_BUCKET environment variable not set. S3 operations will fail.")

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

def process_audio(audio_path, model_name="base", initial_prompt=None):
    """Process audio with Whisper and extract detailed information."""
    logger.info(f"Processing audio: {audio_path} with model: {model_name}")
    
    model = load_whisper_model(model_name)
    
    # Configure transcription settings
    settings = {
        "model_name": model_name,
        "initial_prompt": initial_prompt,
        "temperature": 0,
        "word_timestamps": True,
        "condition_on_previous_text": False,
        "language": "en",
    }
    
    # Perform transcription
    result = model.transcribe(
        str(audio_path),
        initial_prompt=initial_prompt,
        language=settings["language"],
        temperature=settings["temperature"],
        word_timestamps=settings["word_timestamps"],
        condition_on_previous_text=settings["condition_on_previous_text"]
    )
    
    # Include settings in result
    result["settings"] = settings
    
    # Calculate confidence score
    confidence_score = calculate_confidence(result["segments"])
    result["confidence_score"] = confidence_score
    
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
    if not LARAVEL_API_URL:
        logger.error("LARAVEL_API_URL not set, cannot update job status.")
        return
    try:
        url = f"{LARAVEL_API_URL}/transcription/{job_id}/status"
        logger.info(f"Sending status update to Laravel: {url} with status: {status}")
        payload = {
            'status': status, 'response_data': response_data, 'error_message': error_message,
            'completed_at': datetime.now().isoformat() if status in ['completed', 'failed', 'transcribed'] else None
        }
        headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
        response = requests.post(url, json=payload, headers=headers, timeout=10)
        response.raise_for_status()
        logger.info(f"Successfully updated job status in Laravel for job {job_id} to {status}")
    except requests.exceptions.HTTPError as http_err:
        logger.error(f"HTTP error updating status for {job_id}: {http_err} - Response: {http_err.response.text}")
    except requests.exceptions.RequestException as req_err:
        logger.error(f"Request exception updating status for {job_id}: {req_err}")
    except Exception as e:
        logger.error(f"Generic error updating status for {job_id}: {str(e)}", exc_info=True)

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint."""
    return jsonify({
        'status': 'healthy',
        'service': 'transcription-service',
        'timestamp': datetime.now().isoformat(),
        'laravel_api_url_set': bool(LARAVEL_API_URL),
        'aws_bucket_set': bool(AWS_BUCKET)
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
def process_transcription_route():
    """Process a transcription job."""
    data = request.json
    
    if not data or 'job_id' not in data or 'audio_s3_key' not in data:
        logger.error(f"Invalid request. Missing job_id or audio_s3_key: {data}")
        return jsonify({'success': False, 'message': 'Invalid request: job_id and audio_s3_key required.'}), 400
    
    job_id = data['job_id']
    audio_s3_key = data['audio_s3_key']
    model_name = data.get('model_name', 'base')
    initial_prompt = data.get('initial_prompt', None)
    
    logger.info(f"Received transcription job: {job_id} for S3 audio key: {audio_s3_key} with model: {model_name}")
    
    if not s3_client or not AWS_BUCKET:
        logger.error("S3 client or AWS_BUCKET not configured for transcription service.")
        update_job_status(job_id, 'failed', None, "Transcription service S3 configuration error.")
        return jsonify({'success': False, 'message': 'Transcription service S3 configuration error.'}), 500

    s3_job_prefix = os.path.dirname(audio_s3_key) # e.g., s3/jobs/UUID

    with tempfile.TemporaryDirectory() as tmpdir:
        local_audio_path = os.path.join(tmpdir, "downloaded_audio.wav")
        local_transcript_txt_path = os.path.join(tmpdir, "transcript.txt")
        local_transcript_srt_path = os.path.join(tmpdir, "transcript.srt")
        local_transcript_json_path = os.path.join(tmpdir, "transcript.json")

        s3_transcript_txt_key = os.path.join(s3_job_prefix, "transcript.txt")
        s3_transcript_srt_key = os.path.join(s3_job_prefix, "transcript.srt")
        s3_transcript_json_key = os.path.join(s3_job_prefix, "transcript.json")

        try:
            logger.info(f"Downloading audio from S3: bucket={AWS_BUCKET}, key={audio_s3_key} to {local_audio_path}")
            s3_client.download_file(AWS_BUCKET, audio_s3_key, local_audio_path)
            logger.info(f"Successfully downloaded {audio_s3_key} to {local_audio_path}")

            update_job_status(job_id, 'transcribing')
            
            transcription_result = process_audio(local_audio_path, model_name, initial_prompt)
            
            # Save transcripts locally first
            with open(local_transcript_txt_path, 'w', encoding='utf-8') as f:
                f.write(transcription_result['text'])
            logger.info(f"Local transcript text saved to: {local_transcript_txt_path}")
            
            save_srt(transcription_result['segments'], local_transcript_srt_path)
            
            with open(local_transcript_json_path, 'w', encoding='utf-8') as f:
                json.dump(transcription_result, f, indent=2)
            logger.info(f"Local transcript JSON saved to: {local_transcript_json_path}")

            # Upload transcripts to S3
            s3_client.upload_file(local_transcript_txt_path, AWS_BUCKET, s3_transcript_txt_key)
            logger.info(f"Uploaded transcript text to S3 key: {s3_transcript_txt_key}")
            s3_client.upload_file(local_transcript_srt_path, AWS_BUCKET, s3_transcript_srt_key)
            logger.info(f"Uploaded transcript SRT to S3 key: {s3_transcript_srt_key}")
            s3_client.upload_file(
                local_transcript_json_path, 
                AWS_BUCKET, 
                s3_transcript_json_key,
                ExtraArgs={'ContentType': 'application/json'}
            )
            logger.info(f"Uploaded transcript JSON to S3 key: {s3_transcript_json_key} with ContentType application/json")
            
            response_data = {
                'message': 'Transcription completed successfully and files uploaded to S3.',
                'service_timestamp': datetime.now().isoformat(),
                'transcript_path': s3_transcript_txt_key,
                'transcript_srt_path': s3_transcript_srt_key,
                'transcript_json_path': s3_transcript_json_key,
                'transcript_text_excerpt': transcription_result['text'][:255], # Excerpt for logging/DB
                'confidence_score': transcription_result.get('confidence_score', 0.0),
                'language': transcription_result.get('language', 'en'),
                'metadata': {
                    'service': 'transcription-service',
                    'processed_by': 'Whisper-based transcription',
                    'model': model_name
                }
            }
            
            # Update job status in Laravel - status 'transcribed' signals completion of this step
            # Terminology recognition will be the next step triggered by Laravel if applicable.
            update_job_status(job_id, 'transcribed', response_data)
            
            return jsonify({'success': True, 'job_id': job_id, 'data': response_data})
            
        except Exception as e:
            logger.error(f"Error processing transcription for job {job_id}: {str(e)}", exc_info=True)
            update_job_status(job_id, 'failed', None, f"Transcription failed: {str(e)}")
            return jsonify({'success': False, 'job_id': job_id, 'message': f'Transcription failed: {str(e)}'}), 500

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=os.environ.get('FLASK_ENV') == 'development') 