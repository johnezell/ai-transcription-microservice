"""
Teaching Pattern Analyzer Module
Analyzes speech/non-speech patterns to identify teaching styles and content types in guitar lessons.
"""

import numpy as np
import logging
import sys
import platform
from datetime import datetime
from typing import Dict, List, Tuple, Optional, Any
from dataclasses import dataclass

logger = logging.getLogger(__name__)

# Algorithm version for data provenance and reprocessing
ALGORITHM_VERSION = "1.0.0"
ALGORITHM_RELEASE_DATE = "2024-12-01"

@dataclass
class TeachingPattern:
    """Represents a detected teaching pattern."""
    pattern_type: str
    confidence: float
    description: str
    evidence: Dict[str, Any]
    characteristics: Dict[str, float]

class TeachingPatternAnalyzer:
    """
    Analyzes speech/non-speech patterns to identify teaching styles and content types.
    """
    
    # Pattern classification thresholds
    PATTERN_THRESHOLDS = {
        'demonstration': {
            'non_speech_ratio_min': 0.60,  # 60%+ non-speech (playing guitar)
            'max_speech_duration': 10.0,   # Short verbal explanations
        },
        'instructional': {
            'speech_ratio_min': 0.40,      # 40-70% speech
            'speech_ratio_max': 0.70,
            'alternation_cycles_min': 3,   # Regular alternation
        },
        'overview': {
            'speech_ratio_min': 0.50,      # 50%+ speech
            'front_speech_ratio': 0.4,     # Speech heavy at start
            'back_speech_ratio': 0.3,      # Speech heavy at end
        },
        'performance': {
            'non_speech_ratio_min': 0.80,  # 80%+ playing
            'speech_segments_max': 3,      # Very few speech segments
        }
    }
    
    def __init__(self):
        """Initialize the teaching pattern analyzer."""
        logger.info("Initializing Teaching Pattern Analyzer")
    
    def analyze_teaching_patterns(self, transcription_result: Dict, speech_activity_data: Dict) -> Dict:
        """
        Main method to analyze teaching patterns.
        
        Args:
            transcription_result: Full transcription result with segments
            speech_activity_data: Speech activity analysis from quality_metrics.py
            
        Returns:
            Dict containing teaching pattern analysis results with full data provenance
        """
        try:
            processing_timestamp = datetime.now().isoformat()
            segments = transcription_result.get('segments', [])
            text = transcription_result.get('text', '')
            
            if not segments:
                return {'error': 'No segments available for teaching pattern analysis'}
            
            # Analyze temporal patterns  
            temporal_analysis = self._analyze_temporal_patterns(segments, speech_activity_data)
            
            # Detect specific teaching patterns
            detected_patterns = self._detect_teaching_patterns(temporal_analysis, text)
            
            # Classify overall content type
            content_classification = self._classify_content_type(detected_patterns, text, temporal_analysis)
            
            # Get content keywords used for classification
            content_keywords = self._get_content_keywords()
            
            # ENHANCED: Include full data provenance for reprocessing
            return {
                # Core analysis results
                'temporal_analysis': temporal_analysis,
                'detected_patterns': [self._pattern_to_dict(p) for p in detected_patterns],
                'content_classification': content_classification,
                'summary': self._generate_summary(detected_patterns, content_classification),
                
                # Data provenance for reprocessing
                'algorithm_metadata': {
                    'version': ALGORITHM_VERSION,
                    'release_date': ALGORITHM_RELEASE_DATE,
                    'processing_timestamp': processing_timestamp,
                    'python_version': sys.version,
                    'platform': platform.platform(),
                    'analyzer_class': self.__class__.__name__
                },
                
                # Algorithm parameters used (for exact reprocessing)
                'algorithm_parameters': {
                    'pattern_thresholds': self.PATTERN_THRESHOLDS.copy(),
                    'content_keywords': content_keywords,
                    'analysis_method': 'temporal_pattern_based',
                    'confidence_calculation': 'evidence_weighted'
                },
                
                # Raw input data preserved (for reprocessing different algorithms)
                'input_data_snapshot': {
                    'segments_count': len(segments),
                    'text_length': len(text),
                    'speech_activity_fields': list(speech_activity_data.keys()),
                    'total_duration': speech_activity_data.get('total_duration_seconds', 0),
                    'segments_preserved': True,  # Segments are already in transcription_result
                    'speech_activity_preserved': True  # Speech activity data preserved
                },
                
                # Processing metadata for debugging/auditing
                'processing_metadata': {
                    'analysis_successful': True,
                    'patterns_detected_count': len(detected_patterns),
                    'temporal_analysis_fields': list(temporal_analysis.keys()),
                    'content_classification_successful': bool(content_classification),
                    'processing_notes': [
                        f"Analyzed {len(segments)} speech segments",
                        f"Detected {len(detected_patterns)} teaching patterns",
                        f"Content classified as: {content_classification.get('primary_type', 'unknown')}"
                    ]
                }
            }
            
        except Exception as e:
            error_timestamp = datetime.now().isoformat()
            logger.error(f"Teaching pattern analysis failed: {str(e)}")
            
            # Include error metadata for debugging
            return {
                'error': f'Teaching pattern analysis failed: {str(e)}',
                'algorithm_metadata': {
                    'version': ALGORITHM_VERSION,
                    'error_timestamp': error_timestamp,
                    'python_version': sys.version,
                    'platform': platform.platform()
                },
                'processing_metadata': {
                    'analysis_successful': False,
                    'error_type': type(e).__name__,
                    'error_details': str(e)
                }
            }
    
    def _analyze_temporal_patterns(self, segments: List[Dict], speech_activity_data: Dict) -> Dict:
        """Analyze temporal patterns in speech/non-speech segments."""
        total_duration = speech_activity_data.get('total_duration_seconds', 0)
        speech_duration = speech_activity_data.get('speech_duration_seconds', 0)
        silence_duration = speech_activity_data.get('silence_duration_seconds', 0)
        
        # Calculate ratios
        speech_ratio = speech_duration / total_duration if total_duration > 0 else 0
        non_speech_ratio = silence_duration / total_duration if total_duration > 0 else 0
        
        # Analyze segment patterns
        segment_durations = [seg.get('end', 0) - seg.get('start', 0) for seg in segments]
        
        # Count alternation cycles (speech to silence to speech)
        alternation_cycles = self._count_alternation_cycles(segments)
        
        # Analyze front/back loading of speech
        front_speech_ratio, back_speech_ratio = self._analyze_speech_distribution(segments, total_duration)
        
        return {
            'total_duration': float(total_duration),
            'speech_ratio': float(speech_ratio),
            'non_speech_ratio': float(non_speech_ratio),
            'segment_count': len(segments),
            'average_segment_duration': float(np.mean(segment_durations)) if segment_durations else 0.0,
            'max_segment_duration': float(max(segment_durations)) if segment_durations else 0.0,
            'alternation_cycles': int(alternation_cycles),
            'front_speech_ratio': float(front_speech_ratio),
            'back_speech_ratio': float(back_speech_ratio)
        }
    
    def _count_alternation_cycles(self, segments: List[Dict]) -> int:
        """Count speech-silence-speech cycles indicating instructional patterns."""
        if len(segments) < 3:
            return 0
        
        cycles = 0
        # Simple heuristic: count segments as indication of alternation
        # In real implementation, would analyze pause gaps between segments
        return max(0, len(segments) - 2)  # Each additional segment suggests alternation
    
    def _analyze_speech_distribution(self, segments: List[Dict], total_duration: float) -> Tuple[float, float]:
        """Analyze how speech is distributed across the lesson."""
        if not segments or total_duration <= 0:
            return 0.0, 0.0
        
        front_third = total_duration / 3
        back_third = total_duration * 2 / 3
        
        front_speech_time = 0
        back_speech_time = 0
        
        for segment in segments:
            start_time = segment.get('start', 0)
            end_time = segment.get('end', 0)
            duration = end_time - start_time
            
            # Count speech in front third
            if start_time < front_third:
                overlap = min(end_time, front_third) - start_time
                front_speech_time += max(0, overlap)
            
            # Count speech in back third  
            if end_time > back_third:
                overlap = end_time - max(start_time, back_third)
                back_speech_time += max(0, overlap)
        
        front_ratio = front_speech_time / front_third if front_third > 0 else 0
        back_ratio = back_speech_time / (total_duration - back_third) if total_duration > back_third else 0
        
        return float(front_ratio), float(back_ratio)
    
    def _detect_teaching_patterns(self, temporal_analysis: Dict, text: str) -> List[TeachingPattern]:
        """Detect specific teaching patterns based on analysis."""
        patterns = []
        
        # Test demonstration pattern
        demo_pattern = self._test_demonstration_pattern(temporal_analysis, text)
        if demo_pattern:
            patterns.append(demo_pattern)
        
        # Test instructional pattern
        instruction_pattern = self._test_instructional_pattern(temporal_analysis, text)
        if instruction_pattern:
            patterns.append(instruction_pattern)
        
        # Test overview pattern
        overview_pattern = self._test_overview_pattern(temporal_analysis, text)
        if overview_pattern:
            patterns.append(overview_pattern)
        
        # Test performance pattern
        performance_pattern = self._test_performance_pattern(temporal_analysis, text)
        if performance_pattern:
            patterns.append(performance_pattern)
        
        # Sort by confidence
        return sorted(patterns, key=lambda x: x.confidence, reverse=True)
    
    def _test_demonstration_pattern(self, temporal_analysis: Dict, text: str) -> Optional[TeachingPattern]:
        """Test for demonstration pattern: High non-speech ratio with minimal talking."""
        non_speech_ratio = temporal_analysis.get('non_speech_ratio', 0)
        max_segment_duration = temporal_analysis.get('max_segment_duration', 0)
        
        thresholds = self.PATTERN_THRESHOLDS['demonstration']
        score = 0.0
        evidence = {}
        
        # Check non-speech ratio
        if non_speech_ratio >= thresholds['non_speech_ratio_min']:
            score += 0.6
            evidence['non_speech_ratio'] = non_speech_ratio
        
        # Check for short speech segments
        if max_segment_duration <= thresholds['max_speech_duration']:
            score += 0.3
            evidence['max_segment_duration'] = max_segment_duration
        
        # Look for demonstration keywords
        demo_keywords = ['like this', 'sounds like', 'here\'s how', 'listen', 'hear']
        demo_count = sum(1 for keyword in demo_keywords if keyword in text.lower())
        if demo_count > 0:
            score += 0.1
            evidence['demo_keywords'] = demo_count
        
        if score >= 0.6:
            return TeachingPattern(
                pattern_type='demonstration',
                confidence=min(1.0, score),
                description=f"High demonstration content ({non_speech_ratio:.1%} playing) with minimal verbal instruction",
                evidence=evidence,
                characteristics={
                    'non_speech_ratio': float(non_speech_ratio),
                    'demonstration_focus': float(score)
                }
            )
        
        return None
    
    def _test_instructional_pattern(self, temporal_analysis: Dict, text: str) -> Optional[TeachingPattern]:
        """Test for instructional pattern: Balanced speech/non-speech with alternation."""
        speech_ratio = temporal_analysis.get('speech_ratio', 0)
        alternation_cycles = temporal_analysis.get('alternation_cycles', 0)
        
        thresholds = self.PATTERN_THRESHOLDS['instructional']
        score = 0.0
        evidence = {}
        
        # Check speech ratio in instructional range
        if thresholds['speech_ratio_min'] <= speech_ratio <= thresholds['speech_ratio_max']:
            score += 0.5
            evidence['speech_ratio'] = speech_ratio
        
        # Check alternation cycles
        if alternation_cycles >= thresholds['alternation_cycles_min']:
            score += 0.3
            evidence['alternation_cycles'] = alternation_cycles
        
        # Look for instructional keywords
        instruction_keywords = ['play', 'try', 'practice', 'learn', 'technique', 'watch', 'notice']
        instruction_count = sum(1 for keyword in instruction_keywords if keyword in text.lower())
        if instruction_count > 0:
            score += 0.2
            evidence['instruction_keywords'] = instruction_count
        
        if score >= 0.6:
            return TeachingPattern(
                pattern_type='instructional',
                confidence=min(1.0, score),
                description=f"Balanced instructional content ({speech_ratio:.1%} speech) with {alternation_cycles} teaching cycles",
                evidence=evidence,
                characteristics={
                    'speech_ratio': float(speech_ratio),
                    'alternation_cycles': int(alternation_cycles),
                    'instructional_balance': float(score)
                }
            )
        
        return None
    
    def _test_overview_pattern(self, temporal_analysis: Dict, text: str) -> Optional[TeachingPattern]:
        """Test for overview pattern: Speech-heavy at beginning and end."""
        speech_ratio = temporal_analysis.get('speech_ratio', 0)
        front_speech_ratio = temporal_analysis.get('front_speech_ratio', 0)
        back_speech_ratio = temporal_analysis.get('back_speech_ratio', 0)
        
        thresholds = self.PATTERN_THRESHOLDS['overview']
        score = 0.0
        evidence = {}
        
        # Check overall speech ratio
        if speech_ratio >= thresholds['speech_ratio_min']:
            score += 0.3
            evidence['speech_ratio'] = speech_ratio
        
        # Check front loading
        if front_speech_ratio >= thresholds['front_speech_ratio']:
            score += 0.35
            evidence['front_speech_ratio'] = front_speech_ratio
        
        # Check back loading
        if back_speech_ratio >= thresholds['back_speech_ratio']:
            score += 0.35
            evidence['back_speech_ratio'] = back_speech_ratio
        
        if score >= 0.6:
            return TeachingPattern(
                pattern_type='overview',
                confidence=min(1.0, score),
                description=f"Overview pattern with speech-heavy introduction ({front_speech_ratio:.1%}) and conclusion ({back_speech_ratio:.1%})",
                evidence=evidence,
                characteristics={
                    'speech_ratio': float(speech_ratio),
                    'front_loading': float(front_speech_ratio),
                    'back_loading': float(back_speech_ratio)
                }
            )
        
        return None
    
    def _test_performance_pattern(self, temporal_analysis: Dict, text: str) -> Optional[TeachingPattern]:
        """Test for performance pattern: Very high non-speech ratio."""
        non_speech_ratio = temporal_analysis.get('non_speech_ratio', 0)
        segment_count = temporal_analysis.get('segment_count', 0)
        
        thresholds = self.PATTERN_THRESHOLDS['performance']
        score = 0.0
        evidence = {}
        
        # Check very high non-speech ratio
        if non_speech_ratio >= thresholds['non_speech_ratio_min']:
            score += 0.7
            evidence['non_speech_ratio'] = non_speech_ratio
        
        # Check for few speech segments
        if segment_count <= thresholds['speech_segments_max']:
            score += 0.2
            evidence['segment_count'] = segment_count
        
        # Look for performance keywords
        performance_keywords = ['song', 'piece', 'performance', 'play through', 'full version']
        performance_count = sum(1 for keyword in performance_keywords if keyword in text.lower())
        if performance_count > 0:
            score += 0.1
            evidence['performance_keywords'] = performance_count
        
        if score >= 0.7:  # Higher threshold for performance
            return TeachingPattern(
                pattern_type='performance',
                confidence=min(1.0, score),
                description=f"Performance-focused content ({non_speech_ratio:.1%} playing) with minimal verbal content",
                evidence=evidence,
                characteristics={
                    'non_speech_ratio': float(non_speech_ratio),
                    'performance_focus': float(score)
                }
            )
        
        return None
    
    def _classify_content_type(self, patterns: List[TeachingPattern], text: str, temporal_analysis: Dict) -> Dict:
        """Classify the overall content type."""
        if not patterns:
            return {
                'primary_type': 'unknown',
                'confidence': 0.0,
                'description': 'No clear teaching pattern detected'
            }
        
        # Primary type is highest confidence pattern
        primary_pattern = patterns[0]
        
        # Additional classification based on content
        content_indicators = {
            'technique_focused': ['technique', 'fingerpicking', 'strumming', 'picking'],
            'theory_focused': ['chord', 'scale', 'theory', 'progression'],
            'song_focused': ['song', 'piece', 'version', 'cover'],
            'beginner_focused': ['beginner', 'basic', 'first', 'start', 'learn']
        }
        
        content_scores = {}
        for content_type, keywords in content_indicators.items():
            count = sum(1 for keyword in keywords if keyword in text.lower())
            content_scores[content_type] = count
        
        primary_content = max(content_scores.items(), key=lambda x: x[1]) if content_scores else ('general', 0)
        
        return {
            'primary_type': primary_pattern.pattern_type,
            'confidence': primary_pattern.confidence,
            'description': primary_pattern.description,
            'content_focus': primary_content[0] if primary_content[1] > 0 else 'general',
            'secondary_patterns': [p.pattern_type for p in patterns[1:]] if len(patterns) > 1 else []
        }
    
    def _generate_summary(self, patterns: List[TeachingPattern], content_classification: Dict) -> Dict:
        """Generate a human-readable summary."""
        if not patterns:
            return {
                'teaching_style': 'Undefined',
                'content_type': 'General lesson',
                'effectiveness_notes': ['Consider establishing clearer speech/demonstration patterns'],
                'recommendations': ['Add more structured alternation between explanation and demonstration']
            }
        
        primary_pattern = patterns[0]
        content_focus = content_classification.get('content_focus', 'general')
        
        # Generate teaching style description
        style_descriptions = {
            'demonstration': 'Demonstration-heavy teaching style with extensive playing examples',
            'instructional': 'Balanced instructional approach with clear explanation-demonstration cycles',
            'overview': 'Overview-style lesson with comprehensive introduction and summary',
            'performance': 'Performance-focused content with minimal verbal instruction'
        }
        
        teaching_style = style_descriptions.get(primary_pattern.pattern_type, 'Unknown teaching style')
        
        # Generate recommendations based on pattern
        recommendations = self._get_recommendations(primary_pattern.pattern_type, primary_pattern.confidence)
        
        return {
            'teaching_style': teaching_style,
            'content_type': f'{content_focus.replace("_", " ").title()} lesson',
            'confidence': float(primary_pattern.confidence),
            'pattern_strength': 'Strong' if primary_pattern.confidence > 0.8 else 'Moderate' if primary_pattern.confidence > 0.6 else 'Weak',
            'effectiveness_notes': self._get_effectiveness_notes(primary_pattern, content_classification),
            'recommendations': recommendations
        }
    
    def _get_recommendations(self, pattern_type: str, confidence: float) -> List[str]:
        """Get recommendations based on detected pattern."""
        recommendations = []
        
        if confidence < 0.7:
            recommendations.append("Consider establishing a more consistent teaching pattern")
        
        if pattern_type == 'demonstration':
            recommendations.extend([
                "Add brief verbal explanations before playing examples",
                "Include summary statements after demonstrations"
            ])
        elif pattern_type == 'instructional':
            recommendations.extend([
                "Excellent balance of instruction and demonstration",
                "Consider adding practice segments for student engagement"
            ])
        elif pattern_type == 'overview':
            recommendations.extend([
                "Good lesson structure with clear introduction and conclusion",
                "Consider adding more interactive elements in the middle section"
            ])
        elif pattern_type == 'performance':
            recommendations.extend([
                "Add brief introductions to explain what students will learn",
                "Consider breaking long performances into teachable segments"
            ])
        
        return recommendations
    
    def _get_effectiveness_notes(self, primary_pattern: TeachingPattern, content_classification: Dict) -> List[str]:
        """Get effectiveness notes based on analysis."""
        notes = []
        
        speech_ratio = primary_pattern.characteristics.get('speech_ratio', 0)
        non_speech_ratio = primary_pattern.characteristics.get('non_speech_ratio', 0)
        
        if speech_ratio > 0.7:
            notes.append("High verbal content - good for explanation-heavy topics")
        elif speech_ratio < 0.3:
            notes.append("Low verbal content - relies heavily on demonstration")
        else:
            notes.append("Balanced verbal and demonstration content")
        
        if non_speech_ratio > 0.8:
            notes.append("Extensive playing time - good for ear training")
        
        confidence = primary_pattern.confidence
        if confidence > 0.8:
            notes.append("Strong, consistent teaching pattern")
        elif confidence < 0.6:
            notes.append("Inconsistent teaching pattern - consider more structure")
        
        return notes
    
    def _pattern_to_dict(self, pattern: TeachingPattern) -> Dict:
        """Convert TeachingPattern to dictionary with JSON-safe values."""
        return {
            'pattern_type': pattern.pattern_type,
            'confidence': float(pattern.confidence),
            'description': pattern.description,
            'evidence': self._ensure_json_serializable(pattern.evidence),
            'characteristics': self._ensure_json_serializable(pattern.characteristics)
        }
    
    def _ensure_json_serializable(self, obj: Any) -> Any:
        """Ensure all values in an object are JSON serializable."""
        if isinstance(obj, dict):
            return {key: self._ensure_json_serializable(value) for key, value in obj.items()}
        elif isinstance(obj, list):
            return [self._ensure_json_serializable(item) for item in obj]
        elif isinstance(obj, (np.integer, np.int32, np.int64)):
            return int(obj)
        elif isinstance(obj, (np.floating, np.float32, np.float64)):
            return float(obj)
        elif isinstance(obj, np.ndarray):
            return obj.tolist()
        else:
            return obj
    
    def _get_content_keywords(self) -> Dict[str, List[str]]:
        """
        Get the content keywords used for classification.
        
        This method returns the exact keyword lists used during analysis,
        ensuring they can be stored for reprocessing with the same parameters.
        """
        return {
            'technique_focused': ['technique', 'fingerpicking', 'strumming', 'picking'],
            'theory_focused': ['chord', 'scale', 'theory', 'progression'],
            'song_focused': ['song', 'piece', 'version', 'cover'],
            'beginner_focused': ['beginner', 'basic', 'first', 'start', 'learn'],
            'demonstration_keywords': ['like this', 'sounds like', 'here\'s how', 'listen', 'hear'],
            'instruction_keywords': ['play', 'try', 'practice', 'learn', 'technique', 'watch', 'notice'],
            'performance_keywords': ['song', 'piece', 'performance', 'play through', 'full version']
        } 