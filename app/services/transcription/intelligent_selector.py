#!/usr/bin/env python3
"""
Enhanced Intelligent Model Selection Engine for AI Transcription Microservice

This module implements a sophisticated model selection system that uses comprehensive
quality metrics to choose the best performing model, not just the largest one.

Key Features:
- Quality-based model selection using comprehensive metrics
- Model performance comparison and ranking
- Performance memory for content-specific optimization
- Integration with AdvancedQualityAnalyzer
- Best-model selection regardless of size
"""

import logging
import time
import pickle
import os
from typing import Dict, List, Optional, Tuple, Any
from dataclasses import dataclass, asdict
from enum import Enum
import json
import numpy as np

# Import the advanced quality analyzer
try:
    from quality_metrics import AdvancedQualityAnalyzer
except ImportError:
    import sys
    sys.path.append(os.path.dirname(os.path.abspath(__file__)))
    from quality_metrics import AdvancedQualityAnalyzer

logger = logging.getLogger(__name__)

class ModelSize(Enum):
    """Available Whisper model sizes."""
    TINY = "tiny"
    SMALL = "small" 
    MEDIUM = "medium"
    LARGE_V3 = "large-v3"

@dataclass
class ComprehensiveQualityMetrics:
    """Enhanced quality metrics using AdvancedQualityAnalyzer."""
    overall_quality_score: float
    confidence_score: float
    speech_activity_score: float
    content_quality_score: float
    temporal_quality_score: float
    model_performance_score: float
    processing_time: float
    cost_efficiency: float
    
    def get_weighted_score(self, weights: Optional[Dict] = None) -> float:
        """Calculate weighted quality score."""
        if weights is None:
            weights = {
                'overall_quality': 0.25,
                'confidence': 0.20,
                'speech_activity': 0.15,
                'content_quality': 0.15,
                'temporal_quality': 0.10,
                'model_performance': 0.10,
                'cost_efficiency': 0.05
            }
        
        return (
            self.overall_quality_score * weights['overall_quality'] +
            self.confidence_score * weights['confidence'] +
            self.speech_activity_score * weights['speech_activity'] +
            self.content_quality_score * weights['content_quality'] +
            self.temporal_quality_score * weights['temporal_quality'] +
            self.model_performance_score * weights['model_performance'] +
            self.cost_efficiency * weights['cost_efficiency']
        )

@dataclass
class ModelPerformanceResult:
    """Complete performance result for a single model."""
    model_name: str
    quality_metrics: ComprehensiveQualityMetrics
    transcription_result: Dict[str, Any]
    success: bool
    error_message: Optional[str] = None
    
    @property
    def performance_score(self) -> float:
        """Get the overall performance score for ranking."""
        if not self.success:
            return 0.0
        return self.quality_metrics.get_weighted_score()

@dataclass
class ModelComparisonResult:
    """Result of comparing multiple models."""
    best_model: str
    all_results: List[ModelPerformanceResult]
    performance_ranking: List[Tuple[str, float]]
    decision_reason: str
    total_comparison_time: float
    
    def get_model_result(self, model_name: str) -> Optional[ModelPerformanceResult]:
        """Get result for specific model."""
        for result in self.all_results:
            if result.model_name == model_name:
                return result
        return None

@dataclass
class SelectionDecision:
    """Model selection decision with metadata."""
    model_used: str
    quality_score: float
    escalation_reason: Optional[str]
    processing_time: float
    confidence_achieved: float
    metrics: ComprehensiveQualityMetrics

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

@dataclass 
class ContentProfile:
    """Profile of content characteristics for performance memory."""
    duration_category: str  # 'short', 'medium', 'long'
    complexity_category: str  # 'low', 'medium', 'high'
    content_type: str  # 'musical', 'speech', 'mixed'
    
    def to_key(self) -> str:
        """Convert to string key for storage."""
        return f"{self.duration_category}_{self.complexity_category}_{self.content_type}"

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

