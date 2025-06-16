"""
Teaching Pattern Model Comparator
Compares different LLM models' ability to analyze teaching patterns, pedagogical quality,
and provide educational recommendations for guitar instruction content.
"""

import json
import time
import logging
import requests
from datetime import datetime
from typing import Dict, List, Any, Optional
from dataclasses import dataclass, asdict

logger = logging.getLogger(__name__)

@dataclass
class TeachingAnalysisResult:
    """Result from a single model's teaching pattern analysis."""
    model_name: str
    teaching_pattern_detected: str
    confidence_score: float  # Model's confidence in its analysis
    speech_to_demo_ratio: str  # Model's assessment of balance
    teaching_cycles_detected: int
    pedagogical_quality_score: float  # 0-10 scale
    strengths_identified: List[str]
    improvement_suggestions: List[str]
    target_audience: str  # beginner, intermediate, advanced
    lesson_effectiveness: str  # excellent, good, fair, poor
    content_organization: str  # well-structured, moderate, needs-improvement
    educational_value: str  # high, medium, low
    instructor_communication: str  # clear, adequate, unclear
    processing_time: float
    raw_llm_response: str

@dataclass 
class ModelComparisonSummary:
    """Summary comparing all tested models."""
    models_tested: List[str]
    consensus_pattern: str  # Pattern most models agreed on
    consensus_confidence: float  # Agreement percentage
    best_pedagogical_analyzer: str  # Model with highest avg pedagogical scores
    most_detailed_feedback: str  # Model providing most comprehensive suggestions
    fastest_model: str
    model_agreement_analysis: Dict[str, Any]
    recommendation: str  # Which model to use for teaching analysis

