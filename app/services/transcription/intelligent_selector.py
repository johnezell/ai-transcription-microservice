#!/usr/bin/env python3
"""
Intelligent Model Selection Engine for AI Transcription Microservice

This module implements a cascading model selection system that starts with the smallest
Whisper model (tiny) and escalates to larger models based on quality metrics.

Key Features:
- Cascading model selection (tiny → small → medium → large-v3)
- Multi-metric decision matrix for escalation
- Content-aware pre-selection
- Performance tracking and optimization
- Seamless integration with existing transcription service
"""

import logging
import time
from typing import Dict, List, Optional, Tuple, Any
from dataclasses import dataclass
from enum import Enum
import json

logger = logging.getLogger(__name__)

class ModelSize(Enum):
    """Available Whisper model sizes in escalation order."""
    TINY = "tiny"
    SMALL = "small" 
    MEDIUM = "medium"
    LARGE_V3 = "large-v3"

@dataclass
class QualityMetrics:
    """Quality metrics for transcription evaluation."""
    avg_confidence: float
    segment_consistency: float
    duration_coverage: float
    low_confidence_penalty: float
    overall_score: float

@dataclass
class SelectionDecision:
    """Model selection decision with metadata."""
    model_used: str
    quality_score: float
    escalation_reason: Optional[str]
    processing_time: float
    confidence_achieved: float
    metrics: QualityMetrics

@dataclass
class IntelligentSelectionResult:
    """Complete result from intelligent model selection."""
    initial_model: str
    final_model: str
    escalation_count: int
    decisions: List[SelectionDecision]
    total_processing_time: float
    time_saved_percentage: float
    quality_achieved: float
    transcription_result: Dict[str, Any]

class MultiMetricDecisionMatrix:
    """
    Multi-metric decision matrix for intelligent model escalation.
    
    Uses weighted scoring algorithm to determine if model escalation is needed
    based on confidence, consistency, coverage, and penalty metrics.
    """
    
    def __init__(self, config: Optional[Dict] = None):
        """Initialize decision matrix with configurable weights."""
        self.config = config or {}
        self.confidence_weight = self.config.get('confidence_weight', 0.4)
        self.consistency_weight = self.config.get('consistency_weight', 0.3)
        self.coverage_weight = self.config.get('coverage_weight', 0.2)
        self.penalty_weight = self.config.get('penalty_weight', 0.1)
        
        # Quality thresholds for escalation decisions
        self.escalation_thresholds = {
            'tiny': 0.75,
            'small': 0.80,
            'medium': 0.85,
            'large-v3': 0.90
        }
        
        # Minimum confidence threshold for acceptable quality
        self.min_acceptable_confidence = 0.8
        
    def extract_quality_metrics(self, transcription_result: Dict) -> QualityMetrics:
        """Extract quality metrics from transcription result."""
        segments = transcription_result.get('segments', [])
        word_segments = transcription_result.get('word_segments', [])
        
        # Calculate average confidence
        confidence_scores = []
        for segment in segments:
            if 'confidence' in segment:
                confidence_scores.append(segment['confidence'])
            elif 'words' in segment:
                word_scores = [w.get('score', 0) for w in segment['words'] if 'score' in w]
                if word_scores:
                    confidence_scores.append(sum(word_scores) / len(word_scores))
        
        avg_confidence = sum(confidence_scores) / len(confidence_scores) if confidence_scores else 0.0
        
        # Calculate segment consistency (variation in confidence)
        if len(confidence_scores) > 1:
            variance = sum((x - avg_confidence) ** 2 for x in confidence_scores) / len(confidence_scores)
            segment_consistency = max(0.0, 1.0 - variance)
        else:
            segment_consistency = 1.0 if confidence_scores else 0.0
        
        # Calculate duration coverage
        if segments:
            total_duration = max(seg.get('end', 0) for seg in segments)
            speech_duration = sum(seg.get('end', 0) - seg.get('start', 0) for seg in segments)
            duration_coverage = speech_duration / total_duration if total_duration > 0 else 0.0
        else:
            duration_coverage = 0.0
        
        # Calculate low confidence penalty
        low_confidence_count = sum(1 for score in confidence_scores if score < 0.7)
        low_confidence_penalty = low_confidence_count / len(confidence_scores) if confidence_scores else 0.0
        
        # Calculate overall quality score
        overall_score = (
            avg_confidence * self.confidence_weight +
            segment_consistency * self.consistency_weight +
            duration_coverage * self.coverage_weight -
            low_confidence_penalty * self.penalty_weight
        )
        overall_score = max(0.0, min(1.0, overall_score))
        
        return QualityMetrics(
            avg_confidence=avg_confidence,
            segment_consistency=segment_consistency,
            duration_coverage=duration_coverage,
            low_confidence_penalty=low_confidence_penalty,
            overall_score=overall_score
        )
    
    def should_escalate(self, metrics: QualityMetrics, current_model: str) -> Tuple[bool, str]:
        """
        Determine if model escalation is needed based on quality metrics.
        
        Returns:
            Tuple of (should_escalate: bool, reason: str)
        """
        threshold = self.escalation_thresholds.get(current_model, 0.95)
        
        # Primary escalation criteria
        if metrics.overall_score < threshold:
            return True, f"overall_quality_score_{metrics.overall_score:.3f}_below_threshold_{threshold}"
        
        # Secondary escalation criteria
        if metrics.avg_confidence < self.min_acceptable_confidence:
            return True, f"avg_confidence_{metrics.avg_confidence:.3f}_below_minimum_{self.min_acceptable_confidence}"
        
        # Tertiary escalation criteria for edge cases
        if metrics.segment_consistency < 0.6 and current_model in ['tiny', 'small']:
            return True, f"poor_segment_consistency_{metrics.segment_consistency:.3f}"
        
        if metrics.low_confidence_penalty > 0.3 and current_model == 'tiny':
            return True, f"high_low_confidence_penalty_{metrics.low_confidence_penalty:.3f}"
        
        return False, "quality_threshold_met"

