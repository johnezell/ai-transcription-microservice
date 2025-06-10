#!/usr/bin/env python3
"""
WhisperX Model Management System
Handles loading, caching, and management of WhisperX models including:
- WhisperX transcription models
- Alignment models (wav2vec2-based)
- Speaker diarization models (pyannote.audio)
"""

import os
import gc
import torch
import whisperx
import logging
from functools import lru_cache
from typing import Dict, Any, Optional, Tuple, Union
from pathlib import Path
import time
from datetime import datetime
import psutil

# Set up logging
logger = logging.getLogger(__name__)

class WhisperXModelManager:
    """
    Centralized manager for WhisperX models with caching and resource management.
    """
    
    def __init__(self, cache_dir: str = "/tmp/whisperx_models", device: str = None):
        """
        Initialize the WhisperX model manager.
        
        Args:
            cache_dir: Directory to cache downloaded models
            device: Device to use ('cuda', 'cpu', or None for auto-detection)
        """
        self.cache_dir = Path(cache_dir)
        self.cache_dir.mkdir(parents=True, exist_ok=True)
        
        # Auto-detect device if not specified
        if device is None:
            self.device = "cuda" if torch.cuda.is_available() else "cpu"
        else:
            self.device = device
            
        # Compute type for faster-whisper backend
        self.compute_type = "float16" if self.device == "cuda" else "int8"
        
        # Model caches
        self._whisperx_models: Dict[str, Any] = {}
        self._alignment_models: Dict[str, Any] = {}
        self._diarization_pipeline: Optional[Any] = None
        
        # Performance tracking
        self._model_load_times: Dict[str, float] = {}
        self._memory_usage: Dict[str, float] = {}
        
        logger.info(f"WhisperX Model Manager initialized - Device: {self.device}, Compute: {self.compute_type}")
    
    def get_memory_usage(self) -> Dict[str, float]:
        """Get current memory usage statistics."""
        process = psutil.Process()
        memory_info = process.memory_info()
        
        usage = {
            'rss_mb': memory_info.rss / 1024 / 1024,  # Resident Set Size
            'vms_mb': memory_info.vms / 1024 / 1024,  # Virtual Memory Size
        }
        
        if torch.cuda.is_available():
            usage['gpu_allocated_mb'] = torch.cuda.memory_allocated() / 1024 / 1024
            usage['gpu_reserved_mb'] = torch.cuda.memory_reserved() / 1024 / 1024
            
        return usage
    
    def load_whisperx_model(self, model_name: str = "base", language: str = "en") -> Tuple[Any, Dict[str, Any]]:
        """
        Load WhisperX transcription model with caching.
        
        Args:
            model_name: Whisper model size ('tiny', 'base', 'small', 'medium', 'large-v3')
            language: Language code for the model
            
        Returns:
            Tuple of (model, metadata)
        """
        cache_key = f"{model_name}_{language}_{self.device}"
        
        if cache_key in self._whisperx_models:
            logger.info(f"Using cached WhisperX model: {cache_key}")
            return self._whisperx_models[cache_key], self._get_model_metadata(cache_key)
        
        logger.info(f"Loading WhisperX model: {model_name} (language: {language}, device: {self.device})")
        start_time = time.time()
        
        try:
            # Load WhisperX model with faster-whisper backend
            model = whisperx.load_model(
                model_name,
                device=self.device,
                compute_type=self.compute_type,
                language=language,
                download_root=str(self.cache_dir)
            )
            
            load_time = time.time() - start_time
            memory_usage = self.get_memory_usage()
            
            # Cache the model
            self._whisperx_models[cache_key] = model
            self._model_load_times[cache_key] = load_time
            self._memory_usage[cache_key] = memory_usage['rss_mb']
            
            logger.info(f"WhisperX model loaded successfully in {load_time:.2f}s - Memory: {memory_usage['rss_mb']:.1f}MB")
            
            return model, self._get_model_metadata(cache_key)
            
        except Exception as e:
            logger.error(f"Failed to load WhisperX model {model_name}: {str(e)}")
            raise
    
    def load_alignment_model(self, language: str = "en") -> Tuple[Any, Dict[str, Any]]:
        """
        Load alignment model for word-level timestamps.
        
        Args:
            language: Language code for alignment model
            
        Returns:
            Tuple of (alignment_model, metadata)
        """
        # OPTIMIZED: Always use CPU for alignment models to eliminate device mismatch issues
        # Transcription uses GPU for speed, alignment uses CPU for reliability
        alignment_device = "cpu"
        cache_key = f"align_{language}_{alignment_device}"
        
        if cache_key in self._alignment_models:
            logger.info(f"Using cached alignment model: {cache_key}")
            return self._alignment_models[cache_key], self._get_model_metadata(cache_key)
        
        logger.info(f"Loading alignment model for language: {language}")
        start_time = time.time()
        
        try:
            # Load alignment model on CPU for consistent, reliable processing
            logger.info(f"Loading alignment model with device={alignment_device}, language={language}")
            model_dir = "/app/models"
            logger.info(f"Using alignment model directory: {model_dir}")
            model, metadata = whisperx.load_align_model(
                language_code=language,
                device=alignment_device,
                model_dir=model_dir
            )
            
            logger.info(f"Loaded alignment model type: {type(model)}")
            logger.info(f"Alignment model loaded on {alignment_device} for reliable processing")
            
            load_time = time.time() - start_time
            memory_usage = self.get_memory_usage()
            
            # Cache the model
            alignment_data = {
                'model': model,
                'metadata': metadata
            }
            self._alignment_models[cache_key] = alignment_data
            self._model_load_times[cache_key] = load_time
            self._memory_usage[cache_key] = memory_usage['rss_mb']
            
            logger.info(f"Alignment model loaded successfully in {load_time:.2f}s - Memory: {memory_usage['rss_mb']:.1f}MB")
            
            return alignment_data, self._get_model_metadata(cache_key)
            
        except Exception as e:
            logger.error(f"Failed to load alignment model for {language}: {str(e)}")
            # Return None to allow graceful fallback
            return None, {'error': str(e), 'language': language}
    
    def load_diarization_pipeline(self, use_auth_token: str = None) -> Tuple[Any, Dict[str, Any]]:
        """
        Load speaker diarization pipeline.
        
        Args:
            use_auth_token: HuggingFace auth token for pyannote models
            
        Returns:
            Tuple of (diarization_pipeline, metadata)
        """
        if self._diarization_pipeline is not None:
            logger.info("Using cached diarization pipeline")
            return self._diarization_pipeline, self._get_model_metadata("diarization")
        
        logger.info("Loading speaker diarization pipeline")
        start_time = time.time()
        
        try:
            # Load diarization pipeline
            diarize_model = whisperx.DiarizationPipeline(
                use_auth_token=use_auth_token,
                device=self.device
            )
            
            load_time = time.time() - start_time
            memory_usage = self.get_memory_usage()
            
            # Cache the pipeline
            self._diarization_pipeline = diarize_model
            self._model_load_times["diarization"] = load_time
            self._memory_usage["diarization"] = memory_usage['rss_mb']
            
            logger.info(f"Diarization pipeline loaded successfully in {load_time:.2f}s - Memory: {memory_usage['rss_mb']:.1f}MB")
            
            return diarize_model, self._get_model_metadata("diarization")
            
        except Exception as e:
            logger.error(f"Failed to load diarization pipeline: {str(e)}")
            # Return None to allow graceful fallback
            return None, {'error': str(e)}
    
    def _get_model_metadata(self, cache_key: str) -> Dict[str, Any]:
        """Get metadata for a cached model."""
        return {
            'cache_key': cache_key,
            'device': self.device,
            'compute_type': self.compute_type,
            'load_time': self._model_load_times.get(cache_key, 0),
            'memory_usage_mb': self._memory_usage.get(cache_key, 0),
            'loaded_at': datetime.now().isoformat()
        }
    
    def get_model_info(self) -> Dict[str, Any]:
        """Get information about all loaded models."""
        return {
            'device': self.device,
            'compute_type': self.compute_type,
            'cache_dir': str(self.cache_dir),
            'loaded_models': {
                'whisperx': list(self._whisperx_models.keys()),
                'alignment': list(self._alignment_models.keys()),
                'diarization': self._diarization_pipeline is not None
            },
            'load_times': self._model_load_times.copy(),
            'memory_usage': self._memory_usage.copy(),
            'current_memory': self.get_memory_usage()
        }
    
    def clear_cache(self, model_type: str = None):
        """
        Clear model cache to free memory.
        
        Args:
            model_type: Type of models to clear ('whisperx', 'alignment', 'diarization', or None for all)
        """
        if model_type is None or model_type == 'whisperx':
            self._whisperx_models.clear()
            logger.info("Cleared WhisperX model cache")
        
        if model_type is None or model_type == 'alignment':
            self._alignment_models.clear()
            logger.info("Cleared alignment model cache")
        
        if model_type is None or model_type == 'diarization':
            self._diarization_pipeline = None
            logger.info("Cleared diarization pipeline cache")
        
        # Force garbage collection
        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()


