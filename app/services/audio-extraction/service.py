#!/usr/bin/env python3
from flask import Flask, request, jsonify
import os
import requests
import json
import logging
import subprocess
from datetime import datetime
import tempfile
import uuid
from typing import Dict, List, Optional, Union
from speech_quality_analyzer import SpeechQualityAnalyzer, analyze_speech_quality, compare_audio_files
from whisper_quality_analyzer import WhisperQualityAnalyzer, analyze_with_whisper_testing

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Audio Processing Configuration with Environment Variable Support
AUDIO_PROCESSING_CONFIG = {
    "default_quality": os.environ.get('AUDIO_QUALITY_LEVEL', 'balanced'),
    "enable_normalization": os.environ.get('ENABLE_NORMALIZATION', 'true').lower() == 'true',
    "enable_vad": os.environ.get('ENABLE_VAD', 'false').lower() == 'true',
    "max_threads": int(os.environ.get('FFMPEG_THREADS', '4'))
}

# Create Flask app
app = Flask(__name__)

# Set environment constants for cleaner path handling
S3_BASE_DIR = '/var/www/storage/app/public/s3'
S3_JOBS_DIR = os.path.join(S3_BASE_DIR, 'jobs')

# Get environment variables
LARAVEL_API_URL = os.environ.get('LARAVEL_API_URL', 'http://laravel/api')

# Ensure base directory exists
os.makedirs(S3_JOBS_DIR, exist_ok=True)

def validate_audio_input(input_path):
    """Validate audio input using ffprobe."""
    try:
        logger.info(f"Validating audio input: {input_path}")
        
        command = [
            "ffprobe", "-v", "error",
            "-select_streams", "a:0",
            "-show_entries", "stream=codec_name,sample_rate,channels,duration",
            "-of", "json",
            str(input_path)
        ]
        
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            raise RuntimeError(f"FFprobe validation error: {result.stderr}")
        
        probe_data = json.loads(result.stdout)
        
        if not probe_data.get('streams'):
            raise RuntimeError("No audio streams found in input file")
        
        stream = probe_data['streams'][0]
        validation_info = {
            'codec': stream.get('codec_name', 'unknown'),
            'sample_rate': int(stream.get('sample_rate', 0)),
            'channels': int(stream.get('channels', 0)),
            'duration': float(stream.get('duration', 0))
        }
        
        logger.info(f"Audio validation successful: {validation_info}")
        return validation_info
        
    except Exception as e:
        logger.error(f"Audio validation failed: {str(e)}")
        raise

def assess_audio_quality(audio_path):
    """Assess audio quality metrics using ffprobe."""
    try:
        logger.info(f"Assessing audio quality: {audio_path}")
        
        command = [
            "ffprobe", "-v", "error",
            "-select_streams", "a:0",
            "-show_entries", "stream=bit_rate,sample_rate,channels",
            "-show_entries", "format=bit_rate,duration",
            "-of", "json",
            str(audio_path)
        ]
        
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            logger.warning(f"Quality assessment failed: {result.stderr}")
            return None
        
        probe_data = json.loads(result.stdout)
        stream = probe_data.get('streams', [{}])[0]
        format_info = probe_data.get('format', {})
        
        quality_metrics = {
            'bit_rate': int(stream.get('bit_rate', 0)) or int(format_info.get('bit_rate', 0)),
            'sample_rate': int(stream.get('sample_rate', 0)),
            'channels': int(stream.get('channels', 0)),
            'duration': float(format_info.get('duration', 0))
        }
        
        logger.info(f"Audio quality assessment: {quality_metrics}")
        return quality_metrics
        
    except Exception as e:
        logger.warning(f"Quality assessment error: {str(e)}")
        return None

