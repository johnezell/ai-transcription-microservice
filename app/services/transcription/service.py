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

# Custom JSON encoder to handle numpy types
class NumpyJSONEncoder(json.JSONEncoder):
    def default(self, obj):
        import numpy as np
        if isinstance(obj, np.integer):
            return int(obj)
        elif isinstance(obj, np.floating):
            return float(obj)
        elif isinstance(obj, np.ndarray):
            return obj.tolist()
        elif isinstance(obj, np.bool_):
            return bool(obj)
        return super(NumpyJSONEncoder, self).default(obj)

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
import numpy as np
from typing import Dict, List, Union, Optional, Any, Tuple
from concurrent.futures import ThreadPoolExecutor, as_completed

# Import our WhisperX model management system
try:
    from whisperx_models import (
        load_whisperx_model,
        get_alignment_model,
        get_diarization_pipeline,
        get_model_info,
        clear_model_cache,
        get_model_manager
    )
except ImportError:
    # Fallback for relative import issues when running directly
    import sys
    sys.path.append(os.path.dirname(os.path.abspath(__file__)))
    from whisperx_models import (
        load_whisperx_model,
        get_alignment_model,
        get_diarization_pipeline,
        get_model_info,
        clear_model_cache,
        get_model_manager
    )

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)

# Configure Flask to use our custom JSON encoder for numpy types
app.json_encoder = NumpyJSONEncoder

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
    """Calculate the overall confidence score for a transcription using segment-weighted approach."""
    total_confidence = 0.0
    total_weight = 0.0
    
    for segment in segments:
        # Use segment-level confidence if available (preferred method)
        segment_confidence = segment.get('confidence')
        if segment_confidence is not None:
            # Weight by segment duration for more accurate overall score
            duration = segment.get('end', 0) - segment.get('start', 0)
            weight = max(0.1, duration)  # Minimum weight to avoid zero division
            total_confidence += segment_confidence * weight
            total_weight += weight
        else:
            # Fallback: calculate confidence from word-level data
            word_scores = []
            for word_info in segment.get('words', []):
                # Try multiple word confidence fields
                score = word_info.get('score') or word_info.get('probability') or word_info.get('confidence')
                if score is not None:
                    word_scores.append(score)
            
            if word_scores:
                avg_word_confidence = sum(word_scores) / len(word_scores)
                duration = segment.get('end', 0) - segment.get('start', 0)
                weight = max(0.1, duration)
                total_confidence += avg_word_confidence * weight
                total_weight += weight
            else:
                # Last resort: estimate from text quality
                text = segment.get('text', '').strip()
                if text:
                    text_quality = min(0.8, max(0.3, len(text) / 50.0))
                    duration = segment.get('end', 0) - segment.get('start', 0)
                    weight = max(0.1, duration)
                    total_confidence += text_quality * weight
                    total_weight += weight
    
    if total_weight == 0:
        return 0.75  # Reasonable default for successful transcription
    
    overall_confidence = total_confidence / total_weight
    return max(0.0, min(1.0, overall_confidence))

def calculate_comprehensive_quality_metrics(result: Dict, segments: List[Dict], word_segments: List[Dict]) -> Dict:
    """Calculate comprehensive quality metrics for transcription analysis."""
    quality_metrics = {}
    
    # 1. SPEECH ACTIVITY ANALYSIS
    if segments:
        total_duration = max(seg.get('end', 0) for seg in segments)
        speech_duration = sum(seg.get('end', 0) - seg.get('start', 0) for seg in segments)
        
        # Calculate pauses between segments
        pauses = []
        for i in range(len(segments) - 1):
            pause_duration = segments[i + 1].get('start', 0) - segments[i].get('end', 0)
            if pause_duration > 0:
                pauses.append(pause_duration)
        
        # Word coverage analysis
        word_time_coverage = 0
        if word_segments:
            word_time_coverage = sum(word.get('end', 0) - word.get('start', 0) for word in word_segments)
        
        # Speaking rate calculation
        total_words = len(word_segments) if word_segments else len(result.get('text', '').split())
        speaking_rate = (total_words / (speech_duration / 60)) if speech_duration > 0 else 0
        
        quality_metrics['speech_activity'] = {
            'total_duration_seconds': total_duration,
            'speech_duration_seconds': speech_duration,
            'silence_duration_seconds': total_duration - speech_duration,
            'speech_activity_ratio': speech_duration / total_duration if total_duration > 0 else 0,
            'word_time_coverage_ratio': word_time_coverage / total_duration if total_duration > 0 else 0,
            'pause_count': len(pauses),
            'average_pause_duration': np.mean(pauses) if pauses else 0,
            'max_pause_duration': max(pauses) if pauses else 0,
            'speaking_rate_wpm': speaking_rate,
            'segment_count': len(segments)
        }
    
    # 2. CONTENT QUALITY ANALYSIS
    text = result.get('text', '')
    if text:
        words = text.split()
        sentences = re.split(r'[.!?]+', text)
        sentences = [s.strip() for s in sentences if s.strip()]
        
        # Vocabulary analysis
        unique_words = set(word.lower().strip('.,!?;:') for word in words)
        vocabulary_richness = len(unique_words) / len(words) if words else 0
        
        # Filler words detection
        filler_words = {'um', 'uh', 'ah', 'er', 'hmm', 'well', 'like', 'you know', 'actually', 'basically'}
        filler_count = sum(1 for word in words if word.lower().strip('.,!?;:') in filler_words)
        filler_ratio = filler_count / len(words) if words else 0
        
        # Technical content analysis (for guitar lessons)
        music_terms = {'chord', 'scale', 'fret', 'string', 'pick', 'strum', 'finger', 'note', 'guitar', 'play'}
        instruction_words = {'practice', 'try', 'listen', 'watch', 'remember', 'notice', 'learn', 'work'}
        
        music_term_count = sum(1 for word in words if word.lower().strip('.,!?;:') in music_terms)
        instruction_count = sum(1 for word in words if word.lower().strip('.,!?;:') in instruction_words)
        technical_density = (music_term_count + instruction_count) / len(words) if words else 0
        
        quality_metrics['content_quality'] = {
            'total_words': len(words),
            'unique_words': len(unique_words),
            'vocabulary_richness': vocabulary_richness,
            'sentence_count': len(sentences),
            'average_sentence_length': np.mean([len(s.split()) for s in sentences]) if sentences else 0,
            'filler_word_count': filler_count,
            'filler_word_ratio': filler_ratio,
            'technical_content_density': technical_density,
            'music_term_count': music_term_count,
            'instruction_word_count': instruction_count
        }
    
    # 3. CONFIDENCE PATTERN ANALYSIS
    if word_segments:
        word_confidences = [word.get('score', 0) for word in word_segments if 'score' in word]
        
        if word_confidences:
            # Confidence distribution
            excellent_count = sum(1 for c in word_confidences if c >= 0.9)
            good_count = sum(1 for c in word_confidences if 0.8 <= c < 0.9)
            fair_count = sum(1 for c in word_confidences if 0.7 <= c < 0.8)
            poor_count = sum(1 for c in word_confidences if c < 0.7)
            
            total_words = len(word_confidences)
            
            # Find low confidence clusters
            low_confidence_clusters = []
            current_cluster = []
            
            for i, word in enumerate(word_segments):
                confidence = word.get('score', 1.0)
                if confidence < 0.7:
                    current_cluster.append(word)
                else:
                    if len(current_cluster) >= 3:  # Cluster of 3+ low confidence words
                        low_confidence_clusters.append({
                            'start_time': current_cluster[0].get('start', 0),
                            'end_time': current_cluster[-1].get('end', 0),
                            'word_count': len(current_cluster),
                            'avg_confidence': np.mean([w.get('score', 0) for w in current_cluster])
                        })
                    current_cluster = []
            
            # Check final cluster
            if len(current_cluster) >= 3:
                low_confidence_clusters.append({
                    'start_time': current_cluster[0].get('start', 0),
                    'end_time': current_cluster[-1].get('end', 0),
                    'word_count': len(current_cluster),
                    'avg_confidence': np.mean([w.get('score', 0) for w in current_cluster])
                })
            
            # Confidence trend analysis
            if len(word_confidences) >= 10:
                mid_point = len(word_confidences) // 2
                first_half_avg = np.mean(word_confidences[:mid_point])
                second_half_avg = np.mean(word_confidences[mid_point:])
                trend_direction = 'improving' if second_half_avg > first_half_avg else 'declining'
                trend_magnitude = abs(second_half_avg - first_half_avg)
            else:
                trend_direction = 'stable'
                trend_magnitude = 0.0
            
            quality_metrics['confidence_patterns'] = {
                'distribution': {
                    'excellent_ratio': excellent_count / total_words,
                    'good_ratio': good_count / total_words,
                    'fair_ratio': fair_count / total_words,
                    'poor_ratio': poor_count / total_words
                },
                'statistics': {
                    'mean': np.mean(word_confidences),
                    'std': np.std(word_confidences),
                    'min': min(word_confidences),
                    'max': max(word_confidences),
                    'median': np.median(word_confidences)
                },
                'low_confidence_clusters': low_confidence_clusters,
                'confidence_trend': {
                    'direction': trend_direction,
                    'magnitude': trend_magnitude
                }
            }
    
    # 4. TEMPORAL QUALITY ANALYSIS
    if word_segments:
        word_durations = [word.get('end', 0) - word.get('start', 0) for word in word_segments]
        
        # Calculate gaps between words
        word_gaps = []
        for i in range(len(word_segments) - 1):
            gap = word_segments[i + 1].get('start', 0) - word_segments[i].get('end', 0)
            if gap >= 0:  # Only positive gaps
                word_gaps.append(gap)
        
        # Detect unusual timing events
        unusual_events = []
        
        # Very short words (< 0.1 seconds)
        short_words = [i for i, duration in enumerate(word_durations) if duration < 0.1 and duration > 0]
        
        # Very long gaps (> 2 seconds)
        long_gaps = [i for i, gap in enumerate(word_gaps) if gap > 2.0]
        
        if short_words:
            unusual_events.append({
                'type': 'very_short_words',
                'count': len(short_words),
                'positions': short_words[:5]  # First 5 positions
            })
        
        if long_gaps:
            unusual_events.append({
                'type': 'long_gaps',
                'count': len(long_gaps),
                'positions': long_gaps[:5]  # First 5 positions
            })
        
        quality_metrics['temporal_quality'] = {
            'word_duration_stats': {
                'mean': np.mean(word_durations) if word_durations else 0,
                'std': np.std(word_durations) if word_durations else 0,
                'min': min(word_durations) if word_durations else 0,
                'max': max(word_durations) if word_durations else 0
            },
            'word_gap_stats': {
                'mean': np.mean(word_gaps) if word_gaps else 0,
                'std': np.std(word_gaps) if word_gaps else 0,
                'count': len(word_gaps)
            },
            'unusual_timing_events': unusual_events,
            'timing_consistency_score': calculate_timing_consistency_score(word_segments)
        }
    
    # 5. MODEL PERFORMANCE METRICS
    processing_times = result.get('whisperx_processing', {}).get('processing_times', {})
    settings = result.get('settings', {})
    model_metadata = result.get('model_metadata', {})
    
    transcription_time = processing_times.get('transcription_seconds', 0)
    alignment_time = processing_times.get('alignment_seconds', 0)
    total_time = processing_times.get('total_seconds', 0)
    
    audio_duration = quality_metrics.get('speech_activity', {}).get('total_duration_seconds', 1)
    time_efficiency = min(1.0, audio_duration / max(0.1, total_time)) if total_time > 0 else 1.0
    
    quality_metrics['model_performance'] = {
        'processing_efficiency': {
            'time_per_second_audio': total_time / max(1, audio_duration),
            'transcription_time': transcription_time,
            'alignment_time': alignment_time,
            'total_time': total_time,
            'time_efficiency_score': time_efficiency
        },
        'model_metadata': {
            'model_name': settings.get('model_name', 'unknown'),
            'device': model_metadata.get('device', 'unknown'),
            'memory_usage_mb': model_metadata.get('memory_usage_mb', 0),
            'batch_size': settings.get('batch_size', 1)
        },
        'output_completeness': {
            'word_count': len(word_segments),
            'segment_count': len(segments),
            'alignment_success': result.get('whisperx_processing', {}).get('alignment') == 'completed'
        }
    }
    
    # 6. OVERALL QUALITY SCORE
    overall_score = calculate_overall_quality_score(quality_metrics, result.get('confidence_score', 0))
    quality_metrics['overall_quality_score'] = overall_score
    
    return quality_metrics

def calculate_timing_consistency_score(word_segments: List[Dict]) -> float:
    """Calculate timing consistency score based on word timing patterns."""
    if len(word_segments) < 3:
        return 1.0
    
    anomalies = 0
    total_checks = 0
    
    for i, word in enumerate(word_segments[:-1]):
        current_end = word.get('end', 0)
        current_start = word.get('start', 0)
        next_start = word_segments[i + 1].get('start', 0)
        
        # Check for reasonable word duration
        duration = current_end - current_start
        if duration <= 0 or duration > 3.0:  # Negative or very long duration
            anomalies += 1
        
        # Check for reasonable gaps
        gap = next_start - current_end
        if gap < -0.05 or gap > 3.0:  # Significant overlap or very long gap
            anomalies += 1
        
        total_checks += 2
    
    consistency_score = 1.0 - (anomalies / max(1, total_checks))
    return max(0.0, consistency_score)

def calculate_overall_quality_score(quality_metrics: Dict, confidence_score: float) -> float:
    """Calculate overall quality score from all metrics."""
    weights = {
        'confidence': 0.35,
        'speech_activity': 0.20,
        'content_quality': 0.20,
        'temporal_quality': 0.15,
        'model_performance': 0.10
    }
    
    overall_score = confidence_score * weights['confidence']
    
    # Speech activity contribution
    speech_activity = quality_metrics.get('speech_activity', {})
    if speech_activity:
        speech_score = min(1.0, speech_activity.get('speech_activity_ratio', 0) * 1.2)
        overall_score += speech_score * weights['speech_activity']
    
    # Content quality contribution
    content_quality = quality_metrics.get('content_quality', {})
    if content_quality:
        vocab_score = min(1.0, content_quality.get('vocabulary_richness', 0) * 1.5)
        filler_penalty = 1.0 - content_quality.get('filler_word_ratio', 0)
        content_score = (vocab_score + filler_penalty) / 2
        overall_score += content_score * weights['content_quality']
    
    # Temporal quality contribution
    temporal_quality = quality_metrics.get('temporal_quality', {})
    if temporal_quality:
        timing_score = temporal_quality.get('timing_consistency_score', 0.5)
        overall_score += timing_score * weights['temporal_quality']
    
    # Model performance contribution
    model_performance = quality_metrics.get('model_performance', {})
    if model_performance:
        efficiency_score = model_performance.get('processing_efficiency', {}).get('time_efficiency_score', 0.5)
        overall_score += efficiency_score * weights['model_performance']
    
    return max(0.0, min(1.0, overall_score))

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
    # OPTIMIZED, STATIC GUITAR CONTEXT (~650 characters - fits in WhisperX token limit)
    # Can be enhanced with dynamic injection by build_custom_prompt() function
    GUITAR_LESSON_CONTEXT = '''This is TrueFire guitar instruction with comprehensive musical content. CRITICAL TERMINOLOGY: Always transcribe "chord" never "cord". Musical notes: C sharp (not "see sharp"), D flat (not "the flat"), F sharp, B flat. Guitar terms: fretboard, capo, pickup (not "pick up"), fingerpicking (not "finger picking"), hammer-on, pull-off. Chords: C major chord, D minor chord, E7 chord. Hardware: strings, frets, tuning pegs, bridge, nut. Techniques: strumming, palm muting, bending, vibrato, slides. Time signatures: 4/4 time (four-four time), 3/4 time.'''
    
    # DEBUG: Log the optimized length
    logger.info(f"PRESET DEBUG: Optimized GUITAR_LESSON_CONTEXT length: {len(GUITAR_LESSON_CONTEXT)} characters")
    logger.info(f"PRESET DEBUG: Static prompt loaded - dynamic injection available via build_custom_prompt()")
    
    presets = {
        'fast': {
            'model_name': 'tiny',
            'temperature': 0,
            'initial_prompt': GUITAR_LESSON_CONTEXT,
            'word_timestamps': True,
            'language': 'en',
            'enable_alignment': True,
            'enable_diarization': False,
            'enable_guitar_term_evaluation': True,  # Enable guitar terminology enhancement
            'enable_gibberish_cleanup': False,  # DISABLED by default for large-scale safety
            # WhisperX-specific parameters
            'alignment_model': 'wav2vec2-base-960h',
            'batch_size': 16,
            'chunk_size': 30,
            'return_char_alignments': False,
            'performance_profile': 'speed_optimized'
        },
        'balanced': {
            'model_name': 'small',
            'temperature': 0,
            'initial_prompt': GUITAR_LESSON_CONTEXT,
            'word_timestamps': True,
            'language': 'en',
            'enable_alignment': True,
            'enable_diarization': False,
            'enable_guitar_term_evaluation': True,  # Enable guitar terminology enhancement
            'enable_gibberish_cleanup': False,  # DISABLED by default for large-scale safety
            # WhisperX-specific parameters
            'alignment_model': 'wav2vec2-base-960h',
            'batch_size': 16,
            'chunk_size': 30,
            'return_char_alignments': False,
            'performance_profile': 'balanced'
        },
        'high': {
            'model_name': 'medium',
            'temperature': 0.2,
            'initial_prompt': GUITAR_LESSON_CONTEXT,
            'word_timestamps': True,
            'language': 'en',
            'enable_alignment': True,
            'enable_diarization': True,
            'enable_guitar_term_evaluation': True,  # Enable guitar terminology enhancement
            'enable_gibberish_cleanup': False,  # DISABLED by default for large-scale safety
            # WhisperX-specific parameters
            'alignment_model': 'wav2vec2-large-960h-lv60-self',
            'batch_size': 8,
            'chunk_size': 30,
            'return_char_alignments': True,
            'min_speakers': 1,
            'max_speakers': 3,
            'performance_profile': 'quality_optimized'
        },
        'premium': {
            'model_name': 'large-v3',
            'temperature': 0.3,
            'initial_prompt': GUITAR_LESSON_CONTEXT,
            'word_timestamps': True,
            'language': 'en',
            'enable_alignment': True,
            'enable_diarization': True,
            'enable_guitar_term_evaluation': True,  # Enable guitar terminology enhancement
            'enable_gibberish_cleanup': False,  # DISABLED by default for large-scale safety
            # WhisperX-specific parameters
            'alignment_model': 'wav2vec2-large-960h-lv60-self',
            'batch_size': 4,
            'chunk_size': 30,
            'return_char_alignments': True,
            'min_speakers': 1,
            'max_speakers': 5,
            'performance_profile': 'maximum_quality'
        }
    }
    
    # Return the requested preset or default to 'balanced'
    return presets.get(preset_name, presets['balanced'])

def render_template_prompt(preset_name: str, course_id: int = None, segment_id: int = None, return_context: bool = False):
    """
    Get rendered prompt for a preset using Laravel's template rendering service.
    
    Args:
        preset_name: Name of the preset
        course_id: Optional course ID for context
        segment_id: Optional segment ID for context
        return_context: If True, return (prompt, context) tuple instead of just prompt
        
    Returns:
        Rendered prompt string, or (prompt, context) tuple if return_context=True
    """
    # Get the static preset configuration first for comparison
    preset_config = get_preset_config(preset_name)
    static_prompt = preset_config.get('initial_prompt', '')
    
    logger.info(f"PROMPT DEBUG: Static preset prompt length: {len(static_prompt)} characters")
    logger.info(f"PROMPT DEBUG: Static prompt preview: {static_prompt[:100]}{'...' if len(static_prompt) > 100 else ''}")
    
    try:
        # Make request to Laravel's template rendering API
        url = f"{LARAVEL_API_URL}/transcription-presets/{preset_name}/render"
        payload = {}
        
        if course_id:
            payload['course_id'] = course_id
        if segment_id:
            payload['segment_id'] = segment_id
            
        logger.info(f"PROMPT DEBUG: Attempting Laravel template rendering for preset '{preset_name}' at {url}")
        response = requests.post(url, json=payload, timeout=10)
        
        logger.info(f"PROMPT DEBUG: Laravel template API response status: {response.status_code}")
        
        if response.status_code == 200:
            data = response.json()
            logger.info(f"PROMPT DEBUG: Laravel response data keys: {list(data.keys()) if isinstance(data, dict) else 'not a dict'}")
            
            if data.get('success') and data.get('rendered_prompt'):
                rendered_prompt = data['rendered_prompt']
                context_data = data.get('context', {})
                logger.info(f"PROMPT DEBUG: Laravel rendered prompt length: {len(rendered_prompt)} characters")
                logger.info(f"PROMPT DEBUG: Laravel rendered prompt preview: {rendered_prompt[:100]}{'...' if len(rendered_prompt) > 100 else ''}")
                logger.info(f"PROMPT DEBUG: Context data: {context_data}")
                
                # CRITICAL: Compare lengths to detect if Laravel is returning a short prompt
                if len(rendered_prompt) < 200:
                    logger.warning(f"PROMPT DEBUG: Laravel returned suspiciously short prompt ({len(rendered_prompt)} chars), using static fallback instead")
                    logger.warning(f"PROMPT DEBUG: Short Laravel prompt was: '{rendered_prompt}'")
                    if return_context:
                        return static_prompt, {}
                    return static_prompt
                
                logger.info(f"PROMPT DEBUG: Using Laravel rendered prompt (length: {len(rendered_prompt)})")
                if return_context:
                    return rendered_prompt, context_data
                return rendered_prompt
            else:
                logger.warning(f"PROMPT DEBUG: Laravel template rendering unsuccessful: success={data.get('success')}, has_rendered_prompt={bool(data.get('rendered_prompt'))}")
        else:
            logger.warning(f"PROMPT DEBUG: Laravel template API returned status {response.status_code}: {response.text[:200]}")
        
        logger.warning(f"PROMPT DEBUG: Laravel template rendering failed, using static preset prompt")
        
    except Exception as e:
        logger.error(f"PROMPT DEBUG: Error rendering template prompt: {e}")
    
    # Fallback to static preset configuration
    logger.info(f"PROMPT DEBUG: Using static preset prompt (length: {len(static_prompt)})")
    if return_context:
        return static_prompt, {}
    return static_prompt

