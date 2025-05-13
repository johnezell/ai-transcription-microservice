#!/usr/bin/env python3
from flask import Flask, request, jsonify
import os
import re
import requests
import json
import logging
from datetime import datetime
import spacy
from spacy.matcher import PhraseMatcher
from pathlib import Path

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

# Fallback music terms in case API is not available
FALLBACK_MUSIC_TERMS = {
"music_genres": [
"acoustic",
"acoustic blues",
"americana",
"acoustic rock",
"fingerstyle",
"classical",
"country",
"country rock",
"blues",
"folk",
"bluegrass",
"classic rock",
"gospel",
"jam band",
"multi genre",
"blues-rock",
"rock",
"country blues",
"modern country",
"western swing",
"funk",
"jazz",
"r&b",
"soul",
"jazz blues",
"metal",
"modern rock",
"hard rock",
"latin rock",
"gypsy jazz",
"progressive rock",
"funk rock",
"surf rock",
"world music",
"brazilian",
"celtic",
"flamenco",
"reggae",
"fingerstyle blues",
"singer-songwriter",
"roots rock",
"chicago blues",
"jazz rock",
"modern blues",
"british blues",
"jump blues",
"smooth jazz",
"southern rock",
"texas blues",
"honky-tonk",
"rockabilly",
"jazz funk",
"bebop",
"fingerstyle jazz",
"modern jazz",
"soul jazz",
"latin",
"latin jazz",
"swing jazz"
],
"musical_instruments": [
"acoustic guitar",
"guitar",
"electric guitar",
"acoustic bass",
"mandolin",
"fiddle",
"violin",
"electric bass",
"bass",
"12-string guitar",
"banjo",
"dobro",
"upright bass",
"drums",
"saxophone",
"daw",
"harmonica",
"ukulele"
],
"skill_levels": [
"late beginner",
"intermediate",
"beginner",
"late intermediate",
"advanced"
],
"music_topics": [
"home recording",
"daw",
"chords",
"scales",
"theory",
"chord melody",
"chord progressions",
"songs",
"fingerpicking",
"technique",
"rhythm",
"soloing",
"improvisation",
"alternate tunings",
"licks",
"picking",
"slide",
"effects",
"bass grooves",
"bass lines",
"applied theory",
"comping",
"reference",
"songwriting",
"ear training",
"modes",
"accompaniment",
"practice",
"sight-reading",
"vocals",
"looping",
"jamming",
"caged system",
"solo guitar"
],
"truefire_series": [
"series",
"none",
"licks you must know",
"song courses",
"song lessons",
"handbook",
"practice plan",
"core skills",
"artist series",
"style of",
"deep dive",
"genre study",
"survival guides",
"for beginners",
"flying solo",
"a closer look",
"home recording",
"toolkit courses",
"foundry",
"songpacks",
"factory",
"masterclasses",
"jump start",
"greatest hits",
"play guitar",
"take 5",
"guidebook",
"essentials",
"play with fire",
"my guitar heroes",
"on location",
"bootcamps",
"solo factory",
"chord studies",
"guitar lab",
"multi-track jam packs",
"fakebooks",
"in the jam: single artist",
"live plus",
"play like",
"in the jam: full band",
"practice sessions",
"trading solos",
"focus on",
"premium song lessons",
"playbook",
"indie",
"guitar gym",
"jam night",
"JamPlay"
]
}

def fetch_music_terms_from_api():
    """Fetch music terms from the Laravel API."""
    try:
        url = f"{LARAVEL_API_URL}/music-terms/export"
        logger.info(f"Fetching music terms from API: {url}")
        
        response = requests.get(url)
        
        if response.status_code == 200:
            music_terms = response.json()
            logger.info(f"Successfully fetched {sum(len(terms) for terms in music_terms.values())} music terms from API")
            return music_terms
        else:
            logger.warning(f"Failed to fetch music terms from API, using fallback. Status: {response.status_code}")
            return FALLBACK_MUSIC_TERMS
            
    except Exception as e:
        logger.error(f"Error fetching music terms from API: {str(e)}")
        logger.warning("Using fallback music terms")
        return FALLBACK_MUSIC_TERMS

# Load and prepare Spacy model for music term recognition
def load_spacy_model():
    """Load and prepare spaCy model with music terminology patterns."""
    try:
        # Load smaller model for efficiency
        nlp = spacy.load("en_core_web_sm")
        
        # Create phrase matcher
        matcher = PhraseMatcher(nlp.vocab, attr="LOWER")
        
        # Fetch music terms from API
        music_terms = fetch_music_terms_from_api()
        
        # Add patterns for each category
        for category, terms in music_terms.items():
            # Create patterns for each term
            patterns = [nlp.make_doc(term) for term in terms]
            if patterns:  # Only add if there are patterns
                matcher.add(category, None, *patterns)
        
        logger.info("Successfully loaded spaCy model with music term patterns")
        return nlp, matcher, music_terms
    
    except Exception as e:
        logger.error(f"Error loading spaCy model: {str(e)}")
        raise

# Initialize NLP model and matcher
nlp, matcher, MUSIC_TERMS = load_spacy_model()