class ContentAwarePreSelector:
    """
    Content-aware pre-selection for optimal initial model choice.
    
    Analyzes audio characteristics to recommend the best starting model,
    potentially skipping tiny model for complex content.
    """
    
    def __init__(self):
        self.duration_thresholds = {
            'very_short': 30,   # < 30s
            'short': 120,       # 30s - 2min
            'medium': 600,      # 2min - 10min
            'long': 1800        # 10min - 30min
        }
    
    def analyze_audio_content(self, audio_path: str, transcription_result: Optional[Dict] = None) -> Dict:
        """
        Analyze audio content characteristics for model recommendation.
        
        Args:
            audio_path: Path to audio file
            transcription_result: Optional initial transcription for analysis
            
        Returns:
            Content analysis with model recommendation
        """
        try:
            import librosa
            import numpy as np
            
            # Load audio for analysis
            y, sr = librosa.load(audio_path, sr=16000)
            duration = len(y) / sr
            
            # Basic audio analysis
            analysis = {
                'duration_seconds': duration,
                'sample_rate': sr,
                'audio_length': len(y)
            }
            
            # Complexity analysis based on spectral features
            try:
                # Spectral centroid (brightness)
                spectral_centroids = librosa.feature.spectral_centroid(y=y, sr=sr)
                analysis['spectral_centroid_mean'] = np.mean(spectral_centroids)
                
                # Zero crossing rate (speech/noise indicator)
                zcr = librosa.feature.zero_crossing_rate(y)
                analysis['zero_crossing_rate'] = np.mean(zcr)
                
                # RMS energy (volume consistency)
                rms = librosa.feature.rms(y=y)
                analysis['rms_energy_std'] = np.std(rms)
                
                # Estimate complexity score
                complexity_score = self._calculate_complexity_score(analysis)
                analysis['complexity_score'] = complexity_score
                
            except Exception as e:
                logger.warning(f"Advanced audio analysis failed: {e}")
                analysis['complexity_score'] = 0.5  # Default moderate complexity
            
            # Recommend initial model
            recommended_model = self._recommend_initial_model(analysis)
            analysis['recommended_model'] = recommended_model
            analysis['recommendation_reason'] = self._get_recommendation_reason(analysis)
            
            return analysis
            
        except ImportError:
            logger.warning("librosa not available, using basic duration-based analysis")
            return self._basic_duration_analysis(audio_path)
        except Exception as e:
            logger.error(f"Content analysis failed: {e}")
            return {
                'duration_seconds': 0,
                'complexity_score': 0.5,
                'recommended_model': 'tiny',
                'recommendation_reason': 'analysis_failed_default_to_tiny',
                'error': str(e)
            }
    
    def _calculate_complexity_score(self, analysis: Dict) -> float:
        """Calculate audio complexity score from spectral features."""
        # Normalize features and combine for complexity score
        centroid_norm = min(1.0, analysis['spectral_centroid_mean'] / 4000)  # Normalize to ~4kHz
        zcr_norm = min(1.0, analysis['zero_crossing_rate'] * 10)  # Scale up ZCR
        energy_variability = min(1.0, analysis['rms_energy_std'] * 5)  # Scale energy std
        
        # Weighted combination
        complexity = (centroid_norm * 0.4 + zcr_norm * 0.3 + energy_variability * 0.3)
        return max(0.0, min(1.0, complexity))
    
    def _recommend_initial_model(self, analysis: Dict) -> str:
        """Recommend initial model based on content analysis."""
        duration = analysis['duration_seconds']
        complexity = analysis.get('complexity_score', 0.5)
        
        # Very short audio - always start with tiny
        if duration < self.duration_thresholds['very_short']:
            return 'tiny'
        
        # MUSICAL CONTENT OPTIMIZATION: For guitar lessons and musical content,
        # start with 'small' model to handle specialized terminology better
        # Musical content typically has complex vocabulary that benefits from larger models
        if duration > 45:  # For most real guitar lesson segments
            return 'small'
        
        # High complexity audio - skip tiny model
        if complexity > 0.7:  # Lowered from 0.8 for better sensitivity
            return 'small'
        
        # Medium-long audio with moderate complexity - start with small
        if duration > self.duration_thresholds['short'] and complexity > 0.5:  # Lowered from 0.6
            return 'small'
        
        # Default to tiny only for very short, simple content
        return 'tiny'
    
    def _get_recommendation_reason(self, analysis: Dict) -> str:
        """Get human-readable reason for model recommendation."""
        duration = analysis['duration_seconds']
        complexity = analysis.get('complexity_score', 0.5)
        model = analysis['recommended_model']
        
        if model == 'small':
            if duration > 45:
                return f"musical_content_optimization_duration_{duration:.0f}s"
            elif complexity > 0.7:
                return f"high_complexity_{complexity:.2f}_skip_tiny"
            elif duration > self.duration_thresholds['short']:
                return f"medium_duration_{duration:.0f}s_moderate_complexity_{complexity:.2f}"
        
        return f"standard_tiny_start_duration_{duration:.0f}s_complexity_{complexity:.2f}"
    
    def _basic_duration_analysis(self, audio_path: str) -> Dict:
        """Fallback to basic duration-based analysis when librosa unavailable."""
        try:
            import wave
            with wave.open(audio_path, 'rb') as wav_file:
                frames = wav_file.getnframes()
                sample_rate = wav_file.getframerate()
                duration = frames / sample_rate
        except:
            # Ultimate fallback
            duration = 60  # Assume 1 minute
        
        return {
            'duration_seconds': duration,
            'complexity_score': 0.5,
            'recommended_model': 'tiny' if duration < 300 else 'small',
            'recommendation_reason': f'basic_duration_analysis_{duration:.0f}s',
            'analysis_method': 'basic_duration_only'
        }

