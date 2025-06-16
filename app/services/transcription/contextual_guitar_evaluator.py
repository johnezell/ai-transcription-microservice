#!/usr/bin/env python3
"""
Contextual Guitar Term Evaluator

Purpose: Identify low-confidence words that are actually legitimate guitar instruction terms
and boost their confidence scores. This compensates for WhisperX giving low confidence to
specialized guitar terminology that isn't in standard dictionaries.

Approach: Contextual evaluation within segments to determine if low-confidence words are
legitimate guitar terms deserving higher confidence scores.
"""

import json
import requests
import logging
from typing import Dict, List, Any, Optional, Tuple
from dataclasses import dataclass

logger = logging.getLogger(__name__)

@dataclass
class ContextualEvaluation:
    """Result of contextual guitar term evaluation"""
    word: str
    original_confidence: float
    segment_context: str
    is_legitimate_guitar_term: bool
    confidence_boost_applied: bool
    reasoning: str
    llm_response: str = ""

class ContextualGuitarEvaluator:
    """
    Evaluates guitar terms in context to boost confidence for legitimate terminology
    that WhisperX undervalues due to specialized vocabulary.
    """
    
    def __init__(self, 
                 llm_endpoint: str = None,
                 model_name: str = None,
                 confidence_threshold: float = 0.6,  # Lower threshold to catch more undervalued terms
                 boost_target: float = 0.9):         # Boost to 90% rather than 100%
        
        self.llm_endpoint = llm_endpoint or "http://ollama-service:11434/api/generate"
        self.model_name = model_name or "llama3.2:3b"
        self.confidence_threshold = confidence_threshold
        self.boost_target = boost_target
        
        logger.info(f"Contextual Guitar Evaluator initialized - Model: {self.model_name}, Threshold: {confidence_threshold}")
    
    def extract_low_confidence_words(self, transcription_data: Dict[str, Any]) -> List[Dict[str, Any]]:
        """Extract words with confidence below threshold along with their context"""
        low_confidence_words = []
        
        # Extract from word_segments structure
        if 'word_segments' in transcription_data:
            segments = transcription_data['word_segments']
            
            for i, word_data in enumerate(segments):
                confidence = float(word_data.get('score', word_data.get('confidence', 0)))
                word = word_data.get('word', '').strip()
                
                if confidence < self.confidence_threshold and word:
                    # Get context window around this word
                    context_start = max(0, i - 5)
                    context_end = min(len(segments), i + 6)
                    context_words = [seg.get('word', '') for seg in segments[context_start:context_end]]
                    context = ' '.join(context_words).strip()
                    
                    low_confidence_words.append({
                        'index': i,
                        'word': word,
                        'confidence': confidence,
                        'context': context,
                        'word_data': word_data
                    })
        
        return low_confidence_words
    
    def evaluate_in_context(self, word: str, context: str, model_name: str = None) -> ContextualEvaluation:
        """
        Evaluate if a low-confidence word is a legitimate guitar term in its context
        that deserves a higher confidence score.
        """
        model = model_name or self.model_name
        
        # Create focused prompt for contextual evaluation
        prompt = f"""You are analyzing guitar lesson transcription quality.

CONTEXT: "{context}"
LOW-CONFIDENCE WORD: "{word}"

This word received a low confidence score from the speech recognition system. 
Your task: Determine if this is a legitimate guitar instruction term that DESERVES a higher confidence score.

Consider:
1. Is "{word}" a legitimate guitar/music term in this context?
2. Would a guitar instructor realistically say this word in a lesson?
3. Does it fit naturally in the surrounding instruction context?

LEGITIMATE guitar terms include:
- Playing techniques: fingerpicking, strumming, hammer-on, pull-off, bending, sliding
- Guitar parts: fretboard, neck, bridge, soundhole, headstock, tuning pegs
- Music theory: chord, scale, progression, key, mode, interval
- Technical terms: tablature, capo, pick, plectrum, action, intonation
- Musical notation: sharp, flat, major, minor, seventh, ninth, sus, add

IGNORE common words that happen to be in guitar context (the, and, you, can, will, etc.)

Respond with ONLY:
"BOOST" - if this is a legitimate guitar term deserving higher confidence
"SKIP" - if this is likely a common word, error, or not a guitar-specific term

Do not explain your reasoning."""

        try:
            response = requests.post(
                self.llm_endpoint,
                json={
                    "model": model,
                    "prompt": prompt,
                    "stream": False,
                    "options": {
                        "temperature": 0.1,
                        "num_predict": 10
                    }
                },
                timeout=20
            )
            
            if response.status_code == 200:
                result = response.json()
                llm_response = result.get('response', '').strip().upper()
                
                # Parse response
                should_boost = llm_response.startswith("BOOST")
                
                reasoning = "LLM contextual evaluation"
                if should_boost:
                    reasoning += f" - identified as legitimate guitar term in context"
                else:
                    reasoning += f" - not identified as guitar-specific term"
                
                return ContextualEvaluation(
                    word=word,
                    original_confidence=0,  # Will be filled in later
                    segment_context=context,
                    is_legitimate_guitar_term=should_boost,
                    confidence_boost_applied=should_boost,
                    reasoning=reasoning,
                    llm_response=llm_response
                )
            else:
                logger.warning(f"LLM request failed with status {response.status_code}")
                return self._create_fallback_evaluation(word, context, "LLM request failed")
                
        except Exception as e:
            logger.error(f"Error in LLM evaluation: {e}")
            return self._create_fallback_evaluation(word, context, f"LLM error: {str(e)}")
    
    def _create_fallback_evaluation(self, word: str, context: str, error_reason: str) -> ContextualEvaluation:
        """Create fallback evaluation when LLM is unavailable"""
        
        # Don't use hardcoded terms - rely purely on LLM contextual evaluation
        # If LLM is unavailable, default to NOT boosting to avoid false positives
        logger.warning(f"LLM unavailable for contextual evaluation of '{word}': {error_reason}")
        
        return ContextualEvaluation(
            word=word,
            original_confidence=0,
            segment_context=context,
            is_legitimate_guitar_term=False,  # Conservative: don't boost without LLM confirmation
            confidence_boost_applied=False,
            reasoning=f"No boost applied - LLM unavailable: {error_reason}",
            llm_response=""
        )
    
    def evaluate_segment_contextually(self, transcription_data: Dict[str, Any], 
                                    models_to_test: List[str] = None) -> Dict[str, Any]:
        """
        Main method: Evaluate low-confidence words contextually and boost legitimate guitar terms
        """
        models = models_to_test or [self.model_name]
        
        # Extract low-confidence words
        low_confidence_words = self.extract_low_confidence_words(transcription_data)
        
        logger.info(f"Found {len(low_confidence_words)} words below confidence threshold {self.confidence_threshold}")
        
        results = {}
        
        for model in models:
            logger.info(f"Evaluating with model: {model}")
            
            model_results = {
                'model_name': model,
                'words_evaluated': len(low_confidence_words),
                'words_boosted': 0,
                'evaluations': [],
                'enhanced_transcription': None
            }
            
            # Create a copy of transcription data for modification
            enhanced_data = json.loads(json.dumps(transcription_data))
            
            for word_info in low_confidence_words:
                evaluation = self.evaluate_in_context(
                    word_info['word'], 
                    word_info['context'],
                    model
                )
                
                # Fill in the original confidence
                evaluation.original_confidence = word_info['confidence']
                
                # Apply boost if recommended
                if evaluation.confidence_boost_applied:
                    model_results['words_boosted'] += 1
                    
                    # Update the enhanced transcription data
                    word_index = word_info['index']
                    if 'word_segments' in enhanced_data:
                        enhanced_data['word_segments'][word_index]['score'] = self.boost_target
                        enhanced_data['word_segments'][word_index]['confidence'] = self.boost_target
                        enhanced_data['word_segments'][word_index]['original_confidence'] = evaluation.original_confidence
                        enhanced_data['word_segments'][word_index]['boost_reason'] = 'contextual_guitar_term'
                
                model_results['evaluations'].append({
                    'word': evaluation.word,
                    'original_confidence': evaluation.original_confidence,
                    'boosted_confidence': self.boost_target if evaluation.confidence_boost_applied else evaluation.original_confidence,
                    'was_boosted': evaluation.confidence_boost_applied,
                    'context': evaluation.segment_context[:100] + "..." if len(evaluation.segment_context) > 100 else evaluation.segment_context,
                    'reasoning': evaluation.reasoning,
                    'llm_response': evaluation.llm_response
                })
            
            # Calculate average confidence improvement
            if model_results['words_boosted'] > 0:
                boost_rate = (model_results['words_boosted'] / model_results['words_evaluated']) * 100
                logger.info(f"Model {model}: Boosted {model_results['words_boosted']}/{model_results['words_evaluated']} words ({boost_rate:.1f}%)")
            
            model_results['enhanced_transcription'] = enhanced_data
            results[model] = model_results
        
        return results
    
    def compare_models(self, transcription_data: Dict[str, Any], 
                      models: List[str]) -> Dict[str, Any]:
        """
        Compare multiple models for contextual guitar term evaluation
        """
        logger.info(f"Comparing {len(models)} models for contextual evaluation")
        
        comparison_results = self.evaluate_segment_contextually(transcription_data, models)
        
        # Add summary comparison
        summary = {
            'models_compared': len(models),
            'comparison_summary': {}
        }
        
        for model_name, results in comparison_results.items():
            boost_rate = 0
            if results['words_evaluated'] > 0:
                boost_rate = (results['words_boosted'] / results['words_evaluated']) * 100
                
            summary['comparison_summary'][model_name] = {
                'words_evaluated': results['words_evaluated'],
                'words_boosted': results['words_boosted'],
                'boost_rate_percent': round(boost_rate, 1),
                'effectiveness_score': round(boost_rate, 1)  # Simple effectiveness metric
            }
        
        # Find best performing model
        best_model = None
        best_score = 0
        for model_name, stats in summary['comparison_summary'].items():
            if stats['effectiveness_score'] > best_score:
                best_score = stats['effectiveness_score']
                best_model = model_name
        
        summary['recommended_model'] = best_model
        summary['best_effectiveness_score'] = best_score
        
        comparison_results['summary'] = summary
        
        return comparison_results

