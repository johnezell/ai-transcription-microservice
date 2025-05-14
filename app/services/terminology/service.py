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

s3_client = None
if AWS_BUCKET and AWS_DEFAULT_REGION:
    s3_client = boto3.client('s3', region_name=AWS_DEFAULT_REGION)
else:
    logger.error("AWS_BUCKET or AWS_DEFAULT_REGION environment variables not set. S3 operations will fail.")

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

def update_job_status(job_id, status, response_data=None, error_message=None):
    """Update the job status in Laravel."""
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

def extract_terms_v1(transcript_text: str) -> dict:
    """Extract terms using V1 regex-based approach."""
    logger.info("Extracting terms using V1 regex method.")
    found_terms_details = [] # List of dicts: {"term": "extracted_term", "category": "cat_name", "count": N}
    
    category_summary = {category: 0 for category in TERMINOLOGY_CATEGORIES.keys()}
    unique_terms_by_category = {category: set() for category in TERMINOLOGY_CATEGORIES.keys()}
    term_counts = {}

    for category, patterns in TERMINOLOGY_CATEGORIES.items():
        for pattern_str in patterns:
            regex = re.compile(r'\b' + pattern_str + r'\b', re.IGNORECASE)
            for match in regex.finditer(transcript_text):
                term = match.group(0).lower()
                term_counts[term] = term_counts.get(term, 0) + 1
                unique_terms_by_category[category].add(term)
    
    total_unique_terms = 0
    for category, terms_set in unique_terms_by_category.items():
        if terms_set:
            category_summary[category] = len(terms_set)
            total_unique_terms += len(terms_set)
            for term in terms_set:
                 found_terms_details.append({
                     "term": term,
                     "category": category,
                     "count": term_counts.get(term, 0)
                 })

    return {
        "method": "regex_v1",
        "total_unique_terms": total_unique_terms,
        "total_term_occurrences": sum(term_counts.values()),
        "category_summary": category_summary, # { "category": count_of_unique_terms_in_category }
        "terms": found_terms_details # Detailed list
    }

@app.route('/health', methods=['GET'])
def health_check():
    return jsonify({
        'status': 'healthy',
        'service': 'terminology-recognition-service',
        'timestamp': datetime.now().isoformat(),
        'laravel_api_url_set': bool(LARAVEL_API_URL),
        'aws_bucket_set': bool(AWS_BUCKET)
    })

@app.route('/process', methods=['POST'])
def process_terminology_route():
    data = request.json
    if not data or 'job_id' not in data or 'transcript_s3_key' not in data:
        logger.error(f"Invalid request payload: {data}")
        return jsonify({'success': False, 'message': 'job_id and transcript_s3_key required'}), 400

    job_id = data['job_id']
    transcript_s3_key = data['transcript_s3_key'] # e.g., s3/jobs/VIDEO_ID/transcript.txt
    logger.info(f"Received terminology recognition job ID: {job_id} for transcript S3 key: {transcript_s3_key}")

    if not s3_client or not AWS_BUCKET:
        logger.error("S3 client or AWS_BUCKET not configured for terminology service.")
        update_job_status(job_id, 'failed', error_message="Terminology service S3 configuration error.")
        return jsonify({'success': False, 'message': 'Terminology service S3 configuration error.'}), 500

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
            
            if not transcript_text.strip():
                logger.warning(f"Transcript file {transcript_s3_key} is empty for job {job_id}.")
                # Decide how to handle empty transcript: fail or return empty terms
                terminology_results = extract_terms_v1("") # Process empty string to get zeroed results
            else:
                terminology_results = extract_terms_v1(transcript_text)
            
            with open(local_terminology_json_path, 'w', encoding='utf-8') as f:
                json.dump(terminology_results, f, indent=2)
            logger.info(f"Terminology JSON saved locally: {local_terminology_json_path}")

            logger.info(f"Uploading terminology JSON to S3: bucket={AWS_BUCKET}, key={s3_terminology_json_key}")
            s3_client.upload_file(str(local_terminology_json_path), AWS_BUCKET, s3_terminology_json_key)
            logger.info(f"Successfully uploaded terminology JSON to S3: {s3_terminology_json_key}")
            
            response_data_for_laravel = {
                'message': 'Terminology recognition completed successfully.',
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
            
            return jsonify({'success': True, 'job_id': job_id, 'data': response_data_for_laravel})
            
        except Exception as e:
            logger.error(f"Error processing terminology for job {job_id}: {str(e)}", exc_info=True)
            update_job_status(job_id, 'failed', error_message=f"Terminology recognition failed: {str(e)}")
            return jsonify({'success': False, 'job_id': job_id, 'message': f'Terminology recognition failed: {str(e)}'}), 500

# Removed old /refresh-terms and related spacy model loading for V1 simplicity.
# If API-driven term definitions are re-added, that logic can be restored.

if __name__ == '__main__':
    # For local dev, FLASK_ENV can be set to development for debug mode
    # In Fargate, FLASK_ENV will be production by default from CDK if not overridden
    is_debug = os.environ.get('FLASK_ENV') == 'development'
    app.run(host='0.0.0.0', port=5000, debug=is_debug) 