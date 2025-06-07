#!/usr/bin/env python3
"""
Whisper Quality Analyzer Module
Tests actual Whisper transcription confidence and combines with technical metrics.
Integrates with existing transcription service for real-world performance testing.
"""

import os
import logging
import requests
import json
import time
from typing import Dict, List, Tuple, Optional
from datetime import datetime

# Import our speech quality analyzer
from speech_quality_analyzer import SpeechQualityAnalyzer

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class WhisperQualityAnalyzer:
    """
    Analyzes WAV files using actual Whisper transcription confidence scores.
    Combines technical metrics (60%) with real-world Whisper performance (40%).
    """
    
    def __init__(self, transcription_service_url: str = None, timeout: int = 30):
        """
        Initialize the Whisper quality analyzer.
        
        Args:
            transcription_service_url: URL of the transcription service
            timeout: Timeout for transcription requests in seconds
        """
        self.transcription_service_url = transcription_service_url or os.environ.get(
            'TRANSCRIPTION_SERVICE_URL', 
            'http://transcription:5000'
        )
        self.timeout = timeout
        self.speech_analyzer = SpeechQualityAnalyzer()
        logger.info(f"Initialized Whisper Quality Analyzer (service: {self.transcription_service_url})")
        
    def test_whisper_confidence(self, audio_path: str) -> Dict:
        """
        Test actual Whisper transcription confidence for an audio file.
        
        Args:
            audio_path: Path to WAV file
            
        Returns:
            Dictionary with transcription results and confidence metrics
        """
        logger.info(f"Testing Whisper confidence: {audio_path}")
        
        if not os.path.exists(audio_path):
            return {
                'success': False,
                'error': f"Audio file not found: {audio_path}",
                'confidence_score': 0.0
            }
            
        try:
            # Prepare the audio file for transcription service
            with open(audio_path, 'rb') as audio_file:
                files = {'audio': audio_file}
                data = {
                    'return_confidence': 'true',
                    'return_segments': 'true',
                    'language': 'en'  # Assuming English for guitar instruction content
                }
                
                # Make request to transcription service
                start_time = time.time()
                response = requests.post(
                    f"{self.transcription_service_url}/transcribe",
                    files=files,
                    data=data,
                    timeout=self.timeout
                )
                processing_time = time.time() - start_time
                
                if response.status_code != 200:
                    return {
                        'success': False,
                        'error': f"Transcription service error: {response.status_code} - {response.text}",
                        'confidence_score': 0.0
                    }
                    
                result = response.json()
                
                if not result.get('success', False):
                    return {
                        'success': False,
                        'error': f"Transcription failed: {result.get('error', 'Unknown error')}",
                        'confidence_score': 0.0
                    }
                    
                # Extract confidence metrics
                transcript_text = result.get('transcript', '')
                segments = result.get('segments', [])
                
                # Calculate confidence scores
                confidence_metrics = self._calculate_confidence_metrics(segments, transcript_text)
                
                return {
                    'success': True,
                    'audio_path': audio_path,
                    'processing_time': round(processing_time, 2),
                    'transcript_text': transcript_text,
                    'transcript_length': len(transcript_text),
                    'word_count': len(transcript_text.split()) if transcript_text else 0,
                    'segment_count': len(segments),
                    'confidence_score': confidence_metrics['overall_confidence'],
                    'confidence_metrics': confidence_metrics,
                    'timestamp': datetime.now().isoformat()
                }
                
        except requests.exceptions.Timeout:
            return {
                'success': False,
                'error': f"Transcription request timed out after {self.timeout} seconds",
                'confidence_score': 0.0
            }
        except requests.exceptions.ConnectionError:
            return {
                'success': False,
                'error': f"Could not connect to transcription service at {self.transcription_service_url}",
                'confidence_score': 0.0
            }
        except Exception as e:
            logger.error(f"Error testing Whisper confidence: {str(e)}")
            return {
                'success': False,
                'error': str(e),
                'confidence_score': 0.0
            }
            
    def _calculate_confidence_metrics(self, segments: List[Dict], transcript_text: str) -> Dict:
        """
        Calculate detailed confidence metrics from Whisper segments.
        
        Args:
            segments: List of transcription segments with confidence scores
            transcript_text: Full transcript text
            
        Returns:
            Dictionary with detailed confidence metrics
        """
        if not segments:
            return {
                'overall_confidence': 0.0,
                'avg_confidence': 0.0,
                'min_confidence': 0.0,
                'max_confidence': 0.0,
                'low_confidence_segments': 0,
                'confidence_distribution': {},
                'quality_indicators': []
            }
            
        # Extract confidence scores from segments
        confidences = []
        low_confidence_count = 0
        
        for segment in segments:
            # Whisper provides confidence at word level within segments
            if 'words' in segment:
                for word in segment['words']:
                    conf = word.get('confidence', 0.0)
                    confidences.append(conf)
                    if conf < 0.7:  # Threshold for low confidence
                        low_confidence_count += 1
            elif 'confidence' in segment:
                # Fallback to segment-level confidence
                conf = segment['confidence']
                confidences.append(conf)
                if conf < 0.7:
                    low_confidence_count += 1
                    
        if not confidences:
            return {
                'overall_confidence': 0.0,
                'avg_confidence': 0.0,
                'min_confidence': 0.0,
                'max_confidence': 0.0,
                'low_confidence_segments': 0,
                'confidence_distribution': {},
                'quality_indicators': ['No confidence data available']
            }
            
        # Calculate statistics
        avg_confidence = sum(confidences) / len(confidences)
        min_confidence = min(confidences)
        max_confidence = max(confidences)
        
        # Confidence distribution
        distribution = {
            'excellent': sum(1 for c in confidences if c >= 0.9),
            'good': sum(1 for c in confidences if 0.8 <= c < 0.9),
            'fair': sum(1 for c in confidences if 0.7 <= c < 0.8),
            'poor': sum(1 for c in confidences if c < 0.7)
        }
        
        # Quality indicators
        quality_indicators = []
        
        if avg_confidence >= 0.9:
            quality_indicators.append("Excellent transcription confidence")
        elif avg_confidence >= 0.8:
            quality_indicators.append("Good transcription confidence")
        elif avg_confidence >= 0.7:
            quality_indicators.append("Fair transcription confidence")
        else:
            quality_indicators.append("Poor transcription confidence")
            
        if min_confidence < 0.5:
            quality_indicators.append("Some words have very low confidence")
            
        if distribution['poor'] > len(confidences) * 0.2:
            quality_indicators.append("High percentage of low-confidence words")
            
        if len(transcript_text.split()) < 5:
            quality_indicators.append("Very short transcript - may indicate audio issues")
            
        # Overall confidence score (weighted average favoring consistency)
        overall_confidence = (avg_confidence * 0.7 + min_confidence * 0.3) * 100
        
        return {
            'overall_confidence': round(overall_confidence, 2),
            'avg_confidence': round(avg_confidence, 3),
            'min_confidence': round(min_confidence, 3),
            'max_confidence': round(max_confidence, 3),
            'low_confidence_segments': low_confidence_count,
            'confidence_distribution': distribution,
            'quality_indicators': quality_indicators
        }
        
    def analyze_with_whisper_testing(self, audio_path: str) -> Dict:
        """
        Comprehensive analysis combining technical metrics with Whisper confidence testing.
        Uses 60% technical + 40% Whisper confidence weighting.
        
        Args:
            audio_path: Path to WAV file
            
        Returns:
            Dictionary with comprehensive analysis results
        """
        logger.info(f"Comprehensive analysis with Whisper testing: {audio_path}")
        
        # Get technical analysis (60% weight)
        technical_analysis = self.speech_analyzer.analyze_speech_quality(audio_path)
        
        if not technical_analysis['success']:
            return {
                'success': False,
                'error': f"Technical analysis failed: {technical_analysis.get('error', 'Unknown error')}",
                'overall_score': 0.0
            }
            
        # Get Whisper confidence testing (40% weight)
        whisper_analysis = self.test_whisper_confidence(audio_path)
        
        # Calculate combined score
        technical_score = technical_analysis['overall_score']
        whisper_score = whisper_analysis.get('confidence_score', 0.0)
        
        # Weighted combination: 60% technical + 40% Whisper
        combined_score = (technical_score * 0.6) + (whisper_score * 0.4)
        
        # Determine final grade
        if combined_score >= 90:
            final_grade = "Excellent"
        elif combined_score >= 80:
            final_grade = "Good"
        elif combined_score >= 70:
            final_grade = "Fair"
        elif combined_score >= 60:
            final_grade = "Poor"
        else:
            final_grade = "Unacceptable"
            
        # Generate recommendations
        recommendations = []
        
        if whisper_analysis['success']:
            if whisper_score >= 80:
                recommendations.append("Whisper transcription quality is excellent")
            elif whisper_score >= 70:
                recommendations.append("Whisper transcription quality is good")
            elif whisper_score >= 60:
                recommendations.append("Whisper transcription quality is acceptable but could be improved")
            else:
                recommendations.append("Whisper transcription quality is poor - consider audio preprocessing")
                
            # Add specific recommendations based on confidence metrics
            conf_metrics = whisper_analysis.get('confidence_metrics', {})
            if conf_metrics.get('low_confidence_segments', 0) > 0:
                recommendations.append(f"Contains {conf_metrics['low_confidence_segments']} low-confidence segments")
                
        else:
            recommendations.append(f"Whisper testing failed: {whisper_analysis.get('error', 'Unknown error')}")
            recommendations.append("Score based on technical analysis only")
            
        # Technical recommendations
        tech_metrics = technical_analysis.get('metrics', {})
        for metric_name, metric_data in tech_metrics.items():
            if metric_data['score'] < 70:
                recommendations.append(f"Consider improving {metric_name.replace('_', ' ')}: {metric_data['reasoning']}")
                
        result = {
            'success': True,
            'audio_path': audio_path,
            'overall_score': round(combined_score, 2),
            'final_grade': final_grade,
            'timestamp': datetime.now().isoformat(),
            'component_scores': {
                'technical_score': round(technical_score, 2),
                'technical_weight': 0.6,
                'whisper_score': round(whisper_score, 2),
                'whisper_weight': 0.4,
                'whisper_success': whisper_analysis['success']
            },
            'recommendations': recommendations,
            'technical_analysis': technical_analysis,
            'whisper_analysis': whisper_analysis
        }
        
        logger.info(f"Comprehensive analysis complete: {combined_score:.2f}/100 ({final_grade})")
        return result
        
    def compare_with_whisper_testing(self, audio_files: List[str]) -> Dict:
        """
        Compare multiple audio files using comprehensive analysis including Whisper testing.
        
        Args:
            audio_files: List of paths to WAV files
            
        Returns:
            Dictionary with comparison results and recommendations
        """
        logger.info(f"Comparing {len(audio_files)} audio files with Whisper testing")
        
        if not audio_files:
            return {
                'success': False,
                'error': "No audio files provided",
                'recommendations': []
            }
            
        # Analyze each file comprehensively
        analyses = []
        for audio_path in audio_files:
            analysis = self.analyze_with_whisper_testing(audio_path)
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
        
        # Generate detailed recommendations
        best_file = analyses[0]
        recommendations = []
        
        # Primary recommendation
        recommendations.append({
            'rank': 1,
            'audio_path': best_file['audio_path'],
            'score': best_file['overall_score'],
            'grade': best_file['final_grade'],
            'technical_score': best_file['component_scores']['technical_score'],
            'whisper_score': best_file['component_scores']['whisper_score'],
            'whisper_success': best_file['component_scores']['whisper_success'],
            'reasoning': f"Highest combined score ({best_file['overall_score']:.2f}/100) with balanced technical and Whisper performance",
            'recommended_for': "Primary choice for Whisper transcription"
        })
        
        # Additional recommendations
        for i, analysis in enumerate(analyses[1:], 2):
            score_diff = best_file['overall_score'] - analysis['overall_score']
            
            reasoning_parts = []
            if score_diff < 5:
                reasoning_parts.append(f"Very close to best option (only {score_diff:.2f} points lower)")
            elif score_diff < 15:
                reasoning_parts.append(f"Good alternative ({score_diff:.2f} points lower)")
            else:
                reasoning_parts.append(f"Significantly lower quality ({score_diff:.2f} points lower)")
                
            # Add component score details
            tech_diff = best_file['component_scores']['technical_score'] - analysis['component_scores']['technical_score']
            whisper_diff = best_file['component_scores']['whisper_score'] - analysis['component_scores']['whisper_score']
            
            if analysis['component_scores']['whisper_success']:
                reasoning_parts.append(f"Technical: -{tech_diff:.1f}, Whisper: -{whisper_diff:.1f}")
            else:
                reasoning_parts.append(f"Technical: -{tech_diff:.1f}, Whisper: failed")
                
            recommendations.append({
                'rank': i,
                'audio_path': analysis['audio_path'],
                'score': analysis['overall_score'],
                'grade': analysis['final_grade'],
                'technical_score': analysis['component_scores']['technical_score'],
                'whisper_score': analysis['component_scores']['whisper_score'],
                'whisper_success': analysis['component_scores']['whisper_success'],
                'reasoning': "; ".join(reasoning_parts),
                'recommended_for': "Backup option" if score_diff < 15 else "Not recommended"
            })
            
        # Calculate summary statistics
        whisper_success_count = sum(1 for a in analyses if a['component_scores']['whisper_success'])
        avg_technical = sum(a['component_scores']['technical_score'] for a in analyses) / len(analyses)
        avg_whisper = sum(a['component_scores']['whisper_score'] for a in analyses if a['component_scores']['whisper_success'])
        avg_whisper = avg_whisper / whisper_success_count if whisper_success_count > 0 else 0
        
        result = {
            'success': True,
            'timestamp': datetime.now().isoformat(),
            'files_analyzed': len(analyses),
            'files_failed': len(audio_files) - len(analyses),
            'whisper_tests_successful': whisper_success_count,
            'best_file': best_file['audio_path'],
            'best_score': best_file['overall_score'],
            'recommendations': recommendations,
            'detailed_analyses': analyses,
            'summary': {
                'score_range': f"{analyses[-1]['overall_score']:.2f} - {analyses[0]['overall_score']:.2f}",
                'quality_spread': f"{analyses[0]['overall_score'] - analyses[-1]['overall_score']:.2f} points",
                'avg_technical_score': round(avg_technical, 2),
                'avg_whisper_score': round(avg_whisper, 2),
                'all_acceptable': all(a['overall_score'] >= 60 for a in analyses),
                'whisper_success_rate': f"{whisper_success_count}/{len(analyses)}"
            }
        }
        
        logger.info(f"Comprehensive comparison complete. Best file: {best_file['audio_path']} ({best_file['overall_score']:.2f}/100)")
        return result


