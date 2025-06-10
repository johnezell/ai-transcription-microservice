#!/usr/bin/env python3
"""
WhisperX Model Pre-loader

This script pre-downloads and caches WhisperX models during Docker build 
to prevent re-downloading at runtime. Models are cached to persistent volumes.

Usage:
    python preload_models.py [--models tiny,base,small,medium,large-v3] [--languages en,es,fr]
"""

import os
import sys
import argparse
import logging
import time
from pathlib import Path

# Add current directory to path for imports
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from whisperx_models import WhisperXModelManager

# Set up logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Common model configurations for TrueFire guitar lessons
DEFAULT_MODELS = ['tiny', 'base', 'small', 'medium', 'large-v3']
DEFAULT_LANGUAGES = ['en']  # Primary language for guitar instruction content

def preload_whisperx_models(models: list, languages: list, cache_dir: str = None):
    """
    Pre-load WhisperX models to persistent cache.
    
    Args:
        models: List of model names to preload
        languages: List of language codes to preload
        cache_dir: Cache directory (uses env var if not specified)
    """
    logger.info("Starting WhisperX model pre-loading...")
    logger.info(f"Models to preload: {models}")
    logger.info(f"Languages to preload: {languages}")
    
    # Initialize model manager with persistent cache
    manager = WhisperXModelManager(cache_dir=cache_dir)
    logger.info(f"Using cache directory: {manager.cache_dir}")
    
    # Ensure cache directory exists and is writable
    manager.cache_dir.mkdir(parents=True, exist_ok=True)
    if not os.access(manager.cache_dir, os.W_OK):
        logger.error(f"Cache directory not writable: {manager.cache_dir}")
        return False
    
    preload_summary = {
        'success': 0,
        'failed': 0,
        'total_size_mb': 0,
        'details': []
    }
    
    # Pre-load transcription models
    for model_name in models:
        for language in languages:
            try:
                logger.info(f"Pre-loading WhisperX model: {model_name} ({language})")
                start_time = time.time()
                
                model, metadata = manager.load_whisperx_model(model_name, language)
                
                load_time = time.time() - start_time
                memory_usage = metadata.get('memory_usage_mb', 0)
                
                preload_summary['success'] += 1
                preload_summary['total_size_mb'] += memory_usage
                preload_summary['details'].append({
                    'model': f"{model_name}_{language}",
                    'load_time': load_time,
                    'memory_mb': memory_usage,
                    'status': 'success'
                })
                
                logger.info(f"✅ Successfully pre-loaded {model_name} ({language}) in {load_time:.2f}s - {memory_usage:.1f}MB")
                
            except Exception as e:
                logger.error(f"❌ Failed to pre-load {model_name} ({language}): {e}")
                preload_summary['failed'] += 1
                preload_summary['details'].append({
                    'model': f"{model_name}_{language}",
                    'error': str(e),
                    'status': 'failed'
                })
    
    # Pre-load alignment models for supported languages
    logger.info("Pre-loading alignment models...")
    for language in languages:
        try:
            logger.info(f"Pre-loading alignment model: {language}")
            alignment_data, metadata = manager.load_alignment_model(language)
            
            if alignment_data:
                preload_summary['success'] += 1
                preload_summary['details'].append({
                    'model': f"alignment_{language}",
                    'status': 'success'
                })
                logger.info(f"✅ Successfully pre-loaded alignment model for {language}")
            else:
                logger.warning(f"⚠️ Alignment model not available for {language}")
                
        except Exception as e:
            logger.error(f"❌ Failed to pre-load alignment model for {language}: {e}")
            preload_summary['failed'] += 1
            preload_summary['details'].append({
                'model': f"alignment_{language}",
                'error': str(e),
                'status': 'failed'
            })
    
    # Pre-load diarization pipeline (optional)
    try:
        logger.info("Pre-loading diarization pipeline...")
        diarize_pipeline, metadata = manager.load_diarization_pipeline()
        
        if diarize_pipeline:
            preload_summary['success'] += 1
            preload_summary['details'].append({
                'model': 'diarization',
                'status': 'success'
            })
            logger.info("✅ Successfully pre-loaded diarization pipeline")
        else:
            logger.warning("⚠️ Diarization pipeline not available (requires HuggingFace token)")
            
    except Exception as e:
        logger.warning(f"⚠️ Could not pre-load diarization pipeline: {e}")
        # Not critical for basic transcription
    
    # Print summary
    total_models = preload_summary['success'] + preload_summary['failed']
    success_rate = (preload_summary['success'] / total_models * 100) if total_models > 0 else 0
    
    logger.info("=" * 60)
    logger.info("MODEL PRE-LOADING SUMMARY")
    logger.info("=" * 60)
    logger.info(f"✅ Successfully loaded: {preload_summary['success']}")
    logger.info(f"❌ Failed to load: {preload_summary['failed']}")
    logger.info(f"📊 Success rate: {success_rate:.1f}%")
    logger.info(f"💾 Total cache size: {preload_summary['total_size_mb']:.1f}MB")
    logger.info(f"📁 Cache directory: {manager.cache_dir}")
    
    # Check cache directory contents
    try:
        cache_files = list(manager.cache_dir.rglob("*"))
        cache_size = sum(f.stat().st_size for f in cache_files if f.is_file())
        logger.info(f"📂 Cache files: {len([f for f in cache_files if f.is_file()])} files")
        logger.info(f"💽 Disk usage: {cache_size / 1024 / 1024:.1f}MB")
    except Exception as e:
        logger.warning(f"Could not check cache directory: {e}")
    
    return preload_summary['failed'] == 0

def main():
    """Main function for command-line usage."""
    import time
    
    parser = argparse.ArgumentParser(description='Pre-load WhisperX models to persistent cache')
    parser.add_argument('--models', type=str, default=','.join(DEFAULT_MODELS),
                       help=f'Comma-separated list of models (default: {",".join(DEFAULT_MODELS)})')
    parser.add_argument('--languages', type=str, default=','.join(DEFAULT_LANGUAGES),
                       help=f'Comma-separated list of languages (default: {",".join(DEFAULT_LANGUAGES)})')
    parser.add_argument('--cache-dir', type=str, default=None,
                       help='Cache directory (uses WHISPERX_CACHE_DIR env var if not specified)')
    parser.add_argument('--essential-only', action='store_true',
                       help='Only pre-load essential models (small, medium, large-v3)')
    
    args = parser.parse_args()
    
    # Parse model and language lists
    models = [m.strip() for m in args.models.split(',') if m.strip()]
    languages = [l.strip() for l in args.languages.split(',') if l.strip()]
    
    # Filter to essential models if requested
    if args.essential_only:
        essential_models = ['small', 'medium', 'large-v3']
        models = [m for m in models if m in essential_models]
        logger.info(f"Essential-only mode: using models {models}")
    
    # Validate models
    valid_models = ['tiny', 'base', 'small', 'medium', 'large-v3']
    invalid_models = [m for m in models if m not in valid_models]
    if invalid_models:
        logger.error(f"Invalid models: {invalid_models}. Valid models: {valid_models}")
        return False
    
    # Start pre-loading
    success = preload_whisperx_models(models, languages, args.cache_dir)
    
    if success:
        logger.info("🎉 Model pre-loading completed successfully!")
        return True
    else:
        logger.error("💥 Model pre-loading completed with errors!")
        return False

if __name__ == '__main__':
    success = main()
    sys.exit(0 if success else 1) 