def apply_vad_preprocessing(input_path: str, output_path: str) -> bool:
    """
    Apply Voice Activity Detection preprocessing with advanced silence removal.
    
    Args:
        input_path: Path to input audio file
        output_path: Path to output audio file
        
    Returns:
        bool: True if successful, False otherwise
        
    Raises:
        RuntimeError: If VAD preprocessing fails
    """
    try:
        logger.info(f"Applying VAD preprocessing: {input_path} -> {output_path}")
        
        # Advanced silence removal with bidirectional processing
        command = [
            "ffmpeg", "-y", "-i", str(input_path), "-vn",
            "-af", "silenceremove=start_periods=1:start_duration=1:start_threshold=-60dB:detection=peak,aformat=dblp,areverse,silenceremove=start_periods=1:start_duration=1:start_threshold=-60dB:detection=peak,aformat=dblp,areverse",
            "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
            str(output_path)
        ]
        
        logger.info(f"VAD FFmpeg command: {' '.join(command)}")
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            raise RuntimeError(f"VAD preprocessing failed: {result.stderr}")
        
        # Verify output file exists and has content
        if not os.path.exists(output_path) or os.path.getsize(output_path) == 0:
            raise RuntimeError("VAD preprocessing produced empty or missing output file")
        
        logger.info(f"VAD preprocessing completed successfully: {output_path}")
        return True
        
    except Exception as e:
        logger.error(f"VAD preprocessing failed: {str(e)}")
        raise

def calculate_processing_metrics() -> Dict[str, float]:
    """
    Calculate processing performance metrics.
    
    Returns:
        Dict containing processing metrics
    """
    try:
        # TODO: Implement actual metrics calculation based on processing history
        # For now, return placeholder values that can be extended
        metrics = {
            'avg_processing_time': 0.0,  # Average processing time in seconds
            'quality_score': 0.0,        # Quality assessment score (0-100)
            'error_rate': 0.0             # Error rate percentage (0-100)
        }
        
        logger.info(f"Processing metrics calculated: {metrics}")
        return metrics
        
    except Exception as e:
        logger.error(f"Error calculating processing metrics: {str(e)}")
        return {
            'avg_processing_time': 0.0,
            'quality_score': 0.0,
            'error_rate': 0.0
        }

