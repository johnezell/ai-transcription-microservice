#!/usr/bin/env python3
"""
Intelligent Audio Quality Selector for Audio Extraction Service

This module implements intelligent audio quality selection using cascading quality levels
(fast → balanced → high → premium) with automatic escalation based on quality metrics.

Key Features:
- Cascading quality escalation: Start fast, escalate as needed
- Quality-aware decision making using existing SpeechQualityAnalyzer
- Multi-metric analysis: sample_rate, volume_level, dynamic_range, duration, bit_rate
- Automatic optimization for both speed and quality
- Detailed selection reasoning and metrics
"""

import os
import logging
import time
from typing import Dict, List, Optional, Tuple, Any
from dataclasses import dataclass
from datetime import datetime

# Import existing audio service functions
from speech_quality_analyzer import SpeechQualityAnalyzer, analyze_speech_quality
from audio_quality_utils import get_audio_stats, get_audio_volume_stats

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


@dataclass
class AudioQualityMetrics:
    """Audio quality metrics for intelligent selection decisions."""
    overall_score: float
    sample_rate_score: float
    volume_score: float
    dynamic_range_score: float
    duration_score: float
    bit_rate_score: float
    grade: str
    
    def is_acceptable(self, threshold: float = 75.0) -> bool:
        """Check if audio quality meets the threshold."""
        return self.overall_score >= threshold


@dataclass
class AudioSelectionDecision:
    """Decision made by the intelligent audio selector."""
    selected_quality: str
    confidence_score: float
    processing_time: float
    escalation_reason: Optional[str]
    fallback_used: bool
    
    def to_dict(self) -> Dict[str, Any]:
        """Convert to dictionary for JSON serialization."""
        return {
            'selected_quality': self.selected_quality,
            'confidence_score': self.confidence_score,
            'processing_time': self.processing_time,
            'escalation_reason': self.escalation_reason,
            'fallback_used': self.fallback_used
        }


@dataclass
class IntelligentAudioResult:
    """Complete result from intelligent audio extraction."""
    success: bool
    audio_path: str
    final_quality: str
    processing_time: float
    quality_metrics: Optional[AudioQualityMetrics]
    selection_decision: Optional[AudioSelectionDecision]
    escalation_history: List[Dict[str, Any]]
    error_message: Optional[str] = None
    
    def to_dict(self) -> Dict[str, Any]:
        """Convert to dictionary for JSON serialization."""
        result = {
            'success': self.success,
            'audio_path': self.audio_path,
            'final_quality': self.final_quality,
            'processing_time': self.processing_time,
            'escalation_history': self.escalation_history
        }
        
        if self.quality_metrics:
            result['quality_metrics'] = {
                'overall_score': self.quality_metrics.overall_score,
                'sample_rate_score': self.quality_metrics.sample_rate_score,
                'volume_score': self.quality_metrics.volume_score,
                'dynamic_range_score': self.quality_metrics.dynamic_range_score,
                'duration_score': self.quality_metrics.duration_score,
                'bit_rate_score': self.quality_metrics.bit_rate_score,
                'grade': self.quality_metrics.grade
            }
            
        if self.selection_decision:
            result['selection_decision'] = self.selection_decision.to_dict()
            
        if self.error_message:
            result['error_message'] = self.error_message
            
        return result


