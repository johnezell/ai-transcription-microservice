#!/usr/bin/env python3
"""
Test single-character Roman numeral processing after removing length filter
"""

import sys
import json

try:
    from guitar_term_evaluator import GuitarTerminologyEvaluator
    print("✅ Successfully imported guitar term evaluator")
except ImportError as e:
    print(f"❌ Failed to import guitar term evaluator: {e}")
    sys.exit(1)

def test_single_roman_numerals():
    """Test if single-character Roman numerals are now processed correctly"""
    
    print("=== Testing Single-Character Roman Numerals ===")
    
    # Test cases with single-character Roman numerals
    test_cases = [
        {
            'description': 'Single "I chord"',
            'transcript': [
                {'word': 'I', 'confidence': 0.4, 'start': 0.0, 'end': 0.3},
                {'word': 'chord', 'confidence': 0.6, 'start': 0.4, 'end': 0.8}
            ]
        },
        {
            'description': 'Single "V chord"',
            'transcript': [
                {'word': 'V', 'confidence': 0.3, 'start': 0.0, 'end': 0.2},
                {'word': 'chord', 'confidence': 0.7, 'start': 0.3, 'end': 0.7}
            ]
        },
        {
            'description': 'Mixed single and double Roman numerals',
            'transcript': [
                {'word': 'play', 'confidence': 0.9, 'start': 0.0, 'end': 0.3},
                {'word': 'the', 'confidence': 0.8, 'start': 0.4, 'end': 0.6},
                {'word': 'I', 'confidence': 0.4, 'start': 0.7, 'end': 0.9},
                {'word': 'chord', 'confidence': 0.6, 'start': 1.0, 'end': 1.4},
                {'word': 'then', 'confidence': 0.8, 'start': 1.5, 'end': 1.8},
                {'word': 'IV', 'confidence': 0.3, 'start': 1.9, 'end': 2.2},
                {'word': 'chord', 'confidence': 0.8, 'start': 2.3, 'end': 2.7},
                {'word': 'and', 'confidence': 0.9, 'start': 2.8, 'end': 3.0},
                {'word': 'V', 'confidence': 0.35, 'start': 3.1, 'end': 3.3},
                {'word': 'chord', 'confidence': 0.7, 'start': 3.4, 'end': 3.8}
            ]
        }
    ]
    
    evaluator = GuitarTerminologyEvaluator()
    
    for i, test_case in enumerate(test_cases):
        print(f"\n--- Test Case {i+1}: {test_case['description']} ---")
        
        # Create the transcription_json format
        transcription_json = {
            'word_segments': test_case['transcript']
        }
        
        print(f"Input words: {[w['word'] for w in test_case['transcript']]}")
        print(f"Original confidences: {[w['confidence'] for w in test_case['transcript']]}")
        
        # Run the evaluator
        result = evaluator.evaluate_and_boost(transcription_json)
        
        # Analyze results
        enhanced_segments = result['word_segments']
        
        print("Results:")
        roman_chord_pairs = []
        
        for j, word in enumerate(enhanced_segments):
            original_conf = word.get('original_confidence', word['confidence'])
            current_conf = word['confidence']
            boost_reason = word.get('boost_reason', 'none')
            
            print(f"  {word['word']}: {original_conf:.2f} → {current_conf:.2f} ({boost_reason})")
            
            # Check for Roman numeral + chord pairs
            if j < len(enhanced_segments) - 1:
                current_word = word['word']
                next_word = enhanced_segments[j + 1]['word']
                
                if (current_word.upper() in ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII'] and 
                    next_word.lower() == 'chord'):
                    
                    current_boosted = current_conf == 1.0
                    next_boosted = enhanced_segments[j + 1]['confidence'] == 1.0
                    
                    roman_chord_pairs.append({
                        'phrase': f"{current_word} {next_word}",
                        'both_boosted': current_boosted and next_boosted,
                        'roman_boosted': current_boosted,
                        'chord_boosted': next_boosted
                    })
        
        # Check results for Roman numeral + chord pairs
        if roman_chord_pairs:
            print("\nRoman Numeral Chord Analysis:")
            for pair in roman_chord_pairs:
                if pair['both_boosted']:
                    print(f"  ✅ {pair['phrase']}: Both words boosted to 100%")
                else:
                    print(f"  ❌ {pair['phrase']}: Roman={pair['roman_boosted']}, Chord={pair['chord_boosted']}")
        else:
            print("\nNo Roman numeral + chord pairs found")
        
        # Check compound musical terms detection
        guitar_eval = result.get('guitar_term_evaluation', {})
        if 'musical_counting_patterns' in guitar_eval:
            compound_terms = guitar_eval['musical_counting_patterns']
            print(f"\nCompound musical patterns detected: {len(compound_terms)}")
            for pattern in compound_terms:
                if pattern.get('pattern_type') == 'roman_numeral_chord':
                    print(f"  - {pattern.get('description', 'Unknown pattern')}")

if __name__ == '__main__':
    test_single_roman_numerals() 