def preprocess_for_whisper(input_path: str, output_path: str, quality_level: str = "balanced") -> bool:
    """
    Enhanced audio preprocessing for Whisper transcription with configurable quality levels.
    
    Args:
        input_path: Path to input audio file
        output_path: Path to output WAV file
        quality_level: Quality level ('fast', 'balanced', 'high')
        
    Returns:
        bool: True if successful, False otherwise
        
    Raises:
        RuntimeError: If preprocessing fails
    """
    try:
        logger.info(f"Preprocessing audio for Whisper (quality: {quality_level}): {input_path} -> {output_path}")
        
        # Validate input first
        validation_info = validate_audio_input(input_path)
        logger.info(f"Input validation passed: {validation_info}")
        
        # Define quality-specific filter chains and thread counts
        quality_configs = {
            "fast": {
                "filters": ["dynaudnorm=p=0.9:s=5"],
                "threads": 2
            },
            "balanced": {
                "filters": ["highpass=f=80", "lowpass=f=8000", "dynaudnorm=p=0.9:s=5"],
                "threads": 4
            },
            "high": {
                "filters": ["highpass=f=80", "lowpass=f=8000", "dynaudnorm=p=0.9:s=5:r=0.9", "afftdn=nf=-25"],
                "threads": 6
            },
            "premium": {
                "filters": ["highpass=f=80", "lowpass=f=8000", "dynaudnorm=p=0.9:s=5:r=0.9", "afftdn=nf=-25", "compand=0.3|0.3:1|1:-90/-60|-60/-40|-40/-30|-20/-20:6:0:-90:0.2"],
                "threads": 8,
                "use_vad": True
            }
        }
        
        # Get configuration for the specified quality level
        if quality_level not in quality_configs:
            logger.warning(f"Unknown quality level '{quality_level}', falling back to 'balanced'")
            quality_level = "balanced"
            
        config = quality_configs[quality_level]
        filter_chain = ",".join(config["filters"])
        thread_count = min(config["threads"], AUDIO_PROCESSING_CONFIG["max_threads"])
        
        # Handle VAD preprocessing for premium quality
        if quality_level == "premium" and AUDIO_PROCESSING_CONFIG["enable_vad"]:
            logger.info("Applying VAD preprocessing for premium quality")
            vad_temp_path = output_path + ".vad_temp.wav"
            try:
                # Apply VAD preprocessing first
                apply_vad_preprocessing(input_path, vad_temp_path)
                input_for_processing = vad_temp_path
            except Exception as e:
                logger.warning(f"VAD preprocessing failed, using original input: {str(e)}")
                input_for_processing = input_path
        else:
            input_for_processing = input_path
        
        # Build FFmpeg command with quality-specific settings
        command = [
            "ffmpeg", "-y", "-threads", str(thread_count),
            "-i", str(input_for_processing), "-vn",
            "-af", filter_chain,
            "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
            "-sample_fmt", "s16", str(output_path)
        ]
        
        logger.info(f"FFmpeg command (quality: {quality_level}, threads: {thread_count}): {' '.join(command)}")
        result = subprocess.run(command, capture_output=True, text=True)
        
        # Clean up VAD temp file if it was created
        if quality_level == "premium" and AUDIO_PROCESSING_CONFIG["enable_vad"]:
            vad_temp_path = output_path + ".vad_temp.wav"
            if os.path.exists(vad_temp_path):
                try:
                    os.remove(vad_temp_path)
                    logger.info("Cleaned up VAD temporary file")
                except Exception as e:
                    logger.warning(f"Failed to clean up VAD temp file: {str(e)}")
        
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg preprocessing failed: {result.stderr}")
        
        # Assess output quality
        quality_metrics = assess_audio_quality(output_path)
        if quality_metrics:
            logger.info(f"Output quality metrics: {quality_metrics}")
            
        logger.info(f"Successfully preprocessed audio for Whisper (quality: {quality_level}): {output_path}")
        return True
        
    except Exception as e:
        logger.error(f"Audio preprocessing failed: {str(e)}")
        raise

def convert_to_wav_original(input_path, output_path):
    """Original convert media to WAV format (preserved for rollback)."""
    try:
        logger.info(f"Converting media to WAV (original): {input_path} -> {output_path}")
        
        command = [
            "ffmpeg", "-y",  # Overwrite output
            "-i", str(input_path),
            "-vn",  # Disable video
            "-acodec", "pcm_s16le",  # Force pcm format
            "-ar", "16000",  # Sample rate
            "-ac", "1",  # Mono
            str(output_path)
        ]
        logger.debug(f"FFmpeg command (original): {' '.join(command)}")
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg error: {result.stderr}")
            
        logger.info(f"Successfully converted to WAV (original): {output_path}")
        return True
        
    except Exception as e:
        logger.error(f"Conversion failed (original): {str(e)}")
        raise

def convert_to_wav(input_path, output_path, quality_level: Optional[str] = None):
    """
    Convert media to WAV format optimized for transcription with configurable quality levels.
    
    Args:
        input_path: Path to input media file
        output_path: Path to output WAV file
        quality_level: Quality level override ('fast', 'balanced', 'high')
    
    Returns:
        bool: True if successful, False otherwise
    """
    try:
        # Use provided quality level or default from configuration
        effective_quality = quality_level or AUDIO_PROCESSING_CONFIG["default_quality"]
        
        logger.info(f"Converting media to WAV (quality: {effective_quality}): {input_path} -> {output_path}")
        
        # Check if normalization is enabled
        if AUDIO_PROCESSING_CONFIG["enable_normalization"]:
            # Use enhanced preprocessing with quality levels
            return preprocess_for_whisper(input_path, output_path, effective_quality)
        else:
            logger.info("Audio normalization disabled, using original conversion method")
            return convert_to_wav_original(input_path, output_path)
        
    except Exception as e:
        logger.error(f"Enhanced conversion failed, attempting fallback: {str(e)}")
        # Fallback to original method if enhanced processing fails
        try:
            return convert_to_wav_original(input_path, output_path)
        except Exception as fallback_error:
            logger.error(f"Fallback conversion also failed: {str(fallback_error)}")
            raise