class AudioIntelligentSelector:
    """
    Intelligent audio quality selector with cascading escalation.
    
    Uses quality analysis to automatically escalate from fast → balanced → high → premium
    based on audio quality metrics and predefined thresholds.
    """
    
    # Quality levels in escalation order
    QUALITY_LEVELS = ['fast', 'balanced', 'high', 'premium']
    
    # Escalation thresholds (overall quality score)
    ESCALATION_THRESHOLDS = {
        'fast': 70.0,      # If score < 70, escalate to balanced
        'balanced': 75.0,   # If score < 75, escalate to high  
        'high': 80.0,      # If score < 80, escalate to premium
        'premium': 85.0    # Premium is final level
    }
    
    # Quality level characteristics for decision making
    QUALITY_CONFIGS = {
        'fast': {
            'processing_time_factor': 1.0,
            'expected_quality_range': (60, 75),
            'recommended_for': 'quick processing, draft quality'
        },
        'balanced': {
            'processing_time_factor': 1.5,
            'expected_quality_range': (70, 85),
            'recommended_for': 'good balance of speed and quality'
        },
        'high': {
            'processing_time_factor': 2.0,
            'expected_quality_range': (80, 90),
            'recommended_for': 'high quality audio with noise reduction'
        },
        'premium': {
            'processing_time_factor': 2.5,
            'expected_quality_range': (85, 95),
            'recommended_for': 'maximum quality with advanced processing'
        }
    }
    
    # Target performance metrics
    TARGET_METRICS = {
        'time_savings_percent': 60,    # Target 60% time savings vs always using premium
        'quality_success_rate': 92,    # Target 92% success rate (quality ≥ 75)
        'escalation_rate': 30,         # Target 30% escalation rate
        'cost_reduction_percent': 50   # Target 50% computational cost reduction
    }
    
    def __init__(self):
        """Initialize the intelligent audio selector."""
        self.analyzer = SpeechQualityAnalyzer()
        logger.info("Initialized AudioIntelligentSelector with cascading quality levels")
        
    def analyze_audio_quality(self, audio_path: str) -> Optional[AudioQualityMetrics]:
        """
        Analyze audio quality using the existing SpeechQualityAnalyzer.
        
        Args:
            audio_path: Path to the audio file
            
        Returns:
            AudioQualityMetrics object or None if analysis fails
        """
        try:
            analysis = analyze_speech_quality(audio_path)
            
            if not analysis.get('success', False):
                logger.warning(f"Audio quality analysis failed: {analysis.get('error', 'Unknown error')}")
                return None
                
            metrics_data = analysis.get('metrics', {})
            
            return AudioQualityMetrics(
                overall_score=analysis.get('overall_score', 0.0),
                sample_rate_score=metrics_data.get('sample_rate', {}).get('score', 0.0),
                volume_score=metrics_data.get('volume_level', {}).get('score', 0.0),
                dynamic_range_score=metrics_data.get('dynamic_range', {}).get('score', 0.0),
                duration_score=metrics_data.get('duration', {}).get('score', 0.0),
                bit_rate_score=metrics_data.get('bit_rate', {}).get('score', 0.0),
                grade=analysis.get('grade', 'Unknown')
            )
            
        except Exception as e:
            logger.error(f"Error analyzing audio quality: {str(e)}")
            return None
            
    def should_escalate(self, quality_level: str, metrics: AudioQualityMetrics) -> Tuple[bool, str]:
        """
        Determine if quality should be escalated based on metrics.
        
        Args:
            quality_level: Current quality level being tested
            metrics: Audio quality metrics
            
        Returns:
            Tuple of (should_escalate, reason)
        """
        if quality_level not in self.ESCALATION_THRESHOLDS:
            return False, f"Unknown quality level: {quality_level}"
            
        threshold = self.ESCALATION_THRESHOLDS[quality_level]
        
        if metrics.overall_score >= threshold:
            return False, f"Quality score {metrics.overall_score:.1f} meets threshold {threshold}"
            
        # Check if this is the highest quality level
        if quality_level == 'premium':
            return False, "Already at highest quality level"
            
        # Specific escalation reasons based on weak metrics
        weak_metrics = []
        if metrics.sample_rate_score < 70:
            weak_metrics.append("sample_rate")
        if metrics.volume_score < 60:
            weak_metrics.append("volume_level") 
        if metrics.dynamic_range_score < 65:
            weak_metrics.append("dynamic_range")
        if metrics.bit_rate_score < 70:
            weak_metrics.append("bit_rate")
            
        if weak_metrics:
            reason = f"Quality score {metrics.overall_score:.1f} below threshold {threshold}. Weak metrics: {', '.join(weak_metrics)}"
        else:
            reason = f"Overall quality score {metrics.overall_score:.1f} below threshold {threshold}"
            
        return True, reason
        
    def get_next_quality_level(self, current_level: str) -> Optional[str]:
        """
        Get the next quality level for escalation.
        
        Args:
            current_level: Current quality level
            
        Returns:
            Next quality level or None if at highest level
        """
        try:
            current_index = self.QUALITY_LEVELS.index(current_level)
            if current_index < len(self.QUALITY_LEVELS) - 1:
                return self.QUALITY_LEVELS[current_index + 1]
            return None
        except ValueError:
            logger.error(f"Unknown quality level: {current_level}")
            return None
            
    def extract_with_quality_analysis(self, video_path: str, output_path: str, 
                                    quality_level: str) -> Tuple[bool, Optional[AudioQualityMetrics], float]:
        """
        Extract audio and analyze quality.
        
        Args:
            video_path: Path to input video file
            output_path: Path for output audio file
            quality_level: Quality level to use
            
        Returns:
            Tuple of (success, quality_metrics, processing_time)
        """
        start_time = time.time()
        
        try:
            # Import the convert function from the main service
            from service import convert_to_wav
            
            # Extract audio with specified quality
            success = convert_to_wav(video_path, output_path, quality_level)
            processing_time = time.time() - start_time
            
            if not success:
                return False, None, processing_time
                
            # Analyze the extracted audio quality
            metrics = self.analyze_audio_quality(output_path)
            
            return True, metrics, processing_time
            
        except Exception as e:
            processing_time = time.time() - start_time
            logger.error(f"Error in audio extraction with quality analysis: {str(e)}")
            return False, None, processing_time
            
    def intelligent_audio_extraction(self, video_path: str, output_path: str,
                                   target_quality_threshold: float = 75.0,
                                   max_escalations: int = 3) -> IntelligentAudioResult:
        """
        Perform intelligent audio extraction with automatic quality escalation.
        
        Args:
            video_path: Path to input video file
            output_path: Path for output audio file
            target_quality_threshold: Minimum acceptable quality score
            max_escalations: Maximum number of quality escalations
            
        Returns:
            IntelligentAudioResult with complete extraction results
        """
        start_time = time.time()
        escalation_history = []
        current_quality = 'fast'  # Always start with fastest quality
        
        logger.info(f"Starting intelligent audio extraction: {video_path} -> {output_path}")
        logger.info(f"Target quality threshold: {target_quality_threshold}, Max escalations: {max_escalations}")
        
        for escalation_step in range(max_escalations + 1):
            step_start_time = time.time()
            
            logger.info(f"Extraction attempt {escalation_step + 1}/{max_escalations + 1} with quality: {current_quality}")
            
            # Extract audio and analyze quality
            success, metrics, step_processing_time = self.extract_with_quality_analysis(
                video_path, output_path, current_quality
            )
            
            # Record this step in history
            step_record = {
                'step': escalation_step + 1,
                'quality_level': current_quality,
                'processing_time': step_processing_time,
                'success': success,
                'timestamp': datetime.now().isoformat()
            }
            
            if success and metrics:
                step_record.update({
                    'quality_score': metrics.overall_score,
                    'grade': metrics.grade,
                    'meets_threshold': metrics.overall_score >= target_quality_threshold
                })
                
                # Check if quality is acceptable
                if metrics.overall_score >= target_quality_threshold:
                    step_record['decision'] = 'accepted'
                    escalation_history.append(step_record)
                    
                    total_processing_time = time.time() - start_time
                    
                    logger.info(f"Quality threshold met! Score: {metrics.overall_score:.1f} >= {target_quality_threshold}")
                    logger.info(f"Final quality: {current_quality}, Total time: {total_processing_time:.2f}s")
                    
                    return IntelligentAudioResult(
                        success=True,
                        audio_path=output_path,
                        final_quality=current_quality,
                        processing_time=total_processing_time,
                        quality_metrics=metrics,
                        selection_decision=AudioSelectionDecision(
                            selected_quality=current_quality,
                            confidence_score=metrics.overall_score,
                            processing_time=total_processing_time,
                            escalation_reason=None if escalation_step == 0 else f"Escalated {escalation_step} times for better quality",
                            fallback_used=False
                        ),
                        escalation_history=escalation_history
                    )
                    
                # Check if we should escalate
                should_escalate, reason = self.should_escalate(current_quality, metrics)
                
                if should_escalate and escalation_step < max_escalations:
                    next_quality = self.get_next_quality_level(current_quality)
                    if next_quality:
                        step_record['decision'] = 'escalate'
                        step_record['escalation_reason'] = reason
                        step_record['next_quality'] = next_quality
                        escalation_history.append(step_record)
                        
                        logger.info(f"Escalating from {current_quality} to {next_quality}: {reason}")
                        current_quality = next_quality
                        continue
                        
                # If we can't escalate further, accept current quality
                step_record['decision'] = 'accepted_final'
                step_record['final_reason'] = 'Maximum escalations reached or highest quality level'
                escalation_history.append(step_record)
                
                total_processing_time = time.time() - start_time
                
                logger.info(f"Accepting final quality: {current_quality} (score: {metrics.overall_score:.1f})")
                
                return IntelligentAudioResult(
                    success=True,
                    audio_path=output_path,
                    final_quality=current_quality,
                    processing_time=total_processing_time,
                    quality_metrics=metrics,
                    selection_decision=AudioSelectionDecision(
                        selected_quality=current_quality,
                        confidence_score=metrics.overall_score,
                        processing_time=total_processing_time,
                        escalation_reason=f"Escalated to highest feasible quality level",
                        fallback_used=False
                    ),
                    escalation_history=escalation_history
                )
                
            else:
                # Extraction failed
                step_record['decision'] = 'failed'
                step_record['error'] = 'Audio extraction failed'
                escalation_history.append(step_record)
                
                logger.error(f"Audio extraction failed with quality: {current_quality}")
                
                # Try next quality level if available
                if escalation_step < max_escalations:
                    next_quality = self.get_next_quality_level(current_quality)
                    if next_quality:
                        logger.info(f"Trying next quality level: {next_quality}")
                        current_quality = next_quality
                        continue
                        
                # All attempts failed
                total_processing_time = time.time() - start_time
                
                return IntelligentAudioResult(
                    success=False,
                    audio_path=output_path,
                    final_quality=current_quality,
                    processing_time=total_processing_time,
                    quality_metrics=None,
                    selection_decision=None,
                    escalation_history=escalation_history,
                    error_message=f"All audio extraction attempts failed"
                )
                
        # This shouldn't be reached, but handle it just in case
        total_processing_time = time.time() - start_time
        return IntelligentAudioResult(
            success=False,
            audio_path=output_path,
            final_quality=current_quality,
            processing_time=total_processing_time,
            quality_metrics=None,
            selection_decision=None,
            escalation_history=escalation_history,
            error_message="Unexpected end of extraction process"
        )
        
    def get_quality_recommendation(self, video_path: str) -> Dict[str, Any]:
        """
        Analyze source video and recommend initial quality level.
        
        Args:
            video_path: Path to source video file
            
        Returns:
            Dictionary with recommendation and reasoning
        """
        try:
            # Get basic video information
            import subprocess
            import json
            
            command = [
                "ffprobe", "-v", "error",
                "-select_streams", "a:0",
                "-show_entries", "stream=codec_name,sample_rate,channels,duration,bit_rate",
                "-of", "json",
                str(video_path)
            ]
            
            result = subprocess.run(command, capture_output=True, text=True)
            
            if result.returncode != 0:
                return {
                    'recommended_quality': 'balanced',
                    'confidence': 50,
                    'reasoning': 'Unable to analyze source video, using balanced default'
                }
                
            probe_data = json.loads(result.stdout)
            stream = probe_data.get('streams', [{}])[0]
            
            # Analyze source characteristics
            source_sample_rate = int(stream.get('sample_rate', 0))
            source_channels = int(stream.get('channels', 0))
            source_duration = float(stream.get('duration', 0))
            source_bit_rate = int(stream.get('bit_rate', 0))
            
            # Make recommendation based on source quality
            if source_sample_rate >= 44100 and source_bit_rate >= 256000:
                recommendation = 'premium'
                confidence = 90
                reasoning = f"High-quality source (SR: {source_sample_rate}Hz, BR: {source_bit_rate//1000}kbps) warrants premium processing"
            elif source_sample_rate >= 16000 and source_bit_rate >= 128000:
                recommendation = 'high'
                confidence = 80
                reasoning = f"Good source quality (SR: {source_sample_rate}Hz, BR: {source_bit_rate//1000}kbps) suggests high quality processing"
            elif source_duration > 300:  # 5 minutes
                recommendation = 'balanced'
                confidence = 75
                reasoning = f"Long duration ({source_duration:.1f}s) suggests balanced approach for efficiency"
            else:
                recommendation = 'balanced'
                confidence = 70
                reasoning = f"Standard source quality (SR: {source_sample_rate}Hz) suits balanced processing"
                
            return {
                'recommended_quality': recommendation,
                'confidence': confidence,
                'reasoning': reasoning,
                'source_analysis': {
                    'sample_rate': source_sample_rate,
                    'channels': source_channels,
                    'duration': source_duration,
                    'bit_rate': source_bit_rate
                }
            }
            
        except Exception as e:
            logger.error(f"Error analyzing source video: {str(e)}")
            return {
                'recommended_quality': 'balanced',
                'confidence': 50,
                'reasoning': f'Analysis error: {str(e)}, using balanced default'
            }


