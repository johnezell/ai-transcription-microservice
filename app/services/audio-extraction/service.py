#!/usr/bin/env python3
from flask import Flask, request, jsonify
import os
import requests
import logging
import subprocess
import json
from datetime import datetime
import tempfile
import boto3 # Added for S3 interaction
import time

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://aws-transcription-laravel.local:80/api') # Ensure this matches Laravel's service discovery name
AWS_BUCKET = os.environ.get('AWS_BUCKET')
AWS_DEFAULT_REGION = os.environ.get('AWS_DEFAULT_REGION', 'us-east-1')
AUDIO_EXTRACTION_QUEUE_URL = os.environ.get('AUDIO_EXTRACTION_QUEUE_URL')
CALLBACK_QUEUE_URL = os.environ.get('CALLBACK_QUEUE_URL')

# Initialize S3 client only if AWS_BUCKET is set
s3_client = None
if AWS_BUCKET:
    s3_client = boto3.client('s3', region_name=AWS_DEFAULT_REGION)
else:
    logger.error("AWS_BUCKET environment variable not set. S3 operations will fail.")

# Initialize SQS client
sqs_client = None
if AWS_DEFAULT_REGION:
    sqs_client = boto3.client('sqs', region_name=AWS_DEFAULT_REGION)
    logger.info(f"SQS client initialized with region {AWS_DEFAULT_REGION}")
else:
    logger.error("AWS_DEFAULT_REGION not set. SQS operations will fail.")

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

def send_callback_via_sqs(job_id, status, response_data=None, error_message=None):
    """Send job status updates via SQS"""
    if not sqs_client or not CALLBACK_QUEUE_URL:
        logger.error(f"SQS client or CALLBACK_QUEUE_URL not set. Cannot send callback for job {job_id}.")
        return False
    
    try:
        message_body = {
            'job_id': job_id,
            'status': status,
            'completed_at': datetime.now().isoformat() if status in ['completed', 'failed', 'audio_extracted'] else None,
            'response_data': response_data,
            'error_message': error_message
        }
        
        response = sqs_client.send_message(
            QueueUrl=CALLBACK_QUEUE_URL,
            MessageBody=json.dumps(message_body),
            MessageAttributes={
                'ServiceType': {
                    'DataType': 'String',
                    'StringValue': 'audio-extraction'
                }
            }
        )
        
        logger.info(f"Sent callback to SQS for job {job_id} with status {status}. MessageId: {response.get('MessageId')}")
        return True
    except Exception as e:
        logger.error(f"Failed to send callback to SQS for job {job_id}: {str(e)}")
        return False

def update_job_status(job_id, status, response_data=None, error_message=None):
    """Update the job status in Laravel."""
    # Try SQS first if it's available
    if sqs_client and CALLBACK_QUEUE_URL:
        if send_callback_via_sqs(job_id, status, response_data, error_message):
            return
    
    # Legacy HTTP fallback
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
        'aws_bucket_set': bool(AWS_BUCKET),
        'audio_extraction_queue_url_set': bool(AUDIO_EXTRACTION_QUEUE_URL),
        'callback_queue_url_set': bool(CALLBACK_QUEUE_URL)
    })

def process_audio_job(job_data):
    """Process an audio extraction job."""
    if not job_data or 'job_id' not in job_data or 'video_s3_key' not in job_data:
        logger.error(f"Invalid job data: {job_data}")
        return False
    
    job_id = job_data['job_id']
    s3_video_key = job_data['video_s3_key']
    
    logger.info(f"Processing job {job_id} for S3 video key: {s3_video_key}")

    if not s3_client or not AWS_BUCKET:
        logger.error("S3 client or AWS_BUCKET not configured properly.")
        update_job_status(job_id, 'failed', None, "Audio service S3 configuration error.")
        return False

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
            return True
            
        except Exception as e:
            logger.error(f"Error processing job {job_id}: {str(e)}", exc_info=True)
            update_job_status(job_id, 'failed', None, f"Audio extraction failed: {str(e)}")
            return False

@app.route('/process', methods=['POST'])
def process_audio_extraction():
    """Extract audio from a video file from S3, process, and upload audio to S3 (HTTP API)."""
    data = request.json
    if not data or 'job_id' not in data or 'video_s3_key' not in data:
        logger.error(f"Invalid request data: {data}")
        return jsonify({'success': False, 'message': 'Invalid request: job_id and video_s3_key required.'}), 400
    
    success = process_audio_job(data)
    
    if success:
        return jsonify({'success': True, 'job_id': data['job_id'], 'message': 'Audio extraction job processed successfully'})
    else:
        return jsonify({'success': False, 'job_id': data['job_id'], 'message': 'Audio extraction failed'}), 500

@app.route('/start-sqs-listener', methods=['POST'])
def start_sqs_listener():
    """Endpoint to trigger SQS listener process (for manual startup)."""
    if not sqs_client or not AUDIO_EXTRACTION_QUEUE_URL:
        return jsonify({
            'success': False, 
            'message': 'SQS client or queue URL not configured'
        }), 500
    
    # Start the listener in a background thread
    import threading
    listener_thread = threading.Thread(target=listen_for_sqs_messages, daemon=True)
    listener_thread.start()
    
    return jsonify({
        'success': True,
        'message': 'SQS listener started in background thread'
    })

def listen_for_sqs_messages():
    """Listen for messages on the SQS queue and process them."""
    if not sqs_client or not AUDIO_EXTRACTION_QUEUE_URL:
        logger.error("Cannot start SQS listener: SQS client or queue URL not configured")
        return
    
    logger.info(f"Starting to listen for messages on queue: {AUDIO_EXTRACTION_QUEUE_URL}")
    
    while True:
        try:
            # Receive message from SQS queue
            response = sqs_client.receive_message(
                QueueUrl=AUDIO_EXTRACTION_QUEUE_URL,
                AttributeNames=['All'],
                MessageAttributeNames=['All'],
                MaxNumberOfMessages=1,
                WaitTimeSeconds=20,
                VisibilityTimeout=600  # 10 minutes
            )
            
            messages = response.get('Messages', [])
            
            for message in messages:
                logger.info(f"Received message: {message['MessageId']}")
                receipt_handle = message['ReceiptHandle']
                
                try:
                    body = json.loads(message['Body'])
                    logger.info(f"Processing job from SQS: {body}")
                    
                    success = process_audio_job(body)
                    
                    # Delete the message from the queue if processed successfully
                    if success:
                        sqs_client.delete_message(
                            QueueUrl=AUDIO_EXTRACTION_QUEUE_URL,
                            ReceiptHandle=receipt_handle
                        )
                        logger.info(f"Deleted message {message['MessageId']} from queue")
                    else:
                        logger.error(f"Failed to process job from message {message['MessageId']}")
                        # The message will return to the queue after visibility timeout
                except Exception as e:
                    logger.error(f"Error processing message {message['MessageId']}: {str(e)}", exc_info=True)
            
            # Sleep briefly if no messages were received
            if not messages:
                time.sleep(1)
                
        except Exception as e:
            logger.error(f"Error in SQS listener: {str(e)}", exc_info=True)
            time.sleep(5)  # Wait before retrying

# Start SQS listener in background thread when app starts
if AUDIO_EXTRACTION_QUEUE_URL and sqs_client:
    import threading
    listener_thread = threading.Thread(target=listen_for_sqs_messages, daemon=True)
    listener_thread.start()
    logger.info("Started SQS listener in background thread")

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=os.environ.get('FLASK_ENV') == 'development') 