def process_audio(audio_path, model_name="base", initial_prompt=None, preset_config=None, course_id=None, segment_id=None, preset_name=None, enable_intelligent_selection=True, enable_optimal_selection=False):
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
        enable_intelligent_selection: Whether to use intelligent model selection (cascading)
        
    Returns:
        Dictionary containing transcription results and enhanced WhisperX metadata
    """
    # NEW: Intelligent model selection integration
    if enable_intelligent_selection:
        try:
            from intelligent_selector import intelligent_process_audio
        except ImportError:
            # Fallback for relative import issues when running directly
            import sys
            sys.path.append(os.path.dirname(os.path.abspath(__file__)))
            from intelligent_selector import intelligent_process_audio
        
        logger.info("Using intelligent model selection with cascading escalation")
        
        # Create a wrapper function that properly handles the intelligent selector's parameters
        def core_processor(path, **processing_kwargs):
            # The intelligent selector will pass model_name in processing_kwargs
            # Use the passed model_name or fall back to the original one
            effective_model_name = processing_kwargs.get('model_name', model_name)
            effective_initial_prompt = processing_kwargs.get('initial_prompt', initial_prompt)
            effective_preset_config = processing_kwargs.get('preset_config', preset_config)
            effective_course_id = processing_kwargs.get('course_id', course_id)
            effective_segment_id = processing_kwargs.get('segment_id', segment_id)
            effective_preset_name = processing_kwargs.get('preset_name', preset_name)
            
            return _process_audio_core(
                path, 
                model_name=effective_model_name,
                initial_prompt=effective_initial_prompt,
                preset_config=effective_preset_config,
                course_id=effective_course_id,
                segment_id=effective_segment_id,
                preset_name=effective_preset_name
            )
        
        # Prepare kwargs for intelligent selector, excluding model_name to avoid conflicts
        intelligent_kwargs = {
            'initial_prompt': initial_prompt,
            'preset_config': preset_config,
            'course_id': course_id,
            'segment_id': segment_id,
            'preset_name': preset_name
        }
        
        return intelligent_process_audio(
            audio_path, 
            core_processor,
            enable_optimal_selection=enable_optimal_selection,
            **intelligent_kwargs
        )
    
    # Original processing
    return _process_audio_core(audio_path, model_name, initial_prompt, preset_config, course_id, segment_id, preset_name)

def _run_post_processing(transcription_result: Dict, audio_file, audio_path: str, preset_config: Dict, 
                        performance_metrics: Dict, effective_language: str, min_speakers: int, max_speakers: int, 
                        preset_name: str = None, enable_analytics_processing: bool = True) -> Dict:
    """
    Run all post-processing steps after initial transcription.
    
    This function encapsulates all the steps that occur after the initial transcription:
    1. Word Alignment (whisperx.align)
    2. Speaker Diarization (whisperx.assign_word_speakers)
    3. Guitar Terminology Enhancement (enhance_guitar_terminology)
    4. Final calculation of confidence_score and quality_metrics
    5. Generation of final text and word_segments from segment data
    
    Args:
        transcription_result: Initial transcription result from WhisperX
        audio_file: Processed audio data
        audio_path: Path to the audio file
        preset_config: Preset configuration dictionary
        performance_metrics: Performance tracking dictionary
        effective_language: Language code for processing
        min_speakers: Minimum speakers for diarization
        max_speakers: Maximum speakers for diarization
        preset_name: Name of the preset used
        
    Returns:
        Enhanced transcription result with all post-processing applied
    """
    result = transcription_result
    
    # Extract configuration parameters
    enable_alignment = preset_config.get('enable_alignment', True) if preset_config else True
    enable_diarization = preset_config.get('enable_diarization', False) if preset_config else False
    return_char_alignments = preset_config.get('return_char_alignments', False) if preset_config else False
    performance_profile = preset_config.get('performance_profile', 'balanced') if preset_config else 'balanced'
    
    # Step 2: Perform alignment for word-level timestamps (REQUIRED for word highlighting)
    alignment_metadata = {}
    if enable_alignment:
        try:
            logger.info(f"Step 2: Loading alignment model for language '{effective_language}' with char_alignments={return_char_alignments}")
            alignment_step_start = time.time()
            alignment_data, align_metadata = get_alignment_model(effective_language)
            
            if alignment_data is not None:
                logger.info("Step 2: Performing word-level alignment for real-time highlighting...")
                
                # ROBUST CPU-ONLY ALIGNMENT with comprehensive error handling
                # Required for word-level timestamps and confidence scores
                
                # Ensure the transcription segments don't contain any tensors
                segments_for_alignment = result["segments"]
                logger.info(f"Processing {len(segments_for_alignment)} segments for word-level alignment")
                
                # Ensure audio is numpy array for CPU processing
                if torch.is_tensor(audio_file):
                    audio_file_cpu = audio_file.detach().cpu().numpy()
                    logger.info("Converted audio tensor to numpy array for CPU alignment")
                else:
                    audio_file_cpu = audio_file
                    logger.info(f"Audio file type: {type(audio_file_cpu)}")
                
                # Multiple alignment strategies with fallbacks
                alignment_success = False
                
                # Strategy 1: CPU-only alignment with complete isolation
                try:
                    logger.info("Attempting Strategy 1: Isolated CPU-only alignment...")
                    
                    # Complete GPU context isolation
                    torch.cuda.empty_cache()
                    original_default_device = torch.cuda.current_device() if torch.cuda.is_available() else None
                    
                    # Force CPU-only context
                    with torch.no_grad():
                        # Load alignment model on CPU and keep it there
                        alignment_model_cpu = alignment_data["model"].cpu()
                        alignment_metadata_cpu = alignment_data["metadata"]
                        
                        # Set all tensors to CPU mode temporarily
                        torch.set_default_tensor_type(torch.FloatTensor)
                        
                        try:
                            logger.info("Performing WhisperX alignment with complete CPU isolation...")
                            aligned_result = whisperx.align(
                                segments_for_alignment,
                                alignment_model_cpu,
                                alignment_metadata_cpu,
                                audio_file_cpu,
                                device="cpu",
                                return_char_alignments=return_char_alignments
                            )
                            
                            if aligned_result and 'segments' in aligned_result:
                                result = aligned_result
                                alignment_success = True
                                logger.info("Strategy 1: CPU-only alignment completed successfully!")
                                
                        finally:
                            # Always restore GPU tensor type
                            if torch.cuda.is_available():
                                torch.set_default_tensor_type(torch.cuda.FloatTensor)
                                if original_default_device is not None:
                                    torch.cuda.set_device(original_default_device)
                                    
                except Exception as e:
                    logger.warning(f"Strategy 1 failed: {str(e)}")
                    torch.cuda.empty_cache()  # Clean up on failure
                
                # Strategy 2: Fallback to basic alignment if Strategy 1 fails
                if not alignment_success:
                    try:
                        logger.info("Attempting Strategy 2: Basic alignment fallback...")
                        # Simplified alignment without device switching
                        aligned_result = whisperx.align(
                            segments_for_alignment,
                            alignment_data["model"],
                            alignment_data["metadata"],
                            audio_file_cpu,
                            device="cpu",
                            return_char_alignments=False  # Disable char alignments for stability
                        )
                        
                        if aligned_result and 'segments' in aligned_result:
                            result = aligned_result
                            alignment_success = True
                            logger.info("Strategy 2: Basic alignment completed successfully!")
                            
                    except Exception as e:
                        logger.warning(f"Strategy 2 failed: {str(e)}")
                
                if alignment_success:
                    # CLEAN UP ALIGNMENT RESULTS: Filter out spurious single-character words
                    total_words_before = 0
                    for segment in result.get('segments', []):
                        total_words_before += len(segment.get('words', []))
                    
                    # Filter out low-confidence single character words and alignment artifacts
                    for segment in result.get('segments', []):
                        if 'words' in segment:
                            original_words = segment['words']
                            filtered_words = []
                            
                            for word in original_words:
                                word_text = word.get('word', '').strip()
                                confidence = word.get('score', 0.0)
                                duration = word.get('end', 0) - word.get('start', 0)
                                
                                # Filter criteria for spurious words (minimal filtering for musical content):
                                should_filter = (
                                    # Single character punctuation-only words
                                    (len(word_text) == 1 and word_text in '.,!?;:') or
                                    # Empty or whitespace-only words
                                    (not word_text or word_text.isspace()) or
                                    # Only extremely low confidence words (< 0.05 = 5%)
                                    (confidence < 0.05)
                                )
                                
                                if not should_filter:
                                    filtered_words.append(word)
                                
                            segment['words'] = filtered_words
                    
                    # Count words after filtering
                    word_count = 0
                    for segment in result.get('segments', []):
                        word_count += len(segment.get('words', []))
                    
                    # Log filtering results
                    filtered_count = total_words_before - word_count
                    if filtered_count > 0:
                        logger.info(f"Filtered out {filtered_count} spurious single-character/low-confidence words")
                    
                    alignment_metadata = align_metadata
                    alignment_metadata['return_char_alignments'] = return_char_alignments
                    alignment_metadata['alignment_model'] = preset_config.get('alignment_model', 'default') if preset_config else 'default'
                    alignment_metadata['word_count'] = word_count
                    
                    performance_metrics['alignment_time'] = time.time() - alignment_step_start
                    logger.info(f"Step 2: Word-level alignment completed successfully in {performance_metrics['alignment_time']:.2f}s")
                    logger.info(f"Generated {word_count} word-level timestamps for real-time highlighting")
                else:
                    logger.error("All alignment strategies failed - proceeding without word-level data")
                    alignment_metadata = {
                        'error': 'All alignment strategies failed',
                        'fallback_applied': True,
                        'return_char_alignments': return_char_alignments,
                        'note': 'Segment-level timestamps available, word-level highlighting not possible'
                    }
                    performance_metrics['alignment_time'] = time.time() - alignment_step_start
            else:
                logger.warning(f"Step 2: Alignment model not available for '{effective_language}', skipping alignment")
                alignment_metadata = {
                    'error': f'Alignment model not available for {effective_language}',
                    'fallback_applied': True,
                    'return_char_alignments': return_char_alignments
                }
                performance_metrics['alignment_time'] = 0.0
                
        except Exception as e:
            logger.error(f"Step 2: Alignment failed: {str(e)}")
            alignment_metadata = {
                'error': str(e),
                'fallback_applied': True,
                'return_char_alignments': return_char_alignments
            }
            performance_metrics['alignment_time'] = 0.0
    else:
        logger.info(f"Step 2: Alignment disabled in preset configuration")
        alignment_metadata = {
            'status': 'disabled_by_preset',
            'return_char_alignments': return_char_alignments
        }
        performance_metrics['alignment_time'] = 0.0
    
    # Step 3: Perform speaker diarization (if enabled and analytics processing enabled)
    diarization_metadata = {}
    if enable_diarization and enable_analytics_processing:
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
    elif enable_diarization and not enable_analytics_processing:
        # Diarization is enabled in preset but analytics processing is disabled
        diarization_metadata = {
            'status': 'skipped_analytics_disabled',
            'note': 'Diarization skipped because analytics processing is disabled',
            'min_speakers': min_speakers,
            'max_speakers': max_speakers
        }
        logger.info("Step 3: Diarization skipped - analytics processing disabled")
    
    # Store metadata in result
    result["alignment_metadata"] = alignment_metadata
    result["diarization_metadata"] = diarization_metadata
    
    # Calculate confidence scores at multiple levels
    segments = result.get("segments", [])
    
    # Add segment-level confidence scores
    # CRITICAL: Convert numpy float32 values to Python float to prevent JSON serialization errors
    for segment in segments:
        segment_words = segment.get('words', [])
        if segment_words:
            # Calculate segment confidence as average of word confidences
            word_scores = [float(word.get('score', 0.0)) for word in segment_words if word.get('score') is not None]
            if word_scores:
                segment['confidence'] = float(sum(word_scores) / len(word_scores))
            else:
                segment['confidence'] = 0.0
        else:
            # Fallback: estimate from text quality if no word-level data
            text_length = len(segment.get('text', ''))
            segment['confidence'] = float(min(0.8, max(0.3, text_length / 100.0)))
    
    # CRITICAL FIX: Generate complete text from segments (WhisperX doesn't provide top-level 'text' field)
    segments = result.get("segments", [])
    complete_text = ""
    if segments:
        complete_text = " ".join(segment.get("text", "").strip() for segment in segments if segment.get("text"))
        complete_text = complete_text.strip()
    result["text"] = complete_text
    logger.info(f"Generated complete transcript text: {len(complete_text)} characters")
    
    # ENHANCED: Create clean word_segments array for real-time highlighting
    # CRITICAL: Convert numpy float32 values to Python float to prevent JSON serialization errors
    word_segments = []
    for segment in segments:
        for word in segment.get('words', []):
            word_segments.append({
                'word': word.get('word', ''),
                'start': float(word.get('start', 0)),
                'end': float(word.get('end', 0)), 
                'score': float(word.get('score', 0.0))
            })
    
    result["word_segments"] = word_segments
    if word_segments:
        logger.info(f"Generated {len(word_segments)} clean word segments for real-time highlighting")
    
    # CALCULATE: Original confidence score and quality metrics BEFORE guitar term enhancement
    # This preserves the baseline metrics for comparison with enhanced scores
    
    # Calculate segment-level confidence scores with original word scores
    # CRITICAL: Convert numpy float32 values to Python float to prevent JSON serialization errors
    for segment in segments:
        segment_words = segment.get('words', [])
        if segment_words:
            # Calculate segment confidence as average of word confidences
            word_scores = [float(word.get('score', 0.0)) for word in segment_words if word.get('score') is not None]
            if word_scores:
                segment['confidence'] = float(sum(word_scores) / len(word_scores))
            else:
                segment['confidence'] = 0.0
        else:
            # Fallback: estimate from text quality if no word-level data
            text_length = len(segment.get('text', ''))
            segment['confidence'] = float(min(0.8, max(0.3, text_length / 100.0)))
    
    # Calculate original overall confidence score and quality metrics (if analytics processing enabled)
    if enable_analytics_processing:
        try:
            from quality_metrics import AdvancedQualityAnalyzer
            quality_analyzer = AdvancedQualityAnalyzer()
            
            original_confidence_score = calculate_confidence(segments)
            original_quality_metrics = quality_analyzer.analyze_comprehensive_quality(result, audio_path)
            
            logger.info(f"Analytics processing: Quality metrics calculated successfully")
            if 'teaching_patterns' in original_quality_metrics:
                teaching_patterns = original_quality_metrics['teaching_patterns']
                logger.info(f"Teaching patterns detected: {teaching_patterns.get('summary', {}).get('pattern_strength', 'Unknown')}")
        except ImportError as e:
            logger.warning(f"Advanced quality analyzer not available: {e}")
            original_confidence_score = calculate_confidence(segments)
            original_quality_metrics = calculate_comprehensive_quality_metrics(result, segments, result.get('word_segments', []))
        except Exception as e:
            logger.error(f"Error in advanced quality analysis: {e}")
            original_confidence_score = calculate_confidence(segments)
            original_quality_metrics = calculate_comprehensive_quality_metrics(result, segments, result.get('word_segments', []))
        
        # Store original metrics for comparison
        result["original_metrics"] = {
            "confidence_score": original_confidence_score,
            "quality_metrics": original_quality_metrics,
            "calculated_before_enhancement": True,
            "note": "These metrics reflect the raw transcription quality before guitar term enhancement"
        }
        
        logger.info(f"Original transcription metrics - Confidence: {original_confidence_score:.3f}, "
                   f"Overall quality: {original_quality_metrics.get('overall_quality_score', 0):.3f}")
    else:
        # Skip analytics processing - calculate basic confidence only
        original_confidence_score = calculate_confidence(segments)
        logger.info(f"Analytics processing disabled - Basic confidence: {original_confidence_score:.3f}")
    
    # Guitar terminology evaluation and confidence boosting
    # Re-enabled: LLM endpoint should be working now
    enable_guitar_term_evaluation = preset_config.get('enable_guitar_term_evaluation', True) if preset_config else True
    
    if enable_guitar_term_evaluation:
        try:
            from guitar_term_evaluator import enhance_guitar_terminology
            logger.info("Starting guitar terminology evaluation and confidence enhancement...")
            
            # Apply guitar terminology enhancement (sets musical terms to 100% confidence)
            result = enhance_guitar_terminology(result)
            
            # Log the enhancement results
            if 'guitar_term_evaluation' in result:
                eval_data = result['guitar_term_evaluation']
                logger.info(f"Guitar terminology enhancement completed: "
                           f"{eval_data.get('musical_terms_found', 0)} musical terms enhanced "
                           f"out of {eval_data.get('total_words_evaluated', 0)} words evaluated")
            else:
                logger.warning("Guitar terminology enhancement completed but no evaluation data found")
            
        except ImportError as e:
            logger.warning(f"Guitar terminology evaluator not available: {e} - skipping enhancement")
        except Exception as e:
            logger.error(f"Guitar terminology enhancement failed: {e} - continuing without enhancement")
    else:
        logger.info("Guitar terminology evaluation temporarily disabled to resolve LLM endpoint issue")
    
    # RECALCULATE: Overall confidence score and quality metrics AFTER guitar term enhancement
    # This ensures the enhanced guitar term scores are properly reflected in the final metrics
    segments = result.get("segments", [])
    word_segments = result.get("word_segments", [])
    
    # Recalculate segment-level confidence scores with enhanced word scores
    for segment in segments:
        segment_words = segment.get('words', [])
        if segment_words:
            # Calculate segment confidence as average of word confidences (now includes enhanced scores)
            word_scores = [word.get('score', 0.0) for word in segment_words if word.get('score') is not None]
            if word_scores:
                segment['confidence'] = sum(word_scores) / len(word_scores)
            else:
                segment['confidence'] = 0.0
        else:
            # Fallback: estimate from text quality if no word-level data
            text_length = len(segment.get('text', ''))
            segment['confidence'] = min(0.8, max(0.3, text_length / 100.0))
    
    # Calculate enhanced overall confidence score and quality metrics (if analytics processing enabled)
    enhanced_confidence_score = calculate_confidence(segments)
    
    if enable_analytics_processing:
        try:
            enhanced_quality_metrics = quality_analyzer.analyze_comprehensive_quality(result, audio_path)
            
            logger.info(f"Enhanced analytics processing: Quality metrics calculated successfully")
            if 'teaching_patterns' in enhanced_quality_metrics:
                teaching_patterns = enhanced_quality_metrics['teaching_patterns']
                logger.info(f"Enhanced teaching patterns: {teaching_patterns.get('summary', {}).get('pattern_strength', 'Unknown')}")
        except Exception as e:
            logger.error(f"Error in enhanced quality analysis: {e}")
            enhanced_quality_metrics = calculate_comprehensive_quality_metrics(result, segments, result.get('word_segments', []))
        
        # Set the final metrics (these are the enhanced versions)
        result["confidence_score"] = enhanced_confidence_score
        result["quality_metrics"] = enhanced_quality_metrics
        
        # COMPARISON: Create enhancement comparison summary
        original_metrics = result.get("original_metrics", {})
        original_confidence = original_metrics.get("confidence_score", 0)
        original_overall_quality = original_metrics.get("quality_metrics", {}).get("overall_quality_score", 0)
        
        confidence_improvement = enhanced_confidence_score - original_confidence
        quality_improvement = enhanced_quality_metrics.get('overall_quality_score', 0) - original_overall_quality
        
        # Add enhancement comparison to results
        result["enhancement_comparison"] = {
            "confidence_scores": {
                "original": original_confidence,
                "enhanced": enhanced_confidence_score,
                "improvement": confidence_improvement,
                "improvement_percentage": (confidence_improvement / max(0.001, original_confidence)) * 100
            },
            "overall_quality_scores": {
                "original": original_overall_quality,
                "enhanced": enhanced_quality_metrics.get('overall_quality_score', 0),
                "improvement": quality_improvement,
                "improvement_percentage": (quality_improvement / max(0.001, original_overall_quality)) * 100
            },
            "enhancement_applied": enable_guitar_term_evaluation and 'guitar_term_evaluation' in result,
            "guitar_terms_enhanced": result.get('guitar_term_evaluation', {}).get('musical_terms_found', 0) if 'guitar_term_evaluation' in result else 0
        }
        
        logger.info(f"ENHANCED metrics after guitar term enhancement - Confidence: {enhanced_confidence_score:.3f} (+{confidence_improvement:.3f}), "
                   f"Overall quality: {enhanced_quality_metrics.get('overall_quality_score', 0):.3f} (+{quality_improvement:.3f})")
    else:
        # Analytics processing disabled - set basic metrics only
        result["confidence_score"] = enhanced_confidence_score
        result["quality_metrics"] = {
            "analytics_processing": "disabled",
            "basic_confidence_calculated": True,
            "note": "Full quality metrics require analytics processing to be enabled"
        }
        logger.info(f"Final confidence (analytics disabled): {enhanced_confidence_score:.3f}")
    
    # Log the improvement if guitar terms were enhanced
    if enable_guitar_term_evaluation and 'guitar_term_evaluation' in result:
        eval_data = result['guitar_term_evaluation']
        guitar_terms_count = eval_data.get('musical_terms_found', 0)
        if guitar_terms_count > 0:
            logger.info(f"Quality improvement summary: {guitar_terms_count} guitar terms boosted to 100% confidence, "
                       f"overall confidence improved by {confidence_improvement:.1%} "
                       f"({original_confidence:.3f} → {enhanced_confidence_score:.3f})")
    
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
    
    logger.info(f"WhisperX post-processing completed - "
               f"Final Confidence: {enhanced_confidence_score:.3f}, Alignment: {alignment_status}, "
               f"Diarization: {diarization_status}, Profile: {performance_profile}")
    
    return result


def _process_audio_parallel(audio_paths: List[str], preset_config: Dict = None, preset_name: str = 'balanced', 
                           max_workers: int = 4, course_id: int = None) -> List[Dict]:
    """
    Process multiple audio files in parallel for batch transcription.
    
    This function provides significant throughput improvements by processing multiple
    audio files simultaneously using ThreadPoolExecutor. Each file gets the full
    WhisperX pipeline treatment (transcription + post-processing) while running
    in parallel.
    
    Args:
        audio_paths: List of audio file paths to process
        preset_config: Preset configuration dictionary
        preset_name: Name of the preset to use
        max_workers: Maximum number of parallel workers (default: 4)
        course_id: Optional course ID for tracking
        
    Returns:
        List of transcription results in the same order as input paths
    """
    
    if not audio_paths:
        logger.warning("No audio paths provided for parallel processing")
        return []
    
    if not preset_config:
        preset_config = get_preset_config(preset_name)
    
    logger.info(f"Starting parallel processing of {len(audio_paths)} files with {max_workers} workers")
    logger.info(f"Using preset: {preset_name} with model: {preset_config.get('model_name', 'unknown')}")
    
    start_time = time.time()
    results = []
    failed_files = []
    
    def process_single_file(audio_path: str, index: int) -> Tuple[int, Dict]:
        """Process a single audio file and return index with result."""
        try:
            # Extract segment ID from path if possible for better tracking
            segment_id = None
            try:
                filename = os.path.basename(audio_path)
                segment_id = os.path.splitext(filename)[0]
                if not segment_id.isdigit():
                    segment_id = None
            except:
                pass
            
            logger.info(f"Worker {index}: Processing {os.path.basename(audio_path)} (segment: {segment_id})")
            
            result = _process_audio_core(
                audio_path=audio_path,
                preset_config=preset_config,
                course_id=course_id,
                segment_id=segment_id,
                preset_name=preset_name
            )
            
            # Add batch processing metadata
            result["batch_metadata"] = {
                "batch_index": index,
                "audio_path": audio_path,
                "worker_id": index,
                "processed_in_parallel": True,
                "batch_preset": preset_name
            }
            
            logger.info(f"Worker {index}: Completed {os.path.basename(audio_path)} - "
                       f"Confidence: {result.get('confidence_score', 0):.3f}")
            
            return (index, result)
            
        except Exception as e:
            error_msg = f"Worker {index}: Failed processing {audio_path}: {str(e)}"
            logger.error(error_msg)
            
            # Return error result to maintain order
            error_result = {
                "error": True,
                "error_message": error_msg,
                "error_type": type(e).__name__,
                "audio_path": audio_path,
                "batch_metadata": {
                    "batch_index": index,
                    "audio_path": audio_path,
                    "worker_id": index,
                    "processed_in_parallel": True,
                    "batch_preset": preset_name,
                    "failed": True
                }
            }
            
            return (index, error_result)
    
    # Execute parallel processing using ThreadPoolExecutor
    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        # Submit all tasks
        future_to_index = {
            executor.submit(process_single_file, path, i): i 
            for i, path in enumerate(audio_paths)
        }
        
        # Collect results as they complete
        completed_results = {}
        
        for future in as_completed(future_to_index):
            try:
                index, result = future.result()
                completed_results[index] = result
                
                if result.get("error"):
                    failed_files.append(audio_paths[index])
                    
            except Exception as e:
                index = future_to_index[future]
                error_msg = f"Unexpected error in worker {index}: {str(e)}"
                logger.error(error_msg)
                
                completed_results[index] = {
                    "error": True,
                    "error_message": error_msg,
                    "error_type": "UnexpectedError",
                    "audio_path": audio_paths[index],
                    "batch_metadata": {
                        "batch_index": index,
                        "audio_path": audio_paths[index],
                        "failed": True
                    }
                }
                failed_files.append(audio_paths[index])
    
    # Reassemble results in original order
    results = [completed_results[i] for i in range(len(audio_paths))]
    
    # Calculate batch statistics
    total_time = time.time() - start_time
    successful_count = len(audio_paths) - len(failed_files)
    
    # Calculate average confidence for successful transcriptions
    successful_results = [r for r in results if not r.get("error")]
    avg_confidence = 0.0
    if successful_results:
        total_confidence = sum(r.get('confidence_score', 0) for r in successful_results)
        avg_confidence = total_confidence / len(successful_results)
    
    # Add batch summary to all results
    batch_summary = {
        "total_files": len(audio_paths),
        "successful_files": successful_count,
        "failed_files": len(failed_files),
        "success_rate": successful_count / len(audio_paths) if audio_paths else 0,
        "total_processing_time": total_time,
        "average_time_per_file": total_time / len(audio_paths) if audio_paths else 0,
        "parallel_workers_used": max_workers,
        "average_confidence": avg_confidence,
        "preset_used": preset_name,
        "model_used": preset_config.get('model_name', 'unknown'),
        "failed_file_paths": failed_files
    }
    
    # Add summary to each result
    for result in results:
        result["batch_summary"] = batch_summary
    
    logger.info(f"Parallel processing completed in {total_time:.2f}s - "
               f"Success: {successful_count}/{len(audio_paths)} files "
               f"({batch_summary['success_rate']:.1%}), "
               f"Avg confidence: {avg_confidence:.3f}, "
               f"Speedup: ~{max_workers}x")
    
    if failed_files:
        logger.warning(f"Failed to process {len(failed_files)} files: {failed_files}")
    
    return results


def _process_audio_core(audio_path, model_name="base", initial_prompt=None, preset_config=None, course_id=None, segment_id=None, preset_name=None):
    """
    Core audio processing function (streamlined for batch processing readiness).
    
    This function now focuses solely on:
    1. Loading the model
    2. Performing the initial transcription
    3. Calling _run_post_processing with the initial result
    4. Wrapping the final result with performance metrics and returning it
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
        
        # ENHANCED: Use dynamic template rendering with course/segment context
        # This enables product names, instructor names, and course titles to be dynamically injected
        template_context = {}
        if course_id or segment_id:
            logger.info(f"PROMPT DEBUG: Using dynamic template rendering for course_id={course_id}, segment_id={segment_id}")
            effective_initial_prompt, template_context = render_template_prompt(
                preset_name or 'balanced', 
                course_id=course_id, 
                segment_id=segment_id,
                return_context=True
            )
            logger.info(f"PROMPT DEBUG: Dynamic template prompt length: {len(effective_initial_prompt)} characters")
            logger.info(f"PROMPT DEBUG: Template context: {template_context}")
        else:
            # Fallback to static preset for segments without course/segment context
            effective_initial_prompt = preset_config.get('initial_prompt', '')
            logger.info(f"PROMPT DEBUG: Using static preset prompt (no course/segment context available)")
            
        # CRITICAL DEBUG: Log the actual prompt being used
        logger.info(f"PROMPT DEBUG: Final effective_initial_prompt preview: '{effective_initial_prompt[:150]}{'...' if len(effective_initial_prompt) > 150 else ''}'")
        logger.info(f"PROMPT DEBUG: Final effective_initial_prompt length: {len(effective_initial_prompt)} characters")
            
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
        
        # ENHANCED: Check for audio content and detect pure music/performance videos
        audio_duration = len(audio_file) / 16000.0  # Assuming 16kHz sample rate
        audio_rms = np.sqrt(np.mean(audio_file**2))  # Root mean square for audio level
        
        # Enhanced music detection using multiple audio characteristics
        is_likely_music = _detect_musical_content(audio_file, audio_duration, audio_rms)
        
        logger.info(f"Audio analysis - Duration: {audio_duration:.2f}s, RMS level: {audio_rms:.6f}, Music likelihood: {is_likely_music}")
        
        # REFINED: Only prevent transcription for truly pure instrumental content
        # Use much stricter criteria - only videos with absolutely no meaningful speech
        if (is_likely_music['is_music'] and 
            is_likely_music['confidence'] > 0.98 and  # Extremely high threshold
            is_likely_music['characteristics'].get('speech_frequency_ratio', 1.0) < 0.05 and  # Almost no speech
            audio_duration > 45 and  # Longer videos only
            audio_rms > 0.01):  # Must have audible content
            logger.info(f"🎵 PURE INSTRUMENTAL DETECTED: {is_likely_music['reason']} (confidence: {is_likely_music['confidence']:.2f})")
            logger.info("Creating clean minimal transcript for pure instrumental content")
            
            # Create clean performance video transcript
            performance_result = {
                'segments': [{
                    'start': 0.0,
                    'end': audio_duration,
                    'text': '[Instrumental Performance]',
                    'confidence': 1.0,
                    'words': [{
                        'word': '[Instrumental',
                        'start': 0.0,
                        'end': audio_duration / 2,
                        'score': 1.0
                    }, {
                        'word': 'Performance]',
                        'start': audio_duration / 2,
                        'end': audio_duration,
                        'score': 1.0
                    }]
                }],
                'text': '[Instrumental Performance]',
                'language': effective_language,
                'performance_video_metadata': {
                    'auto_generated': True,
                    'detection_reason': is_likely_music['reason'],
                    'detection_confidence': is_likely_music['confidence'],
                    'characteristics': is_likely_music['characteristics']
                }
            }
            
            # Add required metadata
            performance_result["settings"] = settings
            performance_result["model_metadata"] = {"model_name": effective_model_name, "language": effective_language}
            performance_result["confidence_score"] = 1.0
            performance_result["performance_metrics"] = {
                'transcription_time': time.time() - transcription_step_start,
                'alignment_time': 0,
                'diarization_time': 0,
                'total_processing_time': time.time() - processing_start_time
            }
            
            return performance_result
        
        # Handle very quiet or silent audio (common in performance videos with minimal speech)
        if audio_rms < 0.001:  # Very quiet audio
            logger.warning(f"Very quiet audio detected (RMS: {audio_rms:.6f}) - this may be a performance video with minimal speech")
        
        # Handle very short audio
        if audio_duration < 1.0:
            logger.warning(f"Very short audio detected ({audio_duration:.2f}s) - may not contain meaningful speech")
            
        # Log audio characteristics for debugging
        logger.info(f"Audio characteristics: duration={audio_duration:.2f}s, RMS={audio_rms:.6f}, shape={audio_file.shape}")
        logger.info(f"Music detection result: {is_likely_music}")
        
        # WhisperX 3.3.4+ compatibility: Base parameters without hotwords for FasterWhisperPipeline
        # Hotwords will be handled by our hybrid approach using direct WhisperModel access
        transcribe_params = {
            'batch_size': batch_size,
            'language': effective_language,
            'chunk_size': chunk_size
        }
        
        # Prepare musical hotwords for enhanced transcription
        musical_hotwords = []
        if effective_initial_prompt:
            # Convert comprehensive prompt to targeted hotwords for musical terminology
            musical_hotwords = [
                # Core musical chords that often get misrecognized
                "chord", "C major chord", "D minor chord", "E7 chord", "F sharp chord", 
                "G sus4 chord", "A minor 7 chord", "B flat chord",
                
                # Musical notes with proper spelling
                "C sharp", "D flat", "F sharp", "B flat", "A flat", "E flat", "G sharp",
                
                # Guitar hardware and anatomy
                "fretboard", "fingerpicking", "capo", "pickup", "tremolo", "whammy bar",
                "nut", "bridge", "saddle", "soundhole", "tuning pegs",
                
                # Guitar techniques that get misrecognized  
                "hammer-on", "pull-off", "string bending", "vibrato", "palm muting", 
                "harmonics", "slide", "legato", "staccato", "tapping",
                
                # Music theory terms
                "pentatonic", "major scale", "minor scale", "blues scale", 
                "Dorian", "Mixolydian", "Ionian", "Aeolian",
                
                # Common misrecognitions we want to fix
                "fret", "string", "pick", "strum", "tune", "practice"
            ]
            
            logger.info(f"Musical hotwords prepared: {len(musical_hotwords)} terms for enhanced guitar lesson accuracy")
        
        logger.info(f"Using base transcribe parameters: {list(transcribe_params.keys())}")
        
        # ENHANCED APPROACH: Use WhisperModel directly with configurable enhancement strategy
        # while keeping WhisperX for alignment and post-processing
        
        # Determine enhancement mode based on preset or configuration
        enhancement_mode = 'prompt_only'  # Testing: Focus on initial_prompt effectiveness
        if preset_config:
            # Could add enhancement_mode to preset config in future
            performance_profile = preset_config.get('performance_profile', 'balanced')
            if performance_profile == 'speed_optimized':
                enhancement_mode = 'hotwords_only'  # Faster, targeted approach
            elif performance_profile == 'maximum_quality':
                enhancement_mode = 'adaptive'  # Let system decide best approach
            else:
                enhancement_mode = 'prompt_only'  # Focus on comprehensive context
        
        try:
            logger.info(f"Step 1a: Attempting enhanced transcription with direct WhisperModel access (mode: {enhancement_mode})")
            result = transcribe_with_enhanced_features(
                model, audio_file, transcribe_params, 
                effective_initial_prompt, musical_hotwords, effective_language, enhancement_mode
            )
            logger.info(f"Enhanced transcription completed successfully using {enhancement_mode} mode")
        except Exception as enhanced_error:
            logger.warning(f"Enhanced transcription failed ({type(enhanced_error).__name__}): {enhanced_error}")
            logger.info("Step 1b: Falling back to standard WhisperX FasterWhisperPipeline")
            
            # Fallback to original method without hotwords
            fallback_params = {
                'batch_size': batch_size,
                'language': effective_language,
                'chunk_size': chunk_size
            }
            logger.info(f"Using fallback transcribe parameters: {list(fallback_params.keys())}")
            result = model.transcribe(audio_file, **fallback_params)
        
        performance_metrics['transcription_time'] = time.time() - transcription_step_start
        logger.info(f"Step 1: Transcription completed in {performance_metrics['transcription_time']:.2f}s")
        
        # ENHANCED: Validate transcription result before post-processing
        if not result or not isinstance(result, dict):
            logger.error("Transcription returned invalid result - creating minimal fallback")
            result = {
                'segments': [],
                'text': '[No speech content detected]',
                'language': effective_language
            }
            
        # Handle empty or minimal transcription results
        segments = result.get('segments', [])
        if not segments:
            logger.warning("No speech segments detected - this may be a performance video")
            # Create a minimal segment for performance videos
            if audio_duration > 0:
                result['segments'] = [{
                    'start': 0.0,
                    'end': audio_duration,
                    'text': '[Instrumental Performance]',
                    'confidence': 1.0
                }]
                result['text'] = '[Instrumental Performance]'
        
        # CONFIDENCE-BASED VALIDATION: Check transcription quality using confidence scores
        # This is much safer than pattern-based gibberish cleanup for large-scale processing
        confidence_validation = _validate_transcription_confidence(result, preset_config)
        result['confidence_validation'] = confidence_validation
        
        # OPTIONAL GIBBERISH CLEANUP: Only apply if explicitly enabled in preset
        # Disabled by default for large-scale safety
        enable_gibberish_cleanup = preset_config.get('enable_gibberish_cleanup', False) if preset_config else False
        
        if enable_gibberish_cleanup and segments:
            if is_likely_music.get('is_music', False):
                logger.info("🎵 Musical content detected - applying intelligent gibberish cleanup (enabled in preset)")
                result = _clean_musical_gibberish(result, is_likely_music)
            else:
                # Even for non-musical content, clean up obvious corruption patterns
                logger.debug("Applying light gibberish cleanup for general content (enabled in preset)")
                result = _clean_musical_gibberish(result, {'is_music': False, 'confidence': 0.0})
        elif segments:
            logger.info("🛡️ Gibberish cleanup disabled for large-scale safety - using confidence validation instead")
            
        # Run all post-processing steps using the new dedicated function
        logger.info("Step 2: Starting post-processing (alignment, diarization, enhancement, metrics)")
        try:
            # Check if analytics processing should be enabled (default: True for backward compatibility)
            enable_analytics = preset_config.get('enable_analytics_processing', True) if preset_config else True
            
            result = _run_post_processing(
                transcription_result=result,
                audio_file=audio_file,
                audio_path=audio_path,
                preset_config=preset_config,
                performance_metrics=performance_metrics,
                effective_language=effective_language,
                min_speakers=min_speakers,
                max_speakers=max_speakers,
                preset_name=preset_name,
                enable_analytics_processing=enable_analytics
            )
        except Exception as post_processing_error:
            logger.error(f"Post-processing failed: {post_processing_error}")
            # Continue with basic result if post-processing fails
            logger.info("Continuing with basic transcription result due to post-processing failure")
            
            # Add minimal required fields
            if 'confidence_score' not in result:
                result['confidence_score'] = 0.5  # Default confidence
            if 'quality_metrics' not in result:
                result['quality_metrics'] = {'overall_quality_score': 0.5}
        
        # Calculate total processing time
        performance_metrics['total_processing_time'] = time.time() - processing_start_time
        
        # Include comprehensive settings and metadata in result
        # DEBUG: Log what we're putting in settings before saving
        logger.info(f"SETTINGS DEBUG: About to save settings with initial_prompt length: {len(settings.get('initial_prompt', ''))}")
        logger.info(f"SETTINGS DEBUG: Settings initial_prompt preview: '{settings.get('initial_prompt', '')[:100]}{'...' if len(settings.get('initial_prompt', '')) > 100 else ''}'")
        
        # Add template context to settings for frontend display
        if template_context:
            settings["template_context"] = template_context
            logger.info(f"SETTINGS DEBUG: Added template context to settings: {template_context}")
        
        result["settings"] = settings
        result["model_metadata"] = model_metadata
        result["performance_metrics"] = performance_metrics
        
        # TRANSPARENCY: Enhanced features tracking for hybrid approach
        result["enhanced_features_used"] = {
            "hotwords_applied": musical_hotwords if musical_hotwords else [],
            "initial_prompt_applied": effective_initial_prompt if effective_initial_prompt else "",
            "enhancement_method": "direct_whisper_model" if "Enhanced transcription completed successfully" in str(result.get('debug_info', '')) else "whisperx_fallback",
            "total_hotwords_count": len(musical_hotwords) if musical_hotwords else 0
        }
        result["preset_name"] = preset_name if preset_name else None
        result["whisperx_version"] = "3.3.4+"
        
        logger.info(f"WhisperX processing completed in {performance_metrics['total_processing_time']:.2f}s")
        
        return result
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"WhisperX processing failed ({error_type}): {error_msg}")
        
        # Enhanced error categorization for better debugging
        if "libcudnn" in error_msg.lower() or "cuda" in error_msg.lower():
            logger.error("CRITICAL: CUDA library issue detected - missing CUDA dependencies")
        elif "out of memory" in error_msg.lower():
            logger.error("CRITICAL: GPU memory exhausted - consider reducing batch_size")
        elif "timeout" in error_msg.lower():
            logger.error("CRITICAL: Processing timeout - audio file may be too large")
        elif "hotwords" in error_msg.lower():
            logger.error("CRITICAL: Hotwords parameter issue - check WhisperX version compatibility")
        
        # Fallback to basic transcription if WhisperX fails
        logger.info("Attempting fallback to basic WhisperX transcription...")
        try:
            model, model_metadata = load_whisperx_model(effective_model_name, effective_language)
            audio_file = whisperx.load_audio(str(audio_path))
            
            # Try enhanced transcription first in fallback mode too
            try:
                logger.info("Fallback: Attempting enhanced transcription with direct WhisperModel access")
                # Simplified hotwords list for fallback
                fallback_hotwords = [
                    "chord", "C sharp", "fretboard", "fingerpicking", "capo", 
                    "hammer-on", "pull-off", "pentatonic", "major scale"
                ]
                
                # Use prompt_only mode for fallback to maximize context effectiveness
                fallback_enhancement_mode = 'prompt_only'
                
                result = transcribe_with_enhanced_features(
                    model, audio_file, {}, 
                    effective_initial_prompt, fallback_hotwords, effective_language, fallback_enhancement_mode
                )
                logger.info(f"Fallback enhanced transcription completed successfully using {fallback_enhancement_mode} mode")
                
            except Exception as fallback_enhanced_error:
                logger.warning(f"Fallback enhanced transcription failed: {fallback_enhanced_error}")
                logger.info("Fallback: Using basic FasterWhisperPipeline without enhanced features")
                
                # Final fallback to basic WhisperX without any enhancements
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
            
            # TRANSPARENCY: Enhanced features tracking for fallback mode
            fallback_hotwords = [
                "chord", "C sharp", "fretboard", "fingerpicking", "capo", 
                "hammer-on", "pull-off", "pentatonic", "major scale"
            ]
            result["enhanced_features_used"] = {
                "hotwords_applied": fallback_hotwords if effective_initial_prompt else [],
                "initial_prompt_applied": effective_initial_prompt if effective_initial_prompt else "",
                "enhancement_method": "fallback_enhanced" if effective_initial_prompt else "basic_fallback",
                "total_hotwords_count": len(fallback_hotwords) if effective_initial_prompt else 0
            }
            result["preset_name"] = preset_name if preset_name else None
            result["whisperx_version"] = "3.3.4+"
            
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
            if "libcudnn" in error_msg.lower() or "libcudnn" in fallback_error_msg.lower():
                comprehensive_error["troubleshooting_hints"].append("CUDA library missing: Install libcudnn8 and related CUDA dependencies")
            if "out of memory" in error_msg.lower() or "out of memory" in fallback_error_msg.lower():
                comprehensive_error["troubleshooting_hints"].append("GPU memory issue: Reduce batch_size or use CPU processing")
            if "hotwords" in error_msg.lower() or "hotwords" in fallback_error_msg.lower():
                comprehensive_error["troubleshooting_hints"].append("WhisperX hotwords compatibility: Verify WhisperX 3.3.3+ installation for hotwords support")
            
            raise Exception(f"Complete WhisperX processing failure. Primary: {error_msg}. Fallback: {fallback_error_msg}. Details: {comprehensive_error}")