class IntelligentModelSelector:
    """
    Core intelligent model selection engine.
    
    Implements cascading model selection with quality-based escalation,
    content-aware pre-selection, and comprehensive performance tracking.
    """
    
    def __init__(self, config: Optional[Dict] = None):
        """Initialize intelligent selector with configuration."""
        self.config = config or {}
        self.decision_matrix = MultiMetricDecisionMatrix(self.config)
        self.pre_selector = ContentAwarePreSelector()
        
        # Model escalation sequence
        self.model_sequence = [
            ModelSize.TINY.value,
            ModelSize.SMALL.value,
            ModelSize.MEDIUM.value,
            ModelSize.LARGE_V3.value
        ]
        
        # Performance baselines for time savings calculation
        self.model_baselines = {
            'tiny': 30,     # seconds
            'small': 180,   # seconds  
            'medium': 600,  # seconds
            'large-v3': 1200 # seconds
        }
        
        self.enable_pre_selection = self.config.get('enable_pre_selection', True)
        self.max_escalations = self.config.get('max_escalations', 3)
        
    def select_and_process(self, audio_path: str, process_audio_func, **kwargs) -> IntelligentSelectionResult:
        """
        Perform intelligent model selection with cascading escalation.
        
        Args:
            audio_path: Path to audio file to process
            process_audio_func: Function to call for transcription processing
            **kwargs: Additional arguments to pass to process_audio_func
            
        Returns:
            IntelligentSelectionResult with complete selection metadata
        """
        start_time = time.time()
        decisions = []
        
        # Step 1: Content-aware pre-selection
        if self.enable_pre_selection:
            content_analysis = self.pre_selector.analyze_audio_content(audio_path)
            initial_model = content_analysis['recommended_model']
            logger.info(f"Pre-selection analysis: {content_analysis['recommendation_reason']} → {initial_model}")
        else:
            initial_model = 'tiny'
            content_analysis = {'recommended_model': initial_model, 'recommendation_reason': 'pre_selection_disabled'}
        
        current_model = initial_model
        escalation_count = 0
        final_result = None
        
        # Step 2: Cascading model processing
        while escalation_count <= self.max_escalations:
            try:
                logger.info(f"Processing with model: {current_model} (attempt {escalation_count + 1})")
                model_start_time = time.time()
                
                # Process audio with current model
                # Update preset config to use current model
                if 'preset_config' in kwargs and kwargs['preset_config']:
                    kwargs['preset_config']['model_name'] = current_model
                
                result = process_audio_func(audio_path, model_name=current_model, **kwargs)
                model_processing_time = time.time() - model_start_time
                
                # Step 3: Evaluate quality and decide escalation
                metrics = self.decision_matrix.extract_quality_metrics(result)
                should_escalate, escalation_reason = self.decision_matrix.should_escalate(metrics, current_model)
                
                # Record decision
                decision = SelectionDecision(
                    model_used=current_model,
                    quality_score=metrics.overall_score,
                    escalation_reason=escalation_reason if should_escalate else None,
                    processing_time=model_processing_time,
                    confidence_achieved=metrics.avg_confidence,
                    metrics=metrics
                )
                decisions.append(decision)
                
                logger.info(f"Model {current_model}: quality={metrics.overall_score:.3f}, "
                           f"confidence={metrics.avg_confidence:.3f}, time={model_processing_time:.1f}s")
                
                # Step 4: Check escalation
                if should_escalate:
                    next_model = self._get_next_model(current_model)
                    if next_model and escalation_count < self.max_escalations:
                        logger.info(f"Escalating from {current_model} to {next_model}: {escalation_reason}")
                        current_model = next_model
                        escalation_count += 1
                        continue
                    else:
                        logger.info(f"Maximum escalations reached or no next model available")
                
                # Step 5: Finalize result
                final_result = result
                break
                
            except Exception as e:
                logger.error(f"Model {current_model} failed: {str(e)}")
                
                # Try fallback model
                fallback_model = self._get_next_model(current_model)
                if fallback_model and escalation_count < self.max_escalations:
                    decisions.append(SelectionDecision(
                        model_used=current_model,
                        quality_score=0.0,
                        escalation_reason=f"model_failure_{str(e)[:50]}",
                        processing_time=0.0,
                        confidence_achieved=0.0,
                        metrics=QualityMetrics(0, 0, 0, 1.0, 0)
                    ))
                    current_model = fallback_model
                    escalation_count += 1
                    continue
                else:
                    raise e
        
        # Calculate performance metrics
        total_processing_time = time.time() - start_time
        time_saved = self._calculate_time_saved(initial_model, decisions[-1].model_used, total_processing_time)
        
        # Create comprehensive result
        selection_result = IntelligentSelectionResult(
            initial_model=initial_model,
            final_model=decisions[-1].model_used,
            escalation_count=escalation_count,
            decisions=decisions,
            total_processing_time=total_processing_time,
            time_saved_percentage=time_saved,
            quality_achieved=decisions[-1].quality_score,
            transcription_result=final_result
        )
        
        # Add intelligent selection metadata to transcription result
        final_result['intelligent_selection'] = {
            'initial_model': initial_model,
            'final_model': decisions[-1].model_used,
            'escalation_count': escalation_count,
            'total_processing_time': total_processing_time,
            'time_saved_percentage': time_saved,
            'quality_achieved': decisions[-1].quality_score,
            'content_analysis': content_analysis,
            'decisions': [
                {
                    'model': d.model_used,
                    'quality_score': d.quality_score,
                    'confidence': d.confidence_achieved,
                    'processing_time': d.processing_time,
                    'escalation_reason': d.escalation_reason
                }
                for d in decisions
            ]
        }
        
        logger.info(f"Intelligent selection completed: {initial_model}→{decisions[-1].model_used} "
                   f"(quality: {decisions[-1].quality_score:.3f}, time saved: {time_saved:.1f}%)")
        
        return selection_result
    
    def _get_next_model(self, current_model: str) -> Optional[str]:
        """Get the next model in escalation sequence."""
        try:
            current_index = self.model_sequence.index(current_model)
            if current_index < len(self.model_sequence) - 1:
                return self.model_sequence[current_index + 1]
        except ValueError:
            pass
        return None
    
    def _calculate_time_saved(self, initial_model: str, final_model: str, actual_time: float) -> float:
        """Calculate percentage of time saved compared to worst-case scenario."""
        worst_case_time = self.model_baselines.get('large-v3', 1200)
        initial_baseline = self.model_baselines.get(initial_model, 30)
        
        if actual_time <= 0:
            return 0.0
        
        # Calculate savings compared to always using large-v3
        time_saved_percentage = max(0.0, (worst_case_time - actual_time) / worst_case_time * 100)
        return min(100.0, time_saved_percentage)