def get_audio_duration(audio_path):
    """Get audio duration using ffprobe."""
    try:
        command = [
            "ffprobe", 
            "-v", "error", 
            "-show_entries", "format=duration", 
            "-of", "default=noprint_wrappers=1:nokey=1", 
            audio_path
        ]
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            logger.warning(f"Failed to get duration: {result.stderr}")
            return None
            
        duration = float(result.stdout.strip())
        return duration
        
    except Exception as e:
        logger.warning(f"Error getting duration: {str(e)}")
        return None

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
    """Enhanced health check endpoint with Phase 3 information."""
    return jsonify({
        'status': 'healthy',
        'service': 'audio-extraction-service',
        'version': 'Phase 3',
        'timestamp': datetime.now().isoformat(),
        'features': {
            'quality_levels': ['fast', 'balanced', 'high', 'premium'],
            'vad_enabled': AUDIO_PROCESSING_CONFIG["enable_vad"],
            'normalization_enabled': AUDIO_PROCESSING_CONFIG["enable_normalization"],
            'max_threads': AUDIO_PROCESSING_CONFIG["max_threads"],
            'default_quality': AUDIO_PROCESSING_CONFIG["default_quality"]
        },
        'capabilities': {
            'voice_activity_detection': True,
            'premium_quality_processing': True,
            'advanced_noise_reduction': True,
            'dynamic_audio_normalization': True,
            'processing_metrics': True
        }
    })

