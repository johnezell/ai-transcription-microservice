#!/usr/bin/env python3
from flask import Flask, request, jsonify
import os
import re
import requests
import json
import logging
from datetime import datetime
import tempfile
from pathlib import Path # Keep Path if used for temp file manipulations
import boto3
import time
import spacy # Keep spacy for later, but V1 might not use it heavily yet
# from spacy.matcher import PhraseMatcher # V1 might not use PhraseMatcher initially with hardcoded regex

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL') # Expected to be http://aws-transcription-laravel-service.local:80/api
AWS_BUCKET = os.environ.get('AWS_BUCKET')
AWS_DEFAULT_REGION = os.environ.get('AWS_DEFAULT_REGION', 'us-east-1')
TERMINOLOGY_EXPORT_ENDPOINT = f"{LARAVEL_API_URL}/terminology/export" if LARAVEL_API_URL else None
TERMINOLOGY_QUEUE_URL = os.environ.get('TERMINOLOGY_QUEUE_URL')
CALLBACK_QUEUE_URL = os.environ.get('CALLBACK_QUEUE_URL')

# Initialize S3 client
s3_client = None
if AWS_BUCKET and AWS_DEFAULT_REGION:
    s3_client = boto3.client('s3', region_name=AWS_DEFAULT_REGION)
else:
    logger.error("AWS_BUCKET or AWS_DEFAULT_REGION environment variables not set. S3 operations will fail.")

# Initialize SQS client
sqs_client = None
if AWS_DEFAULT_REGION:
    sqs_client = boto3.client('sqs', region_name=AWS_DEFAULT_REGION)
    logger.info(f"SQS client initialized with region {AWS_DEFAULT_REGION}")
else:
    logger.error("AWS_DEFAULT_REGION not set. SQS operations will fail.")

# Define V1 Terminology Categories and Keywords/Patterns
# This can be expanded or moved to a config file/API later
TERMINOLOGY_CATEGORIES = {
    "Technology": [r"php", r"laravel", r"vue\.js", r"aws", r"s3", r"fargate", r"docker", r"python", r"api", r"javascript", r"node\.js"],
    "AWS Services": [r"ec2", r"lambda", r"aurora", r"rds", r"ecs", r"ecr", r"cloudwatch", r"secrets manager", r"cloudmap", r"nlb", r"api gateway"],
    "Dev Concepts": [r"microservice", r"containerization", r"serverless", r"ci/cd", r"deployment", r"database", r"queue", r"asynchronous", r"sdk"]
}

# Placeholder for spaCy model if we re-introduce it quickly - for now, V1 is regex based.
# nlp = None
# try:
#     nlp = spacy.load("en_core_web_sm") # Model is downloaded in Dockerfile
#     logger.info("spaCy model en_core_web_sm loaded successfully.")
# except OSError:
#     logger.error("spaCy model 'en_core_web_sm' not found. Please ensure it was downloaded in the Dockerfile.")
#     # Service might still run but advanced features will fail or use fallbacks.

# This will hold the terms fetched from Laravel API
DYNAMIC_TERMINOLOGY = {}

def fetch_defined_terminology():
    """Fetch terminology definitions from the Laravel API."""
    global DYNAMIC_TERMINOLOGY
    if not TERMINOLOGY_EXPORT_ENDPOINT:
        logger.error("LARAVEL_API_URL (for terminology export) is not configured. Cannot fetch dynamic terms.")
        DYNAMIC_TERMINOLOGY = {} # Reset or use a hardcoded fallback if desired
        return False

    try:
        logger.info(f"Fetching terminology from Laravel API: {TERMINOLOGY_EXPORT_ENDPOINT}")
        response = requests.get(TERMINOLOGY_EXPORT_ENDPOINT, timeout=15)
        response.raise_for_status()
        
        data = response.json()
        
        # Check the structure of the response and handle it appropriately
        if isinstance(data, list):
            # Convert list to dictionary structure expected by the rest of the code
            DYNAMIC_TERMINOLOGY = {}
            # Group terms by category if they have one
            for item in data:
                category = item.get('category_slug', 'uncategorized')
                if category not in DYNAMIC_TERMINOLOGY:
                    DYNAMIC_TERMINOLOGY[category] = []
                DYNAMIC_TERMINOLOGY[category].append(item)
            
            logger.info(f"Successfully fetched and converted list data: {len(data)} terms across {len(DYNAMIC_TERMINOLOGY)} categories.")
        else:
            # Assume it's already a dictionary as expected
            DYNAMIC_TERMINOLOGY = data
            logger.info(f"Successfully fetched {sum(len(terms) for terms in DYNAMIC_TERMINOLOGY.values())} terms across {len(DYNAMIC_TERMINOLOGY)} categories from API.")
        
        return True
    except requests.exceptions.RequestException as e:
        logger.error(f"Failed to fetch terminology from Laravel API: {e}")
        DYNAMIC_TERMINOLOGY = {} # Reset or use a hardcoded fallback
        return False
    except json.JSONDecodeError as e:
        logger.error(f"Failed to decode JSON from terminology API response: {e}")
        DYNAMIC_TERMINOLOGY = {}
        return False

