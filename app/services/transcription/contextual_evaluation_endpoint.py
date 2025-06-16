"""
Contextual Guitar Term Evaluation Endpoint

Add this to the transcription service to provide contextual evaluation
of guitar terms for confidence boosting.
"""

from flask import request, jsonify
import json
import logging
from contextual_guitar_evaluator import ContextualGuitarEvaluator

logger = logging.getLogger(__name__)

def add_contextual_evaluation_routes(app):
    """Add contextual evaluation routes to the Flask app"""
    
    @app.route('/contextual-guitar-evaluation', methods=['POST'])
    def contextual_guitar_evaluation():
        """
        Evaluate low-confidence words contextually and boost legitimate guitar terms
        
        Expects JSON with:
        - transcription_data: Dict with word_segments
        - model_name: Optional LLM model to use  
        - confidence_threshold: Optional threshold (default 0.6)
        - boost_target: Optional boost target (default 0.9)
        """
        try:
            data = request.get_json()
            
            if not data or 'transcription_data' not in data:
                return jsonify({
                    'success': False,
                    'error': 'Missing transcription_data in request'
                }), 400
            
            transcription_data = data['transcription_data']
            model_name = data.get('model_name', 'llama3.2:3b')
            confidence_threshold = data.get('confidence_threshold', 0.6)
            boost_target = data.get('boost_target', 0.9)
            
            # Initialize evaluator
            evaluator = ContextualGuitarEvaluator(
                model_name=model_name,
                confidence_threshold=confidence_threshold,
                boost_target=boost_target
            )
            
            # Evaluate contextually
            results = evaluator.evaluate_segment_contextually(
                transcription_data, 
                [model_name]
            )
            
            model_result = results[model_name]
            
            return jsonify({
                'success': True,
                'model_used': model_name,
                'words_evaluated': model_result['words_evaluated'],
                'words_boosted': model_result['words_boosted'],
                'boost_rate_percent': round((model_result['words_boosted'] / max(model_result['words_evaluated'], 1)) * 100, 1),
                'evaluations': model_result['evaluations'],
                'enhanced_transcription': model_result['enhanced_transcription']
            })
            
        except Exception as e:
            logger.error(f"Error in contextual guitar evaluation: {e}")
            return jsonify({
                'success': False,
                'error': str(e)
            }), 500
    
    @app.route('/compare-contextual-models', methods=['POST'])
    def compare_contextual_models():
        """
        Compare multiple models for contextual guitar term evaluation
        
        Expects JSON with:
        - transcription_data: Dict with word_segments
        - models: List of model names to compare
        - confidence_threshold: Optional threshold (default 0.6)
        - boost_target: Optional boost target (default 0.9)
        """
        try:
            data = request.get_json()
            
            if not data or 'transcription_data' not in data:
                return jsonify({
                    'success': False,
                    'error': 'Missing transcription_data in request'
                }), 400
            
            transcription_data = data['transcription_data']
            models = data.get('models', ['llama3.2:3b', 'llama3.1:latest', 'mistral:7b-instruct'])
            confidence_threshold = data.get('confidence_threshold', 0.6)
            boost_target = data.get('boost_target', 0.9)
            
            if not models:
                return jsonify({
                    'success': False,
                    'error': 'No models specified for comparison'
                }), 400
            
            # Initialize evaluator
            evaluator = ContextualGuitarEvaluator(
                confidence_threshold=confidence_threshold,
                boost_target=boost_target
            )
            
            # Compare models
            comparison_results = evaluator.compare_models(transcription_data, models)
            
            return jsonify({
                'success': True,
                'comparison_results': comparison_results
            })
            
        except Exception as e:
            logger.error(f"Error in contextual model comparison: {e}")
            return jsonify({
                'success': False,
                'error': str(e)
            }), 500
    
    @app.route('/test-contextual-evaluation', methods=['POST'])
    def test_contextual_evaluation():
        """
        Test endpoint with sample guitar lesson data
        
        Expects JSON with:
        - models: Optional list of models to test (default: all available)
        - confidence_threshold: Optional threshold (default 0.6)
        """
        try:
            data = request.get_json() or {}
            
            models = data.get('models', ['llama3.2:3b', 'llama3.1:latest', 'mistral:7b-instruct'])
            confidence_threshold = data.get('confidence_threshold', 0.6)
            
            # Sample guitar lesson transcription with low-confidence guitar terms
            test_data = {
                "word_segments": [
                    {"word": "Today", "start": 0.0, "end": 0.4, "score": 0.95},
                    {"word": "we'll", "start": 0.4, "end": 0.7, "score": 0.92},
                    {"word": "learn", "start": 0.7, "end": 1.1, "score": 0.96},
                    {"word": "fingerpicking", "start": 1.1, "end": 1.8, "score": 0.42},  # Low confidence guitar term
                    {"word": "technique", "start": 1.8, "end": 2.4, "score": 0.88},
                    {"word": "on", "start": 2.4, "end": 2.6, "score": 0.97},
                    {"word": "the", "start": 2.6, "end": 2.8, "score": 0.98},
                    {"word": "fretboard", "start": 2.8, "end": 3.4, "score": 0.38},  # Low confidence guitar term
                    {"word": "Start", "start": 3.4, "end": 3.8, "score": 0.91},
                    {"word": "with", "start": 3.8, "end": 4.1, "score": 0.94},
                    {"word": "a", "start": 4.1, "end": 4.2, "score": 0.97},
                    {"word": "C", "start": 4.2, "end": 4.4, "score": 0.52},  # Low confidence - could be guitar term in context
                    {"word": "major", "start": 4.4, "end": 4.8, "score": 0.89},
                    {"word": "chord", "start": 4.8, "end": 5.2, "score": 0.45},  # Low confidence guitar term
                    {"word": "using", "start": 5.2, "end": 5.6, "score": 0.93},
                    {"word": "alternating", "start": 5.6, "end": 6.3, "score": 0.84},
                    {"word": "bass", "start": 6.3, "end": 6.7, "score": 0.51},  # Low confidence guitar term
                    {"word": "notes", "start": 6.7, "end": 7.1, "score": 0.88},
                    {"word": "Remember", "start": 7.1, "end": 7.7, "score": 0.89},
                    {"word": "to", "start": 7.7, "end": 7.9, "score": 0.96},
                    {"word": "mute", "start": 7.9, "end": 8.2, "score": 0.48},  # Low confidence guitar term
                    {"word": "unused", "start": 8.2, "end": 8.7, "score": 0.86},
                    {"word": "strings", "start": 8.7, "end": 9.2, "score": 0.59}   # Low confidence guitar term
                ]
            }
            
            # Initialize evaluator
            evaluator = ContextualGuitarEvaluator(
                confidence_threshold=confidence_threshold
            )
            
            # Compare models
            comparison_results = evaluator.compare_models(test_data, models)
            
            return jsonify({
                'success': True,
                'test_data_used': test_data,
                'comparison_results': comparison_results,
                'test_description': 'Sample guitar lesson with low-confidence guitar terms that should be boosted'
            })
            
        except Exception as e:
            logger.error(f"Error in contextual evaluation test: {e}")
            return jsonify({
                'success': False,
                'error': str(e)
            }), 500

    return app 