# Refresh terms at regular intervals or on demand
def refresh_music_terms():
    """Refresh music terms from API and update the matcher."""
    global matcher, MUSIC_TERMS
    
    try:
        # Fetch fresh terms
        fresh_terms = fetch_music_terms_from_api()
        
        # Create a new matcher
        new_matcher = PhraseMatcher(nlp.vocab, attr="LOWER")
        
        # Add patterns for each category
        for category, terms in fresh_terms.items():
            # Create patterns for each term
            patterns = [nlp.make_doc(term) for term in terms]
            if patterns:  # Only add if there are patterns
                new_matcher.add(category, None, *patterns)
        
        # Update the global variables
        matcher = new_matcher
        MUSIC_TERMS = fresh_terms
        
        logger.info("Successfully refreshed music terms from API")
        return True
    
    except Exception as e:
        logger.error(f"Error refreshing music terms: {str(e)}")
        return False

def extract_music_terms(transcript_text):
    """Extract music-related terms from transcript text."""
    # Process the transcript text with spaCy
    doc = nlp(transcript_text)
    
    # Find matches using the phrase matcher
    matches = matcher(doc)
    
    # Structure to store results
    results = {
        "total_terms": 0,
        "terms_by_category": {},
        "term_instances": []
    }
    
    # Initialize counters for each category
    for category in MUSIC_TERMS.keys():
        results["terms_by_category"][category] = []
    
    # Process matches
    for match_id, start, end in matches:
        # Get the matched text and its category
        match_text = doc[start:end].text
        category = nlp.vocab.strings[match_id]
        
        # Add to category list if not already present
        if match_text not in results["terms_by_category"][category]:
            results["terms_by_category"][category].append(match_text)
        
        # Add instance with position information
        results["term_instances"].append({
            "term": match_text,
            "category": category,
            "position": {
                "start": start,
                "end": end
            },
            "context": doc[max(0, start-5):min(len(doc), end+5)].text
        })
    
    # Update total count
    results["total_terms"] = sum(len(terms) for terms in results["terms_by_category"].values())
    
    return results

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
        'service': 'music-term-recognition-service',
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

@app.route('/refresh-terms', methods=['POST'])
def api_refresh_terms():
    """Endpoint to manually refresh music terms from API."""
    success = refresh_music_terms()
    
    if success:
        return jsonify({
            'success': True,
            'message': 'Music terms refreshed successfully',
            'timestamp': datetime.now().isoformat(),
            'categories': list(MUSIC_TERMS.keys()),
            'term_count': sum(len(terms) for terms in MUSIC_TERMS.values())
        })
    else:
        return jsonify({
            'success': False,
            'message': 'Failed to refresh music terms',
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/process', methods=['POST'])
def process_transcript():
    """Process a transcript for music term recognition."""
    data = request.json
    
    if not data or 'job_id' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. job_id is required.'
        }), 400
    
    job_id = data['job_id']
    logger.info(f"Received music term recognition job: {job_id}")
    
    # Define standard file paths
    job_dir = os.path.join(S3_JOBS_DIR, job_id)
    transcript_path = os.path.join(job_dir, 'transcript.txt')
    music_terms_json_path = os.path.join(job_dir, 'music_terms.json')
    
    try:
        # Check if transcript file exists
        if not os.path.exists(transcript_path):
            error_msg = f"Transcript file not found at standard path: {transcript_path}"
            logger.error(error_msg)
            update_job_status(job_id, 'failed', None, error_msg)
            return jsonify({
                'success': False,
                'message': error_msg
            }), 404
        
        # Update status to processing
        update_job_status(job_id, 'processing')
        
        # Refresh music terms before processing
        refresh_music_terms()
        
        # Read the transcript
        with open(transcript_path, 'r', encoding='utf-8') as f:
            transcript_text = f.read()
        
        # Process the transcript
        music_terms_result = extract_music_terms(transcript_text)
        
        # Save the results to a JSON file
        with open(music_terms_json_path, 'w', encoding='utf-8') as f:
            json.dump(music_terms_result, f, indent=2)
        
        # Prepare response data
        response_data = {
            'message': 'Music term recognition completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'music_terms_json_path': music_terms_json_path,
            'term_count': music_terms_result['total_terms'],
            'categories': {
                category: len(terms) 
                for category, terms in music_terms_result['terms_by_category'].items() if terms
            },
            'metadata': {
                'service': 'music-term-recognition-service',
                'processed_by': 'spaCy-based term recognition'
            }
        }
        
        # Update job status in Laravel
        update_job_status(job_id, 'completed', response_data)
        
        return jsonify({
            'success': True,
            'job_id': job_id,
            'message': 'Music term recognition processed successfully',
            'data': response_data
        })
        
    except Exception as e:
        logger.error(f"Error processing job {job_id}: {str(e)}")
        
        # Update job status in Laravel
        update_job_status(job_id, 'failed', None, str(e))
        
        return jsonify({
            'success': False,
            'job_id': job_id,
            'message': f'Music term recognition failed: {str(e)}'
        }), 500

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True) 