class PerformanceMemory:
    """Memory system for tracking model performance across different content types."""
    
    def __init__(self, memory_file: str = None):
        self.memory_file = memory_file or "model_performance_memory.pkl"
        self.performance_history: Dict[str, List[Dict]] = {}
        self.load_memory()
    
    def record_performance(self, content_profile: ContentProfile, model_results: List[ModelPerformanceResult]):
        """Record performance results for content profile."""
        profile_key = content_profile.to_key()
        
        if profile_key not in self.performance_history:
            self.performance_history[profile_key] = []
        
        # Record each model's performance
        performance_record = {
            'timestamp': time.time(),
            'content_profile': asdict(content_profile),
            'model_performances': {}
        }
        
        for result in model_results:
            if result.success:
                performance_record['model_performances'][result.model_name] = {
                    'performance_score': result.performance_score,
                    'quality_score': result.quality_metrics.overall_quality_score,
                    'confidence_score': result.quality_metrics.confidence_score,
                    'processing_time': result.quality_metrics.processing_time,
                    'cost_efficiency': result.quality_metrics.cost_efficiency
                }
        
        self.performance_history[profile_key].append(performance_record)
        
        # Keep only recent history (last 100 records per profile)
        if len(self.performance_history[profile_key]) > 100:
            self.performance_history[profile_key] = self.performance_history[profile_key][-100:]
        
        self.save_memory()
    
    def get_recommended_models(self, content_profile: ContentProfile, top_k: int = 2) -> List[Tuple[str, float]]:
        """Get recommended models for content profile based on historical performance."""
        profile_key = content_profile.to_key()
        
        if profile_key not in self.performance_history or not self.performance_history[profile_key]:
            # No history, return default ordering
            return [('small', 0.8), ('medium', 0.75)]
        
        # Aggregate performance scores for each model
        model_scores = {}
        for record in self.performance_history[profile_key][-20:]:  # Use recent 20 records
            for model_name, performance in record['model_performances'].items():
                if model_name not in model_scores:
                    model_scores[model_name] = []
                model_scores[model_name].append(performance['performance_score'])
        
        # Calculate average performance for each model
        model_averages = {}
        for model_name, scores in model_scores.items():
            if scores:
                model_averages[model_name] = np.mean(scores)
        
        # Sort by performance
        ranked_models = sorted(model_averages.items(), key=lambda x: x[1], reverse=True)
        
        return ranked_models[:top_k]
    
    def save_memory(self):
        """Save performance memory to disk."""
        try:
            with open(self.memory_file, 'wb') as f:
                pickle.dump(self.performance_history, f)
        except Exception as e:
            logger.warning(f"Failed to save performance memory: {e}")
    
    def load_memory(self):
        """Load performance memory from disk."""
        try:
            if os.path.exists(self.memory_file):
                with open(self.memory_file, 'rb') as f:
                    self.performance_history = pickle.load(f)
                logger.info(f"Loaded performance memory with {len(self.performance_history)} content profiles")
        except Exception as e:
            logger.warning(f"Failed to load performance memory: {e}")
            self.performance_history = {}

