#!/usr/bin/env python3

from guitar_term_evaluator import GuitarTerminologyEvaluator, WordSegment
import json

def test_iv_chord_detection():
    """Test if IV chord gets detected as a compound musical term"""
    evaluator = GuitarTerminologyEvaluator()
    
    # Test case: IV chord
    test_transcript = [
        {'word': 'IV', 'confidence': 0.4, 'start': 0.0, 'end': 0.5},
        {'word': 'chord', 'confidence': 0.8, 'start': 0.6, 'end': 1.0}
    ]
    
    print("=== Testing IV chord detection ===")
    print(f"Input: {[w['word'] for w in test_transcript]}")
    print(f"Original confidences: {[w['confidence'] for w in test_transcript]}")
    
    result = evaluator.evaluate_transcript(test_transcript)
    
    print("\n=== Results ===")
    
    # Show enhanced words
    for word in result['enhanced_transcript']:
        original_conf = word.get('original_confidence', word['confidence'])
        boost_reason = word.get('boost_reason', 'none')
        print(f"{word['word']}: {original_conf:.2f} -> {word['confidence']:.2f} ({boost_reason})")
    
    # Show compound musical terms found
    guitar_eval = result.get('guitar_term_evaluation', {})
    if 'compound_musical_terms' in guitar_eval:
        print(f"\nCompound terms found: {guitar_eval['compound_musical_terms']}")
    else:
        print("\nNo compound musical terms found")
    
    # Show full evaluation metadata
    print(f"\nGuitar terms found: {guitar_eval.get('guitar_terms_found', 0)}")
    print(f"Total enhanced words: {guitar_eval.get('total_words_enhanced', 0)}")
    
    # Test individual method calls for debugging
    print("\n=== Debugging Individual Methods ===")
    
    # Convert to WordSegment objects for internal method testing
    segments = [WordSegment(
        word=w['word'],
        start=w['start'],
        end=w['end'],
        confidence=w['confidence'],
        original_confidence=w['confidence']
    ) for w in test_transcript]
    
    # Test the compound detection method directly
    compound_result = evaluator._check_roman_numeral_chord(segments, 0)
    if compound_result:
        print(f"Roman numeral chord detected: {compound_result.description}")
        print(f"Pattern type: {compound_result.pattern_type}")
        print(f"Words: {compound_result.words}")
    else:
        print("No Roman numeral chord detected by _check_roman_numeral_chord")
    
    return result

if __name__ == '__main__':
    test_iv_chord_detection() 