def intelligent_audio_extraction(video_path: str, output_path: str, 
                               target_quality_threshold: float = 75.0,
                               max_escalations: int = 3) -> IntelligentAudioResult:
    """
    Convenience function for intelligent audio extraction.
    
    Args:
        video_path: Path to input video file
        output_path: Path for output audio file
        target_quality_threshold: Minimum acceptable quality score
        max_escalations: Maximum number of quality escalations
        
    Returns:
        IntelligentAudioResult with complete extraction results
    """
    selector = AudioIntelligentSelector()
    return selector.intelligent_audio_extraction(
        video_path, output_path, target_quality_threshold, max_escalations
    )


def get_audio_quality_recommendation(video_path: str) -> Dict[str, Any]:
    """
    Convenience function to get quality recommendation for a video.
    
    Args:
        video_path: Path to source video file
        
    Returns:
        Dictionary with recommendation and reasoning
    """
    selector = AudioIntelligentSelector()
    return selector.get_quality_recommendation(video_path)


if __name__ == "__main__":
    # Example usage and testing
    import sys
    
    if len(sys.argv) < 3:
        print("Usage: python audio_intelligent_selector.py <video_path> <output_path> [threshold]")
        print("Example: python audio_intelligent_selector.py input.mp4 output.wav 75")
        sys.exit(1)
        
    video_path = sys.argv[1]
    output_path = sys.argv[2]
    threshold = float(sys.argv[3]) if len(sys.argv) > 3 else 75.0
    
    print(f"Intelligent Audio Extraction")
    print(f"Video: {video_path}")
    print(f"Output: {output_path}")
    print(f"Quality threshold: {threshold}")
    print("=" * 50)
    
    # Get quality recommendation first
    recommendation = get_audio_quality_recommendation(video_path)
    print(f"Quality Recommendation: {recommendation['recommended_quality']} ({recommendation['confidence']}% confidence)")
    print(f"Reasoning: {recommendation['reasoning']}")
    print("-" * 50)
    
    # Perform intelligent extraction
    result = intelligent_audio_extraction(video_path, output_path, threshold)
    
    print(f"Extraction Result:")
    print(f"Success: {result.success}")
    print(f"Final Quality: {result.final_quality}")
    print(f"Processing Time: {result.processing_time:.2f}s")
    
    if result.quality_metrics:
        print(f"Final Quality Score: {result.quality_metrics.overall_score:.1f}/100 ({result.quality_metrics.grade})")
        
    print(f"Escalation Steps: {len(result.escalation_history)}")
    
    if result.selection_decision:
        print(f"Selection Confidence: {result.selection_decision.confidence_score:.1f}")
        if result.selection_decision.escalation_reason:
            print(f"Escalation Reason: {result.selection_decision.escalation_reason}")
            
    if result.error_message:
        print(f"Error: {result.error_message}") 