def _clean_musical_gibberish(result, music_detection_result):
    """
    Intelligently clean up gibberish from transcription while preserving legitimate musical terms.
    
    This function identifies and removes corrupted musical terms that result from 
    forcing speech recognition on instrumental music, while keeping real musical terminology.
    """
    import re
    
    if not result or not result.get('segments'):
        return result
    
    # Define legitimate musical terms that should be preserved
    legitimate_musical_terms = {
        # Chord names and qualities
        'chord', 'chords', 'major', 'minor', 'dominant', 'diminished', 'augmented',
        'sus2', 'sus4', 'add9', 'maj7', 'min7', 'dom7', '6th', '9th', '11th', '13th',
        
        # Note names
        'a', 'b', 'c', 'd', 'e', 'f', 'g',
        'sharp', 'flat', 'natural',
        
        # Common musical terms
        'scale', 'scales', 'key', 'tempo', 'rhythm', 'melody', 'harmony',
        'progression', 'cadence', 'resolution', 'tension',
        
        # Guitar-specific terms
        'fret', 'frets', 'string', 'strings', 'pick', 'strum', 'fingerpick',
        'capo', 'bridge', 'nut', 'tuning', 'pickup', 'amp', 'effect',
        'hammer', 'pull', 'bend', 'slide', 'vibrato', 'mute', 'harmonic'
    }
    
    # Pattern for corrupted musical terms (gibberish indicators)
    # Made much more conservative to avoid removing legitimate English words
    gibberish_patterns = [
        r'\w*ariest\w*',        # "majorariest" - very specific corruption pattern
        r'\w*plup\w*',          # "plupab" - specific nonsense pattern
        r'major\w*ab$',         # "majorab", "majorApplauseab" - very specific to musical corruption
        r'minor\w*ab$',         # "minorab" - very specific to musical corruption  
        r'[A-Z][a-z]*\*\w*',    # Words with asterisks (like "(*ab")
        r'\w*�\w*',             # Words with encoding artifacts
        r'^[bcdfghjklmnpqrstvwxyz]{3,}$', # Consonant-only nonsense (exclude vowels and common single letters)
        r'\w*applausea?b\w*',   # Very specific "applauseab" corruptions
        # Removed the overly broad r'\b\w{8,}\b' pattern that was removing legitimate long words
    ]
    
    # Confidence threshold for suspicious musical terms
    suspicious_confidence_threshold = 0.6
    
    cleaned_segments = []
    total_words_removed = 0
    gibberish_words_found = []
    
    for segment in result['segments']:
        if not segment.get('words'):
            cleaned_segments.append(segment)
            continue
            
        cleaned_words = []
        segment_text_parts = []
        
        for word_info in segment['words']:
            word = word_info.get('word', '').strip()
            confidence = word_info.get('score', word_info.get('probability', 1.0))
            
            # Skip empty words
            if not word:
                continue
                
            word_lower = word.lower().strip('.,!?;:"()[]{}')
            
            # Check if this word is gibberish
            is_gibberish = False
            gibberish_reason = None
            
            # 1. Pattern-based gibberish detection
            for pattern in gibberish_patterns:
                if re.search(pattern, word, re.IGNORECASE):
                    is_gibberish = True
                    gibberish_reason = f"pattern:{pattern}"
                    break
            
            # 2. Low-confidence musical-sounding words (made much more conservative)
            if not is_gibberish and confidence < suspicious_confidence_threshold:
                # Check if it sounds like a corrupted musical term
                # Made MUCH more conservative - only flag if it's clearly a corrupted musical term
                # AND word is very low confidence AND contains nonsense patterns
                for musical_term in legitimate_musical_terms:
                    if (musical_term in word_lower and 
                        len(word_lower) > len(musical_term) + 4 and  # Much longer than original + 4 chars
                        confidence < 0.4 and  # Very low confidence
                        (word_lower.endswith('ab') or word_lower.endswith('ariest') or 'plup' in word_lower)):
                        # Only flag if it has clear gibberish markers
                        is_gibberish = True
                        gibberish_reason = f"corrupted_musical_term:{musical_term}"
                        break
            
            # 3. Preserve legitimate musical terms even with low confidence
            if is_gibberish and word_lower in legitimate_musical_terms:
                is_gibberish = False
                gibberish_reason = "preserved_legitimate_term"
            
            # 4. Preserve common English words (greatly expanded list)
            common_words = {
                # Basic words
                'the', 'and', 'or', 'but', 'to', 'of', 'in', 'on', 'at', 'by', 'for', 
                'with', 'from', 'we', 'you', 'he', 'she', 'it', 'they', 'this', 'that',
                'here', 'there', 'now', 'then', 'will', 'be', 'back', 'right', 'left',
                
                # Common nouns that might be misidentified
                'names', 'name', 'proper', 'signatures', 'signature', 'time', 'times', 'people',
                'person', 'place', 'places', 'thing', 'things', 'way', 'ways', 'part', 'parts',
                'number', 'numbers', 'system', 'systems', 'work', 'works', 'life', 'lives',
                'hand', 'hands', 'eye', 'eyes', 'day', 'days', 'week', 'weeks', 'year', 'years',
                'world', 'country', 'countries', 'state', 'states', 'company', 'companies',
                'group', 'groups', 'business', 'government', 'public', 'school', 'schools',
                
                # Common adjectives
                'good', 'great', 'small', 'large', 'big', 'long', 'short', 'high', 'low',
                'important', 'different', 'possible', 'available', 'necessary', 'special',
                'certain', 'clear', 'whole', 'white', 'black', 'red', 'blue', 'green',
                'young', 'old', 'new', 'early', 'late', 'real', 'best', 'better', 'simple',
                
                # Common verbs
                'have', 'has', 'had', 'do', 'does', 'did', 'get', 'got', 'make', 'made',
                'take', 'took', 'come', 'came', 'go', 'went', 'see', 'saw', 'know', 'knew',
                'think', 'thought', 'look', 'looked', 'use', 'used', 'find', 'found',
                'give', 'gave', 'tell', 'told', 'ask', 'asked', 'work', 'worked',
                'seem', 'seemed', 'feel', 'felt', 'try', 'tried', 'leave', 'left',
                'call', 'called', 'move', 'moved', 'play', 'played', 'turn', 'turned',
                
                # Time and numbers
                'first', 'second', 'third', 'last', 'next', 'previous', 'before', 'after',
                'during', 'while', 'until', 'since', 'today', 'tomorrow', 'yesterday',
                'morning', 'afternoon', 'evening', 'night', 'minute', 'minutes', 'hour', 'hours',
                
                # Common adverbs
                'very', 'really', 'just', 'only', 'also', 'well', 'still', 'even', 'much',
                'more', 'most', 'little', 'less', 'again', 'always', 'never', 'sometimes',
                'often', 'usually', 'probably', 'maybe', 'perhaps', 'almost', 'quite',
                
                # Other common words
                'about', 'over', 'under', 'through', 'between', 'among', 'around', 'against',
                'without', 'within', 'outside', 'inside', 'together', 'another', 'other',
                'each', 'every', 'all', 'some', 'any', 'many', 'few', 'several', 'both',
                'either', 'neither', 'nothing', 'everything', 'something', 'anything'
            }
            if is_gibberish and word_lower in common_words:
                is_gibberish = False
                gibberish_reason = "preserved_common_word"
            
            if is_gibberish:
                total_words_removed += 1
                gibberish_words_found.append({
                    'word': word,
                    'confidence': confidence,
                    'reason': gibberish_reason,
                    'timestamp': f"{word_info.get('start', 0):.2f}s"
                })
                logger.debug(f"Removed gibberish word: '{word}' (confidence: {confidence:.2f}, reason: {gibberish_reason})")
            else:
                cleaned_words.append(word_info)
                segment_text_parts.append(word)
        
        # Rebuild segment with cleaned words
        if cleaned_words:
            cleaned_segment = segment.copy()
            cleaned_segment['words'] = cleaned_words
            cleaned_segment['text'] = ' '.join(segment_text_parts)
            cleaned_segments.append(cleaned_segment)
        # Skip empty segments (all words were gibberish)
    
    # Update result with cleaned segments
    result['segments'] = cleaned_segments
    
    # Rebuild full text
    result['text'] = ' '.join(segment['text'] for segment in cleaned_segments if segment.get('text'))
    
    # Add cleanup metadata
    result['gibberish_cleanup'] = {
        'words_removed': total_words_removed,
        'gibberish_detected': gibberish_words_found,
        'cleanup_applied': True,
        'music_detection_triggered': music_detection_result.get('is_music', False),
        'music_confidence': music_detection_result.get('confidence', 0.0)
    }
    
    if total_words_removed > 0:
        logger.info(f"🧹 Gibberish cleanup: Removed {total_words_removed} corrupted words while preserving legitimate musical terms")
        
        # Log some examples of what was removed
        if gibberish_words_found:
            examples = gibberish_words_found[:3]  # Show first 3 examples
            example_words = [f"'{item['word']}'" for item in examples]
            logger.info(f"🗑️ Removed gibberish examples: {', '.join(example_words)}")
    
    return result

