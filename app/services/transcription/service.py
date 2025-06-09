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
import time
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

# Import WhisperX for transcription with alignment and diarization
import whisperx
from functools import lru_cache
import re
from typing import Dict, List, Union, Optional, Any, Tuple

# Import our WhisperX model management system
from whisperx_models import (
    load_whisperx_model,
    get_alignment_model,
    get_diarization_pipeline,
    get_model_info,
    clear_model_cache
)

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Set environment constants for cleaner path handling
S3_BASE_DIR = '/var/www/storage/app/public/s3'
S3_JOBS_DIR = os.path.join(S3_BASE_DIR, 'jobs')
D_DRIVE_BASE = '/mnt/d_drive'  # D drive mount point for segment-based storage

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://laravel/api')

# Ensure base directory exists
os.makedirs(S3_JOBS_DIR, exist_ok=True)

# Backward compatibility function - now uses WhisperX
@lru_cache(maxsize=1)
def load_whisper_model(model_name="base"):
    """Load the WhisperX model with caching for efficiency (backward compatibility)."""
    logger.info(f"Loading WhisperX model: {model_name}")
    model, metadata = load_whisperx_model(model_name)
    return model

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

# Legacy timestamp correction system removed - WhisperX alignment provides superior accuracy
# WhisperX alignment replaces the need for FFmpeg-based timestamp correction

def get_preset_config(preset_name: str) -> Dict[str, Any]:
    """
    Get preset configuration for WhisperX transcription settings with enhanced WhisperX parameters.
    
    Args:
        preset_name: Name of the preset ('fast', 'balanced', 'high', 'premium')
        
    Returns:
        Dictionary containing preset configuration parameters optimized for WhisperX
    """
    presets = {
        'fast': {
            'model_name': 'tiny',
            'temperature': 0,
            'initial_prompt': 'This is a guitar lesson with music instruction.',
            'word_timestamps': True,
            'language': 'en',
            'enable_alignment': True,
            'enable_diarization': False,
            # WhisperX-specific parameters
            'alignment_model': 'wav2vec2-base-960h',
            'batch_size': 16,
            'chunk_size': 30,
            'return_char_alignments': False,
            # 'vad_onset': 0.500,  # Removed - not supported in WhisperX 3.1.1+
            # 'vad_offset': 0.363,  # Removed - not supported in WhisperX 3.1.1+
            'performance_profile': 'speed_optimized'
        },
        'balanced': {
            'model_name': 'small',
            'temperature': 0,
            'initial_prompt': 'This is a guitar lesson with music instruction. The instructor discusses guitar techniques, chords, scales, and musical concepts.',
            'word_timestamps': True,
            'language': 'en',
            'enable_alignment': True,
            'enable_diarization': False,
            # WhisperX-specific parameters
            'alignment_model': 'wav2vec2-base-960h',
            'batch_size': 16,
            'chunk_size': 30,
            'return_char_alignments': False,
            # 'vad_onset': 0.500,  # Removed - not supported in WhisperX 3.1.1+
            # 'vad_offset': 0.363,  # Removed - not supported in WhisperX 3.1.1+
            'performance_profile': 'balanced'
        },
        'high': {
            'model_name': 'medium',
            'temperature': 0.2,
            'initial_prompt': 'This is a detailed guitar lesson with comprehensive music instruction. The instructor covers guitar techniques, music theory, chord progressions, scales, fingerpicking patterns, strumming techniques, and musical terminology. Listen carefully for technical terms, note names, chord names, and specific musical instructions.',
            'word_timestamps': True,
            'language': 'en',
            'enable_alignment': True,
            'enable_diarization': True,
            # WhisperX-specific parameters
            'alignment_model': 'wav2vec2-large-960h-lv60-self',
            'batch_size': 8,
            'chunk_size': 30,
            'return_char_alignments': True,
            # 'vad_onset': 0.400,  # Removed - not supported in WhisperX 3.1.1+
            # 'vad_offset': 0.300,  # Removed - not supported in WhisperX 3.1.1+
            'min_speakers': 1,
            'max_speakers': 3,
            'performance_profile': 'quality_optimized'
        },
        'premium': {
            'model_name': 'large-v3',
            'temperature': 0.3,
            'initial_prompt': 'This is a comprehensive guitar lesson with advanced music instruction and education content. The instructor provides detailed explanations of guitar techniques, advanced music theory concepts, chord progressions, scale patterns, fingerpicking and strumming techniques, musical terminology, and educational guidance. Pay special attention to technical musical terms, note names, chord names, scale degrees, time signatures, key signatures, musical intervals, and specific instructional language. The content may include references to musical styles, artists, songs, and educational methodologies.',
            'word_timestamps': True,
            'language': 'en',
            'enable_alignment': True,
            'enable_diarization': True,
            # WhisperX-specific parameters
            'alignment_model': 'wav2vec2-large-960h-lv60-self',
            'batch_size': 4,
            'chunk_size': 30,
            'return_char_alignments': True,
            # 'vad_onset': 0.300,  # Removed - not supported in WhisperX 3.1.1+
            # 'vad_offset': 0.200,  # Removed - not supported in WhisperX 3.1.1+
            'min_speakers': 1,
            'max_speakers': 5,
            'performance_profile': 'maximum_quality'
        }
    }
    
    # Return the requested preset or default to 'balanced'
    return presets.get(preset_name, presets['balanced'])