def test_whisper_confidence(audio_path: str, transcription_service_url: str = None) -> Dict:
    """
    Convenience function to test Whisper confidence for a single audio file.
    
    Args:
        audio_path: Path to WAV file
        transcription_service_url: URL of transcription service
        
    Returns:
        Dictionary with Whisper confidence results
    """
    analyzer = WhisperQualityAnalyzer(transcription_service_url)
    return analyzer.test_whisper_confidence(audio_path)


def analyze_with_whisper_testing(audio_path: str, transcription_service_url: str = None) -> Dict:
    """
    Convenience function for comprehensive analysis including Whisper testing.
    
    Args:
        audio_path: Path to WAV file
        transcription_service_url: URL of transcription service
        
    Returns:
        Dictionary with comprehensive analysis results
    """
    analyzer = WhisperQualityAnalyzer(transcription_service_url)
    return analyzer.analyze_with_whisper_testing(audio_path)


if __name__ == "__main__":
    # Example usage
    import sys
    
    if len(sys.argv) < 2:
        print("Usage: python whisper_quality_analyzer.py <audio_file1> [audio_file2] ...")
        sys.exit(1)
        
    audio_files = sys.argv[1:]
    analyzer = WhisperQualityAnalyzer()
    
    if len(audio_files) == 1:
        # Single file comprehensive analysis
        result = analyzer.analyze_with_whisper_testing(audio_files[0])
        print(f"\nComprehensive Quality Analysis Results:")
        print(f"File: {result.get('audio_path', 'N/A')}")
        print(f"Overall Score: {result.get('overall_score', 0)}/100 ({result.get('final_grade', 'N/A')})")
        
        if result.get('success'):
            comp_scores = result.get('component_scores', {})
            print(f"Technical Score: {comp_scores.get('technical_score', 0)}/100 (60% weight)")
            print(f"Whisper Score: {comp_scores.get('whisper_score', 0)}/100 (40% weight)")
            print(f"Whisper Test: {'Success' if comp_scores.get('whisper_success') else 'Failed'}")
            
            if result.get('recommendations'):
                print(f"\nRecommendations:")
                for rec in result['recommendations'][:5]:  # Show top 5
                    print(f"  • {rec}")
    else:
        # Multiple file comparison
        result = analyzer.compare_with_whisper_testing(audio_files)
        print(f"\nComprehensive Audio Quality Comparison Results:")
        print(f"Files analyzed: {result.get('files_analyzed', 0)}")
        print(f"Whisper tests successful: {result.get('whisper_tests_successful', 0)}")
        print(f"Best file: {result.get('best_file', 'N/A')} ({result.get('best_score', 0):.2f}/100)")
        
        if result.get('success') and 'recommendations' in result:
            print(f"\nTop Recommendations:")
            for rec in result['recommendations'][:3]:  # Show top 3
                whisper_status = "✓" if rec['whisper_success'] else "✗"
                print(f"  {rec['rank']}. {os.path.basename(rec['audio_path'])} - {rec['score']:.2f}/100 ({rec['grade']})")
                print(f"     Technical: {rec['technical_score']:.1f}, Whisper: {rec['whisper_score']:.1f} {whisper_status}")
                print(f"     {rec['reasoning']}")