class EnhancedMultiMetricDecisionMatrix:
    """
    Enhanced decision matrix using comprehensive quality metrics from quality_metrics.py.
    Makes intelligent decisions about which model performs best regardless of size.
    """
    
    def __init__(self, config: Optional[Dict] = None):
        """Initialize enhanced decision matrix."""
        self.config = config or {}
        self.quality_analyzer = AdvancedQualityAnalyzer()
        
        # Model processing cost factors (relative to tiny)
        self.model_cost_factors = {
            'tiny': 1.0,
            'small': 2.5,
            'medium': 6.0,
            'large-v3': 12.0
        }
    
    def extract_comprehensive_metrics(self, transcription_result: Dict, model_name: str, 
                                    processing_time: float, audio_path: str = None) -> ComprehensiveQualityMetrics:
        """Extract comprehensive quality metrics using AdvancedQualityAnalyzer."""
        try:
            # Use the advanced quality analyzer
            quality_metrics = self.quality_analyzer.analyze_comprehensive_quality(
                transcription_result, audio_path
            )
            
            # Extract scores from different categories
            overall_quality_score = quality_metrics.get('overall_quality_score', 0.0)
            confidence_score = transcription_result.get('confidence_score', 0.0)
            
            # Speech activity metrics
            speech_activity = quality_metrics.get('speech_activity', {})
            speech_activity_score = speech_activity.get('speech_activity_ratio', 0.0)
            
            # Content quality metrics  
            content_quality = quality_metrics.get('content_quality', {})
            content_quality_score = (
                content_quality.get('vocabulary_richness', 0.0) * 0.4 +
                (1.0 - content_quality.get('filler_word_ratio', 0.0)) * 0.3 +
                content_quality.get('technical_content_density', 0.0) * 0.3
            )
            
            # Temporal quality metrics
            temporal_quality = quality_metrics.get('temporal_quality', {})
            temporal_quality_score = temporal_quality.get('timing_consistency_score', 0.0)
            
            # Model performance metrics
            model_performance = quality_metrics.get('model_performance', {})
            processing_efficiency = model_performance.get('processing_efficiency', {})
            model_performance_score = processing_efficiency.get('time_efficiency_score', 0.0)
            
            # Calculate cost efficiency (quality per computational cost)
            cost_factor = self.model_cost_factors.get(model_name, 1.0)
            cost_efficiency = overall_quality_score / cost_factor
            
            return ComprehensiveQualityMetrics(
                overall_quality_score=overall_quality_score,
                confidence_score=confidence_score,
                speech_activity_score=speech_activity_score,
                content_quality_score=content_quality_score,
                temporal_quality_score=temporal_quality_score,
                model_performance_score=model_performance_score,
                processing_time=processing_time,
                cost_efficiency=cost_efficiency
            )
            
        except Exception as e:
            logger.error(f"Error extracting comprehensive metrics: {e}")
            # Fallback to basic metrics
            return ComprehensiveQualityMetrics(
                overall_quality_score=transcription_result.get('confidence_score', 0.0),
                confidence_score=transcription_result.get('confidence_score', 0.0),
                speech_activity_score=0.5,
                content_quality_score=0.5,
                temporal_quality_score=0.5,
                model_performance_score=0.5,
                processing_time=processing_time,
                cost_efficiency=0.5
            )
    
    def compare_model_performance(self, model_results: List[ModelPerformanceResult]) -> ModelComparisonResult:
        """
        Compare multiple model results and select the best performer.
        
        This is the key improvement: we compare actual performance rather than assuming bigger is better.
        """
        if not model_results:
            raise ValueError("No model results to compare")
        
        # Filter successful results
        successful_results = [r for r in model_results if r.success]
        if not successful_results:
            raise ValueError("No successful model results to compare")
        
        # Calculate performance scores and rank models
        performance_ranking = []
        for result in successful_results:
            score = result.performance_score
            performance_ranking.append((result.model_name, score))
        
        # Sort by performance score (descending)
        performance_ranking.sort(key=lambda x: x[1], reverse=True)
        
        # Select best model
        best_model_name = performance_ranking[0][0]
        best_result = next(r for r in successful_results if r.model_name == best_model_name)
        
        # Generate decision reason
        decision_reason = self._generate_decision_reason(best_result, performance_ranking)
        
        # Calculate total comparison time
        total_time = sum(r.quality_metrics.processing_time for r in model_results)
        
        return ModelComparisonResult(
            best_model=best_model_name,
            all_results=model_results,
            performance_ranking=performance_ranking,
            decision_reason=decision_reason,
            total_comparison_time=total_time
        )
    
    def _generate_decision_reason(self, best_result: ModelPerformanceResult, 
                                ranking: List[Tuple[str, float]]) -> str:
        """Generate human-readable decision reason."""
        best_model = best_result.model_name
        best_score = ranking[0][1]
        
        # Find if a smaller model performed competitively
        smaller_models = []
        model_sizes = {'tiny': 0, 'small': 1, 'medium': 2, 'large-v3': 3}
        best_size = model_sizes.get(best_model, 0)
        
        for model_name, score in ranking[1:]:
            model_size = model_sizes.get(model_name, 0)
            if model_size < best_size and score > best_score * 0.95:  # Within 5%
                smaller_models.append((model_name, score))
        
        if smaller_models:
            return f"Selected {best_model} (score: {best_score:.3f}) despite competitive performance from smaller models {smaller_models}"
        elif best_model in ['medium', 'small'] and any(model == 'large-v3' for model, _ in ranking):
            return f"Selected {best_model} (score: {best_score:.3f}) - outperformed larger models through better optimization"
        else:
            return f"Selected {best_model} (score: {best_score:.3f}) - highest quality metrics across all categories"
    
    def should_escalate(self, metrics: ComprehensiveQualityMetrics, current_model: str) -> Tuple[bool, str]:
        """
        Enhanced escalation decision using comprehensive quality metrics.
        
        This method now considers multiple factors, not just simple thresholds.
        """
        # Define REALISTIC quality targets for each model (lowered from previous unrealistic thresholds)
        quality_targets = {
            'tiny': 0.65,      # 65% - reasonable for tiny model
            'small': 0.70,     # 70% - reasonable for small model  
            'medium': 0.75,    # 75% - reasonable for medium model
            'large-v3': 0.80   # 80% - reasonable for large model
        }
        
        current_target = quality_targets.get(current_model, 0.75)
        
        # EARLY STOPPING: If confidence is already very good, don't escalate regardless of other metrics
        if metrics.confidence_score >= 0.85:
            return False, f"excellent_confidence_{metrics.confidence_score:.3f}_no_escalation_needed"
        
        # EARLY STOPPING: If overall quality is already very good, don't escalate
        if metrics.overall_quality_score >= 0.80:
            return False, f"excellent_quality_{metrics.overall_quality_score:.3f}_no_escalation_needed"
        
        # Primary check: overall quality score
        if metrics.overall_quality_score < current_target:
            return True, f"overall_quality_{metrics.overall_quality_score:.3f}_below_target_{current_target}"
        
        # Secondary check: confidence score (lowered threshold)
        if metrics.confidence_score < 0.70:
            return True, f"confidence_{metrics.confidence_score:.3f}_below_minimum"
        
        # FIXED: Only escalate on temporal quality if it's VERY bad (not just 0.0)
        # Previous bug: temporal_quality_issues_0.000 was triggering escalation inappropriately
        if metrics.temporal_quality_score < 0.3 and current_model in ['tiny', 'small']:
            return True, f"severe_temporal_quality_issues_{metrics.temporal_quality_score:.3f}"
        
        # Content quality check - only for very poor content quality
        if metrics.content_quality_score < 0.5 and current_model == 'tiny':
            return True, f"poor_content_complexity_{metrics.content_quality_score:.3f}_needs_larger_model"
        
        return False, "quality_thresholds_met"
    
    def compare_multiple_models(self, audio_path: str, process_audio_func, 
                              models_to_test: List[str], **kwargs) -> ModelComparisonResult:
        """
        Test multiple models and select the best performer.
        
        This is the key new functionality: direct model comparison based on actual performance.
        """
        logger.info(f"Comparing models {models_to_test} for optimal performance selection")
        model_results = []
        
        for model_name in models_to_test:
            logger.info(f"Testing model: {model_name}")
            start_time = time.time()
            
            try:
                # Update preset config to use current model
                test_kwargs = kwargs.copy()
                if 'preset_config' in test_kwargs and test_kwargs['preset_config']:
                    test_kwargs['preset_config']['model_name'] = model_name
                
                # Process audio with this model
                result = process_audio_func(audio_path, model_name=model_name, **test_kwargs)
                processing_time = time.time() - start_time
                
                # Extract comprehensive metrics
                metrics = self.extract_comprehensive_metrics(result, model_name, processing_time, audio_path)
                
                # Create performance result
                model_result = ModelPerformanceResult(
                    model_name=model_name,
                    quality_metrics=metrics,
                    transcription_result=result,
                    success=True
                )
                
                logger.info(f"Model {model_name}: score={model_result.performance_score:.3f}, "
                           f"quality={metrics.overall_quality_score:.3f}, "
                           f"confidence={metrics.confidence_score:.3f}, "
                           f"time={processing_time:.1f}s")
                
            except Exception as e:
                logger.error(f"Model {model_name} failed: {str(e)}")
                model_result = ModelPerformanceResult(
                    model_name=model_name,
                    quality_metrics=ComprehensiveQualityMetrics(0, 0, 0, 0, 0, 0, 0, 0),
                    transcription_result={},
                    success=False,
                    error_message=str(e)
                )
            
            model_results.append(model_result)
        
        # Compare and select best model
        return self.compare_model_performance(model_results)