def test_contextual_evaluation(transcription_file: str = None, models: List[str] = None):
    """
    Test function for contextual guitar term evaluation
    """
    evaluator = ContextualGuitarEvaluator()
    
    # Sample test data if no file provided
    if transcription_file is None:
        test_data = {
            "word_segments": [
                {"word": "Let's", "start": 0.0, "end": 0.3, "score": 0.95},
                {"word": "play", "start": 0.3, "end": 0.6, "score": 0.92},
                {"word": "the", "start": 0.6, "end": 0.8, "score": 0.98},
                {"word": "C", "start": 0.8, "end": 1.0, "score": 0.45},  # Low confidence
                {"word": "major", "start": 1.0, "end": 1.4, "score": 0.88},
                {"word": "chord", "start": 1.4, "end": 1.8, "score": 0.55},  # Low confidence
                {"word": "on", "start": 1.8, "end": 2.0, "score": 0.94},
                {"word": "the", "start": 2.0, "end": 2.2, "score": 0.97},
                {"word": "fretboard", "start": 2.2, "end": 2.8, "score": 0.35},  # Low confidence
                {"word": "using", "start": 2.8, "end": 3.2, "score": 0.89},
                {"word": "fingerpicking", "start": 3.2, "end": 4.0, "score": 0.41}  # Low confidence
            ]
        }
    else:
        with open(transcription_file, 'r') as f:
            test_data = json.load(f)
    
    # Test with specified models or defaults
    test_models = models or ["llama3.2:3b", "llama3.1:latest", "mistral:7b-instruct"]
    
    results = evaluator.compare_models(test_data, test_models)
    
    print(json.dumps(results, indent=2))
    return results

if __name__ == "__main__":
    import sys
    
    models = ["llama3.2:3b", "llama3.1:latest", "mistral:7b-instruct"] if len(sys.argv) < 2 else sys.argv[1].split(',')
    test_contextual_evaluation(models=models) 