@app.route('/metrics', methods=['GET'])
def metrics_endpoint():
    """Processing metrics endpoint for Phase 3."""
    try:
        metrics = calculate_processing_metrics()
        
        return jsonify({
            'success': True,
            'service': 'audio-extraction-service',
            'timestamp': datetime.now().isoformat(),
            'metrics': metrics,
            'configuration': {
                'quality_levels': ['fast', 'balanced', 'high', 'premium'],
                'vad_enabled': AUDIO_PROCESSING_CONFIG["enable_vad"],
                'normalization_enabled': AUDIO_PROCESSING_CONFIG["enable_normalization"],
                'max_threads': AUDIO_PROCESSING_CONFIG["max_threads"],
                'default_quality': AUDIO_PROCESSING_CONFIG["default_quality"]
            }
        })
        
    except Exception as e:
        logger.error(f"Error retrieving metrics: {str(e)}")
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
def process_audio_extraction():
    """Extract audio from a video file."""
    data = request.json
    
    if not data or 'job_id' not in data:
        return jsonify({
            'success': False,
            'message': 'Invalid request data. job_id is required.'
        }), 400
    
    job_id = data['job_id']
    test_mode = data.get('test_mode', False)
    video_path_param = data.get('video_path')
    quality_level = data.get('quality_level', 'balanced')
    segment_id = data.get('segment_id')
    enable_quality_analysis = data.get('enable_quality_analysis', False)
    
    logger.info(f"Processing job {job_id} (test_mode: {test_mode}, quality: {quality_level}, quality_analysis: {enable_quality_analysis})")
    
    # Handle path construction - always use D drive paths now
    if video_path_param:
        # Use the provided video path directly
        # The video_path should be something like "truefire-courses/1/7959.mp4"
        # We need to construct the full path to the d_drive location
        d_drive_base = '/mnt/d_drive'  # This should match the Docker volume mount
        video_path = os.path.join(d_drive_base, video_path_param)
        
        # Extract directory and filename for audio output
        video_dir = os.path.dirname(video_path)
        video_filename = os.path.basename(video_path)
        
        # Determine segment ID for audio filename
        if segment_id:
            base_name = str(segment_id)
        else:
            # Extract segment ID from video filename (e.g., "7959.mp4" -> "7959")
            base_name = os.path.splitext(video_filename)[0]
        
        # Create audio filename based on mode and settings
        if test_mode:
            # For test mode, include quality level and test settings to avoid overwriting
            test_settings = data.get('test_settings', {})
            settings_suffix = ""
            
            if test_settings:
                # Create a short hash of test settings for filename
                import hashlib
                settings_str = json.dumps(test_settings)
                settings_hash = hashlib.md5(settings_str.encode()).hexdigest()[:8]
                settings_suffix = f"_s{settings_hash}"
            
            # Create unique filename: segmentId_qualityLevel_settingsHash.wav
            audio_filename = f"{base_name}_{quality_level}{settings_suffix}.wav"
        else:
            # For regular mode, use simple segment ID filename
            audio_filename = f"{base_name}.wav"
        
        audio_path = os.path.join(video_dir, audio_filename)
        
        logger.info(f"D drive paths - Video: {video_path}, Audio: {audio_path}")
        
    else:
        # Fallback to legacy mode only if no video_path_param provided
        # This should rarely happen with the updated Laravel backend
        logger.warning("No video_path_param provided, falling back to legacy S3 structure")
        job_dir = os.path.join(S3_JOBS_DIR, job_id)
        os.makedirs(job_dir, exist_ok=True)
        
        # Standardized paths
        video_path = os.path.join(job_dir, 'video.mp4')
        audio_path = os.path.join(job_dir, 'audio.wav')
        
        logger.info(f"Legacy fallback paths - Video: {video_path}, Audio: {audio_path}")
    
    # Basic directory structure check for debugging
    try:
        # Check the parent directory of the video file for D drive paths
        if video_path_param:
            video_dir = os.path.dirname(video_path)
            if os.path.exists(video_dir):
                dir_contents = os.listdir(video_dir)
                logger.info(f"Video directory contents: {dir_contents}")
            else:
                logger.error(f"Video directory {video_dir} does not exist")
        else:
            # Legacy mode - check job directory
            job_dir = os.path.join(S3_JOBS_DIR, job_id)
            if os.path.exists(job_dir):
                dir_contents = os.listdir(job_dir)
                logger.info(f"Job directory contents: {dir_contents}")
            else:
                logger.error(f"Job directory {job_dir} does not exist")
                logger.info(f"Creating job directory: {job_dir}")
                os.makedirs(job_dir, exist_ok=True)
    except Exception as e:
        logger.error(f"Error with directory check: {str(e)}")
    
    try:
        # Check if the video file exists
        if not os.path.exists(video_path):
            error_msg = f"Video file not found at path: {video_path}"
            logger.error(error_msg)
            
            # Report error to Laravel
            update_job_status(job_id, 'failed', None, error_msg)
            return jsonify({
                'success': False,
                'message': error_msg
            }), 404
            
        # Update status to extracting_audio
        update_job_status(job_id, 'extracting_audio')
        
        # Ensure the output directory exists (important for test mode)
        audio_dir = os.path.dirname(audio_path)
        if not os.path.exists(audio_dir):
            logger.info(f"Creating audio output directory: {audio_dir}")
            os.makedirs(audio_dir, exist_ok=True)
        
        # Handle quality analysis if enabled
        if enable_quality_analysis and segment_id:
            logger.info(f"Quality analysis enabled for segment {segment_id}")
            
            # Generate all quality levels
            quality_levels = ['fast', 'balanced', 'high', 'premium']
            quality_files = []
            
            # Extract audio with all quality levels
            for ql in quality_levels:
                ql_audio_path = f"{audio_path.replace('.wav', '')}_{ql}.wav"
                try:
                    convert_to_wav(video_path, ql_audio_path, ql)
                    if os.path.exists(ql_audio_path):
                        quality_files.append(ql_audio_path)
                        logger.info(f"Generated {ql} quality: {ql_audio_path}")
                except Exception as e:
                    logger.error(f"Failed to generate {ql} quality: {str(e)}")
            
            if len(quality_files) > 1:
                # Use quality analyzer to select best file
                try:
                    best_file = select_best_audio_quality(quality_files, use_whisper_testing=False)
                    logger.info(f"Quality analysis selected: {best_file}")
                    
                    # Get detailed analysis for the best file
                    analysis_result = analyze_speech_quality(best_file)
                    
                    # Copy best file to the standard segment filename
                    final_audio_path = os.path.join(os.path.dirname(audio_path), f"{segment_id}.wav")
                    import shutil
                    shutil.copy2(best_file, final_audio_path)
                    
                    # Create analysis file with reasoning
                    analysis_file_path = f"{final_audio_path}.analysis"
                    analysis_data = {
                        'selected_file': best_file,
                        'selected_quality': os.path.basename(best_file).split('_')[-1].replace('.wav', ''),
                        'selection_timestamp': datetime.now().isoformat(),
                        'overall_score': analysis_result.get('overall_score', 0),
                        'grade': analysis_result.get('grade', 'Unknown'),
                        'reasoning': f"Selected {os.path.basename(best_file)} with score {analysis_result.get('overall_score', 0)}/100",
                        'detailed_analysis': analysis_result,
                        'all_files_generated': [os.path.basename(f) for f in quality_files],
                        'quality_comparison': {
                            'total_files_analyzed': len(quality_files),
                            'selection_method': 'technical_analysis_weighted_scoring',
                            'metrics_used': ['sample_rate', 'volume_level', 'dynamic_range', 'duration', 'bit_rate']
                        }
                    }
                    
                    # Write analysis file
                    with open(analysis_file_path, 'w') as f:
                        json.dump(analysis_data, f, indent=2)
                    
                    logger.info(f"Created analysis file: {analysis_file_path}")
                    
                    # Update audio_path to point to the final selected file
                    audio_path = final_audio_path
                    
                except Exception as e:
                    logger.error(f"Quality analysis failed: {str(e)}")
                    # Fallback to original single quality extraction
                    convert_to_wav(video_path, audio_path, quality_level)
            else:
                # Fallback to single quality if multi-quality failed
                convert_to_wav(video_path, audio_path, quality_level)
        else:
            # Standard single quality extraction
            convert_to_wav(video_path, audio_path, quality_level)
        
        # Get file size and other metadata
        audio_size = os.path.getsize(audio_path)
        duration = get_audio_duration(audio_path)
        
        # Prepare response data
        response_data = {
            'message': 'Audio extraction completed successfully',
            'service_timestamp': datetime.now().isoformat(),
            'audio_path': audio_path,
            'audio_size_bytes': audio_size,
            'duration_seconds': duration,
            'test_mode': test_mode,
            'quality_level': quality_level,
            'segment_id': segment_id,
            'enable_quality_analysis': enable_quality_analysis,
            'metadata': {
                'service': 'audio-extraction-service',
                'processed_by': 'FFmpeg audio extraction',
                'format': 'WAV',
                'sample_rate': '16000 Hz',
                'channels': '1 (Mono)',
                'codec': 'PCM 16-bit',
                'quality_level': quality_level,
                'test_mode': test_mode,
                'quality_analysis_enabled': enable_quality_analysis
            }
        }
        
        # Add quality analysis info to response if it was used
        if enable_quality_analysis and segment_id:
            analysis_file_path = f"{audio_path}.analysis"
            if os.path.exists(analysis_file_path):
                response_data['quality_analysis'] = {
                    'analysis_file': analysis_file_path,
                    'analysis_available': True,
                    'final_audio_file': audio_path
                }
        
        # Update job status in Laravel
        if test_mode:
            # For test mode, mark as completed since we're only extracting audio
            update_job_status(job_id, 'completed', response_data)
        else:
            # For regular mode, continue to transcription
            update_job_status(job_id, 'processing', response_data)
        
        return jsonify({
            'success': True,
            'job_id': job_id,
            'message': 'Audio extraction processed successfully',
            'data': response_data
        })
        
    except Exception as e:
        logger.error(f"Error processing job {job_id}: {str(e)}")
        
        # Update job status in Laravel
        update_job_status(job_id, 'failed', None, str(e))
        
        return jsonify({
            'success': False,
            'job_id': job_id,
            'message': f'Audio extraction failed: {str(e)}'
        }), 500


