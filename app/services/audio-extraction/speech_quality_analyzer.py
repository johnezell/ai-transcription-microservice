#!/usr/bin/env python3
"""
Speech Quality Analyzer Module
Implements programmatic WAV file quality assessment to select best audio for Whisper transcription.
Leverages existing assess_audio_quality() and get_audio_volume_stats() functions.
"""

import os
import logging
import math
from typing import Dict, List, Tuple, Optional
from datetime import datetime

# Import existing service functions
from ai_roo_audio_quality_validation import get_audio_stats, get_audio_volume_stats

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class SpeechQualityAnalyzer:
    """
    Analyzes WAV files for speech quality metrics optimized for Whisper transcription.
    Uses weighted scoring system with 5 key metrics.
    """
    
    # Quality scoring weights (must sum to 100%)
    WEIGHTS = {
        'sample_rate': 0.25,    # 25% - 16kHz optimal for Whisper
        'volume_level': 0.30,   # 30% - -30dB to -10dB range
        'dynamic_range': 0.20,  # 20% - 10-25dB difference
        'duration': 0.15,       # 15% - 5-30 seconds optimal
        'bit_rate': 0.10        # 10% - 256kbps+ preferred
    }
    
    # Optimal ranges for speech quality
    OPTIMAL_RANGES = {
        'sample_rate': 16000,           # Whisper's preferred sample rate
        'volume_level': (-30, -10),     # dB range for clear speech
        'dynamic_range': (10, 25),      # dB difference for good dynamics
        'duration': (5, 30),            # seconds - optimal for processing
        'bit_rate': 256000              # minimum kbps for quality
    }
    
    def __init__(self):
        """Initialize the speech quality analyzer."""
        logger.info("Initializing Speech Quality Analyzer")
        
    def calculate_sample_rate_score(self, sample_rate: int) -> Tuple[float, str]:
        """
        Calculate sample rate score (25% weight).
        16kHz is optimal for Whisper transcription.
        
        Args:
            sample_rate: Audio sample rate in Hz
            
        Returns:
            Tuple of (score 0-100, reasoning)
        """
        optimal_rate = self.OPTIMAL_RANGES['sample_rate']
        
        if sample_rate == optimal_rate:
            return 100.0, f"Perfect: {sample_rate}Hz matches Whisper's optimal rate"
        elif sample_rate == 8000:
            return 60.0, f"Acceptable: {sample_rate}Hz is usable but not optimal"
        elif sample_rate in [44100, 48000]:
            return 80.0, f"Good: {sample_rate}Hz is high quality, will be downsampled"
        elif sample_rate > optimal_rate:
            return 75.0, f"Acceptable: {sample_rate}Hz is higher than needed"
        else:
            return 40.0, f"Poor: {sample_rate}Hz is below optimal for speech"
            
    def calculate_volume_level_score(self, volume_stats: Dict) -> Tuple[float, str]:
        """
        Calculate volume level score (30% weight).
        -30dB to -10dB range is optimal for speech clarity.
        
        Args:
            volume_stats: Dictionary with mean_volume and max_volume
            
        Returns:
            Tuple of (score 0-100, reasoning)
        """
        if not volume_stats or 'mean_volume' not in volume_stats:
            return 0.0, "No volume statistics available"
            
        try:
            mean_vol = float(volume_stats['mean_volume'].replace('dB', ''))
            max_vol = float(volume_stats.get('max_volume', '0').replace('dB', ''))
            
            optimal_min, optimal_max = self.OPTIMAL_RANGES['volume_level']
            
            if optimal_min <= mean_vol <= optimal_max:
                return 100.0, f"Perfect: Mean volume {mean_vol}dB is in optimal range"
            elif mean_vol < -40:
                return 30.0, f"Too quiet: Mean volume {mean_vol}dB may cause transcription issues"
            elif mean_vol > -5:
                return 40.0, f"Too loud: Mean volume {mean_vol}dB may have clipping"
            elif mean_vol < optimal_min:
                # Gradually decrease score as it gets quieter
                score = 70 + (mean_vol - optimal_min) * 2
                return max(30.0, score), f"Slightly quiet: Mean volume {mean_vol}dB"
            else:
                # Gradually decrease score as it gets louder
                score = 70 - (mean_vol - optimal_max) * 3
                return max(40.0, score), f"Slightly loud: Mean volume {mean_vol}dB"
                
        except (ValueError, AttributeError) as e:
            return 0.0, f"Error parsing volume data: {str(e)}"
            
    def calculate_dynamic_range_score(self, volume_stats: Dict) -> Tuple[float, str]:
        """
        Calculate dynamic range score (20% weight).
        10-25dB difference between mean and max indicates good speech dynamics.
        
        Args:
            volume_stats: Dictionary with mean_volume and max_volume
            
        Returns:
            Tuple of (score 0-100, reasoning)
        """
        if not volume_stats or 'mean_volume' not in volume_stats or 'max_volume' not in volume_stats:
            return 50.0, "Limited volume statistics - assuming average dynamics"
            
        try:
            mean_vol = float(volume_stats['mean_volume'].replace('dB', ''))
            max_vol = float(volume_stats['max_volume'].replace('dB', ''))
            
            dynamic_range = max_vol - mean_vol
            optimal_min, optimal_max = self.OPTIMAL_RANGES['dynamic_range']
            
            if optimal_min <= dynamic_range <= optimal_max:
                return 100.0, f"Perfect: Dynamic range {dynamic_range:.1f}dB indicates good speech variation"
            elif dynamic_range < 5:
                return 40.0, f"Poor: Dynamic range {dynamic_range:.1f}dB suggests compressed/flat audio"
            elif dynamic_range > 35:
                return 60.0, f"Excessive: Dynamic range {dynamic_range:.1f}dB may have noise or inconsistent levels"
            elif dynamic_range < optimal_min:
                score = 60 + (dynamic_range - 5) * 8  # Scale from 40 to 100
                return max(40.0, score), f"Limited: Dynamic range {dynamic_range:.1f}dB"
            else:
                score = 100 - (dynamic_range - optimal_max) * 2
                return max(60.0, score), f"High: Dynamic range {dynamic_range:.1f}dB"
                
        except (ValueError, AttributeError) as e:
            return 50.0, f"Error calculating dynamic range: {str(e)}"
            
    def calculate_duration_score(self, duration: float) -> Tuple[float, str]:
        """
        Calculate duration score (15% weight).
        5-30 seconds is optimal for processing efficiency and accuracy.
        
        Args:
            duration: Audio duration in seconds
            
        Returns:
            Tuple of (score 0-100, reasoning)
        """
        if duration <= 0:
            return 0.0, "Invalid duration"
            
        optimal_min, optimal_max = self.OPTIMAL_RANGES['duration']
        
        if optimal_min <= duration <= optimal_max:
            return 100.0, f"Perfect: Duration {duration:.1f}s is optimal for processing"
        elif duration < 2:
            return 30.0, f"Too short: Duration {duration:.1f}s may not provide enough context"
        elif duration < optimal_min:
            score = 60 + (duration - 2) * 13.3  # Scale from 30 to 100
            return max(30.0, score), f"Short: Duration {duration:.1f}s"
        elif duration <= 60:
            score = 100 - (duration - optimal_max) * 1.5
            return max(70.0, score), f"Long: Duration {duration:.1f}s"
        else:
            return 70.0, f"Very long: Duration {duration:.1f}s may impact processing speed"
            
    def calculate_bit_rate_score(self, bit_rate: int) -> Tuple[float, str]:
        """
        Calculate bit rate score (10% weight).
        256kbps+ is preferred for quality, but less critical for speech.
        
        Args:
            bit_rate: Audio bit rate in bps
            
        Returns:
            Tuple of (score 0-100, reasoning)
        """
        if bit_rate <= 0:
            return 50.0, "Bit rate unavailable - assuming standard quality"
            
        min_quality = self.OPTIMAL_RANGES['bit_rate']
        
        if bit_rate >= min_quality:
            return 100.0, f"Excellent: Bit rate {bit_rate//1000}kbps ensures good quality"
        elif bit_rate >= 128000:
            return 80.0, f"Good: Bit rate {bit_rate//1000}kbps is acceptable for speech"
        elif bit_rate >= 64000:
            return 60.0, f"Fair: Bit rate {bit_rate//1000}kbps is minimal for speech"
        else:
            return 40.0, f"Poor: Bit rate {bit_rate//1000}kbps may affect quality"
            
    def analyze_speech_quality(self, audio_path: str) -> Dict:
        """
        Analyze speech quality of a single WAV file using weighted scoring system.
        
        Args:
            audio_path: Path to WAV file
            
        Returns:
            Dictionary with detailed analysis and overall score
        """
        logger.info(f"Analyzing speech quality: {audio_path}")
        
        if not os.path.exists(audio_path):
            return {
                'success': False,
                'error': f"Audio file not found: {audio_path}",
                'overall_score': 0.0
            }
            
        try:
            # Get audio statistics
            audio_stats = get_audio_stats(audio_path)
            volume_stats = get_audio_volume_stats(audio_path)
            
            if not audio_stats:
                return {
                    'success': False,
                    'error': "Failed to extract audio statistics",
                    'overall_score': 0.0
                }
                
            # Calculate individual scores
            sample_rate_score, sample_rate_reason = self.calculate_sample_rate_score(
                audio_stats.get('sample_rate', 0)
            )
            
            volume_score, volume_reason = self.calculate_volume_level_score(volume_stats)
            
            dynamic_range_score, dynamic_range_reason = self.calculate_dynamic_range_score(volume_stats)
            
            duration_score, duration_reason = self.calculate_duration_score(
                audio_stats.get('duration', 0)
            )
            
            bit_rate_score, bit_rate_reason = self.calculate_bit_rate_score(
                audio_stats.get('bit_rate', 0)
            )
            
            # Calculate weighted overall score
            overall_score = (
                sample_rate_score * self.WEIGHTS['sample_rate'] +
                volume_score * self.WEIGHTS['volume_level'] +
                dynamic_range_score * self.WEIGHTS['dynamic_range'] +
                duration_score * self.WEIGHTS['duration'] +
                bit_rate_score * self.WEIGHTS['bit_rate']
            )
            
            # Determine quality grade
            if overall_score >= 90:
                grade = "Excellent"
            elif overall_score >= 80:
                grade = "Good"
            elif overall_score >= 70:
                grade = "Fair"
            elif overall_score >= 60:
                grade = "Poor"
            else:
                grade = "Unacceptable"
                
            result = {
                'success': True,
                'audio_path': audio_path,
                'overall_score': round(overall_score, 2),
                'grade': grade,
                'timestamp': datetime.now().isoformat(),
                'metrics': {
                    'sample_rate': {
                        'score': round(sample_rate_score, 2),
                        'weight': self.WEIGHTS['sample_rate'],
                        'value': audio_stats.get('sample_rate', 0),
                        'reasoning': sample_rate_reason
                    },
                    'volume_level': {
                        'score': round(volume_score, 2),
                        'weight': self.WEIGHTS['volume_level'],
                        'value': volume_stats.get('mean_volume', 'N/A'),
                        'reasoning': volume_reason
                    },
                    'dynamic_range': {
                        'score': round(dynamic_range_score, 2),
                        'weight': self.WEIGHTS['dynamic_range'],
                        'value': f"{volume_stats.get('max_volume', 'N/A')} - {volume_stats.get('mean_volume', 'N/A')}",
                        'reasoning': dynamic_range_reason
                    },
                    'duration': {
                        'score': round(duration_score, 2),
                        'weight': self.WEIGHTS['duration'],
                        'value': f"{audio_stats.get('duration', 0):.1f}s",
                        'reasoning': duration_reason
                    },
                    'bit_rate': {
                        'score': round(bit_rate_score, 2),
                        'weight': self.WEIGHTS['bit_rate'],
                        'value': f"{audio_stats.get('bit_rate', 0)//1000}kbps" if audio_stats.get('bit_rate', 0) > 0 else 'N/A',
                        'reasoning': bit_rate_reason
                    }
                },
                'raw_stats': {
                    'audio_stats': audio_stats,
                    'volume_stats': volume_stats
                }
            }
            
            logger.info(f"Analysis complete: {overall_score:.2f}/100 ({grade})")
            return result
            
        except Exception as e:
            logger.error(f"Error analyzing audio quality: {str(e)}")
            return {
                'success': False,
                'error': str(e),
                'overall_score': 0.0
            }
            
    def compare_audio_files(self, audio_files: List[str]) -> Dict:
        """
        Compare multiple audio files and rank them by speech quality.
        
        Args:
            audio_files: List of paths to WAV files
            
        Returns:
            Dictionary with comparison results and recommendations
        """
        logger.info(f"Comparing {len(audio_files)} audio files for speech quality")
        
        if not audio_files:
            return {
                'success': False,
                'error': "No audio files provided",
                'recommendations': []
            }
            
        # Analyze each file
        analyses = []
        for audio_path in audio_files:
            analysis = self.analyze_speech_quality(audio_path)
            if analysis['success']:
                analyses.append(analysis)
            else:
                logger.warning(f"Failed to analyze {audio_path}: {analysis.get('error', 'Unknown error')}")
                
        if not analyses:
            return {
                'success': False,
                'error': "No files could be analyzed successfully",
                'recommendations': []
            }
            
        # Sort by overall score (highest first)
        analyses.sort(key=lambda x: x['overall_score'], reverse=True)
        
        # Generate recommendations
        best_file = analyses[0]
        recommendations = []
        
        # Primary recommendation
        recommendations.append({
            'rank': 1,
            'audio_path': best_file['audio_path'],
            'score': best_file['overall_score'],
            'grade': best_file['grade'],
            'reasoning': f"Highest overall quality score ({best_file['overall_score']:.2f}/100)",
            'recommended_for': "Whisper transcription"
        })
        
        # Secondary recommendations if multiple files
        for i, analysis in enumerate(analyses[1:], 2):
            score_diff = best_file['overall_score'] - analysis['overall_score']
            
            if score_diff < 5:
                reasoning = f"Very close to best option (only {score_diff:.2f} points lower)"
            elif score_diff < 15:
                reasoning = f"Good alternative option ({score_diff:.2f} points lower)"
            else:
                reasoning = f"Significantly lower quality ({score_diff:.2f} points lower)"
                
            recommendations.append({
                'rank': i,
                'audio_path': analysis['audio_path'],
                'score': analysis['overall_score'],
                'grade': analysis['grade'],
                'reasoning': reasoning,
                'recommended_for': "Backup option" if score_diff < 15 else "Not recommended"
            })
            
        result = {
            'success': True,
            'timestamp': datetime.now().isoformat(),
            'files_analyzed': len(analyses),
            'files_failed': len(audio_files) - len(analyses),
            'best_file': best_file['audio_path'],
            'best_score': best_file['overall_score'],
            'recommendations': recommendations,
            'detailed_analyses': analyses,
            'summary': {
                'score_range': f"{analyses[-1]['overall_score']:.2f} - {analyses[0]['overall_score']:.2f}",
                'quality_spread': f"{analyses[0]['overall_score'] - analyses[-1]['overall_score']:.2f} points",
                'all_acceptable': all(a['overall_score'] >= 60 for a in analyses)
            }
        }
        
        logger.info(f"Comparison complete. Best file: {best_file['audio_path']} ({best_file['overall_score']:.2f}/100)")
        return result