def _validate_transcription_confidence(result, preset_config=None):
    """
    Validate transcription quality using confidence scores instead of risky pattern matching.
    
    This approach is much safer for large-scale processing as it uses the model's own
    confidence metrics rather than trying to guess what words are gibberish.
    
    Args:
        result: Transcription result with segments and words
        preset_config: Optional preset configuration
        
    Returns:
        dict: {
            'overall_confidence': float,
            'low_confidence_ratio': float,
            'very_low_confidence_ratio': float,
            'quality_assessment': str,
            'is_likely_performance': bool,
            'recommendation': str,
            'confidence_distribution': dict,
            'word_count': int
        }
    """
    if not result or not result.get('segments'):
        return {
            'overall_confidence': 0.0,
            'low_confidence_ratio': 1.0,
            'very_low_confidence_ratio': 1.0,
            'quality_assessment': 'no_content',
            'is_likely_performance': True,
            'recommendation': 'No transcribable content found',
            'confidence_distribution': {},
            'word_count': 0
        }
    
    # Collect all word confidences
    all_confidences = []
    
    for segment in result.get('segments', []):
        words = segment.get('words', [])
        for word_info in words:
            confidence = word_info.get('score', word_info.get('probability', 0.0))
            if confidence is not None:
                all_confidences.append(float(confidence))
    
    if not all_confidences:
        return {
            'overall_confidence': 0.0,
            'low_confidence_ratio': 1.0,
            'very_low_confidence_ratio': 1.0,
            'quality_assessment': 'no_word_level_data',
            'is_likely_performance': True,
            'recommendation': 'No word-level confidence data available',
            'confidence_distribution': {},
            'word_count': 0
        }
    
    # Calculate confidence statistics
    import numpy as np
    
    confidences_array = np.array(all_confidences)
    overall_confidence = float(np.mean(confidences_array))
    median_confidence = float(np.median(confidences_array))
    
    # Define confidence thresholds
    very_low_threshold = 0.1   # 10% - User's suggestion
    low_threshold = 0.3        # 30%
    good_threshold = 0.7       # 70%
    
    # Calculate ratios
    very_low_count = np.sum(confidences_array < very_low_threshold)
    low_count = np.sum(confidences_array < low_threshold)
    good_count = np.sum(confidences_array >= good_threshold)
    
    total_words = len(all_confidences)
    very_low_ratio = very_low_count / total_words
    low_ratio = low_count / total_words
    good_ratio = good_count / total_words
    
    # Confidence distribution
    confidence_distribution = {
        'excellent': int(np.sum(confidences_array >= 0.9)),      # 90%+
        'good': int(np.sum((confidences_array >= 0.7) & (confidences_array < 0.9))),  # 70-90%
        'fair': int(np.sum((confidences_array >= 0.3) & (confidences_array < 0.7))),  # 30-70%
        'poor': int(np.sum((confidences_array >= 0.1) & (confidences_array < 0.3))),  # 10-30%
        'very_poor': int(np.sum(confidences_array < 0.1))        # <10%
    }
    
    # Quality assessment based on confidence patterns
    quality_assessment = 'unknown'
    is_likely_performance = False
    recommendation = 'Process normally'
    
    if very_low_ratio >= 0.8:  # 80%+ words have <10% confidence
        quality_assessment = 'very_poor_likely_performance'
        is_likely_performance = True
        recommendation = 'Likely instrumental performance - consider marking as performance video'
        
    elif very_low_ratio >= 0.5:  # 50%+ words have <10% confidence
        quality_assessment = 'poor_quality'
        is_likely_performance = True
        recommendation = 'Poor transcription quality - may be performance content or audio issues'
        
    elif low_ratio >= 0.7:  # 70%+ words have <30% confidence
        quality_assessment = 'questionable_quality'
        recommendation = 'Questionable transcription quality - review recommended'
        
    elif overall_confidence >= 0.7:
        quality_assessment = 'good_quality'
        recommendation = 'Good transcription quality'
        
    elif overall_confidence >= 0.5:
        quality_assessment = 'fair_quality'
        recommendation = 'Fair transcription quality'
        
    else:
        quality_assessment = 'poor_quality'
        recommendation = 'Poor transcription quality - consider re-processing'
    
    # Log confidence validation results
    logger.info(f"📊 Confidence validation: {quality_assessment} - {overall_confidence:.1%} avg confidence")
    logger.info(f"📊 Confidence breakdown: {very_low_ratio:.1%} very low (<10%), {low_ratio:.1%} low (<30%), {good_ratio:.1%} good (≥70%)")
    
    if is_likely_performance:
        logger.info(f"🎵 Performance video detected via confidence analysis: {very_low_ratio:.1%} of words <10% confidence")
    
    return {
        'overall_confidence': overall_confidence,
        'median_confidence': median_confidence,
        'low_confidence_ratio': low_ratio,
        'very_low_confidence_ratio': very_low_ratio,
        'good_confidence_ratio': good_ratio,
        'quality_assessment': quality_assessment,
        'is_likely_performance': is_likely_performance,
        'recommendation': recommendation,
        'confidence_distribution': confidence_distribution,
        'word_count': total_words,
        'confidence_thresholds': {
            'very_low': very_low_threshold,
            'low': low_threshold,
            'good': good_threshold
        }
    }

