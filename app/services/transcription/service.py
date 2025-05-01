#!/usr/bin/env python3
from flask import Flask, request, jsonify
import os
import requests
import json
import logging
from datetime import datetime
import uuid

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://laravel/api')
SHARED_AUDIO_DIR = '/app/shared/audio'
SHARED_FILES_DIR = '/app/shared/files'

# Ensure shared directories exist
os.makedirs(SHARED_AUDIO_DIR, exist_ok=True)
os.makedirs(SHARED_FILES_DIR, exist_ok=True)

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
            'audio_dir': os.path.exists(SHARED_AUDIO_DIR) and os.access(SHARED_AUDIO_DIR, os.R_OK),
            'files_dir': os.path.exists(SHARED_FILES_DIR) and os.access(SHARED_FILES_DIR, os.W_OK),
        }
    }
    
    return jsonify({
        'success': all(results.values()) if isinstance(results, dict) else False,
        'results': results,
        'timestamp': datetime.now().isoformat()
    })

@app.route('/process', methods=['POST'])
def process_transcription():
    """Process a transcription job."""
    data = request.json
    
    if not data or 'job_id' not in data or 'audio_path' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. job_id and audio_path are required.'
        }), 400
    
    job_id = data['job_id']
    audio_path = data['audio_path']
    
    logger.info(f"Received transcription job: {job_id} for audio: {audio_path}")
    
    try:
        # Check if audio file exists
        if not os.path.exists(audio_path):
            error_msg = f"Audio file not found at path: {audio_path}"
            logger.error(error_msg)
            update_job_status(job_id, 'failed', None, error_msg)
            return jsonify({
                'success': False,
                'message': error_msg
            }), 404
        
        # For now, we'll simulate transcription
        # In a real implementation, this would transcribe the audio
        
        # Simulate transcription
        transcript = "This is a simulated transcript. The actual transcription would be generated from the audio file."
        
        # Generate output file
        output_filename = f"{uuid.uuid4()}.txt"
        output_path = os.path.join(SHARED_FILES_DIR, output_filename)
        
        # Write transcript to file
        with open(output_path, 'w') as f:
            f.write(transcript)
        
        # Log success
        logger.info(f"Successfully created transcript at {output_path}")
        
        # Prepare response data
        response_data = {
            'message': 'Transcription completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'transcript_path': output_path,
            'metadata': {
                'service': 'transcription-service',
                'processed_by': 'Transcription service'
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

def test_laravel_connectivity():
    """Test connectivity to Laravel API."""
    try:
        response = requests.get(f"{LARAVEL_API_URL}/hello")
        return response.status_code == 200
    except Exception as e:
        logger.error(f"Error connecting to Laravel API: {str(e)}")
        return False

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True) 