def select_best_audio_quality(audio_files: List[str], use_whisper_testing: bool = False) -> str:
    """
    Select best audio file from multiple options for Whisper transcription.
    
    Args:
        audio_files: List of paths to audio files
        use_whisper_testing: Whether to include actual Whisper confidence testing
        
    Returns:
        Path to the best audio file
        
    Raises:
        ValueError: If no files provided or no files could be analyzed
    """
    logger.info(f"Selecting best audio quality from {len(audio_files)} files (Whisper testing: {use_whisper_testing})")
    
    if not audio_files:
        raise ValueError("No audio files provided")
        
    if len(audio_files) == 1:
        logger.info(f"Only one file provided, returning: {audio_files[0]}")
        return audio_files[0]
        
    try:
        if use_whisper_testing:
            # Use comprehensive analysis with Whisper testing
            analyzer = WhisperQualityAnalyzer()
            result = analyzer.compare_with_whisper_testing(audio_files)
        else:
            # Use technical analysis only
            result = compare_audio_files(audio_files)
            
        if not result['success']:
            raise ValueError(f"Quality analysis failed: {result.get('error', 'Unknown error')}")
            
        best_file = result['best_file']
        best_score = result['best_score']
        
        logger.info(f"Selected best audio file: {best_file} (score: {best_score:.2f}/100)")
        return best_file
        
    except Exception as e:
        logger.error(f"Error selecting best audio quality: {str(e)}")
        # Fallback to first file if analysis fails
        logger.warning(f"Falling back to first file: {audio_files[0]}")
        return audio_files[0]