# Factory function for easy integration
def create_intelligent_selector(config: Optional[Dict] = None) -> IntelligentModelSelector:
    """Create intelligent model selector with optional configuration."""
    return IntelligentModelSelector(config)

# Quick integration function
def intelligent_process_audio(audio_path: str, process_audio_func, enable_intelligent_selection: bool = True, **kwargs) -> Dict:
    """
    Process audio with intelligent model selection.
    
    Args:
        audio_path: Path to audio file
        process_audio_func: Original process_audio function
        enable_intelligent_selection: Whether to use intelligent selection
        **kwargs: Additional arguments for processing
        
    Returns:
        Enhanced transcription result with intelligent selection metadata
    """
    if not enable_intelligent_selection:
        # Fallback to original processing
        return process_audio_func(audio_path, **kwargs)
    
    # Use intelligent selection
    selector = create_intelligent_selector()
    result = selector.select_and_process(audio_path, process_audio_func, **kwargs)
    return result.transcription_result

if __name__ == "__main__":
    # Example usage and testing
    print("Intelligent Model Selection Engine - Test Mode")
    
    # Test decision matrix
    matrix = MultiMetricDecisionMatrix()
    test_metrics = QualityMetrics(
        avg_confidence=0.73,
        segment_consistency=0.82,
        duration_coverage=0.95,
        low_confidence_penalty=0.15,
        overall_score=0.75
    )
    
    should_escalate, reason = matrix.should_escalate(test_metrics, 'tiny')
    print(f"Test escalation decision: {should_escalate} - {reason}")
    
    # Test pre-selector
    pre_selector = ContentAwarePreSelector()
    # This would normally analyze actual audio file
    mock_analysis = {
        'duration_seconds': 120,
        'complexity_score': 0.6,
        'recommended_model': 'tiny',
        'recommendation_reason': 'test_scenario'
    }
    print(f"Test pre-selection: {mock_analysis}")
    
    print("Intelligent selection engine loaded successfully!") 