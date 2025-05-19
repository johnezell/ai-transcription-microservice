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

# Define terminology options with descriptions
TERMINOLOGY_OPTIONS = {
    "common": {
        "extraction_method": {
            "description": "Method used to extract terminology",
            "options": ["regex", "spacy", "hybrid"],
            "default": "regex",
            "impact": "Regex is faster for exact matches, spaCy provides better linguistic understanding, hybrid uses both approaches."
        },
        "case_sensitive": {
            "description": "Whether term matching should be case sensitive",
            "default": False,
            "impact": "When enabled, 'AWS' and 'aws' would be considered different terms. Usually disabled for better recall."
        },
        "min_term_frequency": {
            "description": "Minimum number of occurrences to include a term",
            "default": 1,
            "range": [1, 10],
            "impact": "Higher values filter out rare terms, useful for focusing on frequently mentioned concepts."
        }
    },
    "advanced": {
        "spacy_model": {
            "description": "spaCy model to use for NLP processing",
            "options": ["en_core_web_sm", "en_core_web_md", "en_core_web_lg"],
            "default": "en_core_web_sm",
            "impact": "Larger models (md, lg) have better accuracy but use more memory and are slower to process."
        },
        "use_lemmatization": {
            "description": "Convert words to their base form",
            "default": True,
            "impact": "When enabled, different forms of a word (e.g., 'running', 'runs') are treated as the same term ('run')."
        },
        "max_terms": {
            "description": "Maximum number of terms to extract",
            "default": 200,
            "range": [10, 1000],
            "impact": "Limits the total number of terms extracted, preventing overwhelming results for long transcripts."
        },
        "context_window": {
            "description": "Number of words around each term to capture as context",
            "default": 5,
            "range": [0, 20],
            "impact": "Larger windows provide more context but can make results verbose."
        },
        "include_uncategorized": {
            "description": "Include terms not in predefined categories",
            "default": False,
            "impact": "When enabled, extracts potentially relevant terms even if they don't match predefined categories."
        },
        "entity_types": {
            "description": "Entity types to recognize with spaCy",
            "options": ["ORG", "PRODUCT", "GPE", "PERSON", "TECH", "ALL"],
            "default": ["ORG", "PRODUCT", "TECH"],
            "impact": "Determines what types of entities are recognized. 'ALL' includes all entity types that spaCy can detect."
        }
    }
}

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

# Map of spaCy models for on-demand loading
spacy_models = {}

def load_spacy_model(model_name="en_core_web_sm"):
    """Load the specified spaCy model with caching."""
    if model_name in spacy_models:
        return spacy_models[model_name]
    
    try:
        logger.info(f"Loading spaCy model: {model_name}")
        model = spacy.load(model_name)
        spacy_models[model_name] = model
        return model
    except OSError:
        logger.error(f"Failed to load spaCy model '{model_name}'. Falling back to regex-only extraction.")
        return None

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

