#!/usr/bin/env python3
from flask import Flask, request, jsonify
import os
import requests
import json
import logging
from datetime import datetime
import time

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Get Laravel API URL from environment or use default
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://laravel/api')

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint."""
    return jsonify({
        'status': 'healthy',
        'service': 'transcription-service',
        'timestamp': datetime.now().isoformat()
    })

@app.route('/process', methods=['POST'])
def process_transcription():
    """Process a transcription job."""
    data = request.json
    
    if not data or 'job_id' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. Job ID is required.'
        }), 400
    
    job_id = data['job_id']
    logger.info(f"Received transcription job: {job_id}")
    
    try:
        # For now, we'll just simulate processing and return success
        # In a real implementation, this would handle the AWS transcription
        
        # Simulate some processing time
        # time.sleep(2)
        
        # Send the result back to Laravel
        response_data = {
            'message': 'Transcription completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'metadata': {
                'service': 'transcription-service',
                'processed_by': 'Python service'
            }
        }
        
        # In a real implementation, we would update the job status in Laravel
        # by making an API call back to the Laravel service
        # update_job_status(job_id, 'completed', response_data)
        
        return jsonify({
            'success': True,
            'job_id': job_id,
            'message': 'Job processed successfully',
            'data': response_data
        })
        
    except Exception as e:
        logger.error(f"Error processing job {job_id}: {str(e)}")
        
        # In a real implementation, we would update the job status in Laravel
        # update_job_status(job_id, 'failed', None, str(e))
        
        return jsonify({
            'success': False,
            'job_id': job_id,
            'message': f'Job processing failed: {str(e)}'
        }), 500

@app.route('/test-laravel-callback', methods=['GET'])
def test_laravel_callback():
    """Test endpoint to verify callback to Laravel API."""
    try:
        job_id = request.args.get('job_id', 'test-job-' + datetime.now().strftime('%Y%m%d%H%M%S'))
        
        logger.info(f"Testing callback to Laravel API for job: {job_id}")
        
        response_data = {
            'message': 'Test callback from Python service',
            'service_timestamp': datetime.now().isoformat(),
            'metadata': {
                'service': 'transcription-service',
                'test': True
            }
        }
        
        # Attempt to update job status in Laravel
        callback_result = update_job_status(job_id, 'completed', response_data)
        
        return jsonify({
            'success': True,
            'message': 'Test callback to Laravel API',
            'job_id': job_id,
            'callback_result': callback_result
        })
        
    except Exception as e:
        logger.error(f"Error testing Laravel callback: {str(e)}")
        return jsonify({
            'success': False,
            'message': f'Error testing Laravel callback: {str(e)}'
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
            'completed_at': datetime.now().isoformat()
        }
        
        logger.info(f"Payload: {json.dumps(payload)}")
        
        response = requests.post(url, json=payload)
        
        result = {
            'status_code': response.status_code,
            'response': response.text
        }
        
        if response.status_code != 200:
            logger.error(f"Failed to update job status in Laravel: {response.text}")
            return result
        else:
            logger.info(f"Successfully updated job status in Laravel for job {job_id}")
            return result
            
    except Exception as e:
        logger.error(f"Error updating job status in Laravel: {str(e)}")
        return {'error': str(e)}

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True) 