# Fetch terms on startup
fetch_defined_terminology()

def extract_terms_dynamically(transcript_text: str) -> dict:
    """Extract terms using dynamically fetched categories and their regex patterns."""
    if not DYNAMIC_TERMINOLOGY:
        logger.warning("No dynamic terminology loaded. Term extraction will likely find nothing.")
        return {
            "method": "dynamic_regex_v1_no_terms_loaded",
            "total_unique_terms": 0,
            "total_term_occurrences": 0,
            "category_summary": {},
            "terms": []
        }

    logger.info("Extracting terms using dynamically fetched regex patterns.")
    found_terms_details = []
    category_summary = {category_slug: 0 for category_slug in DYNAMIC_TERMINOLOGY.keys()}
    unique_terms_by_category = {category_slug: set() for category_slug in DYNAMIC_TERMINOLOGY.keys()}
    term_counts = {}

    for category_slug, term_definitions in DYNAMIC_TERMINOLOGY.items():
        for term_def in term_definitions: # term_def is like {"term": "PHP", "patterns": ["php"], ...}
            term_display_name = term_def.get('term', 'UnknownTerm')
            patterns = term_def.get('patterns', [re.escape(term_display_name.lower())]) # Fallback pattern
            
            for pattern_str in patterns:
                try:
                    # Assuming patterns are valid regex strings. If they are plain strings for exact match, adjust regex.
                    # For exact whole word match of potentially special char patterns: r'\b' + re.escape(pattern_str) + r'\b'
                    # For now, assuming patterns are already well-formed regex or simple keywords for \bword\b match.
                    regex = re.compile(r'\b' + pattern_str + r'\b', re.IGNORECASE)
                    for match in regex.finditer(transcript_text):
                        matched_text_lower = match.group(0).lower()
                        
                        # Use the display name of the term for aggregation
                        term_counts[term_display_name] = term_counts.get(term_display_name, 0) + 1
                        unique_terms_by_category[category_slug].add(term_display_name)
                except re.error as e:
                    logger.warning(f"Invalid regex pattern '{pattern_str}' for term '{term_display_name}' in category '{category_slug}': {e}")
                    continue # Skip this pattern
    
    total_unique_terms = 0
    for category_slug, terms_set in unique_terms_by_category.items():
        if terms_set:
            category_summary[category_slug] = len(terms_set)
            total_unique_terms += len(terms_set)
            for term_name in terms_set:
                 found_terms_details.append({
                     "term": term_name,
                     "category_slug": category_slug, # Store slug for consistency with API output keys
                     "count": term_counts.get(term_name, 0)
                 })

    return {
        "method": "dynamic_regex_v1",
        "total_unique_terms": total_unique_terms,
        "total_term_occurrences": sum(term_counts.values()),
        "category_summary": category_summary, 
        "terms": found_terms_details
    }