def render_template_prompt(preset_name: str, course_id: int = None, segment_id: int = None) -> str:
    """
    Get rendered prompt for a preset using Laravel's template rendering service.
    
    Args:
        preset_name: Name of the preset
        course_id: Optional course ID for context
        segment_id: Optional segment ID for context
        
    Returns:
        Rendered prompt string
    """
    try:
        # Make request to Laravel's template rendering API
        url = f"{LARAVEL_API_URL}/transcription-presets/{preset_name}/render"
        payload = {}
        
        if course_id:
            payload['course_id'] = course_id
        if segment_id:
            payload['segment_id'] = segment_id
            
        response = requests.post(url, json=payload, timeout=10)
        
        if response.status_code == 200:
            data = response.json()
            if data.get('success') and data.get('rendered_prompt'):
                logger.info(f"Template rendered for preset '{preset_name}': {data['rendered_prompt']}")
                return data['rendered_prompt']
        
        logger.warning(f"Failed to render template for preset '{preset_name}', falling back to static prompt")
        
    except Exception as e:
        logger.error(f"Error rendering template prompt: {e}")
    
    # Fallback to static preset configuration
    preset_config = get_preset_config(preset_name)
    return preset_config.get('initial_prompt', '')

def process_audio(audio_path, model_name="base", initial_prompt=None, preset_config=None, course_id=None, segment_id=None, preset_name=None):
    """
    Process audio with WhisperX including transcription, alignment, and optional diarization.
    Enhanced with WhisperX-specific parameters and performance monitoring.
    
    Args:
        audio_path: Path to the audio file
        model_name: Whisper model name (used for backward compatibility)
        initial_prompt: Initial prompt for transcription (used for backward compatibility)
        preset_config: Dictionary containing preset configuration parameters
        course_id: Optional course ID for template rendering
        segment_id: Optional segment ID for template rendering
        preset_name: Optional preset name for template rendering
        
    Returns:
        Dictionary containing transcription results and enhanced WhisperX metadata
    """
    # Performance tracking
    processing_start_time = time.time()
    performance_metrics = {
        'transcription_time': 0,
        'alignment_time': 0,
        'diarization_time': 0,
        'total_processing_time': 0
    }
    
    # Use preset config if provided, otherwise use legacy parameters
    if preset_config:
        effective_model_name = preset_config['model_name']
        effective_temperature = preset_config.get('temperature', 0)
        effective_word_timestamps = preset_config.get('word_timestamps', True)
        effective_language = preset_config.get('language', 'en')
        enable_alignment = preset_config.get('enable_alignment', True)
        enable_diarization = preset_config.get('enable_diarization', False)
        
        # WhisperX-specific parameters
        batch_size = preset_config.get('batch_size', 16)
        chunk_size = preset_config.get('chunk_size', 30)
        return_char_alignments = preset_config.get('return_char_alignments', False)
        # vad_onset = preset_config.get('vad_onset', 0.500)  # Removed - not supported in WhisperX 3.1.1+
        # vad_offset = preset_config.get('vad_offset', 0.363)  # Removed - not supported in WhisperX 3.1.1+
        min_speakers = preset_config.get('min_speakers', 1)
        max_speakers = preset_config.get('max_speakers', 3)
        performance_profile = preset_config.get('performance_profile', 'balanced')
        
        # Try to render template prompt if we have the necessary information
        if preset_name:
            effective_initial_prompt = render_template_prompt(preset_name, course_id, segment_id)
        else:
            effective_initial_prompt = preset_config.get('initial_prompt', '')
            
        logger.info(f"Processing audio with WhisperX preset '{preset_name}': {audio_path} using model: {effective_model_name}, profile: {performance_profile}")
    else:
        # Backward compatibility: use provided parameters or defaults
        effective_model_name = model_name
        effective_initial_prompt = initial_prompt or ''
        effective_temperature = 0
        effective_word_timestamps = True
        effective_language = 'en'
        enable_alignment = True
        enable_diarization = False
        
        # Default WhisperX parameters for legacy mode
        batch_size = 16
        chunk_size = 30
        return_char_alignments = False
        # vad_onset = 0.500  # Removed - not supported in WhisperX 3.1.1+
        # vad_offset = 0.363  # Removed - not supported in WhisperX 3.1.1+
        min_speakers = 1
        max_speakers = 3
        performance_profile = 'legacy_compatibility'
        
        logger.info(f"Processing audio with WhisperX legacy mode: {audio_path} with model: {model_name}")
    
    try:
        # Step 1: Load WhisperX model and perform transcription
        logger.info(f"Step 1: Loading WhisperX model '{effective_model_name}' with profile '{performance_profile}'")
        transcription_step_start = time.time()
        model, model_metadata = load_whisperx_model(effective_model_name, effective_language)
        
        # Configure enhanced transcription settings
        settings = {
            "model_name": effective_model_name,
            "initial_prompt": effective_initial_prompt,
            "temperature": effective_temperature,
            "word_timestamps": effective_word_timestamps,
            "language": effective_language,
            "enable_alignment": enable_alignment,
            "enable_diarization": enable_diarization,
            "whisperx_version": True,
            # WhisperX-specific settings
            "batch_size": batch_size,
            "chunk_size": chunk_size,
            "return_char_alignments": return_char_alignments,
            # "vad_onset": vad_onset,  # Removed - not supported in WhisperX 3.1.1+
            # "vad_offset": vad_offset,  # Removed - not supported in WhisperX 3.1.1+
            "performance_profile": performance_profile
        }
        
        # Add diarization settings if enabled
        if enable_diarization:
            settings.update({
                "min_speakers": min_speakers,
                "max_speakers": max_speakers
            })
        
        # Perform WhisperX transcription with enhanced parameters
        logger.info(f"Step 1: Performing WhisperX transcription with batch_size={batch_size}, chunk_size={chunk_size}...")
        audio_file = whisperx.load_audio(str(audio_path))
        
        # Fix: Convert PyTorch Tensor to NumPy array for WhisperX VAD compatibility
        if hasattr(audio_file, 'numpy'):
            audio_file = audio_file.numpy()
        elif torch.is_tensor(audio_file):
            audio_file = audio_file.detach().cpu().numpy()
        
        # WhisperX 3.1.1+ compatibility: Use only supported parameters
        # Supported parameters: batch_size, language, chunk_size
        transcribe_params = {
            'batch_size': batch_size,
            'language': effective_language,
            'chunk_size': chunk_size
        }
        
        # Log the initial prompt for debugging but don't pass it to transcribe
        if effective_initial_prompt:
            logger.info(f"Initial prompt configured (not passed to API): {effective_initial_prompt[:100]}...")
        
        logger.info(f"Using transcribe parameters: {transcribe_params}")
        result = model.transcribe(audio_file, **transcribe_params)
        
        performance_metrics['transcription_time'] = time.time() - transcription_step_start
        logger.info(f"Step 1: Transcription completed in {performance_metrics['transcription_time']:.2f}s")
        
        # Step 2: Perform alignment for word-level timestamps (if enabled)
        alignment_metadata = {}
        if enable_alignment:
            try:
                logger.info(f"Step 2: Loading alignment model for language '{effective_language}' with char_alignments={return_char_alignments}")
                alignment_step_start = time.time()
                alignment_data, align_metadata = get_alignment_model(effective_language)
                
                if alignment_data is not None:
                    logger.info("Step 2: Performing enhanced word-level alignment...")
                    # Ensure consistent device usage - use the same device as the transcription model
                    alignment_device = model_metadata.get('device', 'cuda' if torch.cuda.is_available() else 'cpu')
                    logger.info(f"Using device '{alignment_device}' for alignment to match transcription model")
                    
                    # Ensure audio is on the correct device if it's a tensor
                    if torch.is_tensor(audio_file):
                        audio_file = audio_file.to(alignment_device)
                    
                    result = whisperx.align(
                        result["segments"],
                        alignment_data["model"],
                        alignment_data["metadata"],
                        audio_file,
                        device=alignment_device,
                        return_char_alignments=return_char_alignments
                    )
                    alignment_metadata = align_metadata
                    alignment_metadata['return_char_alignments'] = return_char_alignments
                    alignment_metadata['alignment_model'] = preset_config.get('alignment_model', 'default') if preset_config else 'default'
                    
                    performance_metrics['alignment_time'] = time.time() - alignment_step_start
                    logger.info(f"Step 2: Word-level alignment completed successfully in {performance_metrics['alignment_time']:.2f}s")
                else:
                    logger.warning(f"Step 2: Alignment model not available for '{effective_language}', skipping alignment")
                    alignment_metadata = {
                        'error': f'Alignment model not available for {effective_language}',
                        'fallback_applied': True,
                        'return_char_alignments': return_char_alignments
                    }
                    
            except Exception as e:
                logger.error(f"Step 2: Alignment failed: {str(e)}")
                alignment_metadata = {
                    'error': str(e),
                    'fallback_applied': True,
                    'return_char_alignments': return_char_alignments
                }
        
        # Step 3: Perform speaker diarization (if enabled)
        diarization_metadata = {}
        if enable_diarization:
            try:
                logger.info(f"Step 3: Loading speaker diarization pipeline (speakers: {min_speakers}-{max_speakers})")
                diarization_step_start = time.time()
                diarize_model, diarize_metadata = get_diarization_pipeline()
                
                if diarize_model is not None:
                    logger.info(f"Step 3: Performing speaker diarization with {min_speakers}-{max_speakers} speakers...")
                    
                    # Enhanced diarization with speaker constraints
                    diarize_segments = diarize_model(
                        audio_file,
                        min_speakers=min_speakers,
                        max_speakers=max_speakers
                    )
                    
                    # Assign speakers to words with enhanced metadata
                    result = whisperx.assign_word_speakers(diarize_segments, result)
                    
                    # Enhanced diarization metadata
                    diarization_metadata = diarize_metadata.copy()
                    diarization_metadata.update({
                        'min_speakers': min_speakers,
                        'max_speakers': max_speakers,
                        'detected_speakers': len(set(segment.get('speaker', 'UNKNOWN') for segment in result.get('segments', []))),
                        'speaker_labels': list(set(segment.get('speaker', 'UNKNOWN') for segment in result.get('segments', []) if segment.get('speaker')))
                    })
                    
                    performance_metrics['diarization_time'] = time.time() - diarization_step_start
                    logger.info(f"Step 3: Speaker diarization completed successfully in {performance_metrics['diarization_time']:.2f}s - Detected {diarization_metadata['detected_speakers']} speakers")
                else:
                    logger.warning("Step 3: Diarization pipeline not available, skipping diarization")
                    diarization_metadata = {
                        'error': 'Diarization pipeline not available',
                        'fallback_applied': True,
                        'min_speakers': min_speakers,
                        'max_speakers': max_speakers
                    }
                    
            except Exception as e:
                logger.error(f"Step 3: Diarization failed: {str(e)}")
                diarization_metadata = {
                    'error': str(e),
                    'fallback_applied': True,
                    'min_speakers': min_speakers,
                    'max_speakers': max_speakers
                }
        
        # Calculate total processing time
        performance_metrics['total_processing_time'] = time.time() - processing_start_time
        
        # Include comprehensive settings and metadata in result
        result["settings"] = settings
        result["model_metadata"] = model_metadata
        result["alignment_metadata"] = alignment_metadata
        result["diarization_metadata"] = diarization_metadata
        result["performance_metrics"] = performance_metrics
        
        # Calculate confidence score from segments
        confidence_score = calculate_confidence(result.get("segments", []))
        result["confidence_score"] = confidence_score
        
        # WhisperX alignment provides superior timing accuracy - no legacy correction needed
        logger.info("WhisperX alignment ensures accurate timestamps without legacy correction")
        
        # Enhanced WhisperX processing summary with detailed status
        alignment_status = "completed" if enable_alignment and not alignment_metadata.get('error') else "skipped" if not enable_alignment else "failed"
        diarization_status = "completed" if enable_diarization and not diarization_metadata.get('error') else "skipped" if not enable_diarization else "failed"
        
        result["whisperx_processing"] = {
            "transcription": "completed",
            "alignment": alignment_status,
            "diarization": diarization_status,
            "processed_by": "WhisperX with enhanced alignment and diarization support",
            "performance_profile": performance_profile,
            "processing_times": {
                "transcription_seconds": performance_metrics['transcription_time'],
                "alignment_seconds": performance_metrics['alignment_time'],
                "diarization_seconds": performance_metrics['diarization_time'],
                "total_seconds": performance_metrics['total_processing_time']
            }
        }
        
        # Add speaker information if diarization was successful
        if diarization_status == "completed" and diarization_metadata.get('speaker_labels'):
            result["speaker_info"] = {
                "detected_speakers": diarization_metadata.get('detected_speakers', 0),
                "speaker_labels": diarization_metadata.get('speaker_labels', []),
                "min_speakers_configured": min_speakers,
                "max_speakers_configured": max_speakers
            }
        
        # Add alignment quality information
        if alignment_status == "completed":
            result["alignment_info"] = {
                "char_alignments_enabled": return_char_alignments,
                "alignment_model": alignment_metadata.get('alignment_model', 'default'),
                "language": effective_language
            }
        
        logger.info(f"WhisperX processing completed in {performance_metrics['total_processing_time']:.2f}s - "
                   f"Confidence: {confidence_score:.2f}, Alignment: {alignment_status}, "
                   f"Diarization: {diarization_status}, Profile: {performance_profile}")
        
        return result
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"WhisperX processing failed ({error_type}): {error_msg}")
        
        # Enhanced error categorization for better debugging
        if "initial_prompt" in error_msg.lower():
            logger.error("CRITICAL: API compatibility issue detected - initial_prompt parameter not supported")
        elif "libcudnn" in error_msg.lower() or "cuda" in error_msg.lower():
            logger.error("CRITICAL: CUDA library issue detected - missing CUDA dependencies")
        elif "out of memory" in error_msg.lower():
            logger.error("CRITICAL: GPU memory exhausted - consider reducing batch_size")
        elif "timeout" in error_msg.lower():
            logger.error("CRITICAL: Processing timeout - audio file may be too large")
        
        # Fallback to basic transcription if WhisperX fails
        logger.info("Attempting fallback to basic WhisperX transcription...")
        try:
            model, model_metadata = load_whisperx_model(effective_model_name, effective_language)
            audio_file = whisperx.load_audio(str(audio_path))
            # WhisperX 3.1.1+ compatibility: Use parameter dictionary for fallback transcription
            fallback_params = {
                'batch_size': 8,  # Reduced batch size for fallback
                'language': effective_language
            }
            result = model.transcribe(audio_file, **fallback_params)
            
            result["settings"] = settings
            result["model_metadata"] = model_metadata
            result["confidence_score"] = calculate_confidence(result.get("segments", []))
            result["whisperx_processing"] = {
                "transcription": "completed",
                "alignment": "failed",
                "diarization": "failed",
                "processed_by": "WhisperX fallback mode",
                "fallback_reason": error_msg,
                "original_error_type": error_type
            }
            
            logger.info("Fallback WhisperX transcription completed successfully")
            return result
            
        except Exception as fallback_error:
            fallback_error_type = type(fallback_error).__name__
            fallback_error_msg = str(fallback_error)
            logger.error(f"Fallback transcription also failed ({fallback_error_type}): {fallback_error_msg}")
            
            # Create comprehensive error information
            comprehensive_error = {
                "primary_error": {"type": error_type, "message": error_msg},
                "fallback_error": {"type": fallback_error_type, "message": fallback_error_msg},
                "troubleshooting_hints": []
            }
            
            # Add troubleshooting hints based on error patterns
            if "initial_prompt" in error_msg.lower() or "initial_prompt" in fallback_error_msg.lower():
                comprehensive_error["troubleshooting_hints"].append("API compatibility issue: WhisperX version may not support initial_prompt parameter")
            if "libcudnn" in error_msg.lower() or "libcudnn" in fallback_error_msg.lower():
                comprehensive_error["troubleshooting_hints"].append("CUDA library missing: Install libcudnn8 and related CUDA dependencies")
            if "out of memory" in error_msg.lower() or "out of memory" in fallback_error_msg.lower():
                comprehensive_error["troubleshooting_hints"].append("GPU memory issue: Reduce batch_size or use CPU processing")
            
            raise Exception(f"Complete WhisperX processing failure. Primary: {error_msg}. Fallback: {fallback_error_msg}. Details: {comprehensive_error}")

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
        'backend': 'WhisperX',
        'timestamp': datetime.now().isoformat()
    })