def analyze_speech_quality(audio_path: str) -> Dict:
    """
    Convenience function to analyze a single audio file.
    
    Args:
        audio_path: Path to WAV file
        
    Returns:
        Dictionary with analysis results
    """
    analyzer = SpeechQualityAnalyzer()
    return analyzer.analyze_speech_quality(audio_path)


def compare_audio_files(audio_files: List[str]) -> Dict:
    """
    Convenience function to compare multiple audio files.
    
    Args:
        audio_files: List of paths to WAV files
        
    Returns:
        Dictionary with comparison results
    """
    analyzer = SpeechQualityAnalyzer()
    return analyzer.compare_audio_files(audio_files)


if __name__ == "__main__":
    # Example usage
    import sys
    
    if len(sys.argv) < 2:
        print("Usage: python speech_quality_analyzer.py <audio_file1> [audio_file2] ...")
        sys.exit(1)
        
    audio_files = sys.argv[1:]
    
    if len(audio_files) == 1:
        # Single file analysis
        result = analyze_speech_quality(audio_files[0])
        print(f"\nSpeech Quality Analysis Results:")
        print(f"File: {result.get('audio_path', 'N/A')}")
        print(f"Overall Score: {result.get('overall_score', 0)}/100")
        print(f"Grade: {result.get('grade', 'N/A')}")
        
        if result.get('success') and 'metrics' in result:
            print(f"\nDetailed Metrics:")
            for metric_name, metric_data in result['metrics'].items():
                print(f"  {metric_name.replace('_', ' ').title()}: {metric_data['score']:.1f}/100 - {metric_data['reasoning']}")
    else:
        # Multiple file comparison
        result = compare_audio_files(audio_files)
        print(f"\nAudio Quality Comparison Results:")
        print(f"Files analyzed: {result.get('files_analyzed', 0)}")
        print(f"Best file: {result.get('best_file', 'N/A')} ({result.get('best_score', 0):.2f}/100)")
        
        if result.get('success') and 'recommendations' in result:
            print(f"\nRecommendations:")
            for rec in result['recommendations'][:3]:  # Show top 3
                print(f"  {rec['rank']}. {os.path.basename(rec['audio_path'])} - {rec['score']:.2f}/100 ({rec['grade']})")
                print(f"     {rec['reasoning']}")