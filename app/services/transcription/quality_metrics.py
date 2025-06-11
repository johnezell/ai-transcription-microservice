"""
Advanced Quality Assurance Metrics for Transcription Analysis

This module provides comprehensive quality metrics beyond basic confidence scoring,
including speech activity detection, content analysis, temporal patterns, and audio quality indicators.
"""

import numpy as np
import librosa
from typing import Dict, List, Tuple, Optional
import re
from datetime import timedelta
import logging

# Import the new teaching pattern analyzer
from teaching_pattern_analyzer import TeachingPatternAnalyzer

logger = logging.getLogger(__name__)

class AdvancedQualityAnalyzer:
    """Advanced quality metrics analyzer for transcription results."""
    
    def __init__(self):
        self.filler_words = {
            'um', 'uh', 'ah', 'er', 'hmm', 'well', 'like', 'you know', 
            'actually', 'basically', 'literally', 'sort of', 'kind of'
        }
        
        self.technical_indicators = {
            'music_terms': ['chord', 'scale', 'fret', 'string', 'pick', 'strum', 'finger', 'note'],
            'instruction_words': ['play', 'practice', 'try', 'listen', 'watch', 'remember', 'notice'],
            'quality_indicators': ['clear', 'clean', 'smooth', 'precise', 'accurate', 'careful']
        }
        
        # Initialize teaching pattern analyzer
        self.teaching_pattern_analyzer = TeachingPatternAnalyzer()
    
    def analyze_comprehensive_quality(self, transcription_result: Dict, audio_path: str = None) -> Dict:
        """Perform comprehensive quality analysis on transcription results."""
        
        quality_metrics = {
            'speech_activity': self.analyze_speech_activity(transcription_result),
            'content_quality': self.analyze_content_quality(transcription_result),
            'temporal_patterns': self.analyze_temporal_patterns(transcription_result),
            'confidence_patterns': self.analyze_confidence_patterns(transcription_result),
            'linguistic_quality': self.analyze_linguistic_quality(transcription_result),
            'model_performance': self.analyze_model_performance(transcription_result)
        }
        
        # Add teaching pattern analysis for educational content
        try:
            speech_activity_data = quality_metrics['speech_activity']
            if 'error' not in speech_activity_data:
                teaching_patterns = self.teaching_pattern_analyzer.analyze_teaching_patterns(
                    transcription_result, speech_activity_data
                )
                quality_metrics['teaching_patterns'] = teaching_patterns
            else:
                quality_metrics['teaching_patterns'] = {'error': 'Speech activity analysis failed - cannot analyze teaching patterns'}
        except Exception as e:
            logger.warning(f"Teaching pattern analysis failed: {e}")
            quality_metrics['teaching_patterns'] = {'error': f'Teaching pattern analysis failed: {str(e)}'}
        
        # Add audio-based metrics if audio path provided
        if audio_path:
            try:
                quality_metrics['audio_quality'] = self.analyze_audio_quality(audio_path)
            except Exception as e:
                logger.warning(f"Audio quality analysis failed: {e}")
                quality_metrics['audio_quality'] = {'error': str(e)}
        
        # Calculate overall quality score
        quality_metrics['overall_quality_score'] = self.calculate_overall_quality_score(quality_metrics)
        
        return quality_metrics
    
    def analyze_speech_activity(self, result: Dict) -> Dict:
        """Analyze speech activity patterns and time coverage."""
        segments = result.get('segments', [])
        word_segments = result.get('word_segments', [])
        
        if not segments:
            return {'error': 'No segments available for speech activity analysis'}
        
        # Calculate total duration and speech duration
        total_duration = max(seg.get('end', 0) for seg in segments) if segments else 0
        speech_duration = sum(seg.get('end', 0) - seg.get('start', 0) for seg in segments)
        
        # Calculate pause patterns
        pauses = []
        for i in range(len(segments) - 1):
            pause_start = segments[i].get('end', 0)
            pause_end = segments[i + 1].get('start', 0)
            if pause_end > pause_start:
                pauses.append(pause_end - pause_start)
        
        # Word density analysis
        word_time_coverage = 0
        if word_segments:
            word_time_coverage = sum(word.get('end', 0) - word.get('start', 0) for word in word_segments)
        
        # Speaking rate analysis
        total_words = len(word_segments) if word_segments else sum(len(seg.get('text', '').split()) for seg in segments)
        speaking_rate = (total_words / (speech_duration / 60)) if speech_duration > 0 else 0  # words per minute
        
        return {
            'total_duration_seconds': total_duration,
            'speech_duration_seconds': speech_duration,
            'silence_duration_seconds': total_duration - speech_duration,
            'speech_activity_ratio': speech_duration / total_duration if total_duration > 0 else 0,
            'word_time_coverage_seconds': word_time_coverage,
            'word_coverage_ratio': word_time_coverage / total_duration if total_duration > 0 else 0,
            'pause_count': len(pauses),
            'average_pause_duration': float(np.mean(pauses)) if pauses else 0.0,
            'max_pause_duration': max(pauses) if pauses else 0,
            'speaking_rate_wpm': speaking_rate,
            'segment_count': len(segments),
            'average_segment_duration': speech_duration / len(segments) if segments else 0
        }
    
    def analyze_content_quality(self, result: Dict) -> Dict:
        """Analyze content quality and coherence."""
        text = result.get('text', '')
        segments = result.get('segments', [])
        word_segments = result.get('word_segments', [])
        
        if not text:
            return {'error': 'No text available for content analysis'}
        
        # Basic text statistics
        words = text.split()
        sentences = re.split(r'[.!?]+', text)
        sentences = [s.strip() for s in sentences if s.strip()]
        
        # Vocabulary analysis
        unique_words = set(word.lower().strip('.,!?;:') for word in words)
        vocabulary_richness = len(unique_words) / len(words) if words else 0
        
        # Filler word analysis
        filler_count = sum(1 for word in words if word.lower().strip('.,!?;:') in self.filler_words)
        filler_ratio = filler_count / len(words) if words else 0
        
        # Technical content analysis
        technical_score = self.calculate_technical_content_score(text)
        
        # Repetition analysis
        word_repetitions = self.analyze_word_repetitions(words)
        
        # Sentence structure analysis
        avg_sentence_length = float(np.mean([len(s.split()) for s in sentences])) if sentences else 0.0
        
        return {
            'total_words': len(words),
            'unique_words': len(unique_words),
            'vocabulary_richness': vocabulary_richness,
            'sentence_count': len(sentences),
            'average_sentence_length': avg_sentence_length,
            'filler_word_count': filler_count,
            'filler_word_ratio': filler_ratio,
            'technical_content_score': technical_score,
            'word_repetition_score': word_repetitions['repetition_score'],
            'repeated_phrases': word_repetitions['repeated_phrases'],
            'text_coherence_score': self.calculate_text_coherence(segments)
        }
    
    def analyze_temporal_patterns(self, result: Dict) -> Dict:
        """Analyze temporal patterns and timing consistency."""
        segments = result.get('segments', [])
        word_segments = result.get('word_segments', [])
        
        if not segments:
            return {'error': 'No segments available for temporal analysis'}
        
        # Segment timing analysis
        segment_durations = [seg.get('end', 0) - seg.get('start', 0) for seg in segments]
        
        # Word timing analysis
        word_durations = []
        word_gaps = []
        
        if word_segments:
            word_durations = [word.get('end', 0) - word.get('start', 0) for word in word_segments]
            
            # Calculate gaps between words
            for i in range(len(word_segments) - 1):
                gap = word_segments[i + 1].get('start', 0) - word_segments[i].get('end', 0)
                if gap >= 0:  # Only positive gaps
                    word_gaps.append(gap)
        
        # Timing consistency analysis
        timing_consistency = self.calculate_timing_consistency(segments, word_segments)
        
        return {
            'segment_duration_stats': {
                'mean': float(np.mean(segment_durations)) if segment_durations else 0.0,
                'std': float(np.std(segment_durations)) if segment_durations else 0.0,
                'min': float(min(segment_durations)) if segment_durations else 0.0,
                'max': float(max(segment_durations)) if segment_durations else 0.0
            },
            'word_duration_stats': {
                'mean': float(np.mean(word_durations)) if word_durations else 0.0,
                'std': float(np.std(word_durations)) if word_durations else 0.0,
                'min': float(min(word_durations)) if word_durations else 0.0,
                'max': float(max(word_durations)) if word_durations else 0.0
            },
            'word_gap_stats': {
                'mean': float(np.mean(word_gaps)) if word_gaps else 0.0,
                'std': float(np.std(word_gaps)) if word_gaps else 0.0,
                'count': len(word_gaps)
            },
            'timing_consistency_score': timing_consistency,
            'unusual_timing_events': self.detect_unusual_timing_events(segments, word_segments)
        }
    
    def analyze_confidence_patterns(self, result: Dict) -> Dict:
        """Analyze confidence score patterns and distributions."""
        segments = result.get('segments', [])
        word_segments = result.get('word_segments', [])
        overall_confidence = result.get('confidence_score', 0)
        
        # Segment confidence analysis
        segment_confidences = [seg.get('confidence', 0) for seg in segments if 'confidence' in seg]
        
        # Word confidence analysis
        word_confidences = [word.get('score', 0) for word in word_segments if 'score' in word]
        
        confidence_analysis = {
            'overall_confidence': overall_confidence,
            'segment_confidence_stats': self.calculate_confidence_stats(segment_confidences),
            'word_confidence_stats': self.calculate_confidence_stats(word_confidences),
            'confidence_distribution': self.analyze_confidence_distribution(word_confidences),
            'low_confidence_clusters': self.find_low_confidence_clusters(word_segments),
            'confidence_trend': self.analyze_confidence_trend(word_segments)
        }
        
        return confidence_analysis
    
    def analyze_linguistic_quality(self, result: Dict) -> Dict:
        """Analyze linguistic quality and naturalness."""
        text = result.get('text', '')
        
        if not text:
            return {'error': 'No text available for linguistic analysis'}
        
        # Grammar and structure analysis
        grammar_score = self.calculate_grammar_score(text)
        
        # Natural speech patterns
        natural_speech_score = self.calculate_natural_speech_score(text)
        
        # Educational content quality (for guitar lessons)
        educational_quality = self.calculate_educational_quality_score(text)
        
        return {
            'grammar_quality_score': grammar_score,
            'natural_speech_score': natural_speech_score,
            'educational_content_score': educational_quality,
            'readability_score': self.calculate_readability_score(text),
            'terminology_accuracy': self.analyze_terminology_accuracy(text)
        }
    
    def analyze_audio_quality(self, audio_path: str) -> Dict:
        """Analyze audio quality characteristics."""
        try:
            y, sr = librosa.load(audio_path, sr=16000)
            
            # Signal quality metrics
            rms_energy = librosa.feature.rms(y=y)[0]
            spectral_centroid = librosa.feature.spectral_centroid(y=y, sr=sr)[0]
            zero_crossing_rate = librosa.feature.zero_crossing_rate(y)[0]
            spectral_rolloff = librosa.feature.spectral_rolloff(y=y, sr=sr)[0]
            
            # Audio quality indicators
            signal_to_noise_ratio = self.estimate_snr(y)
            dynamic_range = float(np.max(rms_energy) - np.min(rms_energy))
            audio_consistency = 1.0 - float(np.std(rms_energy) / np.mean(rms_energy))
            
            return {
                'signal_to_noise_ratio': float(signal_to_noise_ratio),
                'dynamic_range': dynamic_range,
                'audio_consistency_score': float(max(0, min(1, audio_consistency))),
                'average_energy': float(np.mean(rms_energy)),
                'energy_variance': float(np.var(rms_energy)),
                'spectral_complexity': float(np.std(spectral_centroid)),
                'voice_activity_estimation': float(1.0 - np.mean(zero_crossing_rate < 0.1)),
                'frequency_balance': float(np.mean(spectral_rolloff) / (sr / 2))  # Normalized
            }
            
        except Exception as e:
            logger.error(f"Audio quality analysis failed: {e}")
            return {'error': str(e)}
    
    def analyze_model_performance(self, result: Dict) -> Dict:
        """Analyze model-specific performance indicators."""
        processing_times = result.get('whisperx_processing', {}).get('processing_times', {})
        settings = result.get('settings', {})
        model_metadata = result.get('model_metadata', {})
        
        # Processing efficiency
        transcription_time = processing_times.get('transcription_seconds', 0)
        alignment_time = processing_times.get('alignment_seconds', 0)
        total_time = processing_times.get('total_seconds', 0)
        
        # Model utilization
        model_name = settings.get('model_name', 'unknown')
        batch_size = settings.get('batch_size', 1)
        device = model_metadata.get('device', 'unknown')
        memory_usage = model_metadata.get('memory_usage_mb', 0)
        
        return {
            'model_name': model_name,
            'processing_efficiency': {
                'transcription_time': transcription_time,
                'alignment_time': alignment_time,
                'total_time': total_time,
                'time_per_second_audio': total_time / max(1, self.get_audio_duration(result)),
                'alignment_to_transcription_ratio': alignment_time / max(0.1, transcription_time)
            },
            'resource_utilization': {
                'device': device,
                'batch_size': batch_size,
                'memory_usage_mb': memory_usage,
                'gpu_utilization': device == 'cuda'
            },
            'model_reliability_indicators': self.calculate_model_reliability_indicators(result)
        }
    
    # Helper methods
    
    def calculate_technical_content_score(self, text: str) -> float:
        """Calculate score based on technical content relevance."""
        words = text.lower().split()
        technical_matches = 0
        
        for category, terms in self.technical_indicators.items():
            for term in terms:
                technical_matches += text.lower().count(term)
        
        return min(1.0, technical_matches / max(1, len(words) * 0.1))
    
    def analyze_word_repetitions(self, words: List[str]) -> Dict:
        """Analyze word repetition patterns."""
        word_counts = {}
        for word in words:
            clean_word = word.lower().strip('.,!?;:')
            word_counts[clean_word] = word_counts.get(clean_word, 0) + 1
        
        # Find repeated phrases (2-3 word sequences)
        repeated_phrases = self.find_repeated_phrases(words)
        
        # Calculate repetition score (lower is better)
        total_repetitions = sum(count - 1 for count in word_counts.values() if count > 1)
        repetition_score = 1.0 - min(1.0, total_repetitions / max(1, len(words)))
        
        return {
            'repetition_score': repetition_score,
            'repeated_phrases': repeated_phrases,
            'most_frequent_words': sorted(word_counts.items(), key=lambda x: x[1], reverse=True)[:10]
        }
    
    def calculate_timing_consistency(self, segments: List[Dict], word_segments: List[Dict]) -> float:
        """Calculate timing consistency score."""
        if not segments or not word_segments:
            return 0.0
        
        # Check for timing anomalies
        anomalies = 0
        total_checks = 0
        
        # Check segment timing consistency
        for segment in segments:
            start, end = segment.get('start', 0), segment.get('end', 0)
            if end <= start:
                anomalies += 1
            total_checks += 1
        
        # Check word timing consistency
        for i, word in enumerate(word_segments[:-1]):
            current_end = word.get('end', 0)
            next_start = word_segments[i + 1].get('start', 0)
            
            # Check for reasonable gaps (not too negative or too large)
            gap = next_start - current_end
            if gap < -0.1 or gap > 5.0:  # Overlaps > 100ms or gaps > 5s are unusual
                anomalies += 1
            total_checks += 1
        
        consistency_score = 1.0 - (anomalies / max(1, total_checks))
        return max(0.0, consistency_score)
    
    def calculate_confidence_stats(self, confidences: List[float]) -> Dict:
        """Calculate confidence statistics."""
        if not confidences:
            return {'error': 'No confidence scores available'}
        
        return {
            'mean': float(np.mean(confidences)),
            'std': float(np.std(confidences)),
            'min': float(min(confidences)),
            'max': float(max(confidences)),
            'median': float(np.median(confidences)),
            'count': len(confidences)
        }
    
    def analyze_confidence_distribution(self, confidences: List[float]) -> Dict:
        """Analyze confidence score distribution."""
        if not confidences:
            return {'error': 'No confidence scores available'}
        
        # Define confidence buckets
        excellent = sum(1 for c in confidences if c >= 0.9)
        good = sum(1 for c in confidences if 0.8 <= c < 0.9)
        fair = sum(1 for c in confidences if 0.7 <= c < 0.8)
        poor = sum(1 for c in confidences if c < 0.7)
        
        total = len(confidences)
        
        return {
            'excellent_ratio': excellent / total,
            'good_ratio': good / total,
            'fair_ratio': fair / total,
            'poor_ratio': poor / total,
            'distribution_counts': {
                'excellent': excellent,
                'good': good,
                'fair': fair,
                'poor': poor
            }
        }
    
    def find_low_confidence_clusters(self, word_segments: List[Dict]) -> List[Dict]:
        """Find clusters of consecutive low-confidence words."""
        if not word_segments:
            return []
        
        clusters = []
        current_cluster = []
        
        for word in word_segments:
            confidence = word.get('score', 1.0)
            
            if confidence < 0.7:  # Low confidence threshold
                current_cluster.append(word)
            else:
                if len(current_cluster) >= 3:  # Cluster of 3+ low confidence words
                    clusters.append({
                        'start_time': float(current_cluster[0].get('start', 0)),
                        'end_time': float(current_cluster[-1].get('end', 0)),
                        'word_count': len(current_cluster),
                        'avg_confidence': float(np.mean([w.get('score', 0) for w in current_cluster])),
                        'words': [w.get('word', '') for w in current_cluster]
                    })
                current_cluster = []
        
        # Check final cluster
        if len(current_cluster) >= 3:
            clusters.append({
                'start_time': float(current_cluster[0].get('start', 0)),
                'end_time': float(current_cluster[-1].get('end', 0)),
                'word_count': len(current_cluster),
                'avg_confidence': float(np.mean([w.get('score', 0) for w in current_cluster])),
                'words': [w.get('word', '') for w in current_cluster]
            })
        
        return clusters
    
    def estimate_snr(self, audio: np.ndarray) -> float:
        """Estimate signal-to-noise ratio."""
        # Simple SNR estimation based on energy distribution
        energy = audio ** 2
        energy_sorted = np.sort(energy)
        
        # Assume bottom 10% is noise, top 10% is signal
        noise_level = float(np.mean(energy_sorted[:int(len(energy_sorted) * 0.1)]))
        signal_level = float(np.mean(energy_sorted[int(len(energy_sorted) * 0.9):]))
        
        if noise_level > 0:
            snr_db = float(10 * np.log10(signal_level / noise_level))
            return float(max(0, min(60, snr_db)))  # Clamp between 0-60 dB
        
        return 30.0  # Default reasonable SNR
    
    def calculate_overall_quality_score(self, quality_metrics: Dict) -> float:
        """Calculate overall quality score from all metrics."""
        weights = {
            'speech_activity': 0.15,
            'content_quality': 0.25,
            'confidence_patterns': 0.30,
            'temporal_patterns': 0.15,
            'linguistic_quality': 0.10,
            'audio_quality': 0.05
        }
        
        overall_score = 0.0
        total_weight = 0.0
        
        for category, weight in weights.items():
            if category in quality_metrics and 'error' not in quality_metrics[category]:
                category_score = self.extract_category_score(quality_metrics[category], category)
                overall_score += category_score * weight
                total_weight += weight
        
        return overall_score / total_weight if total_weight > 0 else 0.0
    
    def extract_category_score(self, category_data: Dict, category: str) -> float:
        """Extract a normalized score for each category."""
        if category == 'speech_activity':
            return min(1.0, category_data.get('speech_activity_ratio', 0) * 1.2)
        elif category == 'confidence_patterns':
            return category_data.get('overall_confidence', 0)
        elif category == 'content_quality':
            vocab_score = min(1.0, category_data.get('vocabulary_richness', 0) * 2)
            filler_penalty = 1.0 - category_data.get('filler_word_ratio', 0)
            return (vocab_score + filler_penalty) / 2
        elif category == 'temporal_patterns':
            return category_data.get('timing_consistency_score', 0)
        elif category == 'linguistic_quality':
            return (category_data.get('grammar_quality_score', 0) + 
                   category_data.get('natural_speech_score', 0)) / 2
        elif category == 'audio_quality':
            return category_data.get('audio_consistency_score', 0.5)
        
        return 0.5  # Default neutral score
    
    # Additional helper methods would be implemented here...
    def calculate_grammar_score(self, text: str) -> float:
        """Simple grammar quality assessment."""
        # Basic grammar indicators
        sentences = re.split(r'[.!?]+', text)
        sentences = [s.strip() for s in sentences if s.strip()]
        
        if not sentences:
            return 0.0
        
        # Check for basic sentence structure
        complete_sentences = 0
        for sentence in sentences:
            words = sentence.split()
            if len(words) >= 3:  # Minimum reasonable sentence length
                complete_sentences += 1
        
        return complete_sentences / len(sentences) if sentences else 0.0
    
    def calculate_natural_speech_score(self, text: str) -> float:
        """Assess naturalness of speech patterns."""
        # Look for natural speech indicators
        natural_indicators = ['and', 'but', 'so', 'well', 'now', 'then', 'also']
        words = text.lower().split()
        
        if not words:
            return 0.0
        
        natural_word_count = sum(1 for word in words if word.strip('.,!?;:') in natural_indicators)
        return min(1.0, natural_word_count / len(words) * 10)  # Normalize
    
    def calculate_educational_quality_score(self, text: str) -> float:
        """Assess educational content quality for guitar lessons."""
        educational_terms = sum(1 for term in self.technical_indicators['music_terms'] 
                              if term in text.lower())
        instruction_terms = sum(1 for term in self.technical_indicators['instruction_words']
                              if term in text.lower())
        
        words = text.split()
        if not words:
            return 0.0
        
        educational_density = (educational_terms + instruction_terms) / len(words)
        return min(1.0, educational_density * 20)  # Normalize for guitar lesson content
    
    def calculate_readability_score(self, text: str) -> float:
        """Simple readability assessment."""
        words = text.split()
        sentences = re.split(r'[.!?]+', text)
        sentences = [s.strip() for s in sentences if s.strip()]
        
        if not sentences or not words:
            return 0.0
        
        avg_sentence_length = len(words) / len(sentences)
        # Optimal sentence length for spoken content: 10-20 words
        if 10 <= avg_sentence_length <= 20:
            return 1.0
        elif avg_sentence_length < 5 or avg_sentence_length > 30:
            return 0.3
        else:
            return 0.7
    
    def analyze_terminology_accuracy(self, text: str) -> Dict:
        """Analyze accuracy of technical terminology usage."""
        music_terms = sum(1 for term in self.technical_indicators['music_terms'] 
                         if term in text.lower())
        total_words = len(text.split())
        
        return {
            'music_term_count': music_terms,
            'music_term_density': music_terms / max(1, total_words),
            'terminology_diversity': len(set(term for term in self.technical_indicators['music_terms'] 
                                           if term in text.lower()))
        }
    
    def get_audio_duration(self, result: Dict) -> float:
        """Extract audio duration from transcription result."""
        segments = result.get('segments', [])
        if segments:
            return max(seg.get('end', 0) for seg in segments)
        return 1.0  # Default to avoid division by zero
    
    def find_repeated_phrases(self, words: List[str]) -> List[Dict]:
        """Find repeated 2-3 word phrases."""
        phrase_counts = {}
        
        # Check 2-word phrases
        for i in range(len(words) - 1):
            phrase = ' '.join(words[i:i+2]).lower().strip('.,!?;:')
            phrase_counts[phrase] = phrase_counts.get(phrase, 0) + 1
        
        # Check 3-word phrases  
        for i in range(len(words) - 2):
            phrase = ' '.join(words[i:i+3]).lower().strip('.,!?;:')
            phrase_counts[phrase] = phrase_counts.get(phrase, 0) + 1
        
        # Return phrases that appear more than once
        repeated = [{'phrase': phrase, 'count': count} 
                   for phrase, count in phrase_counts.items() 
                   if count > 1 and len(phrase) > 3]
        
        return sorted(repeated, key=lambda x: x['count'], reverse=True)[:10]
    
    def calculate_text_coherence(self, segments: List[Dict]) -> float:
        """Calculate text coherence across segments."""
        if len(segments) < 2:
            return 1.0
        
        # Simple coherence check based on segment transitions
        coherent_transitions = 0
        total_transitions = len(segments) - 1
        
        for i in range(len(segments) - 1):
            current_text = segments[i].get('text', '').strip().lower()
            next_text = segments[i + 1].get('text', '').strip().lower()
            
            # Check for natural transitions (simple heuristic)
            current_words = set(current_text.split()[-3:])  # Last 3 words
            next_words = set(next_text.split()[:3])         # First 3 words
            
            # Look for word overlap or natural connectors
            if (current_words & next_words or 
                any(word in next_text[:20] for word in ['and', 'so', 'now', 'then', 'but', 'also'])):
                coherent_transitions += 1
        
        return coherent_transitions / total_transitions if total_transitions > 0 else 1.0
    
    def analyze_confidence_trend(self, word_segments: List[Dict]) -> Dict:
        """Analyze confidence trends over time."""
        if not word_segments:
            return {'error': 'No word segments available'}
        
        confidences = [word.get('score', 0) for word in word_segments]
        times = [word.get('start', 0) for word in word_segments]
        
        if len(confidences) < 5:
            return {'trend': 'insufficient_data'}
        
        # Simple trend analysis
        first_half_avg = float(np.mean(confidences[:len(confidences)//2]))
        second_half_avg = float(np.mean(confidences[len(confidences)//2:]))
        
        trend_direction = 'improving' if second_half_avg > first_half_avg else 'declining'
        trend_magnitude = abs(second_half_avg - first_half_avg)
        
        return {
            'trend': trend_direction,
            'magnitude': trend_magnitude,
            'first_half_confidence': first_half_avg,
            'second_half_confidence': second_half_avg,
            'confidence_variance': np.var(confidences)
        }
    
    def detect_unusual_timing_events(self, segments: List[Dict], word_segments: List[Dict]) -> List[Dict]:
        """Detect unusual timing events that might indicate quality issues."""
        events = []
        
        # Detect very short segments
        for i, segment in enumerate(segments):
            duration = segment.get('end', 0) - segment.get('start', 0)
            if duration < 0.5:  # Less than 500ms
                events.append({
                    'type': 'very_short_segment',
                    'segment_index': i,
                    'duration': duration,
                    'severity': 'low'
                })
        
        # Detect very long pauses
        for i in range(len(segments) - 1):
            gap = segments[i + 1].get('start', 0) - segments[i].get('end', 0)
            if gap > 3.0:  # More than 3 seconds
                events.append({
                    'type': 'long_pause',
                    'between_segments': [i, i + 1],
                    'duration': gap,
                    'severity': 'medium' if gap < 10 else 'high'
                })
        
        # Detect very fast speech (if word-level data available)
        if word_segments:
            for i, word in enumerate(word_segments):
                duration = word.get('end', 0) - word.get('start', 0)
                word_text = word.get('word', '')
                if duration > 0 and len(word_text) > 3 and duration < 0.1:  # Very fast
                    events.append({
                        'type': 'very_fast_word',
                        'word_index': i,
                        'word': word_text,
                        'duration': duration,
                        'severity': 'low'
                    })
        
        return events
    
    def calculate_model_reliability_indicators(self, result: Dict) -> Dict:
        """Calculate indicators of model reliability and consistency."""
        segments = result.get('segments', [])
        word_segments = result.get('word_segments', [])
        overall_confidence = result.get('confidence_score', 0)
        
        # Consistency indicators
        segment_confidences = [seg.get('confidence', 0) for seg in segments if 'confidence' in seg]
        confidence_consistency = 1.0 - float(np.std(segment_confidences)) if len(segment_confidences) > 1 else 1.0
        
        # Model stress indicators
        processing_times = result.get('whisperx_processing', {}).get('processing_times', {})
        total_time = processing_times.get('total_seconds', 0)
        audio_duration = self.get_audio_duration(result)
        processing_ratio = total_time / max(1, audio_duration)
        
        # Quality indicators
        alignment_success = result.get('whisperx_processing', {}).get('alignment') == 'completed'
        word_count = len(word_segments)
        expected_word_count = len(result.get('text', '').split())
        word_capture_ratio = word_count / max(1, expected_word_count) if expected_word_count > 0 else 1.0
        
        return {
            'confidence_consistency': max(0, confidence_consistency),
            'processing_efficiency': min(1.0, 1.0 / max(0.1, processing_ratio)),
            'alignment_success': alignment_success,
            'word_capture_ratio': min(1.0, word_capture_ratio),
            'overall_reliability_score': (
                confidence_consistency * 0.3 +
                (1.0 / max(0.1, processing_ratio)) * 0.2 +
                (1.0 if alignment_success else 0.0) * 0.3 +
                min(1.0, word_capture_ratio) * 0.2
            )
        } 