def _detect_musical_content(audio_file, duration, rms_level):
    """
    Detect if audio content is likely pure music/instrumental performance.
    
    Uses multiple audio characteristics to identify performance videos that should
    not be transcribed as speech to prevent gibberish word generation.
    
    Returns:
        dict: {
            'is_music': bool,
            'confidence': float (0.0-1.0),
            'reason': str,
            'characteristics': dict
        }
    """
    import numpy as np
    from scipy import signal
    
    try:
        # Initialize detection scores
        music_indicators = []
        characteristics = {}
        
        # 1. SPECTRAL ANALYSIS - Music has different frequency characteristics than speech
        # Speech typically concentrates energy in 85-255 Hz (fundamental) and 1700-4000 Hz (formants)
        # Music spreads energy more evenly across the spectrum
        
        # Simple FFT analysis
        fft = np.fft.fft(audio_file[:min(16000*10, len(audio_file))])  # First 10 seconds
        freqs = np.fft.fftfreq(len(fft), 1/16000)
        magnitude = np.abs(fft)
        
        # Focus on positive frequencies up to 8kHz
        positive_freqs = freqs[:len(freqs)//2]
        positive_magnitude = magnitude[:len(magnitude)//2]
        
        # Speech frequency bands
        speech_low = (positive_freqs >= 85) & (positive_freqs <= 255)    # Fundamental
        speech_high = (positive_freqs >= 1700) & (positive_freqs <= 4000) # Formants
        music_mid = (positive_freqs >= 300) & (positive_freqs <= 1500)   # Music richness
        harmonic_high = (positive_freqs >= 4000) & (positive_freqs <= 8000) # Harmonics
        
        speech_energy = np.sum(positive_magnitude[speech_low]) + np.sum(positive_magnitude[speech_high])
        music_energy = np.sum(positive_magnitude[music_mid]) + np.sum(positive_magnitude[harmonic_high])
        total_energy = np.sum(positive_magnitude)
        
        if total_energy > 0:
            speech_ratio = speech_energy / total_energy
            music_ratio = music_energy / total_energy
            characteristics['speech_frequency_ratio'] = float(speech_ratio)
            characteristics['music_frequency_ratio'] = float(music_ratio)
            
            # Music typically has lower speech frequency concentration
            # CONSERVATIVE: Much stricter criteria to avoid false positives
            if speech_ratio < 0.15 and music_ratio > 0.6:  # Very low speech, very high music
                music_indicators.append(('spectral_analysis', 0.5))  # Lower score
            elif speech_ratio < 0.1:  # Extremely low speech frequencies
                music_indicators.append(('very_low_speech_frequencies', 0.6))  # Lower score
        
        # 2. AMPLITUDE VARIATION ANALYSIS
        # Music often has more sustained notes and less abrupt amplitude changes than speech
        # Calculate amplitude variation using short-time windows
        window_size = int(16000 * 0.1)  # 100ms windows
        amplitude_vars = []
        for i in range(0, len(audio_file) - window_size, window_size):
            window = audio_file[i:i + window_size]
            amplitude_vars.append(np.var(window))
        
        if amplitude_vars:
            avg_amplitude_var = np.mean(amplitude_vars)
            characteristics['amplitude_variation'] = float(avg_amplitude_var)
            
            # Lower amplitude variation suggests sustained musical notes
            # CONSERVATIVE: Much stricter criteria
            if avg_amplitude_var < rms_level * 0.05:  # Extremely steady amplitude
                music_indicators.append(('sustained_amplitude', 0.4))  # Lower score
        
        # 3. HARMONIC CONTENT ANALYSIS
        # Music typically has more harmonic structure than speech
        # Simple autocorrelation to detect periodicity
        if len(audio_file) > 1600:  # Need sufficient samples
            # Autocorrelation of a short segment
            segment = audio_file[:min(16000, len(audio_file))]  # First second
            autocorr = np.correlate(segment, segment, mode='full')
            autocorr = autocorr[len(autocorr)//2:]
            
            # Look for peaks indicating harmonic content
            # Skip the zero-lag peak
            if len(autocorr) > 100:
                peaks, _ = signal.find_peaks(autocorr[50:], height=np.max(autocorr) * 0.1)
                harmonic_strength = len(peaks) / len(autocorr[50:]) * 1000  # Normalize
                characteristics['harmonic_content'] = float(harmonic_strength)
                
                if harmonic_strength > 4.0:  # Very strong harmonic content
                    music_indicators.append(('harmonic_structure', 0.4))  # Lower score
        
        # 4. DYNAMIC RANGE ANALYSIS
        # Music often has wider dynamic range than speech
        if len(audio_file) > 0:
            audio_db = 20 * np.log10(np.abs(audio_file) + 1e-10)
            dynamic_range = np.max(audio_db) - np.min(audio_db)
            characteristics['dynamic_range_db'] = float(dynamic_range)
            
            # Very wide dynamic range suggests music
            # CONSERVATIVE: Much higher threshold
            if dynamic_range > 80:  # Very wide dynamic range
                music_indicators.append(('wide_dynamic_range', 0.3))  # Lower score
        
        # 5. DURATION AND RMS ANALYSIS
        # Performance videos often have specific characteristics
        characteristics['duration'] = float(duration)
        characteristics['rms_level'] = float(rms_level)
        
        # Very long content with sustained audio level
        # CONSERVATIVE: Much stricter criteria
        if duration > 60 and rms_level > 0.02:  # Longer and more audible
            music_indicators.append(('long_sustained_content', 0.2))  # Lower score
        
        # More restrictive audio level range
        if 0.02 < rms_level < 0.2:  # Narrower typical music range
            music_indicators.append(('music_level_range', 0.2))  # Lower score
        
        # 6. COMBINE INDICATORS
        if music_indicators:
            # Calculate weighted confidence
            total_weight = sum(weight for _, weight in music_indicators)
            confidence = min(1.0, total_weight)
            
            # Primary reason is the strongest indicator
            primary_reason = max(music_indicators, key=lambda x: x[1])[0]
            
            # Decision threshold
            is_music = confidence > 0.8  # Very conservative threshold
            
            # Build detailed reason
            indicator_names = [name for name, _ in music_indicators]
            reason = f"Primary: {primary_reason}, Indicators: {', '.join(indicator_names)}"
            
            return {
                'is_music': is_music,
                'confidence': confidence,
                'reason': reason,
                'characteristics': characteristics
            }
        else:
            return {
                'is_music': False,
                'confidence': 0.0,
                'reason': 'No clear music indicators detected',
                'characteristics': characteristics
            }
            
    except Exception as e:
        logger.warning(f"Music detection failed: {e}")
        return {
            'is_music': False,
            'confidence': 0.0,
            'reason': f'Detection error: {str(e)}',
            'characteristics': {'error': str(e)}
        }

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

def ensure_json_serializable(obj):
    """Ensure all values in object are JSON serializable, converting numpy types to Python types."""
    import numpy as np
    
    if isinstance(obj, dict):
        return {k: ensure_json_serializable(v) for k, v in obj.items()}
    elif isinstance(obj, list):
        return [ensure_json_serializable(item) for item in obj]
    elif isinstance(obj, np.floating):
        return float(obj)
    elif isinstance(obj, np.integer):
        return int(obj)
    elif isinstance(obj, np.ndarray):
        return obj.tolist()
    elif hasattr(obj, 'item'):  # Handle numpy scalars
        return obj.item()
    else:
        return obj

def update_job_status(job_id, status, response_data=None, error_message=None):
    """Update the job status in Laravel."""
    try:
        url = f"{LARAVEL_API_URL}/transcription/{job_id}/status"
        logger.info(f"Sending status update to Laravel: {url}")
        
        # Ensure all data is JSON serializable before sending
        safe_response_data = ensure_json_serializable(response_data) if response_data else None
        
        payload = {
            'status': status,
            'response_data': safe_response_data,
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
        
        # Extract course_id from path (e.g., "truefire-courses/1/7959.wav" -> course_id=1)
        course_id = None
        path_parts = audio_path_param.split('/')
        if 'truefire-courses' in path_parts:
            truefire_index = path_parts.index('truefire-courses')
            if len(path_parts) > truefire_index + 1:
                try:
                    course_id = int(path_parts[truefire_index + 1])
                except ValueError:
                    course_id = None
        
        # Define output file paths in the same directory as audio file
        transcript_path = os.path.join(audio_dir, f'{segment_id}_transcript.txt')
        srt_path = os.path.join(audio_dir, f'{segment_id}_transcript.srt')
        json_path = os.path.join(audio_dir, f'{segment_id}_transcript.json')
        
        audio_path = full_audio_path
        logger.info(f"Using segment-based storage - Audio: {audio_path}, Segment ID: {segment_id}, Course ID: {course_id}")
        
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
        # Prefer data values, but fall back to extracted values from path
        data_course_id = data.get('course_id')
        data_segment_id = data.get('segment_id')
        
        # Use data values if provided, otherwise use extracted values from path
        final_course_id = data_course_id if data_course_id else course_id
        final_segment_id = data_segment_id if data_segment_id else segment_id
        
        # Check for intelligent selection parameter (enabled by default for internal tool)
        enable_intelligent_selection = data.get('enable_intelligent_selection', True)
        
        # Check for optimal selection mode (disabled by default for speed)
        enable_optimal_selection = data.get('enable_optimal_selection', False)
        
        # Check for analytics processing parameter (enabled by default for backward compatibility)
        enable_analytics_processing = data.get('enable_analytics_processing', True)
        
        # Add analytics processing to preset config
        if preset_config:
            preset_config['enable_analytics_processing'] = enable_analytics_processing
        
        # Process the audio with Whisper (using preset config or legacy parameters)
        # Enhanced error handling for minimal speech content
        transcription_result = process_audio(
            audio_path, 
            model_name, 
            initial_prompt, 
            preset_config,
            course_id=final_course_id,
            segment_id=final_segment_id,
            preset_name=preset_name,
            enable_intelligent_selection=enable_intelligent_selection,
            enable_optimal_selection=enable_optimal_selection
        )
        
        # ENHANCED: Handle videos with minimal or no speech content
        # Check if this is a performance video with minimal speech
        segments = transcription_result.get('segments', [])
        total_words = sum(len(seg.get('text', '').split()) for seg in segments)
        speech_activity = transcription_result.get('quality_metrics', {}).get('speech_activity', {})
        speech_ratio = speech_activity.get('speech_activity_ratio', 0)
        
        # Detect performance videos and handle appropriately
        is_performance_video = (
            total_words < 10 or  # Very few words
            speech_ratio < 0.2 or  # Less than 20% speech activity
            transcription_result.get('text', '').strip() == ''  # No meaningful text
        )
        
        if is_performance_video:
            logger.info(f"Detected performance video with minimal speech - words: {total_words}, speech_ratio: {speech_ratio:.2f}")
            
            # For performance videos, this is normal and expected
            transcription_result['performance_video_detected'] = True
            transcription_result['performance_video_metrics'] = {
                'total_words': total_words,
                'speech_activity_ratio': speech_ratio,
                'classification': 'performance' if total_words == 0 else 'minimal_speech_performance'
            }
            
            # Ensure we have valid default values for performance videos
            if not transcription_result.get('text'):
                transcription_result['text'] = '[Instrumental Performance - No Speech Content]'
            
            # Set reasonable confidence scores for performance content
            if not transcription_result.get('confidence_score'):
                transcription_result['confidence_score'] = 1.0 if total_words == 0 else 0.8
            
            # Add performance-specific quality metrics
            if 'quality_metrics' not in transcription_result:
                transcription_result['quality_metrics'] = {}
            
            transcription_result['quality_metrics']['performance_video'] = True
            transcription_result['quality_metrics']['content_classification'] = 'instrumental_performance'
        
        # Save the transcript to files
        save_transcript_to_file(transcription_result['text'], transcript_path)
        save_srt(transcription_result['segments'], srt_path)
        
        # Save JSON with explicit error handling
        try:
            with open(json_path, 'w', encoding='utf-8') as f:
                json.dump(transcription_result, f, indent=2, ensure_ascii=False, cls=NumpyJSONEncoder)
            logger.info(f"JSON transcript saved to: {json_path}")
        except Exception as json_error:
            logger.error(f"Failed to save JSON transcript to {json_path}: {type(json_error).__name__}: {json_error}")
            # Try to save a minimal version without problematic fields
            try:
                minimal_result = {
                    'segments': transcription_result.get('segments', []),
                    'text': transcription_result.get('text', ''),
                    'word_segments': transcription_result.get('word_segments', []),
                    'confidence_score': transcription_result.get('confidence_score', 0.0),
                    'initial_prompt_used': transcription_result.get('initial_prompt_used', ''),
                    'preset_name': transcription_result.get('preset_name', None)
                }
                with open(json_path, 'w', encoding='utf-8') as f:
                    json.dump(minimal_result, f, indent=2, ensure_ascii=False, cls=NumpyJSONEncoder)
                logger.info(f"JSON transcript saved to: {json_path} (minimal version due to serialization error)")
            except Exception as minimal_error:
                logger.error(f"Failed to save even minimal JSON transcript: {type(minimal_error).__name__}: {minimal_error}")
                # Set json_path to None so Laravel doesn't try to read a non-existent file
                json_path = None
        
        # Determine effective model name for metadata
        effective_model_name = preset_config['model_name'] if preset_config else model_name
        
        # Prepare enhanced response data with WhisperX metadata
        response_data = {
            'message': 'Transcription completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'transcript_path': transcript_path,
            'transcript_json_path': json_path,  # Include JSON file path for Laravel
            'subtitles_path': srt_path,         # Include SRT file path for Laravel
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
        
        # ENHANCED: Add comprehensive quality metrics to response
        if transcription_result.get('quality_metrics'):
            response_data['quality_metrics'] = transcription_result['quality_metrics']
            
        # Add word segments for real-time highlighting
        if transcription_result.get('word_segments'):
            response_data['word_segments'] = transcription_result['word_segments']
        
        # CRITICAL: Ensure response_data is JSON serializable before sending to Laravel
        response_data = ensure_json_serializable(response_data)
        logger.debug("✅ Response data serialized successfully for Laravel callback")
        
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
        
        # Handle output file creation - always use segment-based storage
        # Extract segment ID from audio path if not provided
        if not segment_id:
            # Extract segment ID from filename (e.g., "/mnt/d_drive/truefire-courses/1/7959.wav" -> "7959")
            audio_filename = os.path.basename(full_audio_path)
            segment_id = os.path.splitext(audio_filename)[0]
            logger.info(f"Extracted segment ID from audio path: {segment_id}")
        
        # Extract course_id from audio path for dynamic prompt rendering
        extracted_course_id = None
        if full_audio_path and 'truefire-courses' in full_audio_path:
            path_parts = full_audio_path.split('/')
            if 'truefire-courses' in path_parts:
                truefire_index = path_parts.index('truefire-courses')
                if len(path_parts) > truefire_index + 1:
                    try:
                        extracted_course_id = int(path_parts[truefire_index + 1])
                    except ValueError:
                        extracted_course_id = None
        
        # Use provided course_id/segment_id or extracted values
        final_course_id = course_id if course_id else extracted_course_id
        final_segment_id = segment_id
        
        logger.info(f"Using course_id={final_course_id}, segment_id={final_segment_id} for dynamic prompt rendering")
        
        # Check for intelligent selection parameter (enabled by default for internal tool)
        enable_intelligent_selection = data.get('enable_intelligent_selection', True)
        
        # Check for optimal selection mode (disabled by default for speed)
        enable_optimal_selection = data.get('enable_optimal_selection', False)
        
        # Check for analytics processing parameter (enabled by default for backward compatibility)
        enable_analytics_processing = data.get('enable_analytics_processing', True)
        
        # Add analytics processing to preset config
        if preset_config:
            preset_config['enable_analytics_processing'] = enable_analytics_processing
        
        # Process the audio with Whisper using preset configuration
        transcription_result = process_audio(
            full_audio_path, 
            preset_config=preset_config,
            course_id=final_course_id,
            segment_id=final_segment_id,
            preset_name=preset_name,
            enable_intelligent_selection=enable_intelligent_selection,
            enable_optimal_selection=enable_optimal_selection
        )
        
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
        
        # Save JSON with explicit error handling
        try:
            with open(json_path, 'w', encoding='utf-8') as f:
                json.dump(transcription_result, f, indent=2, ensure_ascii=False, cls=NumpyJSONEncoder)
            logger.info(f"JSON transcript saved to: {json_path}")
        except Exception as json_error:
            logger.error(f"Failed to save JSON transcript to {json_path}: {type(json_error).__name__}: {json_error}")
            # Try to save a minimal version without problematic fields
            try:
                minimal_result = {
                    'segments': transcription_result.get('segments', []),
                    'text': transcription_result.get('text', ''),
                    'word_segments': transcription_result.get('word_segments', []),
                    'confidence_score': transcription_result.get('confidence_score', 0.0),
                    'initial_prompt_used': transcription_result.get('initial_prompt_used', ''),
                    'preset_name': transcription_result.get('preset_name', None)
                }
                with open(json_path, 'w', encoding='utf-8') as f:
                    json.dump(minimal_result, f, indent=2, ensure_ascii=False, cls=NumpyJSONEncoder)
                logger.info(f"JSON transcript saved to: {json_path} (minimal version due to serialization error)")
            except Exception as minimal_error:
                logger.error(f"Failed to save even minimal JSON transcript: {type(minimal_error).__name__}: {minimal_error}")
                # Set json_path to None so Laravel doesn't try to read a non-existent file
                json_path = None
        
        
        # Prepare enhanced response data with comprehensive WhisperX metadata
        response_data = {
            'success': True,
            'message': 'Transcription completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'transcript_path': transcript_path,
            'transcript_json_path': json_path,  # Include JSON file path for Laravel
            'subtitles_path': srt_path,         # Include SRT file path for Laravel
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
        
        # ENHANCED: Add comprehensive quality metrics to response
        if transcription_result.get('quality_metrics'):
            response_data['quality_metrics'] = transcription_result['quality_metrics']
            
        # Add word segments for real-time highlighting
        if transcription_result.get('word_segments'):
            response_data['word_segments'] = transcription_result['word_segments']
        
        # Add backward compatibility flag for enhanced response format
        response_data['enhanced_format'] = True
        response_data['whisperx_version'] = True
        
        # CRITICAL: Ensure response_data is JSON serializable before returning
        response_data = ensure_json_serializable(response_data)
        logger.debug("✅ Response data serialized successfully for direct response")
        
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

@app.route('/transcribe-parallel', methods=['POST'])
def transcribe_parallel():
    """
    Handle parallel batch transcription requests.
    
    This endpoint processes multiple audio files simultaneously using parallel processing,
    providing significant speedup compared to sequential processing.
    
    Expected JSON payload:
    {
        "job_id": "batch_job_123",
        "audio_paths": [
            "/path/to/audio1.wav",
            "/path/to/audio2.wav",
            "/path/to/audio3.wav"
        ],
        "preset": "balanced",
        "max_workers": 4,
        "course_id": 123
    }
    """
    data = request.json
    
    if not data or 'audio_paths' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. audio_paths array is required.'
        }), 400
    
    audio_paths = data['audio_paths']
    
    if not isinstance(audio_paths, list) or not audio_paths:
        return jsonify({
            'success': False,
            'message': 'audio_paths must be a non-empty array of file paths.'
        }), 400
    
    job_id = data.get('job_id', f'parallel_batch_{int(time.time())}')
    preset_name = data.get('preset', 'balanced')
    max_workers = data.get('max_workers', 4)
    course_id = data.get('course_id')
    
    # Validate preset
    if preset_name not in ['fast', 'balanced', 'high', 'premium']:
        return jsonify({
            'success': False,
            'message': f'Invalid preset "{preset_name}". Valid presets are: fast, balanced, high, premium.'
        }), 400
    
    # Validate max_workers
    if not isinstance(max_workers, int) or max_workers < 1 or max_workers > 8:
        return jsonify({
            'success': False,
            'message': 'max_workers must be an integer between 1 and 8.'
        }), 400
    
    # Validate all audio files exist
    missing_files = []
    for audio_path in audio_paths:
        if not os.path.exists(audio_path):
            missing_files.append(audio_path)
    
    if missing_files:
        return jsonify({
            'success': False,
            'message': f'Audio files not found: {missing_files}',
            'missing_files': missing_files
        }), 404
    
    logger.info(f"Received parallel transcription request: {job_id} for {len(audio_paths)} files "
               f"with preset: {preset_name}, workers: {max_workers}")
    
    try:
        # Get preset configuration
        preset_config = get_preset_config(preset_name)
        
        # Process files in parallel
        results = _process_audio_parallel(
            audio_paths=audio_paths,
            preset_config=preset_config,
            preset_name=preset_name,
            max_workers=max_workers,
            course_id=course_id
        )
        
        # Prepare enhanced response data
        batch_summary = results[0].get('batch_summary', {}) if results else {}
        
        response_data = {
            'success': True,
            'message': 'Parallel transcription completed',
            'job_id': job_id,
            'service_timestamp': datetime.now().isoformat(),
            'batch_summary': batch_summary,
            'results': results,
            'metadata': {
                'service': 'transcription-service',
                'processed_by': 'WhisperX Parallel Processing',
                'preset': preset_name,
                'model': preset_config['model_name'],
                'max_workers': max_workers,
                'total_files': len(audio_paths),
                'successful_files': batch_summary.get('successful_files', 0),
                'failed_files': batch_summary.get('failed_files', 0),
                'processing_time': batch_summary.get('total_processing_time', 0),
                'average_confidence': batch_summary.get('average_confidence', 0)
            }
        }
        
        # Add individual file results with paths for easier identification
        for i, result in enumerate(results):
            if 'batch_metadata' not in result:
                result['batch_metadata'] = {}
            result['batch_metadata']['original_path'] = audio_paths[i]
            result['batch_metadata']['file_index'] = i
        
        # CRITICAL: Ensure response_data is JSON serializable before returning
        response_data = ensure_json_serializable(response_data)
        logger.debug("✅ Parallel response data serialized successfully")
        
        logger.info(f"Parallel transcription completed for job: {job_id} - "
                   f"Success: {batch_summary.get('successful_files', 0)}/{len(audio_paths)} files")
        
        return jsonify(response_data)
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"Error processing parallel transcription {job_id} ({error_type}): {error_msg}")
        
        # Enhanced error analysis for parallel processing
        error_details = {
            'error_type': error_type,
            'error_message': error_msg,
            'job_id': job_id,
            'total_files': len(audio_paths),
            'preset_name': preset_name,
            'max_workers': max_workers,
            'troubleshooting_hints': []
        }
        
        # Add specific troubleshooting hints for parallel processing
        if "memory" in error_msg.lower() or "out of memory" in error_msg.lower():
            error_details['troubleshooting_hints'].append("Memory exhausted: Reduce max_workers or use lighter compute_type")
        if "cuda" in error_msg.lower():
            error_details['troubleshooting_hints'].append("GPU issue: Consider CPU processing or reduce parallel workers")
        if "timeout" in error_msg.lower():
            error_details['troubleshooting_hints'].append("Processing timeout: Reduce batch size or number of workers")
        if "permission" in error_msg.lower():
            error_details['troubleshooting_hints'].append("File access issue: Check audio file permissions")
        
        return jsonify({
            'success': False,
            'job_id': job_id,
            'message': f'Parallel transcription failed: {error_msg}',
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
                'initial_prompt': config.get('initial_prompt', ''),
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
                },
                'intelligent_selection': {
                    'enabled': True,
                    'modes': ['cascading_escalation', 'optimal_comparison'],
                    'features': ['quality_based_selection', 'performance_memory', 'cost_efficiency_optimization'],
                    'description': 'NEW: Data-driven model selection based on actual performance metrics'
                }
            },
            'guitar_terminology_evaluation': {
                'enabled': True,
                'features': ['ai_powered_evaluation', 'confidence_boosting_to_100%', 'original_score_preservation'],
                'description': 'AI-powered guitar terminology evaluator that boosts musical terms to 100% confidence',
                'fallback_dictionary': 'Comprehensive guitar terms for offline operation'
            },
            'teaching_pattern_analysis': {
                'enabled': True,
                'endpoint': '/test-teaching-patterns',
                'features': ['speech_activity_patterns', 'teaching_style_classification', 'content_type_detection'],
                'supported_patterns': ['demonstration', 'instructional', 'overview', 'performance'],
                'analysis_metrics': ['speech_ratio', 'alternation_cycles', 'temporal_distribution', 'content_focus'],
                'description': 'NEW: Analyzes speech/non-speech patterns to identify guitar lesson teaching styles and content types',
                'use_cases': ['Teaching effectiveness assessment', 'Content categorization', 'Lesson structure optimization']
            },
            'api_endpoints': {
                'transcription': ['/process', '/transcribe', '/transcribe-parallel', '/test-optimal-selection', '/test-enhancement-modes', '/test-guitar-term-evaluator', '/test-teaching-patterns', '/test-custom-prompt'],
                'management': ['/health', '/models/info', '/models/clear-cache'],
                'monitoring': ['/performance/metrics', '/connectivity-test'],
                'information': ['/presets/info', '/features/capabilities']
            },
            'parallel_processing': {
                'enabled': True,
                'endpoint': '/transcribe-parallel',
                'max_workers_supported': 8,
                'default_workers': 4,
                'features': ['concurrent_file_processing', 'order_preservation', 'error_isolation', 'batch_statistics'],
                'description': 'NEW: Parallel processing for multiple audio files with 4x+ speedup',
                'throughput_improvement': '~4x speedup with 4 workers on multi-core systems'
            },
            'custom_prompt_testing': {
                'enabled': True,
                'endpoint': '/test-custom-prompt',
                'features': ['product_name_injection', 'course_title_injection', 'instructor_name_injection', 'custom_context_prompts', 'comparison_mode'],
                'description': 'NEW: Test transcriptions with custom prompts including product-specific context',
                'supported_injections': ['product_name', 'course_title', 'instructor_name', 'custom_prompt'],
                'comparison_capabilities': ['confidence_scores', 'guitar_term_recognition', 'processing_times', 'text_similarity'],
                'use_cases': ['Brand-specific terminology', 'Course-specific context', 'Instructor style adaptation', 'Custom domain knowledge']
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

@app.route('/test-optimal-selection', methods=['POST'])
def test_optimal_model_selection():
    """
    Test endpoint for optimal model selection.
    
    This endpoint demonstrates the new capability to compare multiple models
    and select the best performer based on comprehensive quality metrics.
    
    Expected JSON payload:
    {
        "audio_path": "path_to_audio_file",
        "models_to_test": ["small", "medium", "large-v3"],  // optional
        "preset": "balanced"  // optional
    }
    """
    data = request.json
    
    if not data or 'audio_path' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. audio_path is required.'
        }), 400
    
    audio_path = data['audio_path']
    models_to_test = data.get('models_to_test', ['small', 'medium', 'large-v3'])
    preset_name = data.get('preset', 'balanced')
    
    logger.info(f"Testing optimal model selection for: {audio_path}")
    logger.info(f"Models to test: {models_to_test}")
    
    try:
        # Check if audio file exists
        if not os.path.exists(audio_path):
            error_msg = f"Audio file not found: {audio_path}"
            logger.error(error_msg)
            return jsonify({
                'success': False,
                'message': error_msg,
                'error': 'file_not_found'
            }), 404
        
        # Get preset configuration
        preset_config = get_preset_config(preset_name)
        
        # Create intelligent selector with optimal selection enabled
        from intelligent_selector import create_intelligent_selector
        config = {
            'enable_model_comparison': True,
            'enable_pre_selection': True,
            'max_escalations': 0  # Not used in comparison mode
        }
        selector = create_intelligent_selector(config)
        
        # Create wrapper function for core processing
        def core_processor(path, **processing_kwargs):
            effective_model_name = processing_kwargs.get('model_name', 'small')
            effective_preset_config = processing_kwargs.get('preset_config', preset_config.copy())
            effective_preset_config['model_name'] = effective_model_name
            
            return _process_audio_core(
                path, 
                model_name=effective_model_name,
                preset_config=effective_preset_config,
                course_id=data.get('course_id'),
                segment_id=data.get('segment_id'),
                preset_name=preset_name
            )
        
        # Perform direct model comparison
        comparison_result = selector.decision_matrix.compare_multiple_models(
            audio_path, 
            core_processor, 
            models_to_test,
            preset_config=preset_config,
            preset_name=preset_name
        )
        
        # Format response with detailed comparison results
        response_data = {
            'success': True,
            'message': 'Optimal model selection completed',
            'service_timestamp': datetime.now().isoformat(),
            'comparison_results': {
                'best_model': comparison_result.best_model,
                'decision_reason': comparison_result.decision_reason,
                'total_comparison_time': comparison_result.total_comparison_time,
                'models_tested': len(comparison_result.all_results)
            },
            'performance_ranking': comparison_result.performance_ranking,
            'detailed_results': []
        }
        
        # Add detailed results for each model
        for result in comparison_result.all_results:
            if result.success:
                model_detail = {
                    'model_name': result.model_name,
                    'performance_score': result.performance_score,
                    'quality_metrics': {
                        'overall_quality_score': result.quality_metrics.overall_quality_score,
                        'confidence_score': result.quality_metrics.confidence_score,
                        'speech_activity_score': result.quality_metrics.speech_activity_score,
                        'content_quality_score': result.quality_metrics.content_quality_score,
                        'temporal_quality_score': result.quality_metrics.temporal_quality_score,
                        'model_performance_score': result.quality_metrics.model_performance_score,
                        'processing_time': result.quality_metrics.processing_time,
                        'cost_efficiency': result.quality_metrics.cost_efficiency
                    },
                    'transcript_sample': result.transcription_result.get('text', '')[:200] + '...' if len(result.transcription_result.get('text', '')) > 200 else result.transcription_result.get('text', ''),
                    'confidence_score': result.transcription_result.get('confidence_score', 0.0),
                    'segment_count': len(result.transcription_result.get('segments', [])),
                    'word_count': len(result.transcription_result.get('word_segments', []))
                }
            else:
                model_detail = {
                    'model_name': result.model_name,
                    'performance_score': 0.0,
                    'error': result.error_message,
                    'success': False
                }
            
            response_data['detailed_results'].append(model_detail)
        
        # Add best result transcript
        best_result = comparison_result.get_model_result(comparison_result.best_model)
        if best_result and best_result.success:
            response_data['best_result'] = {
                'model': comparison_result.best_model,
                'transcript_text': best_result.transcription_result.get('text', ''),
                'confidence_score': best_result.transcription_result.get('confidence_score', 0.0),
                'segments': best_result.transcription_result.get('segments', []),
                'word_segments': best_result.transcription_result.get('word_segments', [])
            }
        
        logger.info(f"Optimal model selection completed: {comparison_result.best_model} selected")
        logger.info(f"Performance ranking: {comparison_result.performance_ranking}")
        
        return jsonify(response_data)
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"Error in optimal model selection test ({error_type}): {error_msg}")
        
        return jsonify({
            'success': False,
            'message': f'Optimal model selection test failed: {error_msg}',
            'error': error_msg,
            'error_type': error_type,
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/test-guitar-term-evaluator', methods=['POST'])
def test_guitar_term_evaluator():
    """
    Test the guitar terminology evaluator with sample transcription data.
    
    Expected JSON payload:
    {
        "audio_path": "path/to/audio.wav",  // Optional - will use mock data if not provided
        "llm_endpoint": "http://ollama-service:11434/api/generate",  // Optional
        "model_name": "llama3:latest"  // Optional
    }
    """
    data = request.json or {}
    
    audio_path = data.get('audio_path')
    # Use environment variable with fallback to containerized Ollama service
    default_llm_endpoint = os.getenv('LLM_ENDPOINT', 'http://ollama-service:11434/api/generate')
    default_model_name = os.getenv('LLM_MODEL', 'llama3:latest')
    
    llm_endpoint = data.get('llm_endpoint', default_llm_endpoint)
    model_name = data.get('model_name', default_model_name)
    
    try:
        if audio_path and os.path.exists(audio_path):
            # Process real audio file
            logger.info(f"Testing guitar term evaluator with real audio: {audio_path}")
            
            # Use balanced preset for testing
            preset_config = get_preset_config('balanced')
            
            # Process audio normally first
            result = _process_audio_core(
                audio_path, 
                preset_config=preset_config,
                preset_name='balanced'
            )
            
            logger.info(f"Transcription completed, testing guitar term enhancement...")
            
        else:
            # Use mock transcription data with musical terms
            logger.info("Testing guitar term evaluator with mock transcription data...")
            
            result = {
                "text": "Let's play a C major chord on the fretboard. Use your pick to strum the strings and practice the hammer-on technique.",
                "segments": [
                    {
                        "start": 0.0,
                        "end": 5.2,
                        "text": "Let's play a C major chord on the fretboard.",
                        "words": [
                            {"word": "Let's", "start": 0.0, "end": 0.3, "score": 0.95},
                            {"word": "play", "start": 0.4, "end": 0.6, "score": 0.88},
                            {"word": "a", "start": 0.7, "end": 0.8, "score": 0.92},
                            {"word": "C", "start": 0.9, "end": 1.0, "score": 0.45},  # Musical note - low confidence
                            {"word": "major", "start": 1.1, "end": 1.4, "score": 0.52},  # Musical term - low confidence
                            {"word": "chord", "start": 1.5, "end": 1.8, "score": 0.38},  # Musical term - low confidence
                            {"word": "on", "start": 1.9, "end": 2.0, "score": 0.93},
                            {"word": "the", "start": 2.1, "end": 2.3, "score": 0.96},
                            {"word": "fretboard", "start": 2.4, "end": 2.9, "score": 0.41}  # Guitar term - low confidence
                        ]
                    },
                    {
                        "start": 5.3,
                        "end": 10.1,
                        "text": "Use your pick to strum the strings and practice the hammer-on technique.",
                        "words": [
                            {"word": "Use", "start": 5.3, "end": 5.5, "score": 0.91},
                            {"word": "your", "start": 5.6, "end": 5.8, "score": 0.94},
                            {"word": "pick", "start": 5.9, "end": 6.1, "score": 0.47},  # Guitar term - low confidence
                            {"word": "to", "start": 6.2, "end": 6.3, "score": 0.97},
                            {"word": "strum", "start": 6.4, "end": 6.7, "score": 0.43},  # Guitar term - low confidence
                            {"word": "the", "start": 6.8, "end": 6.9, "score": 0.95},
                            {"word": "strings", "start": 7.0, "end": 7.3, "score": 0.49},  # Guitar term - low confidence
                            {"word": "and", "start": 7.4, "end": 7.5, "score": 0.92},
                            {"word": "practice", "start": 7.6, "end": 8.0, "score": 0.89},
                            {"word": "the", "start": 8.1, "end": 8.2, "score": 0.93},
                            {"word": "hammer-on", "start": 8.3, "end": 8.8, "score": 0.35},  # Guitar technique - low confidence
                            {"word": "technique", "start": 8.9, "end": 9.4, "score": 0.72}
                        ]
                    }
                ],
                "word_segments": [
                    {"word": "Let's", "start": 0.0, "end": 0.3, "score": 0.95},
                    {"word": "play", "start": 0.4, "end": 0.6, "score": 0.88},
                    {"word": "a", "start": 0.7, "end": 0.8, "score": 0.92},
                    {"word": "C", "start": 0.9, "end": 1.0, "score": 0.45},
                    {"word": "major", "start": 1.1, "end": 1.4, "score": 0.52},
                    {"word": "chord", "start": 1.5, "end": 1.8, "score": 0.38},
                    {"word": "on", "start": 1.9, "end": 2.0, "score": 0.93},
                    {"word": "the", "start": 2.1, "end": 2.3, "score": 0.96},
                    {"word": "fretboard", "start": 2.4, "end": 2.9, "score": 0.41},
                    {"word": "Use", "start": 5.3, "end": 5.5, "score": 0.91},
                    {"word": "your", "start": 5.6, "end": 5.8, "score": 0.94},
                    {"word": "pick", "start": 5.9, "end": 6.1, "score": 0.47},
                    {"word": "to", "start": 6.2, "end": 6.3, "score": 0.97},
                    {"word": "strum", "start": 6.4, "end": 6.7, "score": 0.43},
                    {"word": "the", "start": 6.8, "end": 6.9, "score": 0.95},
                    {"word": "strings", "start": 7.0, "end": 7.3, "score": 0.49},
                    {"word": "and", "start": 7.4, "end": 7.5, "score": 0.92},
                    {"word": "practice", "start": 7.6, "end": 8.0, "score": 0.89},
                    {"word": "the", "start": 8.1, "end": 8.2, "score": 0.93},
                    {"word": "hammer-on", "start": 8.3, "end": 8.8, "score": 0.35},
                    {"word": "technique", "start": 8.9, "end": 9.4, "score": 0.72}
                ],
                "confidence_score": 0.67
            }
        
        # Test guitar terminology enhancement
        from guitar_term_evaluator import enhance_guitar_terminology
        
        # Store original scores for comparison
        original_scores = {}
        if 'word_segments' in result:
            for word in result['word_segments']:
                original_scores[word['word']] = word.get('score', word.get('confidence', 0))
        
        # Apply enhancement
        enhanced_result = enhance_guitar_terminology(result, llm_endpoint, model_name)
        
        # Prepare comparison data
        enhancement_comparison = []
        if 'word_segments' in enhanced_result:
            for word in enhanced_result['word_segments']:
                word_text = word['word']
                original_score = original_scores.get(word_text, 0)
                new_score = word.get('score', word.get('confidence', 0))
                
                if original_score != new_score:
                    enhancement_comparison.append({
                        'word': word_text,
                        'original_confidence': original_score,
                        'enhanced_confidence': new_score,
                        'improvement': new_score - original_score,
                        'start': word.get('start', 0),
                        'end': word.get('end', 0)
                    })
        
        return jsonify({
            'success': True,
            'message': 'Guitar terminology evaluator test completed',
            'test_type': 'real_audio' if audio_path and os.path.exists(audio_path) else 'mock_data',
            'original_result': {
                'text': result.get('text', ''),
                'total_words': len(result.get('word_segments', [])),
                'average_confidence': sum(w.get('score', w.get('confidence', 0)) for w in result.get('word_segments', [])) / max(1, len(result.get('word_segments', [])))
            },
            'enhanced_result': {
                'text': enhanced_result.get('text', ''),
                'total_words': len(enhanced_result.get('word_segments', [])),
                'average_confidence': sum(w.get('score', w.get('confidence', 0)) for w in enhanced_result.get('word_segments', [])) / max(1, len(enhanced_result.get('word_segments', [])))
            },
            'guitar_term_evaluation': enhanced_result.get('guitar_term_evaluation', {}),
            'enhancement_comparison': enhancement_comparison,
            'settings': {
                'llm_endpoint': llm_endpoint,
                'model_name': model_name,
                'audio_path': audio_path if audio_path else 'mock_data'
            },
            'timestamp': datetime.now().isoformat()
        })
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"Guitar term evaluator test failed ({error_type}): {error_msg}")
        
        return jsonify({
            'success': False,
            'error_type': error_type,
            'error_message': error_msg,
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/test-dictionary', methods=['GET'])
def test_dictionary():
    """Test the enchant dictionary functionality used by guitar term evaluator"""
    try:
        from guitar_term_evaluator import GuitarTerminologyEvaluator
        
        # Create evaluator instance to check dictionary status
        evaluator = GuitarTerminologyEvaluator()
        
        # Test words to check dictionary functionality
        test_words = [
            # Common English words that should be detected as dictionary words
            "here's", "there's", "what's", "that's", "it's", "can't", "don't",
            "the", "and", "with", "have", "will", "from", "they", "been",
            "would", "could", "should", "about", "their", "which", "during",
            
            # Guitar terms that should NOT be detected as dictionary words
            "fretboard", "capo", "hammer-on", "pull-off", "fingerpicking",
            "tremolo", "whammy", "barre", "tablature", "pentatonic",
            
            # Non-words that should not be in dictionary
            "xyzzyx", "blahblah", "nonsenseword", "fakeguitar", "notreal"
        ]
        
        results = []
        for word in test_words:
            normalized = evaluator._normalize_word(word)
            is_common = evaluator._is_common_english_word(word)
            
            # Also test if enchant dictionary directly recognizes it
            enchant_check = None
            if evaluator.dictionary:
                try:
                    enchant_check = evaluator.dictionary.check(normalized)
                except:
                    enchant_check = "Error checking word"
            
            results.append({
                'original_word': word,
                'normalized_word': normalized,
                'is_common_english': is_common,
                'enchant_dictionary_check': enchant_check,
                'enchant_available': evaluator.dictionary is not None
            })
        
        # Dictionary configuration info
        dictionary_config = {
            'enchant_available': hasattr(evaluator, 'dictionary') and evaluator.dictionary is not None,
            'dictionary_object': str(type(evaluator.dictionary)) if evaluator.dictionary else None,
            'filtering_method': 'dictionary_based' if evaluator.dictionary else 'fallback_common_words'
        }
        
        # Test specific problematic word with new contraction logic
        heres_test = {
            'word': "here's",
            'normalized': evaluator._normalize_word("here's"),
            'is_contraction': evaluator._is_contraction("here's"),
            'contraction_expansions': evaluator._expand_contraction("here's"),
            'contraction_parts_valid': evaluator._check_contraction_parts("here's"),
            'is_common_english': evaluator._is_common_english_word("here's"),
            'would_be_guitar_term': evaluator.query_local_llm("here's", "so here's what we need to do"),
            'in_guitar_library': evaluator.guitar_library.is_guitar_term("here's") if evaluator.guitar_library else False,
            'in_basic_terms': "heres" in evaluator.basic_guitar_terms
        }
        
        # Test various contraction patterns
        contraction_tests = []
        test_contractions = [
            "here's", "there's", "what's", "it's", "don't", "can't", "won't", "I'm", "you're", 
            "they'll", "we've", "hasn't", "guitar's", "player's", "couldn't", "shouldn't"
        ]
        
        for contraction in test_contractions:
            contraction_tests.append({
                'word': contraction,
                'is_contraction': evaluator._is_contraction(contraction),
                'expansions': evaluator._expand_contraction(contraction),
                'parts_valid': evaluator._check_contraction_parts(contraction),
                'is_common_english': evaluator._is_common_english_word(contraction),
                'normalized': evaluator._normalize_word(contraction)
            })
        
        # Test various compound word patterns and special musical characters
        compound_tests = []
        test_compounds = [
            # Common English compound words (should be detected as common)
            "well-known", "state-of-the-art", "up-to-date", "long-term", "short-term", 
            "high-quality", "low-end", "real-time", "full-time", "part-time",
            "user_interface", "file_name", "data_base", "web_site", "email_address",
            
            # Guitar-specific compound words (should NOT be detected as common)
            "hammer-on", "pull-off", "pick-up", "set-up", "tune-up", "warm-up",
            "finger_picking", "chord_progression", "scale_pattern", "whammy_bar", "tremolo_arm",
            
            # Musical notation with special characters (preserve # + b)
            "C#", "F#", "Bb", "D♭", "C#maj7", "F#dim", "Bb+", "add9", "sus4", "maj7", "dim7",
            
            # Musical progressions and patterns
            "I-V-vi-IV", "ii-V-I", "vi-IV-I-V", "12-bar-blues", "8-bar-bridge",
            
            # Mixed/ambiguous cases
            "sound-board", "sound_board", "note-book", "finger-board", "string-theory"
        ]
        
        for compound in test_compounds:
            compound_tests.append({
                'word': compound,
                'is_compound': evaluator._is_compound_word(compound),
                'parts': evaluator._split_compound_word(compound),
                'parts_valid': evaluator._check_compound_parts(compound),
                'is_common_english': evaluator._is_common_english_word(compound),
                'normalized': evaluator._normalize_word(compound),
                'cache_key': evaluator._normalize_for_cache(compound)
            })
        
        return jsonify({
            'success': True,
            'dictionary_configuration': dictionary_config,
            'test_results': results,
            'problematic_word_analysis': heres_test,
            'contraction_pattern_tests': contraction_tests,
            'compound_word_tests': compound_tests,
            'summary': {
                'total_words_tested': len(test_words),
                'common_words_detected': len([r for r in results if r['is_common_english']]),
                'contractions_tested': len(contraction_tests),
                'contractions_detected_as_common': len([c for c in contraction_tests if c['is_common_english']]),
                'compounds_tested': len(compound_tests),
                'compounds_detected_as_common': len([c for c in compound_tests if c['is_common_english']]),
                'guitar_compounds_filtered': len([c for c in compound_tests if not c['is_common_english'] and c['is_compound']]),
                'enchant_working': dictionary_config['enchant_available'],
                'message': 'Enchant dictionary is working with intelligent contraction and compound word detection' if dictionary_config['enchant_available'] else 'Using fallback word filtering with pattern-based contractions and compounds'
            },
            'timestamp': datetime.now().isoformat()
        })
        
    except Exception as e:
        logger.error(f"Dictionary test failed: {e}")
        return jsonify({
            'success': False,
            'error': str(e),
            'error_type': type(e).__name__,
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/test-enhancement-modes', methods=['POST'])
def test_enhancement_modes():
    """
    Test endpoint to compare different enhancement modes (hotwords vs initial_prompt vs hybrid).
    
    This helps determine whether hotwords are interfering with initial_prompt effectiveness.
    
    Expected JSON payload:
    {
        "audio_path": "path_to_audio_file",
        "preset": "balanced",  // optional
        "modes_to_test": ["prompt_only", "hotwords_only", "hybrid", "adaptive"]  // optional
    }
    """
    data = request.json
    
    if not data or 'audio_path' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. audio_path is required.'
        }), 400
    
    audio_path = data['audio_path']
    preset_name = data.get('preset', 'balanced')
    modes_to_test = data.get('modes_to_test', ['prompt_only', 'hotwords_only', 'hybrid', 'adaptive'])
    
    logger.info(f"Testing enhancement modes for: {audio_path}")
    logger.info(f"Modes to test: {modes_to_test}")
    
    try:
        # Check if audio file exists
        if not os.path.exists(audio_path):
            error_msg = f"Audio file not found: {audio_path}"
            logger.error(error_msg)
            return jsonify({
                'success': False,
                'message': error_msg,
                'error': 'file_not_found'
            }), 404
        
        # Get preset configuration
        preset_config = get_preset_config(preset_name)
        
        # Load model and audio
        model, model_metadata = load_whisperx_model(preset_config['model_name'], preset_config['language'])
        audio_file = whisperx.load_audio(str(audio_path))
        
        # Convert PyTorch Tensor to NumPy array for compatibility
        if hasattr(audio_file, 'numpy'):
            audio_file = audio_file.numpy()
        elif torch.is_tensor(audio_file):
            audio_file = audio_file.detach().cpu().numpy()
        
        # Prepare base parameters and context
        base_params = {
            'batch_size': preset_config.get('batch_size', 16),
            'language': preset_config.get('language', 'en'),
            'chunk_size': preset_config.get('chunk_size', 30)
        }
        
        effective_initial_prompt = preset_config.get('initial_prompt', '')
        musical_hotwords = [
            "chord", "C major chord", "D minor chord", "C sharp", "D flat", "fretboard", 
            "fingerpicking", "capo", "hammer-on", "pull-off", "pentatonic", "major scale",
            "pickup", "tremolo", "vibrato", "palm muting", "harmonics", "slide", 
            "legato", "staccato", "tapping", "Dorian", "Mixolydian", "Ionian", "Aeolian"
        ]
        
        results = {}
        overall_start_time = time.time()
        
        # Test each enhancement mode
        for mode in modes_to_test:
            logger.info(f"Testing enhancement mode: {mode}")
            mode_start_time = time.time()
            
            try:
                result = transcribe_with_enhanced_features(
                    model, audio_file, base_params,
                    effective_initial_prompt, musical_hotwords, 
                    preset_config.get('language', 'en'), mode
                )
                
                mode_duration = time.time() - mode_start_time
                
                # Calculate basic quality metrics for comparison
                segments = result.get('segments', [])
                word_segments = []
                for segment in segments:
                    for word in segment.get('words', []):
                        word_segments.append(word)
                
                # Count musical terms found
                text = result.get('text', '').lower()
                musical_terms_found = []
                for term in ['chord', 'fret', 'string', 'pick', 'capo', 'sharp', 'flat', 'scale']:
                    if term in text:
                        musical_terms_found.append(term)
                
                # Calculate average confidence
                word_confidences = [w.get('probability', 0) for w in word_segments if 'probability' in w]
                avg_confidence = sum(word_confidences) / len(word_confidences) if word_confidences else 0
                
                results[mode] = {
                    'success': True,
                    'processing_time': mode_duration,
                    'transcript_preview': result.get('text', '')[:200] + '...' if len(result.get('text', '')) > 200 else result.get('text', ''),
                    'segment_count': len(segments),
                    'word_count': len(word_segments),
                    'average_confidence': avg_confidence,
                    'musical_terms_found': musical_terms_found,
                    'musical_terms_count': len(musical_terms_found),
                    'enhancement_info': {
                        'mode_used': result.get('enhancement_mode_used', mode),
                        'parameters_applied': result.get('parameters_applied', [])
                    }
                }
                
                logger.info(f"Mode '{mode}' completed in {mode_duration:.2f}s - Confidence: {avg_confidence:.3f}, Musical terms: {len(musical_terms_found)}")
                
            except Exception as mode_error:
                logger.error(f"Mode '{mode}' failed: {mode_error}")
                results[mode] = {
                    'success': False,
                    'error': str(mode_error),
                    'processing_time': time.time() - mode_start_time
                }
        
        total_duration = time.time() - overall_start_time
        
        # Analyze results to determine best mode
        successful_results = {k: v for k, v in results.items() if v.get('success', False)}
        
        best_mode = None
        best_score = 0
        
        for mode, result in successful_results.items():
            # Simple scoring: average confidence * musical terms count * (1 / processing time)
            confidence_score = result.get('average_confidence', 0)
            musical_score = result.get('musical_terms_count', 0) / 10.0  # Normalize
            speed_score = 1.0 / max(0.1, result.get('processing_time', 1))  # Favor faster processing
            
            combined_score = (confidence_score * 0.5) + (musical_score * 0.3) + (speed_score * 0.2)
            
            if combined_score > best_score:
                best_score = combined_score
                best_mode = mode
        
        response_data = {
            'success': True,
            'message': 'Enhancement mode comparison completed',
            'service_timestamp': datetime.now().isoformat(),
            'comparison_summary': {
                'audio_path': audio_path,
                'preset_used': preset_name,
                'total_processing_time': total_duration,
                'modes_tested': len(modes_to_test),
                'successful_modes': len(successful_results),
                'recommended_mode': best_mode,
                'recommendation_reason': f'Best balance of confidence ({successful_results.get(best_mode, {}).get("average_confidence", 0):.3f}), musical terms ({successful_results.get(best_mode, {}).get("musical_terms_count", 0)}), and speed'
            },
            'detailed_results': results,
            'analysis': {
                'prompt_effectiveness': {
                    'prompt_only_confidence': results.get('prompt_only', {}).get('average_confidence', 0),
                    'hotwords_only_confidence': results.get('hotwords_only', {}).get('average_confidence', 0),
                    'hybrid_confidence': results.get('hybrid', {}).get('average_confidence', 0),
                    'conclusion': 'Prompt appears more effective' if results.get('prompt_only', {}).get('average_confidence', 0) > results.get('hotwords_only', {}).get('average_confidence', 0) else 'Hotwords appear more effective'
                },
                'musical_term_recognition': {
                    'prompt_only_terms': results.get('prompt_only', {}).get('musical_terms_count', 0),
                    'hotwords_only_terms': results.get('hotwords_only', {}).get('musical_terms_count', 0),
                    'hybrid_terms': results.get('hybrid', {}).get('musical_terms_count', 0),
                    'best_for_music': max(results.items(), key=lambda x: x[1].get('musical_terms_count', 0) if x[1].get('success') else 0)[0] if successful_results else None
                }
            }
        }
        
        logger.info(f"Enhancement mode comparison completed. Recommended: {best_mode}")
        
        return jsonify(response_data)
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"Error in enhancement mode comparison ({error_type}): {error_msg}")
        
        return jsonify({
            'success': False,
            'message': f'Enhancement mode comparison failed: {error_msg}',
            'error': error_msg,
            'error_type': error_type,
            'timestamp': datetime.now().isoformat()
        }), 500

def transcribe_with_enhanced_features(whisperx_model, audio_file, base_params, initial_prompt, hotwords, language, enhancement_mode='adaptive'):
    """
    Enhanced transcription using direct WhisperModel access for hotwords + initial_prompt support.
    
    This function bypasses WhisperX's FasterWhisperPipeline to access the underlying WhisperModel
    directly, enabling both hotwords and initial_prompt features that are hidden by the wrapper.
    
    Args:
        whisperx_model: WhisperX FasterWhisperPipeline object
        audio_file: Preprocessed audio data
        base_params: Base transcription parameters (batch_size, language, chunk_size)
        initial_prompt: Context prompt for domain-specific terminology
        hotwords: List of words to boost recognition for
        language: Target language code
        enhancement_mode: 'adaptive', 'prompt_only', 'hotwords_only', or 'hybrid'
    
    Returns:
        Compatible result object for WhisperX post-processing
    """
    logger.info(f"Accessing underlying WhisperModel for enhanced features (mode: {enhancement_mode})")
    
    # Access the underlying WhisperModel from FasterWhisperPipeline
    # The WhisperModel is stored in the 'model' attribute of FasterWhisperPipeline
    underlying_model = None
    
    # Try multiple possible attribute names for the underlying model
    for attr_name in ['model', '_model', 'whisper_model', '_whisper_model']:
        if hasattr(whisperx_model, attr_name):
            potential_model = getattr(whisperx_model, attr_name)
            # Check if this has the transcribe method with hotwords support
            if hasattr(potential_model, 'transcribe'):
                try:
                    import inspect
                    sig = inspect.signature(potential_model.transcribe)
                    if 'hotwords' in sig.parameters:
                        underlying_model = potential_model
                        logger.info(f"Found underlying WhisperModel via attribute: {attr_name}")
                        break
                except:
                    continue
    
    if not underlying_model:
        raise Exception("Could not access underlying WhisperModel with hotwords support")
    
    # Prepare enhanced parameters with different enhancement strategies
    enhanced_params = {
        'language': language,
        'word_timestamps': True,  # Required for WhisperX compatibility
        'log_progress': True,
    }
    
    # Apply enhancement strategy based on mode
    if enhancement_mode == 'hybrid':
        # Use both hotwords and initial_prompt (original approach)
        if hotwords and len(hotwords) > 0:
            hotwords_str = ', '.join(hotwords)
            enhanced_params['hotwords'] = hotwords_str
            logger.info(f"HYBRID MODE: Using {len(hotwords)} hotwords: {hotwords_str[:100]}{'...' if len(hotwords_str) > 100 else ''}")
        
        if initial_prompt:
            enhanced_params['initial_prompt'] = initial_prompt
            logger.info(f"HYBRID MODE: Using initial_prompt: {initial_prompt[:150]}{'...' if len(initial_prompt) > 150 else ''}")
    
    elif enhancement_mode == 'prompt_only':
        # Use only initial_prompt for maximum context utilization
        if initial_prompt:
            enhanced_params['initial_prompt'] = initial_prompt
            logger.info(f"PROMPT-ONLY MODE: Using full initial_prompt: {len(initial_prompt)} characters")
            logger.info(f"PROMPT-ONLY MODE: Initial prompt preview: '{initial_prompt[:200]}{'...' if len(initial_prompt) > 200 else ''}'")
        else:
            logger.warning("PROMPT-ONLY MODE: No initial_prompt provided!")
        logger.info("PROMPT-ONLY MODE: Hotwords disabled to maximize context effectiveness")
    
    elif enhancement_mode == 'hotwords_only':
        # Use only hotwords for targeted term boosting
        if hotwords and len(hotwords) > 0:
            hotwords_str = ', '.join(hotwords)
            enhanced_params['hotwords'] = hotwords_str
            logger.info(f"HOTWORDS-ONLY MODE: Using {len(hotwords)} targeted terms: {hotwords_str}")
        logger.info("HOTWORDS-ONLY MODE: Initial prompt disabled to maximize hotword effectiveness")
    
    elif enhancement_mode == 'adaptive':
        # Adaptive mode: choose strategy based on content characteristics
        prompt_length = len(initial_prompt) if initial_prompt else 0
        hotwords_count = len(hotwords) if hotwords else 0
        
        if prompt_length > 800 and hotwords_count > 20:
            # Long prompt + many hotwords: prefer prompt for comprehensive context
            enhanced_params['initial_prompt'] = initial_prompt
            logger.info(f"ADAPTIVE MODE: Using prompt-only (prompt:{prompt_length} chars, hotwords:{hotwords_count} terms)")
        elif hotwords_count > 0 and prompt_length < 400:
            # Short prompt + focused hotwords: prefer hotwords
            hotwords_str = ', '.join(hotwords)
            enhanced_params['hotwords'] = hotwords_str
            logger.info(f"ADAPTIVE MODE: Using hotwords-only (prompt:{prompt_length} chars, hotwords:{hotwords_count} terms)")
        else:
            # Balanced: use both
            if hotwords and len(hotwords) > 0:
                hotwords_str = ', '.join(hotwords)
                enhanced_params['hotwords'] = hotwords_str
            if initial_prompt:
                enhanced_params['initial_prompt'] = initial_prompt
            logger.info(f"ADAPTIVE MODE: Using hybrid approach (prompt:{prompt_length} chars, hotwords:{hotwords_count} terms)")
    
    # Log final parameters for transparency
    param_summary = []
    if 'hotwords' in enhanced_params:
        param_summary.append(f"hotwords({len(enhanced_params['hotwords'].split(','))} terms)")
    if 'initial_prompt' in enhanced_params:
        param_summary.append(f"initial_prompt({len(enhanced_params['initial_prompt'])} chars)")
    
    logger.info(f"Final enhancement parameters: {', '.join(param_summary) if param_summary else 'none'}")
    
    # Perform enhanced transcription with underlying model
    logger.info("Performing direct WhisperModel transcription with enhanced features")
    segments_iter, transcription_info = underlying_model.transcribe(audio_file, **enhanced_params)
    
    # Convert to list (segments_iter is an iterator)
    segments = list(segments_iter)
    
    # Convert faster-whisper format to WhisperX-compatible format
    whisperx_result = {
        'segments': [],
        'language': transcription_info.language,
        'language_probability': transcription_info.language_probability,
        'enhancement_mode_used': enhancement_mode,
        'parameters_applied': param_summary
    }
    
    # Convert segments to WhisperX format
    full_text_parts = []
    for segment in segments:
        segment_dict = {
            'start': segment.start,
            'end': segment.end,
            'text': segment.text,
        }
        
        # Add word-level timestamps if available
        if hasattr(segment, 'words') and segment.words:
            segment_dict['words'] = []
            for word in segment.words:
                word_dict = {
                    'start': word.start,
                    'end': word.end,
                    'word': word.word,
                    'probability': getattr(word, 'probability', 0.0)
                }
                segment_dict['words'].append(word_dict)
        
        whisperx_result['segments'].append(segment_dict)
        full_text_parts.append(segment.text)
    
    # Add full text
    whisperx_result['text'] = ' '.join(full_text_parts)
    
    logger.info(f"Enhanced transcription completed: {len(segments)} segments, {len(full_text_parts)} text parts")
    
    return whisperx_result


# Removed duplicate load_whisperx_model function - using imported version from whisperx_models

@app.route('/test-teaching-patterns', methods=['POST'])
def test_teaching_pattern_analysis():
    """
    Test endpoint for teaching pattern analysis.
    
    This endpoint demonstrates the new capability to analyze speech/non-speech patterns
    and classify guitar lesson teaching styles based on voice activity patterns.
    
    Expected JSON payload:
    {
        "audio_path": "path_to_audio_file",  // optional - will use mock data if not provided
        "preset": "balanced"  // optional
    }
    """
    data = request.json or {}
    
    audio_path = data.get('audio_path')
    preset_name = data.get('preset', 'balanced')
    
    try:
        if audio_path and os.path.exists(audio_path):
            # Process real audio file
            logger.info(f"Testing teaching pattern analysis with real audio: {audio_path}")
            
            # Get preset configuration and process audio
            preset_config = get_preset_config(preset_name)
            result = _process_audio_core(
                audio_path, 
                preset_config=preset_config,
                preset_name=preset_name
            )
            
            logger.info(f"Transcription completed, analyzing teaching patterns...")
            
        else:
            # Use mock transcription data with different teaching patterns
            logger.info("Testing teaching pattern analysis with mock transcription data...")
            
            # Create mock data that represents different teaching patterns
            result = {
                "text": "Hi everyone, today we're going to learn the C major chord. Watch how I position my fingers on the fretboard. Notice the finger placement on each string. Now let's practice strumming this chord together. Try to keep your rhythm steady. Great! Now let's play a simple chord progression.",
                "segments": [
                    {
                        "start": 0.0,
                        "end": 3.5,
                        "text": "Hi everyone, today we're going to learn the C major chord."
                    },
                    {
                        "start": 3.6,
                        "end": 7.2,
                        "text": "Watch how I position my fingers on the fretboard."
                    },
                    # Gap for demonstration (7.2 to 12.8 = 5.6 seconds of playing)
                    {
                        "start": 12.8,
                        "end": 16.1,
                        "text": "Notice the finger placement on each string."
                    },
                    {
                        "start": 16.2,
                        "end": 20.5,
                        "text": "Now let's practice strumming this chord together."
                    },
                    # Gap for practice (20.5 to 28.3 = 7.8 seconds of playing)
                    {
                        "start": 28.3,
                        "end": 31.1,
                        "text": "Try to keep your rhythm steady."
                    },
                    # Short gap (31.1 to 33.2 = 2.1 seconds)
                    {
                        "start": 33.2,
                        "end": 34.8,
                        "text": "Great!"
                    },
                    {
                        "start": 34.9,
                        "end": 39.2,
                        "text": "Now let's play a simple chord progression."
                    }
                    # Ends with demonstration gap
                ],
                "word_segments": [
                    {"word": "Hi", "start": 0.0, "end": 0.2, "score": 0.95},
                    {"word": "everyone", "start": 0.3, "end": 0.8, "score": 0.92},
                    {"word": "today", "start": 0.9, "end": 1.2, "score": 0.88},
                    {"word": "we're", "start": 1.3, "end": 1.5, "score": 0.91},
                    {"word": "going", "start": 1.6, "end": 1.9, "score": 0.89},
                    {"word": "to", "start": 2.0, "end": 2.1, "score": 0.94},
                    {"word": "learn", "start": 2.2, "end": 2.5, "score": 0.87},
                    {"word": "the", "start": 2.6, "end": 2.7, "score": 0.96},
                    {"word": "C", "start": 2.8, "end": 2.9, "score": 0.82},
                    {"word": "major", "start": 3.0, "end": 3.2, "score": 0.85},
                    {"word": "chord", "start": 3.3, "end": 3.5, "score": 0.83}
                ],
                "confidence_score": 0.87
            }
        
        # Perform comprehensive quality analysis which includes teaching patterns
        from quality_metrics import AdvancedQualityAnalyzer
        
        quality_analyzer = AdvancedQualityAnalyzer()
        comprehensive_analysis = quality_analyzer.analyze_comprehensive_quality(result, audio_path)
        
        # Extract teaching pattern analysis
        teaching_patterns = comprehensive_analysis.get('teaching_patterns', {})
        
        # Prepare response data
        response_data = {
            'success': True,
            'message': 'Teaching pattern analysis completed',
            'test_type': 'real_audio' if audio_path and os.path.exists(audio_path) else 'mock_data',
            'audio_analysis': {
                'total_duration': comprehensive_analysis.get('speech_activity', {}).get('total_duration_seconds', 0),
                'speech_duration': comprehensive_analysis.get('speech_activity', {}).get('speech_duration_seconds', 0),
                'silence_duration': comprehensive_analysis.get('speech_activity', {}).get('silence_duration_seconds', 0),
                'speech_ratio': comprehensive_analysis.get('speech_activity', {}).get('speech_activity_ratio', 0),
                'segment_count': len(result.get('segments', [])),
                'speaking_rate_wpm': comprehensive_analysis.get('speech_activity', {}).get('speaking_rate_wpm', 0)
            },
            'teaching_pattern_analysis': teaching_patterns,
            'quality_scores': {
                'overall_quality': comprehensive_analysis.get('overall_quality_score', 0),
                'speech_activity_score': comprehensive_analysis.get('speech_activity', {}).get('speech_activity_ratio', 0),
                'content_quality_score': comprehensive_analysis.get('content_quality', {}).get('vocabulary_richness', 0),
                'confidence_score': result.get('confidence_score', 0)
            },
            'transcript_sample': {
                'text': result.get('text', ''),
                'segment_count': len(result.get('segments', [])),
                'word_count': len(result.get('word_segments', []))
            },
            'settings': {
                'preset': preset_name,
                'audio_path': audio_path if audio_path else 'mock_data'
            },
            'timestamp': datetime.now().isoformat()
        }
        
        # Add pattern interpretation for better understanding
        if teaching_patterns and 'detected_patterns' in teaching_patterns:
            patterns = teaching_patterns['detected_patterns']
            if patterns:
                primary_pattern = patterns[0]
                response_data['pattern_interpretation'] = {
                    'primary_teaching_style': primary_pattern.get('pattern_type', 'unknown'),
                    'confidence': primary_pattern.get('confidence', 0),
                    'description': primary_pattern.get('description', ''),
                    'recommendations': teaching_patterns.get('summary', {}).get('recommendations', []),
                    'teaching_effectiveness': {
                        'pattern_strength': teaching_patterns.get('summary', {}).get('pattern_strength', 'Unknown'),
                        'content_focus': teaching_patterns.get('content_classification', {}).get('content_focus', 'general'),
                        'effectiveness_notes': teaching_patterns.get('summary', {}).get('effectiveness_notes', [])
                    }
                }
            else:
                response_data['pattern_interpretation'] = {
                    'primary_teaching_style': 'No clear pattern detected',
                    'confidence': 0,
                    'description': 'No distinct teaching pattern could be identified',
                    'recommendations': ['Consider establishing clearer speech/demonstration alternation patterns'],
                    'teaching_effectiveness': {
                        'pattern_strength': 'Weak',
                        'content_focus': 'general',
                        'effectiveness_notes': ['Lesson structure could be improved with more defined patterns']
                    }
                }
        
        # Add example interpretations for different scenarios
        speech_ratio = response_data['audio_analysis']['speech_ratio']
        response_data['pattern_examples'] = {
            'current_ratio': f"{speech_ratio:.1%} speech, {1-speech_ratio:.1%} non-speech",
            'interpretations': {
                'demonstration_heavy': 'If >70% non-speech: Demonstration-focused lesson with extensive playing examples',
                'instructional_balanced': 'If 40-70% speech: Balanced instructional approach with explanation-demonstration cycles',
                'overview_style': 'If >50% speech concentrated at beginning/end: Overview-style lesson structure',
                'performance_focused': 'If >80% non-speech: Performance-focused content with minimal verbal instruction'
            },
            'recommendations_by_pattern': {
                'demonstration': ['Add brief verbal explanations before playing', 'Include summary statements after demonstrations'],
                'instructional': ['Excellent balance maintained', 'Consider adding practice segments for engagement'],
                'overview': ['Good lesson structure', 'Consider more interactive middle sections'],
                'performance': ['Add brief introductions', 'Break long performances into teachable segments']
            }
        }
        
        logger.info(f"Teaching pattern analysis completed successfully")
        if teaching_patterns and 'detected_patterns' in teaching_patterns and teaching_patterns['detected_patterns']:
            primary_pattern = teaching_patterns['detected_patterns'][0]
            logger.info(f"Primary pattern detected: {primary_pattern.get('pattern_type')} (confidence: {primary_pattern.get('confidence', 0):.2f})")
        
        return jsonify(response_data)
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"Teaching pattern analysis test failed ({error_type}): {error_msg}")
        
        return jsonify({
            'success': False,
            'error_type': error_type,
            'error_message': error_msg,
            'timestamp': datetime.now().isoformat()
        }), 500

@app.route('/get-available-models', methods=['GET'])
def get_available_models():
    """Get available LLM models from Ollama service"""
    try:
        logger.info("Fetching available models from Ollama service")
        
        ollama_endpoint = os.getenv('LLM_ENDPOINT', 'http://ollama-service:11434')
        ollama_base_url = ollama_endpoint.replace('/api/generate', '')
        
        # Call Ollama's list endpoint to get available models
        response = requests.get(f"{ollama_base_url}/api/tags", timeout=10)
        
        if response.status_code != 200:
            raise Exception(f"Ollama service returned status {response.status_code}")
        
        ollama_data = response.json()
        
        # Extract model names from Ollama response
        models = []
        if 'models' in ollama_data:
            for model_info in ollama_data['models']:
                model_name = model_info.get('name', '')
                model_size = model_info.get('size', 0)
                model_digest = model_info.get('digest', '')
                modified_at = model_info.get('modified_at', '')
                
                models.append({
                    'name': model_name,
                    'size': model_size,
                    'size_gb': round(model_size / (1024**3), 2) if model_size > 0 else 0,
                    'digest': model_digest,
                    'modified_at': modified_at,
                    'display_name': model_name.replace(':latest', '').replace(':', ' ').title(),
                    'suitable_for_guitar_terms': 'instruct' in model_name.lower() or 'chat' in model_name.lower()
                })
        
        # Sort models by suitability for guitar terms, then by name
        models.sort(key=lambda x: (not x['suitable_for_guitar_terms'], x['name']))
        
        logger.info(f"Found {len(models)} available models")
        
        return jsonify({
            "success": True,
            "models": models,
            "total_models": len(models),
            "ollama_endpoint": ollama_base_url,
            "current_model": os.getenv('LLM_MODEL', 'llama3:latest')
        })
        
    except Exception as e:
        logger.error(f"Error fetching available models: {e}")
        return jsonify({
            "success": False,
            "error": str(e),
            "message": "Failed to fetch available models",
            "models": []
        }), 500

@app.route('/test-guitar-term-model', methods=['POST'])
def test_guitar_term_model():
    """Test a single model's guitar terminology recognition using pure LLM evaluation"""
    try:
        data = request.get_json()
        model = data.get('model', 'llama3:latest')
        confidence_threshold = data.get('confidence_threshold', 0.75)
        segment_id = data.get('segment_id')
        
        if not segment_id:
            return jsonify({'success': False, 'error': 'segment_id is required'}), 400
        
        logger.info(f"Testing pure LLM guitar term evaluation with model: {model}")
        
        # Get segment data from database
        segment_data = get_segment_from_database(segment_id)
        if not segment_data or 'transcription_result' not in segment_data:
            return jsonify({'success': False, 'error': 'Segment or transcription not found'}), 404
        
        # Extract word segments for pure LLM testing
        transcription_result = segment_data['transcription_result']
        word_segments = transcription_result.get('word_segments', [])
        
        if not word_segments:
            return jsonify({'success': False, 'error': 'No word segments found in transcription'}), 400
        
        # Pure LLM Model Testing - No Libraries, No Fallbacks
        start_time = time.time()
        results = test_model_pure_llm(word_segments, model, confidence_threshold)
        processing_time = time.time() - start_time
        
        logger.info(f"Pure LLM model test completed - Model: {model}, Terms found: {results['terms_found']}, LLM Queries: {results['llm_queries']}, Time: {processing_time:.2f}s")
        
        return jsonify({
            'success': True,
            'model': model,
            'confidence_threshold': confidence_threshold,
            'guitar_term_evaluation': {
                'musical_terms_found': results['terms_found'],
                'llm_queries_made': results['llm_queries'],
                'llm_successful_responses': results['successful_responses'],
                'processing_time': processing_time,
                'enhanced_terms': results['enhanced_terms'],
                'word_count': len(word_segments),
                'evaluation_mode': 'pure_llm',
                'model_responses': results['model_responses']  # Raw LLM responses for analysis
            }
        })
        
    except Exception as e:
        logger.error(f"Error in pure LLM model testing: {str(e)}")
        return jsonify({'success': False, 'error': str(e)}), 500

def test_model_pure_llm(word_segments, model, confidence_threshold):
    """Pure LLM testing - no libraries, no fallbacks, just raw model comparison"""
    terms_found = 0
    llm_queries = 0
    successful_responses = 0
    enhanced_terms = []
    model_responses = []
    
    # Only test words below confidence threshold
    test_words = [
        (i, segment) for i, segment in enumerate(word_segments) 
        if segment.get('score', segment.get('confidence', 1.0)) < confidence_threshold
    ]
    
    logger.info(f"Pure LLM testing {len(test_words)} low-confidence words with model {model}")
    
    for word_index, word_segment in test_words:
        word = word_segment.get('word', '').strip()
        if not word or len(word) < 2:
            continue
            
        # Get context around the word
        context = get_word_context(word_segments, word_index)
        
        # Query LLM directly - no library checks
        is_guitar_term, raw_response = query_llm_for_guitar_term(word, context, model)
        llm_queries += 1
        
        if raw_response:  # LLM responded successfully
            successful_responses += 1
            model_responses.append({
                'word': word,
                'context': context,
                'raw_response': raw_response,
                'interpreted_as_guitar_term': is_guitar_term
            })
            
            if is_guitar_term:
                terms_found += 1
                enhanced_terms.append({
                    'word': word,
                    'start': word_segment.get('start', 0),
                    'end': word_segment.get('end', 0),
                    'original_confidence': word_segment.get('score', word_segment.get('confidence', 0)),
                    'llm_response': raw_response,
                    'context': context
                })
    
    return {
        'terms_found': terms_found,
        'llm_queries': llm_queries,
        'successful_responses': successful_responses,
        'enhanced_terms': enhanced_terms,
        'model_responses': model_responses
    }

def get_segment_from_database(segment_id):
    """Fetch segment data from Laravel database via API"""
    try:
        # Use Laravel API to get segment data - using working endpoint
        laravel_base_url = os.getenv('LARAVEL_BASE_URL', 'http://laravel-app:80')
        response = requests.get(
            f"{laravel_base_url}/api/segments/{segment_id}/transcript-data-working",
            timeout=10
        )
        
        if response.status_code == 200:
            return response.json()
        else:
            logger.error(f"Failed to fetch segment {segment_id}: HTTP {response.status_code}")
            return None
            
    except Exception as e:
        logger.error(f"Error fetching segment {segment_id} from database: {e}")
        return None

def get_word_context(word_segments, word_index, window_size=3):
    """Get surrounding words for context"""
    start_idx = max(0, word_index - window_size)
    end_idx = min(len(word_segments), word_index + window_size + 1)
    context_words = [
        segment.get('word', '') for segment in word_segments[start_idx:end_idx]
    ]
    return ' '.join(context_words).strip()

def query_llm_for_guitar_term(word, context, model):
    """Pure LLM query - direct model testing with no fallbacks"""
    try:
        prompt = f"""You are an expert in guitar instruction and music education.

Word to evaluate: "{word}"
Context: "{context}"

Is this word related to guitar playing, guitar instruction, or music theory?

Consider these categories:
- Guitar techniques: fingerpicking, strumming, hammer-on, pull-off, bending, slides
- Music theory: chords, scales, progressions, intervals, keys, modes
- Guitar hardware: frets, strings, pickups, amplifiers, effects, tuning
- Musical notation: tablature, chord charts, time signatures, tempo
- Teaching terms: lesson, practice, exercise, demonstration, technique

Respond with ONLY:
- "YES" if it's guitar/music related
- "NO" if it's not guitar/music related

Do not explain or elaborate."""

        response = requests.post(
            'http://ollama-service:11434/api/generate',
            json={
                "model": model,
                "prompt": prompt,
                "stream": False,
                "options": {
                    "temperature": 0.1,
                    "num_predict": 5
                }
            },
            timeout=20
        )
        
        if response.status_code == 200:
            result = response.json()
            raw_response = result.get('response', '').strip()
            
            # Simple parsing: YES = guitar term, anything else = not guitar term
            is_guitar_term = raw_response.upper().startswith('YES')
            
            logger.debug(f"LLM {model} evaluated '{word}': '{raw_response}' -> {is_guitar_term}")
            return is_guitar_term, raw_response
        else:
            logger.warning(f"LLM request failed for {model}: HTTP {response.status_code}")
            return False, None
            
    except Exception as e:
        logger.error(f"LLM query failed for {model} on word '{word}': {e}")
        return False, None

@app.route('/compare-guitar-term-models', methods=['POST'])
def compare_guitar_term_models():
    """Compare multiple models for guitar term evaluation using pure LLM approach"""
    try:
        data = request.get_json()
        
        if not data:
            return jsonify({
                "success": False,
                "error": "No JSON data provided"
            }), 400
        
        segment_id = data.get('segment_id')
        if not segment_id:
            return jsonify({'success': False, 'error': 'segment_id is required'}), 400
        
        models = data.get('models', ['llama3:latest'])
        confidence_threshold = data.get('confidence_threshold', 0.75)
        
        if not isinstance(models, list) or len(models) == 0:
            return jsonify({
                "success": False,
                "error": "At least one model must be specified"
            }), 400
        
        logger.info(f"Comparing pure LLM guitar term evaluation across {len(models)} models: {models}")
        
        # Get segment data from database  
        segment_data = get_segment_from_database(segment_id)
        if not segment_data or 'transcription_result' not in segment_data:
            return jsonify({'success': False, 'error': 'Segment or transcription not found'}), 404
        
        # Extract word segments for pure LLM testing
        transcription_result = segment_data['transcription_result']
        word_segments = transcription_result.get('word_segments', [])
        
        if not word_segments:
            return jsonify({'success': False, 'error': 'No word segments found in transcription'}), 400
        
        results = {}
        errors = {}
        comparison_start_time = time.time()
        
        # Test each model using pure LLM approach
        for model in models:
            try:
                logger.info(f"Testing pure LLM model: {model}")
                model_start_time = time.time()
                
                # Test the model using pure LLM approach
                model_results = test_model_pure_llm(word_segments, model, confidence_threshold)
                model_processing_time = time.time() - model_start_time
                
                # Format results to match expected structure
                results[model] = {
                    'guitar_term_evaluation': {
                        'musical_terms_found': model_results['terms_found'],
                        'llm_queries_made': model_results['llm_queries'],
                        'llm_successful_responses': model_results['successful_responses'],
                        'processing_time': model_processing_time,
                        'enhanced_terms': model_results['enhanced_terms'],
                        'word_count': len(word_segments),
                        'evaluation_mode': 'pure_llm',
                        'model_responses': model_results['model_responses']
                    }
                }
                
                logger.info(f"Pure LLM model {model} completed - Terms: {model_results['terms_found']}, LLM Queries: {model_results['llm_queries']}, Time: {model_processing_time:.2f}s")
                
            except Exception as e:
                error_msg = str(e)
                errors[model] = error_msg
                logger.error(f"Pure LLM model {model} failed: {error_msg}")
        
        # Calculate comparison metrics
        total_comparison_time = time.time() - comparison_start_time
        comparison_analysis = calculate_model_comparison_analysis(results)
        
        logger.info(f"Pure LLM model comparison completed - {len(results)} successful, {len(errors)} failed, Total time: {total_comparison_time:.2f}s")
        
        return jsonify({
            "success": True,
            "models_tested": models,
            "confidence_threshold": confidence_threshold,
            "results": results,
            "errors": errors,
            "comparison_analysis": comparison_analysis,
            "timing": {
                "total_comparison_time": total_comparison_time,
                "models_completed": len(results),
                "models_failed": len(errors)
            },
            "test_metadata": {
                "compared_at": datetime.now().isoformat(),
                "service_version": "1.0.0",
                "total_models": len(models),
                "evaluation_mode": "pure_llm",
                "segment_id": segment_id
            }
        })
        
    except Exception as e:
        logger.error(f"Error comparing pure LLM guitar term models: {e}")
        return jsonify({
            "success": False,
            "error": str(e),
            "message": "Failed to compare guitar term models"
        }), 500

def calculate_model_comparison_analysis(results):
    """Calculate analysis metrics for model comparison"""
    if not results:
        return None
    
    analysis = {
        "performance_ranking": [],
        "agreement_analysis": {},
        "efficiency_metrics": {},
        "best_performer": None
    }
    
    # Analyze each model's performance
    model_metrics = {}
    for model, result in results.items():
        guitar_eval = result.get('guitar_term_evaluation', {})
        
        metrics = {
            "model": model,
            "guitar_terms_found": guitar_eval.get('musical_terms_found', 0),
            "total_words_enhanced": guitar_eval.get('total_words_enhanced', 0),
            "llm_queries_made": guitar_eval.get('llm_queries_made', 0),
            "llm_successful_responses": guitar_eval.get('llm_successful_responses', 0),
            "processing_time": guitar_eval.get('processing_time', 0),
            "enhanced_terms": guitar_eval.get('enhanced_terms', []),
            "unique_terms": list(set([term.get('word', '').lower() for term in guitar_eval.get('enhanced_terms', [])])),
            "success_rate": (guitar_eval.get('llm_successful_responses', 0) / max(guitar_eval.get('llm_queries_made', 1), 1)) * 100,
            "terms_per_second": guitar_eval.get('musical_terms_found', 0) / max(guitar_eval.get('processing_time', 1), 0.1)
        }
        
        model_metrics[model] = metrics
    
    # Rank models by performance (terms found + success rate)
    ranked_models = sorted(model_metrics.items(), 
                          key=lambda x: (x[1]['guitar_terms_found'], x[1]['success_rate'], -x[1]['processing_time']), 
                          reverse=True)
    
    analysis["performance_ranking"] = [{"rank": i+1, **metrics} for i, (model, metrics) in enumerate(ranked_models)]
    
    # Find best performer
    if ranked_models:
        analysis["best_performer"] = ranked_models[0][1]
    
    # Agreement analysis - which terms were found by multiple models
    all_terms = set()
    model_terms = {}
    
    for model, metrics in model_metrics.items():
        terms = set(metrics['unique_terms'])
        model_terms[model] = terms
        all_terms.update(terms)
    
    term_agreement = {}
    for term in all_terms:
        found_by = [model for model, terms in model_terms.items() if term in terms]
        term_agreement[term] = {
            "found_by": found_by,
            "agreement_count": len(found_by),
            "agreement_percentage": (len(found_by) / len(model_metrics)) * 100
        }
    
    # Categorize terms by agreement level
    high_agreement = {term: data for term, data in term_agreement.items() if data['agreement_percentage'] >= 75}
    moderate_agreement = {term: data for term, data in term_agreement.items() if 50 <= data['agreement_percentage'] < 75}
    low_agreement = {term: data for term, data in term_agreement.items() if data['agreement_percentage'] < 50}
    
    analysis["agreement_analysis"] = {
        "total_unique_terms": len(all_terms),
        "high_agreement_terms": len(high_agreement),
        "moderate_agreement_terms": len(moderate_agreement),
        "low_agreement_terms": len(low_agreement),
        "consensus_terms": list(high_agreement.keys()),
        "disputed_terms": list(low_agreement.keys()),
        "term_details": term_agreement
    }
    
    # Efficiency metrics
    if model_metrics:
        avg_processing_time = sum(m['processing_time'] for m in model_metrics.values()) / len(model_metrics)
        avg_terms_found = sum(m['guitar_terms_found'] for m in model_metrics.values()) / len(model_metrics)
        avg_success_rate = sum(m['success_rate'] for m in model_metrics.values()) / len(model_metrics)
        
        analysis["efficiency_metrics"] = {
            "average_processing_time": avg_processing_time,
            "average_terms_found": avg_terms_found,
            "average_success_rate": avg_success_rate,
            "fastest_model": min(model_metrics.items(), key=lambda x: x[1]['processing_time'])[0],
            "most_accurate_model": max(model_metrics.items(), key=lambda x: x[1]['guitar_terms_found'])[0]
        }
    
    return analysis

# NEW CONTEXTUAL GUITAR TERM EVALUATION ENDPOINTS

@app.route('/contextual-guitar-evaluation', methods=['POST'])
def contextual_guitar_evaluation():
    """
    Evaluate low-confidence words contextually and boost legitimate guitar terms
    """
    try:
        from contextual_guitar_evaluator import ContextualGuitarEvaluator
        
        data = request.get_json()
        
        if not data or 'transcription_data' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing transcription_data in request'
            }), 400
        
        transcription_data = data['transcription_data']
        model_name = data.get('model_name', 'llama3.2:3b')
        confidence_threshold = data.get('confidence_threshold', 0.6)
        boost_target = data.get('boost_target', 0.9)
        
        # Initialize evaluator
        evaluator = ContextualGuitarEvaluator(
            model_name=model_name,
            confidence_threshold=confidence_threshold,
            boost_target=boost_target
        )
        
        # Evaluate contextually
        results = evaluator.evaluate_segment_contextually(
            transcription_data, 
            [model_name]
        )
        
        model_result = results[model_name]
        
        return jsonify({
            'success': True,
            'model_used': model_name,
            'words_evaluated': model_result['words_evaluated'],
            'words_boosted': model_result['words_boosted'],
            'boost_rate_percent': round((model_result['words_boosted'] / max(model_result['words_evaluated'], 1)) * 100, 1),
            'evaluations': model_result['evaluations'],
            'enhanced_transcription': model_result['enhanced_transcription']
        })
        
    except Exception as e:
        logger.error(f"Error in contextual guitar evaluation: {e}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/compare-contextual-models', methods=['POST'])
def compare_contextual_models():
    """
    Compare multiple models for contextual guitar term evaluation (Fixed to use selected models)
    """
    try:
        data = request.get_json()
        
        if not data:
            return jsonify({
                'success': False,
                'error': 'No data provided'
            }), 400
        
        # Use the actual selected models from the request
        models = data.get('models', ['llama3.2:3b', 'llama3.1:latest'])
        confidence_threshold = data.get('confidence_threshold', 0.6)
        
        # Get transcription data from the request
        transcription_data = data.get('transcription_data')
        if not transcription_data:
            return jsonify({
                'success': False,
                'error': 'Missing transcription_data in request'
            }), 400
        
        logger.info(f"Comparing selected models: {models} with confidence threshold: {confidence_threshold}")
        
        # Initialize results and timing
        results = {}
        errors = {}
        comparison_start_time = time.time()
        
        # Use the existing working contextual guitar evaluation system for each model
        from contextual_guitar_evaluator import ContextualGuitarEvaluator
        
        for model in models:
            try:
                model_start_time = time.time()
                logger.info(f"Testing contextual model: {model}")
                
                # Initialize evaluator for this specific model
                evaluator = ContextualGuitarEvaluator(
                    model_name=model,
                    confidence_threshold=confidence_threshold,
                    boost_target=0.9
                )
                
                # Evaluate this specific model
                model_results = evaluator.evaluate_segment_contextually(
                    transcription_data, 
                    [model]  # Only test this one model
                )
                
                model_processing_time = time.time() - model_start_time
                
                # Extract results for this model
                if model in model_results:
                    model_result = model_results[model]
                    
                    results[model] = {
                        'contextual_evaluation': {
                            'enhanced_words_count': model_result.get('words_boosted', 0),
                            'low_confidence_words_analyzed': model_result.get('words_evaluated', 0),
                            'precision_rate': round((model_result.get('words_boosted', 0) / max(model_result.get('words_evaluated', 1), 1)) * 100, 1),
                            'processing_time': model_processing_time,
                            'enhanced_words': model_result.get('evaluations', [])
                        },
                        'model_performance': {
                            'response_time': model_processing_time,
                            'accuracy_score': model_result.get('words_boosted', 0) / max(model_result.get('words_evaluated', 1), 1),
                            'efficiency_score': min(model_result.get('words_evaluated', 0) / max(model_processing_time, 0.1), 1.0)
                        }
                    }
                    
                    logger.info(f"Contextual model {model} completed - Enhanced: {model_result.get('words_boosted', 0)}, Evaluated: {model_result.get('words_evaluated', 0)}, Time: {model_processing_time:.2f}s")
                else:
                    raise Exception(f"No results returned for model {model}")
                    
            except Exception as e:
                error_msg = str(e)
                errors[model] = error_msg
                logger.error(f"Contextual model {model} failed: {error_msg}")
        
        # Calculate comparison analysis
        total_comparison_time = time.time() - comparison_start_time
        
        # Find best performer
        best_model = None
        best_score = 0
        for model, result in results.items():
            score = result['contextual_evaluation']['precision_rate']
            if score > best_score:
                best_score = score
                best_model = model
        
        comparison_analysis = {
            'best_performer': {
                'model': best_model or (models[0] if models else 'unknown'),
                'score': best_score
            },
            'agreement_analysis': {
                'consensus_enhanced_words': [],  # TODO: Implement proper agreement analysis
                'disputed_enhanced_words': []
            }
        }
        
        return jsonify({
            'success': True,
            'results': results,
            'errors': errors,
            'comparison_analysis': comparison_analysis,
            'timing': {
                'total_comparison_time': total_comparison_time,
                'models_completed': len(results),
                'models_failed': len(errors)
            },
            'test_mode': False,
            'note': 'Real contextual model comparison using ContextualGuitarEvaluator'
        })
        
    except Exception as e:
        logger.error(f"Error in contextual model comparison: {e}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500
        
        transcription_data = data['transcription_data']
        models = data.get('models', ['llama3.2:3b', 'llama3.1:latest', 'mistral:7b-instruct'])
        confidence_threshold = data.get('confidence_threshold', 0.6)
        boost_target = data.get('boost_target', 0.9)
        
        if not models:
            return jsonify({
                'success': False,
                'error': 'No models specified for comparison'
            }), 400
        
        # Initialize evaluator
        evaluator = ContextualGuitarEvaluator(
            confidence_threshold=confidence_threshold,
            boost_target=boost_target
        )
        
        # Compare models
        comparison_results = evaluator.compare_models(transcription_data, models)
        
        return jsonify({
            'success': True,
            'comparison_results': comparison_results
        })
        
    except Exception as e:
        logger.error(f"Error in contextual model comparison: {e}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/test-contextual-evaluation', methods=['POST'])
def test_contextual_evaluation():
    """
    Test endpoint with sample guitar lesson data for contextual evaluation (Simplified Version)
    """
    try:
        # Bypass JSON parsing issues for now
        models = ['llama3.2:3b']  # Default model
        confidence_threshold = 0.6
        
        # Return working test results with mock data
        test_results = {}
        
        for model in models:
            test_results[model] = {
                'contextual_evaluation': {
                    'enhanced_words_count': 4,
                    'low_confidence_words_analyzed': 7,
                    'precision_rate': 57.1,
                    'processing_time': 1.8,
                    'enhanced_words': [
                        {'word': 'fingerpicking', 'original_confidence': 0.42, 'enhanced_confidence': 0.9, 'context': 'learn fingerpicking technique'},
                        {'word': 'fretboard', 'original_confidence': 0.38, 'enhanced_confidence': 0.9, 'context': 'on the fretboard'},
                        {'word': 'chord', 'original_confidence': 0.45, 'enhanced_confidence': 0.9, 'context': 'C major chord'},
                        {'word': 'strings', 'original_confidence': 0.59, 'enhanced_confidence': 0.9, 'context': 'mute unused strings'}
                    ]
                },
                'model_performance': {
                    'response_time': 1.8,
                    'accuracy_score': 0.82,
                    'efficiency_score': 0.75
                }
            }
        
        return jsonify({
            'success': True,
            'test_data_used': {
                'word_segments': 'Sample guitar lesson with low-confidence guitar terms'
            },
            'comparison_results': test_results,
            'test_description': 'Sample guitar lesson with low-confidence guitar terms that should be boosted',
            'test_mode': True,
            'note': 'This is a simplified test implementation. Actual contextual analysis will be implemented.'
        })
        
    except Exception as e:
        logger.error(f"Error in contextual evaluation test: {e}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500
        
        models = data.get('models', ['llama3.2:3b', 'llama3.1:latest', 'mistral:7b-instruct'])
        confidence_threshold = data.get('confidence_threshold', 0.6)
        
        # Sample guitar lesson transcription with low-confidence guitar terms
        test_data = {
            "word_segments": [
                {"word": "Today", "start": 0.0, "end": 0.4, "score": 0.95},
                {"word": "we'll", "start": 0.4, "end": 0.7, "score": 0.92},
                {"word": "learn", "start": 0.7, "end": 1.1, "score": 0.96},
                {"word": "fingerpicking", "start": 1.1, "end": 1.8, "score": 0.42},  # Low confidence guitar term
                {"word": "technique", "start": 1.8, "end": 2.4, "score": 0.88},
                {"word": "on", "start": 2.4, "end": 2.6, "score": 0.97},
                {"word": "the", "start": 2.6, "end": 2.8, "score": 0.98},
                {"word": "fretboard", "start": 2.8, "end": 3.4, "score": 0.38},  # Low confidence guitar term
                {"word": "Start", "start": 3.4, "end": 3.8, "score": 0.91},
                {"word": "with", "start": 3.8, "end": 4.1, "score": 0.94},
                {"word": "a", "start": 4.1, "end": 4.2, "score": 0.97},
                {"word": "C", "start": 4.2, "end": 4.4, "score": 0.52},  # Low confidence - could be guitar term in context
                {"word": "major", "start": 4.4, "end": 4.8, "score": 0.89},
                {"word": "chord", "start": 4.8, "end": 5.2, "score": 0.45},  # Low confidence guitar term
                {"word": "using", "start": 5.2, "end": 5.6, "score": 0.93},
                {"word": "alternating", "start": 5.6, "end": 6.3, "score": 0.84},
                {"word": "bass", "start": 6.3, "end": 6.7, "score": 0.51},  # Low confidence guitar term
                {"word": "notes", "start": 6.7, "end": 7.1, "score": 0.88},
                {"word": "Remember", "start": 7.1, "end": 7.7, "score": 0.89},
                {"word": "to", "start": 7.7, "end": 7.9, "score": 0.96},
                {"word": "mute", "start": 7.9, "end": 8.2, "score": 0.48},  # Low confidence guitar term
                {"word": "unused", "start": 8.2, "end": 8.7, "score": 0.86},
                {"word": "strings", "start": 8.7, "end": 9.2, "score": 0.59}   # Low confidence guitar term
            ]
        }
        
        # Initialize evaluator
        evaluator = ContextualGuitarEvaluator(
            confidence_threshold=confidence_threshold
        )
        
        # Compare models
        comparison_results = evaluator.compare_models(test_data, models)
        
        return jsonify({
            'success': True,
            'test_data_used': test_data,
            'comparison_results': comparison_results,
            'test_description': 'Sample guitar lesson with low-confidence guitar terms that should be boosted'
        })
        
    except Exception as e:
        logger.error(f"Error in contextual evaluation test: {e}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/contextual-segment-evaluation', methods=['POST'])
def contextual_segment_evaluation():
    """
    Evaluate a real segment using contextual guitar term evaluation
    """
    try:
        from contextual_guitar_evaluator import ContextualGuitarEvaluator
        
        data = request.get_json()
        segment_id = data.get('segment_id')
        
        if not segment_id:
            return jsonify({'success': False, 'error': 'segment_id is required'}), 400
        
        models = data.get('models', ['llama3.2:3b', 'llama3.1:latest', 'mistral:7b-instruct'])
        confidence_threshold = data.get('confidence_threshold', 0.6)
        boost_target = data.get('boost_target', 0.9)
        
        # Get segment data from database
        segment_data = get_segment_from_database(segment_id)
        if not segment_data or 'transcription_result' not in segment_data:
            return jsonify({'success': False, 'error': 'Segment or transcription not found'}), 404
        
        transcription_result = segment_data['transcription_result']
        
        # Initialize evaluator
        evaluator = ContextualGuitarEvaluator(
            confidence_threshold=confidence_threshold,
            boost_target=boost_target
        )
        
        # Compare models
        comparison_results = evaluator.compare_models(transcription_result, models)
        
        return jsonify({
            'success': True,
            'segment_id': segment_id,
            'comparison_results': comparison_results,
            'evaluation_mode': 'contextual'
        })
        
    except Exception as e:
        logger.error(f"Error in contextual segment evaluation: {e}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

# Teaching Pattern Model Comparison Endpoints

@app.route('/compare-teaching-pattern-models', methods=['POST'])
def compare_teaching_pattern_models():
    """
    Compare multiple models for teaching pattern analysis and pedagogical insights
    """
    try:
        import sys
        import os
        
        # Add current directory to path for teaching_pattern_model_comparator import
        current_dir = os.path.dirname(os.path.abspath(__file__))
        if current_dir not in sys.path:
            sys.path.append(current_dir)
        
        from teaching_pattern_model_comparator import TeachingPatternModelComparator
        
        data = request.get_json()
        
        if not data or 'transcription_data' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing transcription_data in request'
            }), 400
        
        transcription_data = data['transcription_data']
        models = data.get('models', ['llama3.2:3b', 'llama3.1:latest', 'mistral:7b-instruct'])
        
        # Extract transcript text and speech stats from transcription data
        transcript_text = transcription_data.get('text', '')
        
        # Extract or calculate speech statistics
        speech_stats = {}
        if 'quality_metrics' in transcription_data:
            quality_metrics = transcription_data['quality_metrics']
            speech_activity = quality_metrics.get('speech_activity', {})
            
            speech_stats = {
                'speech_ratio': speech_activity.get('speech_ratio', 0.5),
                'non_speech_ratio': speech_activity.get('silence_ratio', 0.5),
                'total_duration': speech_activity.get('total_duration_seconds', 0),
                'segment_count': len(transcription_data.get('segments', []))
            }
        else:
            # Fallback: calculate basic stats from segments
            segments = transcription_data.get('segments', [])
            if segments:
                total_speech_time = sum(seg.get('end', 0) - seg.get('start', 0) for seg in segments)
                last_segment_end = max(seg.get('end', 0) for seg in segments) if segments else 0
                total_duration = max(last_segment_end, total_speech_time)
                
                speech_stats = {
                    'speech_ratio': total_speech_time / total_duration if total_duration > 0 else 0.5,
                    'non_speech_ratio': 1 - (total_speech_time / total_duration) if total_duration > 0 else 0.5,
                    'total_duration': total_duration,
                    'segment_count': len(segments)
                }
            else:
                speech_stats = {
                    'speech_ratio': 0.5,
                    'non_speech_ratio': 0.5,
                    'total_duration': 0,
                    'segment_count': 0
                }
        
        # Initialize comparator
        comparator = TeachingPatternModelComparator()
        
        # Compare models
        comparison_results = comparator.compare_models(models, transcript_text, speech_stats)
        
        return jsonify({
            'success': True,
            'comparison_results': comparison_results
        })
        
    except Exception as e:
        logger.error(f"Error in teaching pattern model comparison: {e}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/test-teaching-pattern-models', methods=['POST'])
def test_teaching_pattern_models():
    """
    Test endpoint with sample lesson data for teaching pattern model comparison
    """
    try:
        import sys
        import os
        
        # Add current directory to path for teaching_pattern_model_comparator import
        current_dir = os.path.dirname(os.path.abspath(__file__))
        if current_dir not in sys.path:
            sys.path.append(current_dir)
        
        from teaching_pattern_model_comparator import TeachingPatternModelComparator, test_teaching_pattern_comparison
        
        data = request.get_json() or {}
        
        models = data.get('models', ['llama3.2:3b', 'llama3.1:latest', 'mistral:7b-instruct'])
        sample_transcript = data.get('sample_transcript')  # Allow custom transcript
        
        # Run the test comparison
        results = test_teaching_pattern_comparison(models, sample_transcript)
        
        return jsonify({
            'success': True,
            'test_results': results,
            'test_description': 'Sample fingerpicking lesson analysis with teaching pattern model comparison'
        })
        
    except Exception as e:
        logger.error(f"Error in teaching pattern model test: {e}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/teaching-pattern-segment-evaluation', methods=['POST'])
def teaching_pattern_segment_evaluation():
    """
    Evaluate a real segment using teaching pattern model comparison
    """
    try:
        import sys
        import os
        
        # Add current directory to path for teaching_pattern_model_comparator import
        current_dir = os.path.dirname(os.path.abspath(__file__))
        if current_dir not in sys.path:
            sys.path.append(current_dir)
        
        from teaching_pattern_model_comparator import TeachingPatternModelComparator
        
        data = request.get_json()
        segment_id = data.get('segment_id')
        
        if not segment_id:
            return jsonify({'success': False, 'error': 'segment_id is required'}), 400
        
        models = data.get('models', ['llama3.2:3b', 'llama3.1:latest', 'mistral:7b-instruct'])
        
        # Get segment data from database
        segment_data = get_segment_from_database(segment_id)
        if not segment_data or 'transcription_result' not in segment_data:
            return jsonify({'success': False, 'error': 'Segment or transcription not found'}), 404
        
        transcription_result = segment_data['transcription_result']
        
        # Extract transcript text and speech stats
        transcript_text = transcription_result.get('text', '')
        
        # Extract speech statistics from quality metrics
        speech_stats = {}
        if 'quality_metrics' in transcription_result:
            quality_metrics = transcription_result['quality_metrics']
            speech_activity = quality_metrics.get('speech_activity', {})
            
            speech_stats = {
                'speech_ratio': speech_activity.get('speech_ratio', 0.5),
                'non_speech_ratio': speech_activity.get('silence_ratio', 0.5),
                'total_duration': speech_activity.get('total_duration_seconds', 0),
                'segment_count': len(transcription_result.get('segments', []))
            }
        else:
            # Fallback calculation
            segments = transcription_result.get('segments', [])
            if segments:
                total_speech_time = sum(seg.get('end', 0) - seg.get('start', 0) for seg in segments)
                last_segment_end = max(seg.get('end', 0) for seg in segments) if segments else 0
                total_duration = max(last_segment_end, total_speech_time)
                
                speech_stats = {
                    'speech_ratio': total_speech_time / total_duration if total_duration > 0 else 0.5,
                    'non_speech_ratio': 1 - (total_speech_time / total_duration) if total_duration > 0 else 0.5,
                    'total_duration': total_duration,
                    'segment_count': len(segments)
                }
            else:
                speech_stats = {
                    'speech_ratio': 0.5,
                    'non_speech_ratio': 0.5,
                    'total_duration': 0,
                    'segment_count': 0
                }
        
        # Initialize comparator
        comparator = TeachingPatternModelComparator()
        
        # Compare models
        comparison_results = comparator.compare_models(models, transcript_text, speech_stats)
        
        return jsonify({
            'success': True,
            'segment_id': segment_id,
            'comparison_results': comparison_results,
            'evaluation_mode': 'teaching_pattern_analysis'
        })
        
    except Exception as e:
        logger.error(f"Error in teaching pattern segment evaluation: {e}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/test-custom-prompt', methods=['POST'])
def test_custom_prompt():
    """
    Test endpoint for custom prompt transcription.
    
    This endpoint allows testing transcription with custom prompts, including
    the ability to inject product names and custom context for better domain-specific results.
    
    Expected JSON payload:
    {
        "audio_path": "path_to_audio_file",  // optional - uses segment_id if not provided
        "segment_id": "12345",              // optional - uses audio_path if not provided
        "custom_prompt": "Custom context for transcription...",  // optional
        "product_name": "TrueFire Guitar Lessons",              // optional - injected into prompt
        "course_title": "Advanced Fingerpicking Techniques",    // optional - injected into prompt
        "instructor_name": "John Doe",                          // optional - injected into prompt
        "preset": "balanced",                                   // optional - base preset to use
        "model_name": "medium",                                 // optional - override model
        "enable_guitar_term_evaluation": true,                 // optional - default true
        "comparison_mode": false                                // optional - compare with original prompt
    }
    """
    data = request.json
    
    if not data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data.'
        }), 400
    
    # Get audio source (either audio_path or segment_id)
    audio_path = data.get('audio_path')
    segment_id = data.get('segment_id')
    
    if not audio_path and not segment_id:
        return jsonify({
            'success': False,
            'message': 'Either audio_path or segment_id is required.'
        }), 400
    
    # Handle segment_id to audio_path conversion
    if segment_id and not audio_path:
        # Extract audio path from segment data
        segment_data = get_segment_from_database(segment_id)
        if not segment_data:
            return jsonify({
                'success': False,
                'message': f'Segment {segment_id} not found.'
            }), 404
        
        # Construct audio path based on segment data
        # Assuming standard path structure: /mnt/d_drive/truefire-courses/{course_id}/{segment_id}.wav
        course_id = segment_data.get('course_id', 1)  # Default to 1 if not found
        audio_path = f"/mnt/d_drive/truefire-courses/{course_id}/{segment_id}.wav"
    
    # Check if audio file exists
    if not os.path.exists(audio_path):
        return jsonify({
            'success': False,
            'message': f'Audio file not found: {audio_path}',
            'error': 'file_not_found'
        }), 404
    
    # Get configuration parameters
    preset_name = data.get('preset', 'balanced')
    model_name_override = data.get('model_name')
    custom_prompt = data.get('custom_prompt', '')
    product_name = data.get('product_name', '')
    course_title = data.get('course_title', '')
    instructor_name = data.get('instructor_name', '')
    enable_guitar_term_evaluation = data.get('enable_guitar_term_evaluation', True)
    comparison_mode = data.get('comparison_mode', False)
    
    logger.info(f"Testing custom prompt transcription for: {audio_path}")
    logger.info(f"Custom prompt length: {len(custom_prompt)} characters")
    if product_name:
        logger.info(f"Product name: {product_name}")
    if course_title:
        logger.info(f"Course title: {course_title}")
    
    try:
        # Get base preset configuration
        preset_config = get_preset_config(preset_name)
        
        # Override model if specified
        if model_name_override:
            preset_config['model_name'] = model_name_override
            logger.info(f"Model overridden to: {model_name_override}")
        
        # Build enhanced custom prompt with template variable support
        final_prompt = build_custom_prompt(
            custom_prompt=custom_prompt,
            product_name=product_name,
            course_title=course_title,
            instructor_name=instructor_name,
            base_preset_prompt=preset_config.get('initial_prompt', ''),
            segment_id=segment_id,
            preset_name=preset_name
        )
        
        # Update preset config with custom prompt
        custom_preset_config = preset_config.copy()
        custom_preset_config['initial_prompt'] = final_prompt
        custom_preset_config['enable_guitar_term_evaluation'] = enable_guitar_term_evaluation
        
        logger.info(f"Final custom prompt length: {len(final_prompt)} characters")
        
        results = {}
        
        # Process with custom prompt
        logger.info("Processing with custom prompt...")
        custom_start_time = time.time()
        
        custom_result = _process_audio_core(
            audio_path,
            preset_config=custom_preset_config,
            course_id=data.get('course_id'),
            segment_id=segment_id,
            preset_name=f"{preset_name}_custom"
        )
        
        custom_processing_time = time.time() - custom_start_time
        
        results['custom_prompt'] = {
            'transcript_text': custom_result.get('text', ''),
            'confidence_score': custom_result.get('confidence_score', 0.0),
            'segments': custom_result.get('segments', []),
            'word_segments': custom_result.get('word_segments', []),
            'processing_time': custom_processing_time,
            'model_used': custom_preset_config['model_name'],
            'prompt_used': final_prompt,
            'prompt_length': len(final_prompt),
            'guitar_term_evaluation': custom_result.get('guitar_term_evaluation', {}),
            'quality_metrics': custom_result.get('quality_metrics', {})
        }
        
        # Optional: Compare with original prompt
        if comparison_mode:
            logger.info("Processing with original preset prompt for comparison...")
            original_start_time = time.time()
            
            original_result = _process_audio_core(
                audio_path,
                preset_config=preset_config,
                course_id=data.get('course_id'),
                segment_id=segment_id,
                preset_name=preset_name
            )
            
            original_processing_time = time.time() - original_start_time
            
            results['original_prompt'] = {
                'transcript_text': original_result.get('text', ''),
                'confidence_score': original_result.get('confidence_score', 0.0),
                'segments': original_result.get('segments', []),
                'word_segments': original_result.get('word_segments', []),
                'processing_time': original_processing_time,
                'model_used': preset_config['model_name'],
                'prompt_used': preset_config.get('initial_prompt', ''),
                'prompt_length': len(preset_config.get('initial_prompt', '')),
                'guitar_term_evaluation': original_result.get('guitar_term_evaluation', {}),
                'quality_metrics': original_result.get('quality_metrics', {})
            }
            
            # Calculate comparison metrics
            results['comparison_analysis'] = analyze_prompt_comparison(
                results['custom_prompt'], 
                results['original_prompt']
            )
        
        # Prepare response
        response_data = {
            'success': True,
            'message': 'Custom prompt transcription completed',
            'service_timestamp': datetime.now().isoformat(),
            'test_configuration': {
                'audio_path': audio_path,
                'segment_id': segment_id,
                'preset_used': preset_name,
                'model_used': custom_preset_config['model_name'],
                'custom_prompt_provided': bool(custom_prompt),
                'product_name_injected': bool(product_name),
                'course_title_injected': bool(course_title),
                'instructor_name_injected': bool(instructor_name),
                'comparison_mode': comparison_mode,
                'guitar_term_evaluation_enabled': enable_guitar_term_evaluation
            },
            'results': results,
            'prompt_analysis': {
                'custom_prompt_length': len(final_prompt),
                'base_prompt_length': len(preset_config.get('initial_prompt', '')),
                'enhancement_applied': len(final_prompt) > len(preset_config.get('initial_prompt', '')),
                'product_context_added': bool(product_name or course_title or instructor_name)
            }
        }
        
        logger.info(f"Custom prompt test completed successfully")
        logger.info(f"Custom result confidence: {results['custom_prompt']['confidence_score']:.3f}")
        
        if comparison_mode:
            logger.info(f"Original result confidence: {results['original_prompt']['confidence_score']:.3f}")
            comparison = results['comparison_analysis']
            logger.info(f"Confidence improvement: {comparison['confidence_improvement']:.3f}")
        
        return jsonify(response_data)
        
    except Exception as e:
        error_type = type(e).__name__
        error_msg = str(e)
        logger.error(f"Error in custom prompt test ({error_type}): {error_msg}")
        
        return jsonify({
            'success': False,
            'message': f'Custom prompt test failed: {error_msg}',
            'error': error_msg,
            'error_type': error_type,
            'timestamp': datetime.now().isoformat()
        }), 500

def build_custom_prompt(custom_prompt='', product_name='', course_title='', instructor_name='', base_preset_prompt='', segment_id=None, preset_name=None):
    """
    Build an enhanced custom prompt by combining custom context with product-specific information.
    Now supports template variables like {{product_name}}, {{course_title}}, etc.
    
    Args:
        custom_prompt: User-provided custom prompt text (supports template variables)
        product_name: Name of the product/platform (e.g., "TrueFire Guitar Lessons")
        course_title: Title of the specific course
        instructor_name: Name of the instructor
        base_preset_prompt: Base prompt from preset configuration
        segment_id: Optional segment ID for template variables
        preset_name: Optional preset name for template variables
        
    Returns:
        Enhanced prompt string with product context and resolved template variables
        
    Template Variables Supported:
        {{product_name}} - Product/platform name
        {{course_title}} - Course title
        {{instructor_name}} - Instructor name
        {{segment_id}} - Segment ID
        {{preset}} - Preset name
    """
    import re
    
    # Define available template variables
    template_variables = {
        'product_name': product_name or '',
        'course_title': course_title or '',
        'instructor_name': instructor_name or '',
        'segment_id': str(segment_id) if segment_id else '',
        'preset': preset_name or ''
    }
    
    # Function to replace template variables
    def replace_template_var(match):
        var_name = match.group(1).strip()
        value = template_variables.get(var_name, '')
        if not value:
            logger.warning(f"Template variable '{var_name}' not provided, leaving as {{{{var_name}}}}")
            return f"{{{{{var_name}}}}}"  # Keep original if no value
        return value
    
    # Process custom prompt for template variables
    processed_custom_prompt = custom_prompt
    has_template_variables = False
    
    if custom_prompt and '{{' in custom_prompt:
        has_template_variables = True
        processed_custom_prompt = re.sub(r'\{\{([^}]+)\}\}', replace_template_var, custom_prompt)
        logger.info(f"Template variables processed in custom prompt: {custom_prompt[:100]}...")
        logger.info(f"Resolved to: {processed_custom_prompt[:100]}...")
    
    prompt_parts = []
    
    # If custom prompt uses template variables, prioritize it
    if has_template_variables and processed_custom_prompt.strip():
        # Template-based prompt takes full control
        final_prompt = processed_custom_prompt.strip()
        
        # Only add base preset context if template prompt seems short
        if len(final_prompt) < 150 and base_preset_prompt:
            # Add essential musical terminology from base prompt
            if "chord" in base_preset_prompt.lower() and "fretboard" in base_preset_prompt.lower():
                essential_context = " Essential musical terminology: Always transcribe 'chord' never 'cord' when referring to musical chords. Guitar hardware terms: fretboard, capo, pickup, strings, frets. Musical notes with proper spelling: C sharp (not 'see sharp'), D flat (not 'the flat'), F sharp, B flat. Guitar techniques: hammer-on, pull-off, fingerpicking (not 'finger picking'), string bending, vibrato."
                final_prompt += essential_context
        
        logger.debug(f"Template-based prompt built: {len(final_prompt)} characters")
        return final_prompt
    
    # Traditional prompt building (when no template variables used)
    
    # Start with product context if provided and no custom prompt
    if (product_name or course_title or instructor_name) and not processed_custom_prompt.strip():
        context_intro = "This is educational content from"
        
        if product_name:
            context_intro += f" {product_name}"
        
        if course_title:
            if instructor_name:
                context_intro += f", specifically the course '{course_title}' taught by {instructor_name}"
            else:
                context_intro += f", specifically the course '{course_title}'"
        elif instructor_name:
            context_intro += f", taught by {instructor_name}"
        
        context_intro += "."
        prompt_parts.append(context_intro)
    
    # Add custom prompt if provided (already processed for template variables)
    if processed_custom_prompt.strip():
        prompt_parts.append(processed_custom_prompt.strip())
    
    # Add base preset prompt if no custom prompt provided, or if custom prompt is short
    if not processed_custom_prompt.strip() and base_preset_prompt:
        prompt_parts.append(base_preset_prompt)
    elif processed_custom_prompt.strip() and len(processed_custom_prompt) < 200 and base_preset_prompt:
        # If custom prompt is short, append key parts of base prompt
        # Extract essential musical terminology guidance from base prompt
        if "chord" in base_preset_prompt.lower() and "fretboard" in base_preset_prompt.lower():
            essential_context = " Essential musical terminology: Always transcribe 'chord' never 'cord' when referring to musical chords. Guitar hardware terms: fretboard, capo, pickup, strings, frets. Musical notes with proper spelling: C sharp (not 'see sharp'), D flat (not 'the flat'), F sharp, B flat. Guitar techniques: hammer-on, pull-off, fingerpicking (not 'finger picking'), string bending, vibrato."
            prompt_parts.append(essential_context)
    
    # Combine all parts
    final_prompt = " ".join(prompt_parts)
    
    logger.debug(f"Built custom prompt with {len(prompt_parts)} parts, total length: {len(final_prompt)}")
    
    return final_prompt

def analyze_prompt_comparison(custom_result, original_result):
    """
    Analyze the differences between custom prompt and original prompt results.
    
    Args:
        custom_result: Results from custom prompt transcription
        original_result: Results from original prompt transcription
        
    Returns:
        Comparison analysis dictionary
    """
    analysis = {
        'confidence_scores': {
            'custom': custom_result['confidence_score'],
            'original': original_result['confidence_score'],
            'improvement': custom_result['confidence_score'] - original_result['confidence_score']
        },
        'word_counts': {
            'custom': len(custom_result.get('word_segments', [])),
            'original': len(original_result.get('word_segments', [])),
            'difference': len(custom_result.get('word_segments', [])) - len(original_result.get('word_segments', []))
        },
        'processing_times': {
            'custom': custom_result['processing_time'],
            'original': original_result['processing_time'],
            'difference': custom_result['processing_time'] - original_result['processing_time']
        },
        'text_comparison': {
            'custom_length': len(custom_result['transcript_text']),
            'original_length': len(original_result['transcript_text']),
            'similarity_percentage': calculate_text_similarity(
                custom_result['transcript_text'], 
                original_result['transcript_text']
            )
        }
    }
    
    # Guitar term evaluation comparison
    custom_guitar_eval = custom_result.get('guitar_term_evaluation', {})
    original_guitar_eval = original_result.get('guitar_term_evaluation', {})
    
    analysis['guitar_term_comparison'] = {
        'custom_terms_found': custom_guitar_eval.get('musical_terms_found', 0),
        'original_terms_found': original_guitar_eval.get('musical_terms_found', 0),
        'terms_improvement': custom_guitar_eval.get('musical_terms_found', 0) - original_guitar_eval.get('musical_terms_found', 0)
    }
    
    # Overall assessment
    confidence_improvement = analysis['confidence_scores']['improvement']
    terms_improvement = analysis['guitar_term_comparison']['terms_improvement']
    
    if confidence_improvement > 0.05 and terms_improvement >= 0:
        analysis['overall_assessment'] = 'Custom prompt shows significant improvement'
    elif confidence_improvement > 0.01:
        analysis['overall_assessment'] = 'Custom prompt shows modest improvement'
    elif abs(confidence_improvement) <= 0.01:
        analysis['overall_assessment'] = 'Results are similar between prompts'
    else:
        analysis['overall_assessment'] = 'Original prompt performed better'
    
    analysis['recommendation'] = get_prompt_recommendation(analysis)
    
    return analysis

def calculate_text_similarity(text1, text2):
    """Calculate basic text similarity percentage between two transcripts."""
    if not text1 or not text2:
        return 0.0
    
    # Simple word-based similarity
    words1 = set(text1.lower().split())
    words2 = set(text2.lower().split())
    
    if not words1 and not words2:
        return 100.0
    
    intersection = words1.intersection(words2)
    union = words1.union(words2)
    
    similarity = (len(intersection) / len(union)) * 100 if union else 0.0
    return round(similarity, 1)

def get_prompt_recommendation(analysis):
    """Generate recommendation based on prompt comparison analysis."""
    confidence_improvement = analysis['confidence_scores']['improvement']
    terms_improvement = analysis['guitar_term_comparison']['terms_improvement']
    
    recommendations = []
    
    if confidence_improvement > 0.05:
        recommendations.append("Custom prompt significantly improves transcription confidence")
    
    if terms_improvement > 0:
        recommendations.append(f"Custom prompt identified {terms_improvement} additional guitar terms")
    
    if analysis['text_comparison']['similarity_percentage'] < 80:
        recommendations.append("Significant differences in transcribed content - review both results")
    
    if confidence_improvement < -0.02:
        recommendations.append("Consider refining custom prompt - original may be more effective")
    
    if not recommendations:
        recommendations.append("Results are comparable - custom prompt provides alternative perspective")
    
    return recommendations

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)