def batch_quality_analysis(input_directory: str, quality_levels: List[str] = None) -> Dict:
    """
    Analyze multiple quality levels of same source audio.
    
    Args:
        input_directory: Directory containing audio files
        quality_levels: List of quality levels to filter by (optional)
        
    Returns:
        Dictionary with batch analysis results
    """
    logger.info(f"Performing batch quality analysis on directory: {input_directory}")
    
    if not os.path.exists(input_directory):
        return {
            'success': False,
            'error': f"Directory not found: {input_directory}",
            'results': []
        }
        
    # Find all WAV files in directory
    audio_files = []
    for filename in os.listdir(input_directory):
        if filename.lower().endswith('.wav'):
            full_path = os.path.join(input_directory, filename)
            
            # Filter by quality levels if specified
            if quality_levels:
                # Check if filename contains any of the specified quality levels
                if not any(level in filename.lower() for level in quality_levels):
                    continue
                    
            audio_files.append(full_path)
            
    if not audio_files:
        return {
            'success': False,
            'error': f"No WAV files found in directory: {input_directory}",
            'results': []
        }
        
    logger.info(f"Found {len(audio_files)} audio files for batch analysis")
    
    try:
        # Analyze each file individually
        analyzer = SpeechQualityAnalyzer()
        results = []
        
        for audio_file in audio_files:
            analysis = analyzer.analyze_speech_quality(audio_file)
            results.append(analysis)
            
        # Sort by score (highest first)
        successful_results = [r for r in results if r['success']]
        successful_results.sort(key=lambda x: x['overall_score'], reverse=True)
        
        # Calculate summary statistics
        if successful_results:
            scores = [r['overall_score'] for r in successful_results]
            avg_score = sum(scores) / len(scores)
            min_score = min(scores)
            max_score = max(scores)
        else:
            avg_score = min_score = max_score = 0.0
            
        return {
            'success': True,
            'timestamp': datetime.now().isoformat(),
            'directory': input_directory,
            'files_found': len(audio_files),
            'files_analyzed': len(successful_results),
            'files_failed': len(audio_files) - len(successful_results),
            'best_file': successful_results[0]['audio_path'] if successful_results else None,
            'best_score': max_score,
            'summary_stats': {
                'avg_score': round(avg_score, 2),
                'min_score': round(min_score, 2),
                'max_score': round(max_score, 2),
                'score_range': round(max_score - min_score, 2)
            },
            'results': results
        }
        
    except Exception as e:
        logger.error(f"Error in batch quality analysis: {str(e)}")
        return {
            'success': False,
            'error': str(e),
            'results': []
        }


