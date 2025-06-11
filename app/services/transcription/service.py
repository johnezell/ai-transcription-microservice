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
    # Comprehensive musical terminology context - same for all presets
    GUITAR_LESSON_CONTEXT = '''This is a comprehensive guitar lesson with music instruction and educational content. The instructor provides detailed explanations of guitar techniques, music theory concepts, chord progressions, scale patterns, fingerpicking and strumming techniques, musical terminology, and educational guidance. CRITICAL TERMINOLOGY: Always transcribe "chord" never "cord" when referring to musical chords. Examples: C major chord, D minor chord, E dominant 7 chord, F sharp diminished chord, G suspended 4 chord, A minor 7 flat 5 chord, B flat major 9 chord. Musical notes with proper spelling: A, B, C, D, E, F, G with accidentals - C# (C sharp not "see sharp"), Db (D flat not "the flat"), F# (F sharp), Bb (B flat), Ab (A flat), Eb (E flat), G# (G sharp). Extended chords: major 7, minor 7, dominant 7, major 9, minor 9, add 9, sus2, sus4, 6/9, diminished 7, half-diminished, augmented. Guitar anatomy and hardware: fretboard (not "freight board" or "fret board" as two words), frets (metal strips), strings (high E string, B string, G string, D string, A string, low E string), capo (not "cape-o"), tuning pegs (not "tuning peggs"), nut, bridge, saddle, soundhole, pickup (not "pick up" as two words), volume knob, tone knob, pickup selector switch, tremolo system, whammy bar, locking nut. Advanced guitar techniques: fingerpicking patterns (not "finger picking"), hybrid picking, sweep picking, economy picking, alternate picking, string skipping, palm muting, pinch harmonics, natural harmonics, artificial harmonics, hammer-on (not "hammering"), pull-off (not "pulling off"), string bending, pre-bend, bend and release, vibrato, wide vibrato, finger vibrato, wrist vibrato, sliding, legato, staccato, tapping, two-handed tapping. Scale systems: major scale (Ionian mode), natural minor scale (Aeolian mode), harmonic minor scale, melodic minor scale, pentatonic major scale, pentatonic minor scale, blues scale, chromatic scale, whole tone scale, diminished scale, bebop scale. Modal theory: Ionian (major), Dorian, Phrygian, Lydian, Mixolydian, Aeolian (natural minor), Locrian. Chord theory and progressions: Roman numeral analysis (I-IV-V-I, ii-V-I, vi-IV-I-V, I-vi-ii-V), circle of fifths, secondary dominants, chord substitutions, tritone substitution, voice leading, inversions (first inversion, second inversion), slash chords. Rhythm and time: 4/4 time (four-four time not "four four time"), 3/4 time (three-four time), 2/4 time (two-four time), 6/8 time (six-eight time), 12/8 time (twelve-eight time), compound time, simple time, syncopation, polyrhythm, cross-rhythm. Musical intervals with proper names: perfect unison, minor second, major second, minor third, major third, perfect fourth, tritone (augmented fourth/diminished fifth), perfect fifth, minor sixth, major sixth, minor seventh, major seventh, perfect octave. Key signatures and scales: C major (no sharps or flats), G major (one sharp: F#), D major (two sharps: F#, C#), A major (three sharps: F#, C#, G#), E major (four sharps), B major (five sharps), F# major (six sharps), C# major (seven sharps), F major (one flat: Bb), Bb major (two flats: Bb, Eb), Eb major (three flats), Ab major (four flats), Db major (five flats), Gb major (six flats), Cb major (seven flats).'''
    
    # DEBUG: Log the actual length of the comprehensive context
    logger.info(f"PRESET DEBUG: GUITAR_LESSON_CONTEXT length: {len(GUITAR_LESSON_CONTEXT)} characters")
    logger.info(f"PRESET DEBUG: GUITAR_LESSON_CONTEXT preview: '{GUITAR_LESSON_CONTEXT[:150]}...'")
    
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
                logger.info(f"PROMPT DEBUG: Laravel rendered prompt length: {len(rendered_prompt)} characters")
                logger.info(f"PROMPT DEBUG: Laravel rendered prompt preview: {rendered_prompt[:100]}{'...' if len(rendered_prompt) > 100 else ''}")
                
                # CRITICAL: Compare lengths to detect if Laravel is returning a short prompt
                if len(rendered_prompt) < 500:
                    logger.warning(f"PROMPT DEBUG: Laravel returned suspiciously short prompt ({len(rendered_prompt)} chars), using static fallback instead")
                    logger.warning(f"PROMPT DEBUG: Short Laravel prompt was: '{rendered_prompt}'")
                    return static_prompt
                
                logger.info(f"PROMPT DEBUG: Using Laravel rendered prompt (length: {len(rendered_prompt)})")
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
                        preset_name: str = None) -> Dict:
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
    
    # Store metadata in result
    result["alignment_metadata"] = alignment_metadata
    result["diarization_metadata"] = diarization_metadata
    
    # Calculate confidence scores at multiple levels
    segments = result.get("segments", [])
    
    # Add segment-level confidence scores
    for segment in segments:
        segment_words = segment.get('words', [])
        if segment_words:
            # Calculate segment confidence as average of word confidences
            word_scores = [word.get('score', 0.0) for word in segment_words if word.get('score') is not None]
            if word_scores:
                segment['confidence'] = sum(word_scores) / len(word_scores)
            else:
                segment['confidence'] = 0.0
        else:
            # Fallback: estimate from text quality if no word-level data
            text_length = len(segment.get('text', ''))
            segment['confidence'] = min(0.8, max(0.3, text_length / 100.0))
    
    # CRITICAL FIX: Generate complete text from segments (WhisperX doesn't provide top-level 'text' field)
    segments = result.get("segments", [])
    complete_text = ""
    if segments:
        complete_text = " ".join(segment.get("text", "").strip() for segment in segments if segment.get("text"))
        complete_text = complete_text.strip()
    result["text"] = complete_text
    logger.info(f"Generated complete transcript text: {len(complete_text)} characters")
    
    # ENHANCED: Create clean word_segments array for real-time highlighting
    word_segments = []
    for segment in segments:
        for word in segment.get('words', []):
            word_segments.append({
                'word': word.get('word', ''),
                'start': word.get('start', 0),
                'end': word.get('end', 0), 
                'score': word.get('score', 0.0)
            })
    
    result["word_segments"] = word_segments
    if word_segments:
        logger.info(f"Generated {len(word_segments)} clean word segments for real-time highlighting")
    
    # CALCULATE: Original confidence score and quality metrics BEFORE guitar term enhancement
    # This preserves the baseline metrics for comparison with enhanced scores
    
    # Calculate segment-level confidence scores with original word scores
    for segment in segments:
        segment_words = segment.get('words', [])
        if segment_words:
            # Calculate segment confidence as average of word confidences
            word_scores = [word.get('score', 0.0) for word in segment_words if word.get('score') is not None]
            if word_scores:
                segment['confidence'] = sum(word_scores) / len(word_scores)
            else:
                segment['confidence'] = 0.0
        else:
            # Fallback: estimate from text quality if no word-level data
            text_length = len(segment.get('text', ''))
            segment['confidence'] = min(0.8, max(0.3, text_length / 100.0))
    
    # Calculate original overall confidence score and quality metrics
    original_confidence_score = calculate_confidence(segments)
    original_quality_metrics = calculate_comprehensive_quality_metrics(result, segments, word_segments)
    
    # Store original metrics for comparison
    result["original_metrics"] = {
        "confidence_score": original_confidence_score,
        "quality_metrics": original_quality_metrics,
        "calculated_before_enhancement": True,
        "note": "These metrics reflect the raw transcription quality before guitar term enhancement"
    }
    
    logger.info(f"Original transcription metrics - Confidence: {original_confidence_score:.3f}, "
               f"Overall quality: {original_quality_metrics.get('overall_quality_score', 0):.3f}")
    
    # ENHANCED: Guitar terminology evaluation and confidence boosting
    enable_guitar_term_evaluation = True  # Default enabled
    if preset_config:
        enable_guitar_term_evaluation = preset_config.get('enable_guitar_term_evaluation', True)
    
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
            
        except ImportError:
            logger.warning("Guitar terminology evaluator not available - skipping enhancement")
        except Exception as e:
            logger.error(f"Guitar terminology enhancement failed: {e} - continuing without enhancement")
    else:
        logger.info("Guitar terminology evaluation disabled by preset configuration")
    
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
    
    # Calculate enhanced overall confidence score and quality metrics
    enhanced_confidence_score = calculate_confidence(segments)
    enhanced_quality_metrics = calculate_comprehensive_quality_metrics(result, segments, word_segments)
    
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
        
        # FIXED: Use hardcoded service presets directly, skip Laravel template rendering
        # Laravel was returning a short "Guitar lesson music instruction" instead of comprehensive context
        effective_initial_prompt = preset_config.get('initial_prompt', '')
        logger.info(f"PROMPT DEBUG: Using hardcoded service preset - Length: {len(effective_initial_prompt)} characters")
        logger.info(f"PROMPT DEBUG: Skipped Laravel template rendering to ensure comprehensive context is used")
        
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
        
        # Run all post-processing steps using the new dedicated function
        logger.info("Step 2: Starting post-processing (alignment, diarization, enhancement, metrics)")
        result = _run_post_processing(
            transcription_result=result,
            audio_file=audio_file,
            audio_path=audio_path,
            preset_config=preset_config,
            performance_metrics=performance_metrics,
            effective_language=effective_language,
            min_speakers=min_speakers,
            max_speakers=max_speakers,
            preset_name=preset_name
        )
        
        # Calculate total processing time
        performance_metrics['total_processing_time'] = time.time() - processing_start_time
        
        # Include comprehensive settings and metadata in result
        # DEBUG: Log what we're putting in settings before saving
        logger.info(f"SETTINGS DEBUG: About to save settings with initial_prompt length: {len(settings.get('initial_prompt', ''))}")
        logger.info(f"SETTINGS DEBUG: Settings initial_prompt preview: '{settings.get('initial_prompt', '')[:100]}{'...' if len(settings.get('initial_prompt', '')) > 100 else ''}'")
        
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
        
        # Check for intelligent selection parameter (enabled by default for internal tool)
        enable_intelligent_selection = data.get('enable_intelligent_selection', True)
        
        # Check for optimal selection mode (disabled by default for speed)
        enable_optimal_selection = data.get('enable_optimal_selection', False)
        
        # Process the audio with Whisper (using preset config or legacy parameters)
        transcription_result = process_audio(
            audio_path, 
            model_name, 
            initial_prompt, 
            preset_config,
            course_id=course_id,
            segment_id=segment_id,
            preset_name=preset_name,
            enable_intelligent_selection=enable_intelligent_selection,
            enable_optimal_selection=enable_optimal_selection
        )
        
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
        
        # Check for intelligent selection parameter (enabled by default for internal tool)
        enable_intelligent_selection = data.get('enable_intelligent_selection', True)
        
        # Check for optimal selection mode (disabled by default for speed)
        enable_optimal_selection = data.get('enable_optimal_selection', False)
        
        # Process the audio with Whisper using preset configuration
        transcription_result = process_audio(
            full_audio_path, 
            preset_config=preset_config,
            course_id=course_id,
            segment_id=segment_id,
            preset_name=preset_name,
            enable_intelligent_selection=enable_intelligent_selection,
            enable_optimal_selection=enable_optimal_selection
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
            'api_endpoints': {
                'transcription': ['/process', '/transcribe', '/transcribe-parallel', '/test-optimal-selection', '/test-enhancement-modes', '/test-guitar-term-evaluator'],
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
        "audio_path": "path/to/audio.wav",  # Optional - will use mock data if not provided
        "llm_endpoint": "http://localhost:11434/api/generate",  # Optional
        "model_name": "llama2"  # Optional
    }
    """
    data = request.json or {}
    
    audio_path = data.get('audio_path')
    llm_endpoint = data.get('llm_endpoint', 'http://localhost:11434/api/generate')
    model_name = data.get('model_name', 'llama2')
    
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

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)