def extract_terms_using_regex(transcript_text, options=None):
    """Extract terms using regex patterns with configurable options."""
    if options is None:
        options = {}
    
    # Get options with defaults
    case_sensitive = options.get("case_sensitive", TERMINOLOGY_OPTIONS["common"]["case_sensitive"]["default"])
    min_term_frequency = options.get("min_term_frequency", TERMINOLOGY_OPTIONS["common"]["min_term_frequency"]["default"])
    
    if not DYNAMIC_TERMINOLOGY:
        logger.warning("No dynamic terminology loaded. Term extraction will likely find nothing.")
        return {
            "method": "regex",
            "total_unique_terms": 0,
            "total_term_occurrences": 0,
            "category_summary": {},
            "terms": []
        }

    logger.info(f"Extracting terms using regex with case_sensitive={case_sensitive}, min_term_frequency={min_term_frequency}")
    
    found_terms_details = []
    category_summary = {category_slug: 0 for category_slug in DYNAMIC_TERMINOLOGY.keys()}
    unique_terms_by_category = {category_slug: set() for category_slug in DYNAMIC_TERMINOLOGY.keys()}
    term_counts = {}

    # Regex flags
    regex_flags = 0 if case_sensitive else re.IGNORECASE

    for category_slug, term_definitions in DYNAMIC_TERMINOLOGY.items():
        for term_def in term_definitions:
            term_display_name = term_def.get('term', 'UnknownTerm')
            patterns = term_def.get('patterns', [re.escape(term_display_name.lower())])
            
            for pattern_str in patterns:
                try:
                    regex = re.compile(r'\b' + pattern_str + r'\b', regex_flags)
                    for match in regex.finditer(transcript_text):
                        matched_text = match.group(0)
                        
                        # Use the display name of the term for aggregation
                        term_counts[term_display_name] = term_counts.get(term_display_name, 0) + 1
                        unique_terms_by_category[category_slug].add(term_display_name)
                except re.error as e:
                    logger.warning(f"Invalid regex pattern '{pattern_str}' for term '{term_display_name}' in category '{category_slug}': {e}")
                    continue
    
    # Apply minimum term frequency filter
    filtered_term_counts = {term: count for term, count in term_counts.items() if count >= min_term_frequency}
    
    total_unique_terms = 0
    for category_slug, terms_set in unique_terms_by_category.items():
        # Filter out terms that don't meet the minimum frequency
        filtered_terms = {term for term in terms_set if term in filtered_term_counts}
        if filtered_terms:
            category_summary[category_slug] = len(filtered_terms)
            total_unique_terms += len(filtered_terms)
            for term_name in filtered_terms:
                found_terms_details.append({
                    "term": term_name,
                    "category_slug": category_slug,
                    "count": filtered_term_counts.get(term_name, 0)
                })

    return {
        "method": "regex",
        "total_unique_terms": total_unique_terms,
        "total_term_occurrences": sum(filtered_term_counts.values()),
        "category_summary": category_summary, 
        "terms": found_terms_details
    }

def extract_terms_using_spacy(transcript_text, options=None):
    """Extract terms using spaCy NLP with configurable options."""
    if options is None:
        options = {}
    
    # Get options with defaults
    spacy_model_name = options.get("spacy_model", TERMINOLOGY_OPTIONS["advanced"]["spacy_model"]["default"])
    entity_types = options.get("entity_types", TERMINOLOGY_OPTIONS["advanced"]["entity_types"]["default"])
    use_lemmatization = options.get("use_lemmatization", TERMINOLOGY_OPTIONS["advanced"]["use_lemmatization"]["default"])
    
    # Handle 'ALL' entity types
    if entity_types == "ALL":
        entity_types = None  # None means include all entity types
    
    # Load spaCy model
    nlp = load_spacy_model(spacy_model_name)
    if not nlp:
        logger.warning(f"Failed to load spaCy model {spacy_model_name}. Falling back to regex extraction.")
        return extract_terms_using_regex(transcript_text, options)
    
    logger.info(f"Processing with spaCy model {spacy_model_name}")
    doc = nlp(transcript_text)
    
    # Extract entities
    entities = []
    for ent in doc.ents:
        if entity_types is None or ent.label_ in entity_types:
            term_text = ent.lemma_ if use_lemmatization else ent.text
            entities.append({
                "term": term_text,
                "entity_type": ent.label_,
                "start": ent.start_char,
                "end": ent.end_char,
                "count": 1  # Starting count
            })
    
    # Group and count entities
    term_counts = {}
    for entity in entities:
        term = entity["term"]
        if term in term_counts:
            term_counts[term]["count"] += 1
        else:
            term_counts[term] = entity
    
    # Format for consistent output
    found_terms = list(term_counts.values())
    
    return {
        "method": "spacy",
        "model": spacy_model_name,
        "total_unique_terms": len(found_terms),
        "total_term_occurrences": sum(entity["count"] for entity in found_terms),
        "entity_types": [entity["entity_type"] for entity in found_terms],
        "terms": found_terms
    }

