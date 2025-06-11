#!/usr/bin/env python3
"""
Test that single-character Roman numerals are no longer filtered out
"""

import sys
import json
from typing import List

try:
    from guitar_term_evaluator import GuitarTerminologyEvaluator
    print("✅ Successfully imported guitar term evaluator")
except ImportError as e:
    print(f"❌ Failed to import guitar term evaluator: {e}")
    sys.exit(1)

def test_single_character_roman_numerals():
    """Test that I chord and V chord get detected properly"""
    
    # Test cases with single-character Roman numerals
    test_cases = [
        {
            'name': 'I chord',
            'segments': [
                {'word': 'I', 'confidence': 0.35, 'start': 0.0, 'end': 0.3},
                {'word': 'chord', 'confidence': 0.70, 'start': 0.4, 'end': 0.8}
            ]
        },
        {
            'name': 'V chord', 
            'segments': [
                {'word': 'V', 'confidence': 0.35, 'start': 0.0, 'end': 0.3},
                {'word': 'chord', 'confidence': 0.80, 'start': 0.4, 'end': 0.8}
            ]
        },
        {
            'name': 'IV chord (multi-character)',
            'segments': [
                {'word': 'IV', 'confidence': 0.35, 'start': 0.0, 'end': 0.4},
                {'word': 'chord', 'confidence': 0.75, 'start': 0.5, 'end': 0.9}
            ]
        }
    ]
    
    evaluator = GuitarTerminologyEvaluator()
    
    print("=== Testing Single-Character Roman Numeral Detection ===")
    
    for test_case in test_cases:
        print(f"\n--- Testing {test_case['name']} ---")
        
        # Create transcription JSON format
        transcription_json = {
            'word_segments': test_case['segments']
        }
        
        # Test with evaluator
        result = evaluator.evaluate_and_boost(transcription_json)
        
        # Check results
        enhanced_segments = result['word_segments']
        guitar_eval = result.get('guitar_term_evaluation', {})
        
        print(f"Input: {[s['word'] for s in test_case['segments']]}")
        print(f"Original confidences: {[s['confidence'] for s in test_case['segments']]}")
        
        success = True
        for i, segment in enumerate(enhanced_segments):
            original_conf = test_case['segments'][i]['confidence']
            new_conf = segment['confidence']
            boost_reason = segment.get('boost_reason', 'none')
            
            print(f"  {segment['word']}: {original_conf:.2f} → {new_conf:.2f} ({boost_reason})")
            
            if new_conf != 1.0:
                success = False
        
        # Check if compound musical terms were detected
        compound_terms = guitar_eval.get('musical_counting_patterns', [])
        compound_detected = any(p.get('pattern_type') == 'roman_numeral_chord' for p in compound_terms)
        
        if compound_detected:
            print(f"  ✅ Compound musical term detected!")
        else:
            print(f"  ❌ No compound musical term detected")
            success = False
        
        if success:
            print(f"  ✅ SUCCESS: {test_case['name']} detected correctly")
        else:
            print(f"  ❌ FAILED: {test_case['name']} not detected properly")
    
    print(f"\n=== Summary ===")
    print("This test verifies that single-character Roman numerals like 'I' and 'V'")
    print("are no longer filtered out by the word length check and can be detected")
    print("as part of compound musical terms like 'I chord' and 'V chord'.")

if __name__ == '__main__':
    test_single_character_roman_numerals() 