class TeachingPatternModelComparator:
    """
    Compares different LLM models for teaching pattern analysis and pedagogical insights.
    """
    
    def __init__(self, llm_endpoint: str = "http://ollama-service:11434/api/generate"):
        self.llm_endpoint = llm_endpoint
        self.timeout = 30  # Longer timeout for complex analysis
        
    def create_teaching_analysis_prompt(self, transcript_text: str, speech_stats: Dict) -> str:
        """Create comprehensive prompt for teaching pattern analysis."""
        
        speech_ratio = speech_stats.get('speech_ratio', 0) * 100
        silence_ratio = speech_stats.get('non_speech_ratio', 0) * 100
        total_duration = speech_stats.get('total_duration', 0)
        segment_count = speech_stats.get('segment_count', 0)
        
        prompt = f"""You are an expert in guitar pedagogy and educational content analysis. Analyze this guitar lesson transcript for teaching patterns and educational effectiveness.

LESSON TRANSCRIPT:
"{transcript_text}"

LESSON STATISTICS:
- Total Duration: {total_duration:.1f} seconds
- Speech Ratio: {speech_ratio:.1f}%
- Playing/Demo Ratio: {silence_ratio:.1f}%  
- Speech Segments: {segment_count}

ANALYSIS REQUIRED:

1. **TEACHING PATTERN IDENTIFICATION**
   Classify the primary teaching pattern:
   - "instructional": Balanced explanation and demonstration with clear teaching cycles
   - "demonstration": Heavy focus on playing examples with minimal explanation
   - "overview": Lecture-style with more talking than playing
   - "performance": Mostly playing with brief introductions

2. **PEDAGOGICAL QUALITY ASSESSMENT**
   Rate the lesson effectiveness (0-10 scale):
   - Content organization and flow
   - Clarity of instruction
   - Balance of explanation vs demonstration  
   - Educational value for students

3. **TEACHING ANALYSIS**
   Identify:
   - Teaching cycles (explain → demonstrate → explain patterns)
   - Target skill level (beginner/intermediate/advanced)
   - Content focus (technique/theory/songs/general)

4. **EDUCATIONAL FEEDBACK**
   Provide:
   - 3 specific strengths of this lesson
   - 3 specific improvement suggestions
   - Assessment of instructor communication quality

RESPOND IN THIS EXACT JSON FORMAT:
{{
    "teaching_pattern": "instructional|demonstration|overview|performance",
    "confidence": 0.0-1.0,
    "speech_demo_balance": "excellent|good|fair|poor",  
    "teaching_cycles_count": 0-20,
    "pedagogical_quality": 0.0-10.0,
    "strengths": ["strength1", "strength2", "strength3"],
    "improvements": ["improvement1", "improvement2", "improvement3"],
    "target_audience": "beginner|intermediate|advanced|mixed",
    "lesson_effectiveness": "excellent|good|fair|poor",
    "content_organization": "well-structured|moderate|needs-improvement", 
    "educational_value": "high|medium|low",
    "instructor_communication": "clear|adequate|unclear"
}}

Analyze carefully and provide detailed, actionable pedagogical insights."""

        return prompt

    def analyze_with_model(self, model_name: str, transcript_text: str, 
                         speech_stats: Dict) -> TeachingAnalysisResult:
        """Analyze teaching patterns using a specific LLM model."""
        
        prompt = self.create_teaching_analysis_prompt(transcript_text, speech_stats)
        start_time = time.time()
        
        try:
            response = requests.post(
                self.llm_endpoint,
                json={
                    "model": model_name,
                    "prompt": prompt,
                    "stream": False,
                    "options": {
                        "temperature": 0.2,  # Lower temperature for consistent analysis
                        "num_predict": 500,  # Allow longer responses
                        "top_p": 0.9
                    }
                },
                timeout=self.timeout
            )
            
            processing_time = time.time() - start_time
            
            if response.status_code == 200:
                result = response.json()
                llm_response = result.get('response', '').strip()
                
                # Parse JSON response
                analysis_data = self._parse_llm_response(llm_response)
                
                return TeachingAnalysisResult(
                    model_name=model_name,
                    teaching_pattern_detected=analysis_data.get('teaching_pattern', 'unknown'),
                    confidence_score=float(analysis_data.get('confidence', 0.0)),
                    speech_to_demo_ratio=analysis_data.get('speech_demo_balance', 'unknown'),
                    teaching_cycles_detected=int(analysis_data.get('teaching_cycles_count', 0)),
                    pedagogical_quality_score=float(analysis_data.get('pedagogical_quality', 0.0)),
                    strengths_identified=analysis_data.get('strengths', []),
                    improvement_suggestions=analysis_data.get('improvements', []),
                    target_audience=analysis_data.get('target_audience', 'unknown'),
                    lesson_effectiveness=analysis_data.get('lesson_effectiveness', 'unknown'),
                    content_organization=analysis_data.get('content_organization', 'unknown'),
                    educational_value=analysis_data.get('educational_value', 'unknown'),
                    instructor_communication=analysis_data.get('instructor_communication', 'unknown'),
                    processing_time=processing_time,
                    raw_llm_response=llm_response
                )
                
            else:
                logger.error(f"Model {model_name} failed with status {response.status_code}")
                return self._create_error_result(model_name, processing_time, f"HTTP {response.status_code}")
                
        except json.JSONDecodeError as e:
            logger.error(f"Model {model_name} JSON parsing failed: {e}")
            processing_time = time.time() - start_time
            return self._create_error_result(model_name, processing_time, f"JSON parsing error: {str(e)}")
            
        except Exception as e:
            logger.error(f"Model {model_name} analysis failed: {e}")
            processing_time = time.time() - start_time
            return self._create_error_result(model_name, processing_time, str(e))

    def compare_models(self, models: List[str], transcript_text: str, 
                      speech_stats: Dict) -> Dict[str, Any]:
        """Compare multiple models for teaching pattern analysis."""
        
        logger.info(f"Comparing {len(models)} models for teaching pattern analysis")
        start_time = time.time()
        
        results = {}
        model_analyses = []
        
        # Analyze with each model
        for model in models:
            logger.info(f"Analyzing with model: {model}")
            analysis = self.analyze_with_model(model, transcript_text, speech_stats)
            results[model] = asdict(analysis)
            model_analyses.append(analysis)
        
        # Generate comparison summary
        summary = self._generate_comparison_summary(model_analyses)
        
        total_time = time.time() - start_time
        
        return {
            'model_results': results,
            'comparison_summary': asdict(summary),
            'analysis_metadata': {
                'models_tested': len(models),
                'total_processing_time': total_time,
                'average_time_per_model': total_time / len(models) if models else 0,
                'analysis_timestamp': datetime.now().isoformat(),
                'transcript_length': len(transcript_text.split()) if transcript_text else 0,
                'speech_statistics_provided': bool(speech_stats)
            }
        }

    def _parse_llm_response(self, response: str) -> Dict[str, Any]:
        """Parse LLM JSON response with fallback handling."""
        try:
            # Try to find JSON in the response
            if '{' in response and '}' in response:
                json_start = response.find('{')
                json_end = response.rfind('}') + 1
                json_str = response[json_start:json_end]
                return json.loads(json_str)
            else:
                logger.warning("No JSON found in LLM response")
                return self._create_fallback_analysis(response)
                
        except json.JSONDecodeError:
            logger.warning("Failed to parse LLM JSON response")
            return self._create_fallback_analysis(response)

    def _create_fallback_analysis(self, response: str) -> Dict[str, Any]:
        """Create fallback analysis when JSON parsing fails."""
        # Simple keyword-based analysis as fallback
        response_lower = response.lower()
        
        # Detect pattern based on keywords
        if any(word in response_lower for word in ['demonstration', 'playing', 'examples']):
            pattern = 'demonstration'
        elif any(word in response_lower for word in ['instructional', 'teaching', 'explain']):
            pattern = 'instructional'
        elif any(word in response_lower for word in ['overview', 'lecture', 'introduction']):
            pattern = 'overview'
        elif any(word in response_lower for word in ['performance', 'song', 'piece']):
            pattern = 'performance'
        else:
            pattern = 'unknown'
        
        return {
            'teaching_pattern': pattern,
            'confidence': 0.3,  # Low confidence for fallback
            'speech_demo_balance': 'unknown',
            'teaching_cycles_count': 0,
            'pedagogical_quality': 5.0,  # Neutral score
            'strengths': ['Response analysis incomplete'],
            'improvements': ['Model response parsing failed'],
            'target_audience': 'unknown',
            'lesson_effectiveness': 'unknown',
            'content_organization': 'unknown',
            'educational_value': 'unknown',
            'instructor_communication': 'unknown'
        }

    def _create_error_result(self, model_name: str, processing_time: float, 
                           error_msg: str) -> TeachingAnalysisResult:
        """Create error result for failed model analysis."""
        return TeachingAnalysisResult(
            model_name=model_name,
            teaching_pattern_detected='error',
            confidence_score=0.0,
            speech_to_demo_ratio='error',
            teaching_cycles_detected=0,
            pedagogical_quality_score=0.0,
            strengths_identified=[f"Analysis failed: {error_msg}"],
            improvement_suggestions=["Model analysis unavailable"],
            target_audience='unknown',
            lesson_effectiveness='error',
            content_organization='error',
            educational_value='error',
            instructor_communication='error',
            processing_time=processing_time,
            raw_llm_response=f"ERROR: {error_msg}"
        )

    def _generate_comparison_summary(self, analyses: List[TeachingAnalysisResult]) -> ModelComparisonSummary:
        """Generate summary comparing all model analyses."""
        
        if not analyses:
            return ModelComparisonSummary(
                models_tested=[],
                consensus_pattern='none',
                consensus_confidence=0.0,
                best_pedagogical_analyzer='none',
                most_detailed_feedback='none',
                fastest_model='none',
                model_agreement_analysis={},
                recommendation='No models successfully analyzed content'
            )
        
        # Filter out error results
        valid_analyses = [a for a in analyses if a.teaching_pattern_detected != 'error']
        
        if not valid_analyses:
            return ModelComparisonSummary(
                models_tested=[a.model_name for a in analyses],
                consensus_pattern='error',
                consensus_confidence=0.0,
                best_pedagogical_analyzer='none',
                most_detailed_feedback='none',
                fastest_model=min(analyses, key=lambda x: x.processing_time).model_name,
                model_agreement_analysis={'all_models_failed': True},
                recommendation='All models failed analysis'
            )
        
        # Find consensus pattern
        patterns = [a.teaching_pattern_detected for a in valid_analyses]
        pattern_counts = {}
        for pattern in patterns:
            pattern_counts[pattern] = pattern_counts.get(pattern, 0) + 1
        
        consensus_pattern = max(pattern_counts.items(), key=lambda x: x[1])[0]
        consensus_confidence = pattern_counts[consensus_pattern] / len(valid_analyses)
        
        # Find best pedagogical analyzer (highest avg pedagogical score)
        pedagogical_scores = [(a.model_name, a.pedagogical_quality_score) for a in valid_analyses]
        best_pedagogical = max(pedagogical_scores, key=lambda x: x[1])[0] if pedagogical_scores else 'none'
        
        # Find most detailed feedback (most suggestions)
        feedback_counts = [(a.model_name, len(a.strengths_identified) + len(a.improvement_suggestions)) 
                          for a in valid_analyses]
        most_detailed = max(feedback_counts, key=lambda x: x[1])[0] if feedback_counts else 'none'
        
        # Find fastest model
        fastest = min(valid_analyses, key=lambda x: x.processing_time).model_name
        
        # Analyze model agreement
        agreement_analysis = self._analyze_model_agreement(valid_analyses)
        
        # Generate recommendation
        recommendation = self._generate_model_recommendation(valid_analyses, 
                                                           best_pedagogical, 
                                                           most_detailed, 
                                                           fastest)
        
        return ModelComparisonSummary(
            models_tested=[a.model_name for a in analyses],
            consensus_pattern=consensus_pattern,
            consensus_confidence=consensus_confidence,
            best_pedagogical_analyzer=best_pedagogical,
            most_detailed_feedback=most_detailed,
            fastest_model=fastest,
            model_agreement_analysis=agreement_analysis,
            recommendation=recommendation
        )

    def _analyze_model_agreement(self, analyses: List[TeachingAnalysisResult]) -> Dict[str, Any]:
        """Analyze how much models agree on different aspects."""
        
        if len(analyses) < 2:
            return {'insufficient_models': True}
        
        # Pattern agreement
        patterns = [a.teaching_pattern_detected for a in analyses]
        pattern_agreement = len(set(patterns)) / len(patterns)  # Lower = more agreement
        
        # Quality score variance
        quality_scores = [a.pedagogical_quality_score for a in analyses]
        avg_quality = sum(quality_scores) / len(quality_scores)
        quality_variance = sum((score - avg_quality) ** 2 for score in quality_scores) / len(quality_scores)
        
        # Effectiveness agreement
        effectiveness_ratings = [a.lesson_effectiveness for a in analyses]
        effectiveness_agreement = len(set(effectiveness_ratings)) / len(effectiveness_ratings)
        
        return {
            'pattern_agreement_score': 1.0 - pattern_agreement,  # Higher = more agreement
            'quality_score_variance': quality_variance,
            'average_quality_score': avg_quality,
            'effectiveness_agreement_score': 1.0 - effectiveness_agreement,
            'models_in_agreement': len(analyses),
            'high_agreement': pattern_agreement < 0.5 and effectiveness_agreement < 0.5
        }

    def _generate_model_recommendation(self, analyses: List[TeachingAnalysisResult],
                                     best_pedagogical: str, most_detailed: str, 
                                     fastest: str) -> str:
        """Generate recommendation for which model to use."""
        
        # Count how many categories each model wins
        model_scores = {}
        for model in [best_pedagogical, most_detailed, fastest]:
            model_scores[model] = model_scores.get(model, 0) + 1
        
        if model_scores:
            top_model = max(model_scores.items(), key=lambda x: x[1])[0]
            
            if model_scores[top_model] >= 2:
                return f"Recommended: {top_model} (excels in multiple categories)"
            elif best_pedagogical == most_detailed:
                return f"Recommended: {best_pedagogical} (best pedagogical insights with detailed feedback)"
            else:
                return f"For teaching analysis: {best_pedagogical}, For detailed feedback: {most_detailed}, For speed: {fastest}"
        
        return "No clear recommendation available"