def extract_terms_hybrid(transcript_text, options=None):
    """Use both regex and spaCy for term extraction and merge results."""
    if options is None:
        options = {}
    
    regex_results = extract_terms_using_regex(transcript_text, options)
    spacy_results = extract_terms_using_spacy(transcript_text, options)
    
    # Merge results
    all_terms = {}
    
    # Add regex terms
    for term in regex_results["terms"]:
        term_key = term["term"].lower()
        all_terms[term_key] = {
            "term": term["term"],
            "category_slug": term.get("category_slug", "uncategorized"),
            "count": term["count"],
            "sources": ["regex"]
        }
    
    # Add/merge spaCy terms
    for term in spacy_results["terms"]:
        term_key = term["term"].lower()
        if term_key in all_terms:
            # Update existing term
            all_terms[term_key]["count"] += term["count"]
            all_terms[term_key]["sources"].append("spacy")
            # Add entity type if from spaCy
            all_terms[term_key]["entity_type"] = term.get("entity_type")
        else:
            # Add new term
            all_terms[term_key] = {
                "term": term["term"],
                "category_slug": "nlp_entities",
                "count": term["count"],
                "entity_type": term.get("entity_type"),
                "sources": ["spacy"]
            }
    
    # Convert back to list
    merged_terms = list(all_terms.values())
    
    return {
        "method": "hybrid",
        "total_unique_terms": len(merged_terms),
        "total_term_occurrences": sum(term["count"] for term in merged_terms),
        "terms": merged_terms
    }

def extract_terms(transcript_text, options=None):
    """Extract terms using the specified method based on options."""
    if options is None:
        options = {}
    
    # Handle case where options is a list instead of a dictionary
    if isinstance(options, list):
        logger.warning("Received options as a list instead of a dictionary. Converting to empty dictionary.")
        options = {}
    
    extraction_method = options.get("extraction_method", TERMINOLOGY_OPTIONS["common"]["extraction_method"]["default"])
    
    logger.info(f"Using extraction method: {extraction_method}")
    
    if extraction_method == "regex":
        return extract_terms_using_regex(transcript_text, options)
    elif extraction_method == "spacy":
        return extract_terms_using_spacy(transcript_text, options)
    elif extraction_method == "hybrid":
        return extract_terms_hybrid(transcript_text, options)
    else:
        logger.warning(f"Unknown extraction method: {extraction_method}, falling back to regex")
        return extract_terms_using_regex(transcript_text, options)

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

@app.route('/terminology-options', methods=['GET'])
def get_terminology_options():
    """Return available terminology options for Laravel frontend."""
    return jsonify({
        'options': TERMINOLOGY_OPTIONS,
        'timestamp': datetime.now().isoformat()
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
    # Get options from job data
    options = job_data.get('options', {})
    
    # Log warning if options is not a dictionary
    if isinstance(options, list):
        logger.warning(f"Job {job_id} received options as a list instead of a dictionary: {options}")
    
    logger.info(f"Processing terminology recognition job ID: {job_id} for transcript S3 key: {transcript_s3_key}")
    logger.info(f"Using options: {options}")

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
            
            # Use the new extraction function with options
            terminology_results = extract_terms(transcript_text, options)
            
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
                    'method': terminology_results.get('method', 'regex'),
                    'options_used': options
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

if __name__ == '__main__':
    # For local dev, FLASK_ENV can be set to development for debug mode
    # In Fargate, FLASK_ENV will be production by default from CDK if not overridden
    is_debug = os.environ.get('FLASK_ENV') == 'development'
    if not DYNAMIC_TERMINOLOGY:
        logger.warning("Attempting to fetch dynamic terminology on startup as it was not loaded initially.")
        fetch_defined_terminology() # Try again if initial load failed
    app.run(host='0.0.0.0', port=5000, debug=is_debug) 