@app.route('/analyze-quality', methods=['POST'])
def analyze_audio_quality_endpoint():
    """API endpoint for audio quality analysis."""
    try:
        data = request.get_json()
        
        if not data or 'audio_path' not in data:
            return jsonify({
                'success': False,
                'error': 'audio_path is required'
            }), 400
            
        audio_path = data['audio_path']
        use_whisper_testing = data.get('use_whisper_testing', False)
        
        logger.info(f"API request for quality analysis: {audio_path} (Whisper: {use_whisper_testing})")
        
        if use_whisper_testing:
            # Use comprehensive analysis with Whisper testing
            result = analyze_with_whisper_testing(audio_path)
        else:
            # Use technical analysis only
            result = analyze_speech_quality(audio_path)
            
        return jsonify(result)
        
    except Exception as e:
        logger.error(f"Error in analyze-quality endpoint: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500


@app.route('/select-best-audio', methods=['POST'])
def select_best_audio_endpoint():
    """API endpoint to select best audio from multiple files."""
    try:
        data = request.get_json()
        
        if not data or 'audio_files' not in data:
            return jsonify({
                'success': False,
                'error': 'audio_files list is required'
            }), 400
            
        audio_files = data['audio_files']
        use_whisper_testing = data.get('use_whisper_testing', False)
        
        if not isinstance(audio_files, list) or len(audio_files) == 0:
            return jsonify({
                'success': False,
                'error': 'audio_files must be a non-empty list'
            }), 400
            
        logger.info(f"API request to select best audio from {len(audio_files)} files (Whisper: {use_whisper_testing})")
        
        # Select best file
        best_file = select_best_audio_quality(audio_files, use_whisper_testing)
        
        # Get detailed analysis for the best file
        if use_whisper_testing:
            analysis = analyze_with_whisper_testing(best_file)
        else:
            analysis = analyze_speech_quality(best_file)
            
        return jsonify({
            'success': True,
            'best_file': best_file,
            'total_files': len(audio_files),
            'use_whisper_testing': use_whisper_testing,
            'analysis': analysis,
            'timestamp': datetime.now().isoformat()
        })
        
    except Exception as e:
        logger.error(f"Error in select-best-audio endpoint: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500


@app.route('/batch-quality-analysis', methods=['POST'])
def batch_quality_analysis_endpoint():
    """API endpoint for batch quality analysis of a directory."""
    try:
        data = request.get_json()
        
        if not data or 'directory' not in data:
            return jsonify({
                'success': False,
                'error': 'directory path is required'
            }), 400
            
        directory = data['directory']
        quality_levels = data.get('quality_levels')
        
        logger.info(f"API request for batch quality analysis: {directory}")
        
        result = batch_quality_analysis(directory, quality_levels)
        return jsonify(result)
        
    except Exception as e:
        logger.error(f"Error in batch-quality-analysis endpoint: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500


if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)