# Global model manager instance
_model_manager: Optional[WhisperXModelManager] = None

def get_model_manager() -> WhisperXModelManager:
    """Get or create the global model manager instance."""
    global _model_manager
    if _model_manager is None:
        _model_manager = WhisperXModelManager()
    return _model_manager

@lru_cache(maxsize=1)
def load_whisperx_model(model_name: str = "base", language: str = "en") -> Tuple[Any, Dict[str, Any]]:
    """
    Load WhisperX model with caching (backward compatibility function).
    
    Args:
        model_name: Whisper model size
        language: Language code
        
    Returns:
        Tuple of (model, metadata)
    """
    manager = get_model_manager()
    return manager.load_whisperx_model(model_name, language)

def get_alignment_model(language: str = "en") -> Tuple[Any, Dict[str, Any]]:
    """
    Load alignment model for word-level timestamps.
    
    Args:
        language: Language code
        
    Returns:
        Tuple of (alignment_model, metadata) or (None, error_info)
    """
    manager = get_model_manager()
    return manager.load_alignment_model(language)

def get_diarization_pipeline(use_auth_token: str = None) -> Tuple[Any, Dict[str, Any]]:
    """
    Load speaker diarization pipeline.
    
    Args:
        use_auth_token: HuggingFace auth token
        
    Returns:
        Tuple of (diarization_pipeline, metadata) or (None, error_info)
    """
    manager = get_model_manager()
    return manager.load_diarization_pipeline(use_auth_token)

def get_model_info() -> Dict[str, Any]:
    """Get information about all loaded models."""
    manager = get_model_manager()
    return manager.get_model_info()

def clear_model_cache(model_type: str = None):
    """Clear model cache to free memory."""
    manager = get_model_manager()
    manager.clear_cache(model_type)