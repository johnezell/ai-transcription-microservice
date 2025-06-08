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
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://laravel/api')

# Ensure base directory exists
os.makedirs(S3_JOBS_DIR, exist_ok=True)

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

def get_preset_config(preset_name: str) -> Dict[str, Any]:
    """
    Get preset configuration for transcription settings.
    
    Args:
        preset_name: Name of the preset ('fast', 'balanced', 'high', 'premium')
        
    Returns:
        Dictionary containing preset configuration parameters
    """
    presets = {
        'fast': {
            'model_name': 'tiny',
            'temperature': 0,
            'initial_prompt': 'Guitar lesson audio transcription.',
            'word_timestamps': False
        },
        'balanced': {
            'model_name': 'small',
            'temperature': 0,
            'initial_prompt': 'Guitar lesson with music theory and techniques.',
            'word_timestamps': True
        },
        'high': {
            'model_name': 'medium',
            'temperature': 0.2,
            'initial_prompt': 'Guitar lesson covering music theory, techniques, chord progressions, scales, and musical terminology.',
            'word_timestamps': True
        },
        'premium': {
            'model_name': 'large-v3',
            'temperature': 0.3,
            'initial_prompt': 'Comprehensive guitar instruction covering advanced music theory, complex techniques, detailed chord progressions, scales, modes, musical terminology, and educational concepts.',
            'word_timestamps': True
        }
    }
    
    # Return the requested preset or default to 'balanced'
    return presets.get(preset_name, presets['balanced'])

def process_audio(audio_path, model_name="base", initial_prompt=None, preset_config=None):
    """
    Process audio with Whisper and extract detailed information.
    
    Args:
        audio_path: Path to the audio file
        model_name: Whisper model name (used for backward compatibility)
        initial_prompt: Initial prompt for transcription (used for backward compatibility)
        preset_config: Dictionary containing preset configuration parameters
        
    Returns:
        Dictionary containing transcription results and metadata
    """
    # Use preset config if provided, otherwise use legacy parameters
    if preset_config:
        effective_model_name = preset_config['model_name']
        effective_initial_prompt = preset_config['initial_prompt']
        effective_temperature = preset_config['temperature']
        effective_word_timestamps = preset_config['word_timestamps']
        logger.info(f"Processing audio with preset configuration: {audio_path}")
    else:
        # Backward compatibility: use provided parameters or defaults
        effective_model_name = model_name
        effective_initial_prompt = initial_prompt
        effective_temperature = 0
        effective_word_timestamps = True
        logger.info(f"Processing audio with legacy parameters: {audio_path} with model: {model_name}")
    
    model = load_whisper_model(effective_model_name)
    
    # Configure transcription settings
    settings = {
        "model_name": effective_model_name,
        "initial_prompt": effective_initial_prompt,
        "temperature": effective_temperature,
        "word_timestamps": effective_word_timestamps,
        "condition_on_previous_text": False,
        "language": "en",
    }
    
    # Perform transcription
    result = model.transcribe(
        str(audio_path),
        initial_prompt=effective_initial_prompt,
        language=settings["language"],
        temperature=effective_temperature,
        word_timestamps=effective_word_timestamps,
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
        'service': 'transcription-service',
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
def process_transcription():
    """
    Process a transcription job with optional preset configuration.
    
    Expected JSON payload:
    {
        "job_id": "required_job_id",
        "preset": "optional_preset_name",  # 'fast', 'balanced', 'high', 'premium'
        "model_name": "optional_legacy_model",  # for backward compatibility
        "initial_prompt": "optional_legacy_prompt"  # for backward compatibility
    }
    """
    data = request.json
    
    if not data or 'job_id' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. job_id is required.'
        }), 400
    
    job_id = data['job_id']
    preset_name = data.get('preset', None)
    
    # Legacy parameters for backward compatibility
    model_name = data.get('model_name', 'base')
    initial_prompt = data.get('initial_prompt', None)
    
    # Validate preset if provided
    if preset_name and preset_name not in ['fast', 'balanced', 'high', 'premium']:
        return jsonify({
            'success': False,
            'message': f'Invalid preset "{preset_name}". Valid presets are: fast, balanced, high, premium.'
        }), 400
    
    # Log the job details
    if preset_name:
        logger.info(f"Received transcription job: {job_id} with preset: {preset_name}")
    else:
        logger.info(f"Received transcription job: {job_id} (legacy mode)")
    
    # Create or get job directory
    job_dir = os.path.join(S3_JOBS_DIR, job_id)
    
    # Define standard file paths
    audio_path = os.path.join(job_dir, 'audio.wav')
    transcript_path = os.path.join(job_dir, 'transcript.txt')
    srt_path = os.path.join(job_dir, 'transcript.srt')
    json_path = os.path.join(job_dir, 'transcript.json')
    
    try:
        # Check if audio file exists
        if not os.path.exists(audio_path):
            error_msg = f"Audio file not found at standard path: {audio_path}"
            logger.error(error_msg)
            update_job_status(job_id, 'failed', None, error_msg)
            return jsonify({
                'success': False,
                'message': error_msg
            }), 404
        
        # Update status to transcribing - now Laravel will handle this
        # but we'll still send an update to confirm we've started
        update_job_status(job_id, 'transcribing')
        
        # Get preset configuration if preset is specified
        preset_config = None
        if preset_name:
            preset_config = get_preset_config(preset_name)
            logger.info(f"Using preset '{preset_name}' with model: {preset_config['model_name']}")
        
        # Process the audio with Whisper (using preset config or legacy parameters)
        transcription_result = process_audio(audio_path, model_name, initial_prompt, preset_config)
        
        # Save the transcript to files
        save_transcript_to_file(transcription_result['text'], transcript_path)
        save_srt(transcription_result['segments'], srt_path)
        with open(json_path, 'w') as f:
            json.dump(transcription_result, f, indent=2)
        
        # Determine effective model name for metadata
        effective_model_name = preset_config['model_name'] if preset_config else model_name
        
        # Prepare response data
        response_data = {
            'message': 'Transcription completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'transcript_path': transcript_path,
            'transcript_text': transcription_result['text'],
            'confidence_score': transcription_result.get('confidence_score', 0.0),
            'metadata': {
                'service': 'transcription-service',
                'processed_by': 'Whisper-based transcription',
                'model': effective_model_name,
                'preset': preset_name if preset_name else None,
                'settings': transcription_result.get('settings', {})
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

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True) 