def send_callback_via_sqs(job_id, status, response_data=None, error_message=None):
    """Send job status updates via SQS"""
    if not sqs_client or not CALLBACK_QUEUE_URL:
        logger.error(f"SQS client or CALLBACK_QUEUE_URL not set. Cannot send callback for job {job_id}.")
        return False
    
    try:
        message_body = {
            'job_id': job_id,
            'status': status,
            'completed_at': datetime.now().isoformat() if status in ['completed', 'failed', 'terminology_extracted'] else None,
            'response_data': response_data,
            'error_message': error_message
        }
        
        response = sqs_client.send_message(
            QueueUrl=CALLBACK_QUEUE_URL,
            MessageBody=json.dumps(message_body),
            MessageAttributes={
                'ServiceType': {
                    'DataType': 'String',
                    'StringValue': 'terminology'
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
        logger.error(f"LARAVEL_API_URL not set for job {job_id}. Cannot update status.")
        return
    try:
        url = f"{LARAVEL_API_URL}/transcription/{job_id}/status" # Existing Laravel endpoint
        logger.info(f"Sending status update to Laravel: {url} for job {job_id} with status: {status}")
        
        payload = {
            'status': status,
            'response_data': response_data,
            'error_message': error_message,
            # 'completed_at' will be set by Laravel or based on final status by this service
        }
        # Add completed_at for terminal statuses from this service
        if status in ['completed', 'failed', 'terminology_extracted']:
             payload['completed_at'] = datetime.now().isoformat()

        headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
        response = requests.post(url, json=payload, headers=headers, timeout=30) # Increased timeout
        response.raise_for_status() # Raises an HTTPError for bad responses (4XX or 5XX)
        logger.info(f"Successfully updated job status in Laravel for job {job_id} to {status}. Response: {response.status_code}")
    except requests.exceptions.HTTPError as http_err:
        logger.error(f"HTTP error updating status for job {job_id} to {status}: {http_err} - Response: {http_err.response.text if http_err.response else 'No response body'}")
    except requests.exceptions.RequestException as req_err:
        logger.error(f"Request exception updating status for job {job_id} to {status}: {req_err}")
    except Exception as e:
        logger.error(f"Generic error updating status for job {job_id} to {status}: {str(e)}", exc_info=True)

@app.route('/health', methods=['GET'])
def health_check():
    return jsonify({
        'status': 'healthy',
        'service': 'terminology-recognition-service',
        'timestamp': datetime.now().isoformat(),
        'laravel_api_url_set': bool(LARAVEL_API_URL),
        'aws_bucket_set': bool(AWS_BUCKET),
        'dynamic_terms_loaded': bool(DYNAMIC_TERMINOLOGY),
        'terminology_queue_url_set': bool(TERMINOLOGY_QUEUE_URL),
        'callback_queue_url_set': bool(CALLBACK_QUEUE_URL)
    })

@app.route('/refresh-terms', methods=['POST']) # Added endpoint to manually refresh terms
def refresh_terms_route():
    logger.info("Received request to refresh terminology via API.")
    success = fetch_defined_terminology()
    if success:
        return jsonify({'success': True, 'message': 'Terminology definitions refreshed successfully.', 'term_count': sum(len(terms) for terms in DYNAMIC_TERMINOLOGY.values())}), 200
    else:
        return jsonify({'success': False, 'message': 'Failed to refresh terminology definitions.'}), 500

def process_terminology_job(job_data):
    """Process a terminology recognition job."""
    if not job_data or 'job_id' not in job_data or 'transcript_s3_key' not in job_data:
        logger.error(f"Invalid job data: {job_data}")
        return False
    
    job_id = job_data['job_id']
    transcript_s3_key = job_data['transcript_s3_key']
    logger.info(f"Processing terminology recognition job ID: {job_id} for transcript S3 key: {transcript_s3_key}")

    if not s3_client or not AWS_BUCKET:
        logger.error("S3 client or AWS_BUCKET not configured for terminology service.")
        update_job_status(job_id, 'failed', error_message="Terminology service S3 configuration error.")
        return False

    s3_job_prefix = os.path.dirname(transcript_s3_key) # e.g., s3/jobs/VIDEO_ID
    s3_terminology_json_key = os.path.join(s3_job_prefix, "terminology.json")

    with tempfile.TemporaryDirectory(prefix="terminology_") as tmpdir:
        local_transcript_path = Path(tmpdir) / "transcript.txt"
        local_terminology_json_path = Path(tmpdir) / "terminology.json"

        try:
            update_job_status(job_id, 'processing_terminology')
            
            logger.info(f"Downloading transcript from S3: bucket={AWS_BUCKET}, key={transcript_s3_key} to {local_transcript_path}")
            s3_client.download_file(AWS_BUCKET, transcript_s3_key, str(local_transcript_path))
            logger.info(f"Successfully downloaded transcript to {local_transcript_path}")
            
            with open(local_transcript_path, 'r', encoding='utf-8') as f:
                transcript_text = f.read()
            
            if not DYNAMIC_TERMINOLOGY and not transcript_text.strip(): # If no terms AND no text, result is empty
                 terminology_results = extract_terms_dynamically("")
            elif not DYNAMIC_TERMINOLOGY:
                 logger.warning("No dynamic terms loaded, processing with empty term set which will find nothing.")
                 terminology_results = extract_terms_dynamically(transcript_text) # Will produce empty results based on current logic
            else:
                terminology_results = extract_terms_dynamically(transcript_text)
            
            with open(local_terminology_json_path, 'w', encoding='utf-8') as f:
                json.dump(terminology_results, f, indent=2)
            logger.info(f"Terminology JSON saved locally: {local_terminology_json_path}")

            logger.info(f"Uploading terminology JSON to S3: bucket={AWS_BUCKET}, key={s3_terminology_json_key}")
            s3_client.upload_file(str(local_terminology_json_path), AWS_BUCKET, s3_terminology_json_key)
            logger.info(f"Successfully uploaded terminology JSON to S3: {s3_terminology_json_key}")
            
            response_data_for_laravel = {
                'message': 'Terminology recognition completed.',
                'service_timestamp': datetime.now().isoformat(),
                'terminology_path': s3_terminology_json_key, # S3 key for the results json
                'term_count': terminology_results.get('total_term_occurrences', 0),
                'unique_term_count': terminology_results.get('total_unique_terms', 0),
                'category_summary': terminology_results.get('category_summary', {}),
                'metadata': {
                    'service': 'terminology-recognition-service',
                    'method': terminology_results.get('method', 'unknown')
                }
            }
            
            # This status will be used by Laravel to know this stage is done.
            # Laravel's TranscriptionController will then decide if the overall video status is 'completed'.
            update_job_status(job_id, 'terminology_extracted', response_data_for_laravel)
            return True
            
        except Exception as e:
            logger.error(f"Error processing terminology for job {job_id}: {str(e)}", exc_info=True)
            update_job_status(job_id, 'failed', error_message=f"Terminology recognition failed: {str(e)}")
            return False

@app.route('/process', methods=['POST'])
def process_terminology_route():
    """Process a terminology recognition job via HTTP API."""
    data = request.json
    if not data or 'job_id' not in data or 'transcript_s3_key' not in data:
        logger.error(f"Invalid request payload: {data}")
        return jsonify({'success': False, 'message': 'job_id and transcript_s3_key required'}), 400

    success = process_terminology_job(data)
    
    if success:
        return jsonify({'success': True, 'job_id': data['job_id'], 'message': 'Terminology recognition job processed successfully'})
    else:
        return jsonify({'success': False, 'job_id': data['job_id'], 'message': 'Terminology recognition failed'}), 500

@app.route('/start-sqs-listener', methods=['POST'])
def start_sqs_listener():
    """Endpoint to trigger SQS listener process (for manual startup)."""
    if not sqs_client or not TERMINOLOGY_QUEUE_URL:
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
    if not sqs_client or not TERMINOLOGY_QUEUE_URL:
        logger.error("Cannot start SQS listener: SQS client or queue URL not configured")
        return
    
    logger.info(f"Starting to listen for messages on queue: {TERMINOLOGY_QUEUE_URL}")
    
    while True:
        try:
            # Receive message from SQS queue
            response = sqs_client.receive_message(
                QueueUrl=TERMINOLOGY_QUEUE_URL,
                AttributeNames=['All'],
                MessageAttributeNames=['All'],
                MaxNumberOfMessages=1,
                WaitTimeSeconds=20,
                VisibilityTimeout=1800  # 30 minutes
            )
            
            messages = response.get('Messages', [])
            
            for message in messages:
                logger.info(f"Received message: {message['MessageId']}")
                receipt_handle = message['ReceiptHandle']
                
                try:
                    body = json.loads(message['Body'])
                    logger.info(f"Processing terminology job from SQS: {body}")
                    
                    success = process_terminology_job(body)
                    
                    # Delete the message from the queue if processed successfully
                    if success:
                        sqs_client.delete_message(
                            QueueUrl=TERMINOLOGY_QUEUE_URL,
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
if TERMINOLOGY_QUEUE_URL and sqs_client:
    import threading
    listener_thread = threading.Thread(target=listen_for_sqs_messages, daemon=True)
    listener_thread.start()
    logger.info("Started SQS listener in background thread")

# Removed old /refresh-terms and related spacy model loading for V1 simplicity.
# If API-driven term definitions are re-added, that logic can be restored.

if __name__ == '__main__':
    # For local dev, FLASK_ENV can be set to development for debug mode
    # In Fargate, FLASK_ENV will be production by default from CDK if not overridden
    is_debug = os.environ.get('FLASK_ENV') == 'development'
    if not DYNAMIC_TERMINOLOGY:
        logger.warning("Attempting to fetch dynamic terminology on startup as it was not loaded initially.")
        fetch_defined_terminology() # Try again if initial load failed
    app.run(host='0.0.0.0', port=5000, debug=is_debug) 