class IntelligentModelSelector:
    """
    Core intelligent model selection engine.
    
    Implements cascading model selection with quality-based escalation,
    content-aware pre-selection, and comprehensive performance tracking.
    """
    
    def __init__(self, config: Optional[Dict] = None):
        """Initialize intelligent selector with configuration."""
        self.config = config or {}
        self.decision_matrix = EnhancedMultiMetricDecisionMatrix(self.config)
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
        self.max_escalations = self.config.get('max_escalations', 2)  # REDUCED from 3 to 2
        self.enable_model_comparison = self.config.get('enable_model_comparison', False)
        self.performance_memory = PerformanceMemory()
        
        # REDUNDANCY PREVENTION: Cache audio analysis and post-processing results
        self._audio_analysis_cache = {}
        self._alignment_cache = {}
        
    def _cache_audio_analysis(self, audio_path: str, analysis_result: Dict):
        """Cache audio analysis to avoid recomputation."""
        self._audio_analysis_cache[audio_path] = analysis_result
        
    def _get_cached_audio_analysis(self, audio_path: str) -> Optional[Dict]:
        """Get cached audio analysis if available."""
        return self._audio_analysis_cache.get(audio_path)
        
    def _should_stop_escalation(self, decisions: List[SelectionDecision], current_model: str) -> Tuple[bool, str]:
        """Check if escalation should stop to prevent infinite loops."""
        
        # STOP: If we've already tried this model
        model_attempts = [d.model_used for d in decisions]
        if model_attempts.count(current_model) > 1:
            return True, f"model_{current_model}_already_attempted"
        
        # STOP: If last model performed better than current (avoid regression)
        if len(decisions) >= 2:
            previous_quality = decisions[-2].quality_score
            current_quality = decisions[-1].quality_score  
            if previous_quality > current_quality:
                return True, f"quality_regression_{previous_quality:.3f}_to_{current_quality:.3f}"
        
        # STOP: If we're at maximum escalations
        if len(decisions) > self.max_escalations:
            return True, f"max_escalations_{self.max_escalations}_reached"
            
        # STOP: If quality is decreasing consistently
        if len(decisions) >= 3:
            last_three_qualities = [d.quality_score for d in decisions[-3:]]
            if all(last_three_qualities[i] >= last_three_qualities[i+1] for i in range(2)):
                return True, "consistent_quality_degradation"
        
        return False, "escalation_can_continue"
    
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
                
                # CHECK: Should we stop escalation before processing?
                should_stop, stop_reason = self._should_stop_escalation(decisions, current_model)
                if should_stop:
                    logger.info(f"Stopping escalation: {stop_reason}")
                    break
                
                model_start_time = time.time()
                
                # Process audio with current model
                # Update preset config to use current model
                if 'preset_config' in kwargs and kwargs['preset_config']:
                    kwargs['preset_config']['model_name'] = current_model
                
                result = process_audio_func(audio_path, model_name=current_model, **kwargs)
                model_processing_time = time.time() - model_start_time
                
                # Step 3: Evaluate quality and decide escalation
                metrics = self.decision_matrix.extract_comprehensive_metrics(result, current_model, model_processing_time)
                should_escalate, escalation_reason = self.decision_matrix.should_escalate(metrics, current_model)
                
                # Record decision
                decision = SelectionDecision(
                    model_used=current_model,
                    quality_score=metrics.overall_quality_score,
                    escalation_reason=escalation_reason if should_escalate else None,
                    processing_time=model_processing_time,
                    confidence_achieved=metrics.confidence_score,
                    metrics=metrics
                )
                decisions.append(decision)
                
                logger.info(f"Model {current_model}: quality={metrics.overall_quality_score:.3f}, "
                           f"confidence={metrics.confidence_score:.3f}, time={model_processing_time:.1f}s")
                
                # Step 4: Check escalation with enhanced logic
                if should_escalate:
                    # ADDITIONAL CHECK: Prevent escalation if quality is actually good enough
                    if metrics.overall_quality_score >= 0.75 and metrics.confidence_score >= 0.75:
                        logger.info(f"Quality is actually good enough (quality={metrics.overall_quality_score:.3f}, "
                                  f"confidence={metrics.confidence_score:.3f}), stopping escalation")
                        should_escalate = False
                        escalation_reason = "quality_sufficient_overriding_escalation_signal"
                    
                    # CHECK: Should we stop escalation due to safety rules?
                    should_stop_safety, safety_reason = self._should_stop_escalation(decisions, current_model)
                    if should_stop_safety:
                        logger.info(f"Safety stop triggered: {safety_reason}")
                        should_escalate = False
                
                if should_escalate:
                    next_model = self._get_next_model(current_model)
                    if next_model and escalation_count < self.max_escalations:
                        logger.info(f"Escalating from {current_model} to {next_model}: {escalation_reason}")
                        current_model = next_model
                        escalation_count += 1
                        continue
                    else:
                        logger.info(f"Cannot escalate further: no next model or max escalations reached")
                
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
                        metrics=ComprehensiveQualityMetrics(0, 0, 0, 0, 0, 0, 0, 0)
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
    
    def select_optimal_model(self, audio_path: str, process_audio_func, **kwargs) -> IntelligentSelectionResult:
        """
        NEW: Direct model comparison to find the optimal performer.
        
        This method tests multiple models and selects the best one based on 
        comprehensive quality metrics, not just size assumptions.
        """
        start_time = time.time()
        
        # Step 1: Content analysis for smart model selection
        content_analysis = self.pre_selector.analyze_audio_content(audio_path)
        content_profile = self._create_content_profile(content_analysis)
        
        # Step 2: Get recommended models from performance memory
        recommended_models = self.performance_memory.get_recommended_models(content_profile, top_k=3)
        
        if recommended_models:
            # Use historical performance to guide model selection
            models_to_test = [model for model, score in recommended_models] + ['small', 'medium']
            # Remove duplicates while preserving order
            models_to_test = list(dict.fromkeys(models_to_test))
            logger.info(f"Using performance memory recommendations: {models_to_test}")
        else:
            # Default comprehensive test for new content types
            models_to_test = ['small', 'medium', 'large-v3']
            logger.info(f"No performance history, testing default models: {models_to_test}")
        
        # Limit to first 3 models for efficiency
        models_to_test = models_to_test[:3]
        
        # Step 3: Compare models directly
        logger.info(f"OPTIMAL MODEL SELECTION: Testing {models_to_test} to find best performer")
        comparison_result = self.decision_matrix.compare_multiple_models(
            audio_path, process_audio_func, models_to_test, **kwargs
        )
        
        # Step 4: Record performance in memory for future optimization
        model_results = [ModelPerformanceResult(
            model_name=result.model_name,
            quality_metrics=self.decision_matrix.extract_comprehensive_metrics(
                result.transcription_result, result.model_name, 
                result.quality_metrics.processing_time, audio_path
            ),
            transcription_result=result.transcription_result,
            success=result.success,
            error_message=result.error_message
        ) for result in comparison_result.all_results]
        
        self.performance_memory.record_performance(content_profile, model_results)
        
        # Step 5: Create comprehensive result
        best_result = comparison_result.get_model_result(comparison_result.best_model)
        total_processing_time = time.time() - start_time
        
        # Convert to IntelligentSelectionResult format
        decisions = [SelectionDecision(
            model_used=result.model_name,
            quality_score=result.quality_metrics.overall_quality_score,
            escalation_reason=None,
            processing_time=result.quality_metrics.processing_time,
            confidence_achieved=result.quality_metrics.confidence_score,
            metrics=result.quality_metrics
        ) for result in comparison_result.all_results if result.success]
        
        # Calculate time saved vs. always using largest model
        time_saved = self._calculate_time_saved('large-v3', comparison_result.best_model, total_processing_time)
        
        # Create result with enhanced metadata
        selection_result = IntelligentSelectionResult(
            initial_model='comparison_mode',
            final_model=comparison_result.best_model,
            escalation_count=0,  # Not applicable in comparison mode
            decisions=decisions,
            total_processing_time=total_processing_time,
            time_saved_percentage=time_saved,
            quality_achieved=best_result.quality_metrics.overall_quality_score,
            transcription_result=best_result.transcription_result
        )
        
        # Add enhanced metadata to transcription result
        best_result.transcription_result['optimal_model_selection'] = {
            'selection_mode': 'direct_comparison',
            'models_tested': models_to_test,
            'best_model': comparison_result.best_model,
            'decision_reason': comparison_result.decision_reason,
            'performance_ranking': comparison_result.performance_ranking,
            'total_comparison_time': total_processing_time,
            'time_saved_percentage': time_saved,
            'content_profile': asdict(content_profile),
            'used_performance_memory': bool(recommended_models),
            'model_performances': [
                {
                    'model': d.model_used,
                    'quality_score': d.quality_score,
                    'confidence': d.confidence_achieved,
                    'processing_time': d.processing_time,
                    'cost_efficiency': d.metrics.cost_efficiency
                }
                for d in decisions
            ]
        }
        
        logger.info(f"OPTIMAL SELECTION COMPLETED: {comparison_result.best_model} selected from {models_to_test}")
        logger.info(f"Decision: {comparison_result.decision_reason}")
        logger.info(f"Performance ranking: {comparison_result.performance_ranking}")
        
        return selection_result
    
    def _create_content_profile(self, content_analysis: Dict) -> ContentProfile:
        """Create content profile for performance memory."""
        duration = content_analysis.get('duration_seconds', 0)
        complexity = content_analysis.get('complexity_score', 0.5)
        
        # Categorize duration
        if duration < 60:
            duration_category = 'short'
        elif duration < 300:
            duration_category = 'medium'
        else:
            duration_category = 'long'
        
        # Categorize complexity
        if complexity < 0.3:
            complexity_category = 'low'
        elif complexity < 0.7:
            complexity_category = 'medium'
        else:
            complexity_category = 'high'
        
        # Determine content type (this could be enhanced with more sophisticated analysis)
        content_type = 'musical'  # Default for guitar lessons
        
        return ContentProfile(
            duration_category=duration_category,
            complexity_category=complexity_category,
            content_type=content_type
        )
    
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
def intelligent_process_audio(audio_path: str, process_audio_func, enable_intelligent_selection: bool = True, 
                            enable_optimal_selection: bool = False, **kwargs) -> Dict:
    """
    Process audio with intelligent model selection.
    
    Args:
        audio_path: Path to audio file
        process_audio_func: Original process_audio function
        enable_intelligent_selection: Whether to use intelligent selection
        enable_optimal_selection: Whether to use direct model comparison for optimal selection
        **kwargs: Additional arguments for processing
        
    Returns:
        Enhanced transcription result with intelligent selection metadata
    """
    if not enable_intelligent_selection:
        # Fallback to original processing
        return process_audio_func(audio_path, **kwargs)
    
    # Create intelligent selector with SAFER default config
    config = {
        'enable_model_comparison': enable_optimal_selection,
        'enable_pre_selection': True,
        'max_escalations': 1  # REDUCED from 2 - single escalation only to prevent infinite loops
    }
    selector = create_intelligent_selector(config)
    
    if enable_optimal_selection:
        # Use direct model comparison for best results
        logger.info("Using OPTIMAL MODEL SELECTION mode - comparing multiple models")
        result = selector.select_optimal_model(audio_path, process_audio_func, **kwargs)
    else:
        # Use cascading escalation (faster)
        logger.info("Using cascading escalation mode with anti-infinite-loop protections")
        result = selector.select_and_process(audio_path, process_audio_func, **kwargs)
    
    # ADD: Escalation safety summary
    if 'intelligent_selection' in result.transcription_result:
        selection_info = result.transcription_result['intelligent_selection']
        logger.info(f"ESCALATION SUMMARY: {selection_info['initial_model']} → {selection_info['final_model']} "
                   f"in {selection_info['escalation_count']} escalations "
                   f"(Quality: {selection_info['quality_achieved']:.3f}, "
                   f"Time: {selection_info['total_processing_time']:.1f}s)")
    
    return result.transcription_result

if __name__ == "__main__":
    # Example usage and testing
    print("Intelligent Model Selection Engine - Test Mode")
    
    # Test decision matrix
    matrix = EnhancedMultiMetricDecisionMatrix()
    test_metrics = ComprehensiveQualityMetrics(
        overall_quality_score=0.73,
        confidence_score=0.82,
        speech_activity_score=0.95,
        content_quality_score=0.85,
        temporal_quality_score=0.90,
        model_performance_score=0.88,
        processing_time=120,
        cost_efficiency=0.87
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