@app.route('/models/info', methods=['GET'])
def get_models_info():
    """Get information about loaded WhisperX models."""
    try:
        model_info = get_model_info()
        return jsonify({
            'success': True,
            'models': model_info,
            'timestamp': datetime.now().isoformat()
        })
    except Exception as e:
        logger.error(f"Error getting model info: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e),
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/models/clear-cache', methods=['POST'])
def clear_models_cache():
    """Clear model cache to free memory."""
    try:
        data = request.json or {}
        model_type = data.get('model_type', None)  # 'whisperx', 'alignment', 'diarization', or None for all
        
        clear_model_cache(model_type)
        
        return jsonify({
            'success': True,
            'message': f'Model cache cleared: {model_type or "all"}',
            'timestamp': datetime.now().isoformat()
        })
    except Exception as e:
        logger.error(f"Error clearing model cache: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e),
            'timestamp': datetime.now().isoformat()
        }), 500

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
        "audio_path": "optional_audio_path",  # Laravel Storage path like "truefire-courses/1/7959.wav"
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
    audio_path_param = data.get('audio_path')
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
    
    # Handle path construction - use segment-based storage if audio_path provided
    if audio_path_param:
        # Use segment-based storage pattern (matching audio-extraction service)
        # audio_path_param should be something like "truefire-courses/1/7959.wav"
        full_audio_path = os.path.join(D_DRIVE_BASE, audio_path_param)
        
        # Extract directory and filename for output files
        audio_dir = os.path.dirname(full_audio_path)
        audio_filename = os.path.basename(full_audio_path)
        
        # Extract segment ID from filename (e.g., "7959.wav" -> "7959")
        segment_id = os.path.splitext(audio_filename)[0]
        
        # Define output file paths in the same directory as audio file
        transcript_path = os.path.join(audio_dir, f'{segment_id}_transcript.txt')
        srt_path = os.path.join(audio_dir, f'{segment_id}_transcript.srt')
        json_path = os.path.join(audio_dir, f'{segment_id}_transcript.json')
        
        audio_path = full_audio_path
        logger.info(f"Using segment-based storage - Audio: {audio_path}, Segment ID: {segment_id}")
        
    else:
        # Fallback to legacy job-based storage
        logger.warning("No audio_path provided, falling back to legacy S3 structure")
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
        
        # Ensure output directory exists (important for segment-based storage)
        output_dir = os.path.dirname(transcript_path)
        if not os.path.exists(output_dir):
            logger.info(f"Creating output directory: {output_dir}")
            os.makedirs(output_dir, exist_ok=True)
        
        # Extract course_id and segment_id from data for template rendering
        course_id = data.get('course_id')
        segment_id = data.get('segment_id')
        
        # Process the audio with Whisper (using preset config or legacy parameters)
        transcription_result = process_audio(
            audio_path, 
            model_name, 
            initial_prompt, 
            preset_config,
            course_id=course_id,
            segment_id=segment_id,
            preset_name=preset_name
        )
        
        # Save the transcript to files
        save_transcript_to_file(transcription_result['text'], transcript_path)
        save_srt(transcription_result['segments'], srt_path)
        with open(json_path, 'w', encoding='utf-8') as f:
            json.dump(transcription_result, f, indent=2, ensure_ascii=False)
        
        # Determine effective model name for metadata
        effective_model_name = preset_config['model_name'] if preset_config else model_name
        
        # Prepare enhanced response data with WhisperX metadata
        response_data = {
            'message': 'Transcription completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'transcript_path': transcript_path,
            'transcript_text': transcription_result['text'],
            'confidence_score': transcription_result.get('confidence_score', 0.0),
            'segments': transcription_result.get('segments', []),
            'metadata': {
                'service': 'transcription-service',
                'processed_by': 'WhisperX Enhanced Transcription',
                'model': effective_model_name,
                'preset': preset_name if preset_name else None,
                'settings': transcription_result.get('settings', {}),
                'whisperx_processing': transcription_result.get('whisperx_processing', {}),
                'performance_metrics': transcription_result.get('performance_metrics', {}),
                'model_metadata': transcription_result.get('model_metadata', {}),
                'alignment_metadata': transcription_result.get('alignment_metadata', {}),
                'diarization_metadata': transcription_result.get('diarization_metadata', {})
            }
        }
        
        # Add enhanced WhisperX features to response if available
        if transcription_result.get('speaker_info'):
            response_data['speaker_info'] = transcription_result['speaker_info']
            
        if transcription_result.get('alignment_info'):
            response_data['alignment_info'] = transcription_result['alignment_info']
            
        if transcription_result.get('timing_correction'):
            response_data['timing_correction'] = transcription_result['timing_correction']
        
        # Update job status in Laravel
        update_job_status(job_id, 'completed', response_data)
        
        return jsonify({
            'success': True,
            'job_id': job_id,
            'message': 'Transcription processed successfully',
            'data': response_data
        })
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"Error processing job {job_id} ({error_type}): {error_msg}")
        
        # Enhanced error analysis for better debugging
        error_details = {
            'error_type': error_type,
            'error_message': error_msg,
            'job_id': job_id,
            'audio_path': audio_path if 'audio_path' in locals() else 'unknown',
            'preset_name': preset_name,
            'troubleshooting_hints': []
        }
        
        # Add specific troubleshooting hints
        if "initial_prompt" in error_msg.lower():
            error_details['troubleshooting_hints'].append("WhisperX API compatibility issue - initial_prompt parameter not supported in current version")
        if "libcudnn" in error_msg.lower() or "cuda" in error_msg.lower():
            error_details['troubleshooting_hints'].append("Missing CUDA libraries - rebuild Docker container with CUDA dependencies")
        if "file not found" in error_msg.lower():
            error_details['troubleshooting_hints'].append("Audio file missing - check audio extraction process")
        if "out of memory" in error_msg.lower():
            error_details['troubleshooting_hints'].append("GPU memory exhausted - reduce batch_size or use CPU processing")
        
        # Update job status in Laravel with detailed error information
        update_job_status(job_id, 'failed', None, error_msg)
        
        return jsonify({
            'success': False,
            'job_id': job_id,
            'message': f'Transcription failed: {error_msg}',
            'error_details': error_details,
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/transcribe', methods=['POST'])
def transcribe_audio():
    """
    Handle transcription test requests from TranscriptionTestJob.
    This endpoint is specifically for testing transcription with direct audio file paths.
    
    Expected JSON payload:
    {
        "job_id": "required_job_id",
        "audio_path": "path_to_audio_file",
        "preset": "optional_preset_name",
        "test_mode": true,
        "transcription_settings": {},
        "segment_id": "optional_segment_id",
        "course_id": "optional_course_id"
    }
    """
    data = request.json
    
    if not data or 'job_id' not in data or 'audio_path' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. job_id and audio_path are required.'
        }), 400
    
    job_id = data['job_id']
    audio_path = data['audio_path']
    preset_name = data.get('preset', 'balanced')
    test_mode = data.get('test_mode', False)
    segment_id = data.get('segment_id')
    course_id = data.get('course_id')
    
    # Validate preset
    if preset_name not in ['fast', 'balanced', 'high', 'premium']:
        return jsonify({
            'success': False,
            'message': f'Invalid preset "{preset_name}". Valid presets are: fast, balanced, high, premium.'
        }), 400
    
    logger.info(f"Received transcription test request: {job_id} for audio: {audio_path} with preset: {preset_name}")
    
    try:
        # For transcription tests, the audio path is provided directly from Laravel
        # Laravel provides the full system path via Storage::disk('d_drive')->path()
        full_audio_path = audio_path
        
        # Check if audio file exists
        if not os.path.exists(full_audio_path):
            error_msg = f"Audio file not found: {full_audio_path}"
            logger.error(error_msg)
            return jsonify({
                'success': False,
                'message': error_msg,
                'error': 'file_not_found'
            }), 404
        
        # Get preset configuration
        preset_config = get_preset_config(preset_name)
        logger.info(f"Using preset '{preset_name}' with model: {preset_config['model_name']}")
        
        # Process the audio with Whisper using preset configuration
        transcription_result = process_audio(
            full_audio_path, 
            preset_config=preset_config,
            course_id=course_id,
            segment_id=segment_id,
            preset_name=preset_name
        )
        
        # Handle output file creation - always use segment-based storage
        # Extract segment ID from audio path if not provided
        if not segment_id:
            # Extract segment ID from filename (e.g., "/mnt/d_drive/truefire-courses/1/7959.wav" -> "7959")
            audio_filename = os.path.basename(full_audio_path)
            segment_id = os.path.splitext(audio_filename)[0]
            logger.info(f"Extracted segment ID from audio path: {segment_id}")
        
        # Always save to segment-based directory (same directory as audio file)
        audio_dir = os.path.dirname(full_audio_path)
        
        # Define output file paths in the same directory as audio file
        transcript_path = os.path.join(audio_dir, f'{segment_id}_transcript.txt')
        srt_path = os.path.join(audio_dir, f'{segment_id}_transcript.srt')
        json_path = os.path.join(audio_dir, f'{segment_id}_transcript.json')
        
        # Ensure output directory exists
        if not os.path.exists(audio_dir):
            logger.info(f"Creating output directory: {audio_dir}")
            os.makedirs(audio_dir, exist_ok=True)
        
        save_transcript_to_file(transcription_result['text'], transcript_path)
        save_srt(transcription_result['segments'], srt_path)
        with open(json_path, 'w', encoding='utf-8') as f:
            json.dump(transcription_result, f, indent=2, ensure_ascii=False)
        
        
        # Prepare enhanced response data with comprehensive WhisperX metadata
        response_data = {
            'success': True,
            'message': 'Transcription completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'transcript_text': transcription_result['text'],
            'confidence_score': transcription_result.get('confidence_score', 0.0),
            'segments': transcription_result.get('segments', []),
            'metadata': {
                'service': 'transcription-service',
                'processed_by': 'WhisperX Enhanced Transcription',
                'model': preset_config['model_name'],
                'preset': preset_name,
                'test_mode': test_mode,
                'segment_id': segment_id,
                'course_id': course_id,
                'audio_path': full_audio_path,
                'settings': transcription_result.get('settings', {}),
                'whisperx_processing': transcription_result.get('whisperx_processing', {}),
                'performance_metrics': transcription_result.get('performance_metrics', {}),
                'model_metadata': transcription_result.get('model_metadata', {}),
                'alignment_metadata': transcription_result.get('alignment_metadata', {}),
                'diarization_metadata': transcription_result.get('diarization_metadata', {})
            }
        }
        
        # Add enhanced WhisperX features to response if available
        if transcription_result.get('speaker_info'):
            response_data['speaker_info'] = transcription_result['speaker_info']
            
        if transcription_result.get('alignment_info'):
            response_data['alignment_info'] = transcription_result['alignment_info']
            
        if transcription_result.get('timing_correction'):
            response_data['timing_correction'] = transcription_result['timing_correction']
        
        # Add backward compatibility flag for enhanced response format
        response_data['enhanced_format'] = True
        response_data['whisperx_version'] = True
        
        logger.info(f"Transcription test completed successfully for job: {job_id}")
        
        return jsonify(response_data)
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"Error processing transcription test {job_id} ({error_type}): {error_msg}")
        
        # Enhanced error analysis for transcription tests
        error_details = {
            'error_type': error_type,
            'error_message': error_msg,
            'job_id': job_id,
            'audio_path': full_audio_path if 'full_audio_path' in locals() else 'unknown',
            'preset_name': preset_name,
            'test_mode': test_mode,
            'troubleshooting_hints': []
        }
        
        # Add specific troubleshooting hints for test failures
        if "initial_prompt" in error_msg.lower():
            error_details['troubleshooting_hints'].append("WhisperX API compatibility issue - initial_prompt parameter not supported")
        if "libcudnn" in error_msg.lower() or "cuda" in error_msg.lower():
            error_details['troubleshooting_hints'].append("Missing CUDA libraries - container needs CUDA dependencies")
        if "file not found" in error_msg.lower():
            error_details['troubleshooting_hints'].append("Audio file missing - check file path and permissions")
        if "out of memory" in error_msg.lower():
            error_details['troubleshooting_hints'].append("GPU memory exhausted - reduce batch_size in preset")
        if "timeout" in error_msg.lower():
            error_details['troubleshooting_hints'].append("Processing timeout - audio file may be too large")
        
        return jsonify({
            'success': False,
            'job_id': job_id,
            'message': f'Transcription test failed: {error_msg}',
            'error': error_msg,
            'error_details': error_details,
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/performance/metrics', methods=['GET'])
def get_performance_metrics():
    """Get performance metrics and system information."""
    try:
        from whisperx_models import get_model_manager
        manager = get_model_manager()
        
        # Get model information and memory usage
        model_info = manager.get_model_info()
        memory_usage = manager.get_memory_usage()
        
        return jsonify({
            'success': True,
            'timestamp': datetime.now().isoformat(),
            'system_metrics': {
                'memory_usage': memory_usage,
                'device': model_info.get('device', 'unknown'),
                'compute_type': model_info.get('compute_type', 'unknown')
            },
            'model_metrics': {
                'loaded_models': model_info.get('loaded_models', {}),
                'load_times': model_info.get('load_times', {}),
                'cache_dir': model_info.get('cache_dir', 'unknown')
            },
            'service_info': {
                'backend': 'WhisperX Enhanced',
                'version': '3.0.0',
                'features': ['transcription', 'alignment', 'diarization', 'performance_monitoring']
            }
        })
    except Exception as e:
        logger.error(f"Error getting performance metrics: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e),
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/presets/info', methods=['GET'])
def get_presets_info():
    """Get information about available presets and their WhisperX configurations."""
    try:
        presets_info = {}
        preset_names = ['fast', 'balanced', 'high', 'premium']
        
        for preset_name in preset_names:
            config = get_preset_config(preset_name)
            presets_info[preset_name] = {
                'model_name': config['model_name'],
                'language': config['language'],
                'features': {
                    'alignment': config['enable_alignment'],
                    'diarization': config['enable_diarization'],
                    'word_timestamps': config['word_timestamps']
                },
                'performance_profile': config.get('performance_profile', 'standard'),
                'whisperx_parameters': {
                    'batch_size': config.get('batch_size', 16),
                    'chunk_size': config.get('chunk_size', 30),
                    'return_char_alignments': config.get('return_char_alignments', False),
                    'vad_onset': config.get('vad_onset', 0.500),
                    'vad_offset': config.get('vad_offset', 0.363)
                }
            }
            
            if config['enable_diarization']:
                presets_info[preset_name]['diarization_parameters'] = {
                    'min_speakers': config.get('min_speakers', 1),
                    'max_speakers': config.get('max_speakers', 3)
                }
        
        return jsonify({
            'success': True,
            'timestamp': datetime.now().isoformat(),
            'presets': presets_info,
            'total_presets': len(presets_info)
        })
    except Exception as e:
        logger.error(f"Error getting presets info: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e),
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/features/capabilities', methods=['GET'])
def get_service_capabilities():
    """Get comprehensive information about service capabilities and WhisperX features."""
    try:
        return jsonify({
            'success': True,
            'timestamp': datetime.now().isoformat(),
            'service_info': {
                'name': 'WhisperX Transcription Service',
                'version': '3.0.0',
                'backend': 'WhisperX with enhanced features',
                'phase': 'Phase 3 - API/Controller Layer Complete'
            },
            'capabilities': {
                'transcription': {
                    'supported_models': ['tiny', 'base', 'small', 'medium', 'large-v3'],
                    'supported_languages': ['en', 'es', 'fr', 'de', 'it', 'pt', 'ru', 'ja', 'ko', 'zh'],
                    'features': ['word_timestamps', 'confidence_scores', 'segment_detection']
                },
                'alignment': {
                    'enabled': True,
                    'models': ['wav2vec2-base-960h', 'wav2vec2-large-960h-lv60-self'],
                    'features': ['word_level_timestamps', 'character_alignments', 'timing_correction']
                },
                'diarization': {
                    'enabled': True,
                    'features': ['speaker_detection', 'speaker_labeling', 'multi_speaker_support'],
                    'max_speakers_supported': 10
                },
                'performance': {
                    'gpu_acceleration': True,
                    'batch_processing': True,
                    'performance_profiles': ['speed_optimized', 'balanced', 'quality_optimized', 'maximum_quality'],
                    'monitoring': ['processing_times', 'memory_usage', 'model_performance']
                }
            },
            'api_endpoints': {
                'transcription': ['/process', '/transcribe'],
                'management': ['/health', '/models/info', '/models/clear-cache'],
                'monitoring': ['/performance/metrics', '/connectivity-test'],
                'information': ['/presets/info', '/features/capabilities']
            },
            'supported_formats': {
                'input': ['wav', 'mp3', 'flac', 'm4a', 'ogg'],
                'output': ['json', 'txt', 'srt', 'vtt']
            }
        })
    except Exception as e:
        logger.error(f"Error getting service capabilities: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e),
            'timestamp': datetime.now().isoformat()
        }), 500

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)