def test_teaching_pattern_comparison(models: List[str] = None, 
                                   sample_transcript: str = None) -> Dict[str, Any]:
    """Test function for teaching pattern model comparison."""
    
    if models is None:
        models = ["llama3.2:3b", "llama3.1:latest", "mistral:7b-instruct"]
    
    if sample_transcript is None:
        sample_transcript = """
        Today we're going to learn fingerpicking technique on the acoustic guitar. 
        Let's start with a simple C major chord. Place your fingers like this.
        Now I'll demonstrate the basic pattern - thumb plays the bass note, 
        then index, middle, and ring fingers pick the higher strings.
        Listen to how it sounds when played slowly.
        [Guitar playing for 15 seconds]
        Now try it yourself, focusing on keeping a steady rhythm.
        The key is to keep your thumb steady on the bass notes while your fingers
        alternate on the treble strings. Let me show you again.
        [Guitar playing for 20 seconds]
        Great! Once you've mastered this basic pattern, you can apply it to other chords.
        Practice this for about 10 minutes each day, and you'll see improvement quickly.
        """
    
    sample_stats = {
        'speech_ratio': 0.55,
        'non_speech_ratio': 0.45,
        'total_duration': 120.0,
        'segment_count': 8
    }
    
    comparator = TeachingPatternModelComparator()
    results = comparator.compare_models(models, sample_transcript, sample_stats)
    
    return results

if __name__ == "__main__":
    import sys
    
    test_models = sys.argv[1].split(',') if len(sys.argv) > 1 else None
    results = test_teaching_pattern_comparison(test_models)
    
    print(json.dumps(results, indent=2)) 