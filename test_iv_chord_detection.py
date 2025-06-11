#!/usr/bin/env python3
"""
Test script to debug IV chord detection in guitar term evaluator
"""

import sys
import os

# Add the transcription service to the Python path
sys.path.append('app/services/transcription')

try:
    from guitar_term_evaluator import GuitarTerminologyEvaluator, WordSegment
    print("✅ Successfully imported guitar term evaluator")
except ImportError as e:
    print(f"❌ Failed to import guitar term evaluator: {e}")
    sys.exit(1)

def test_iv_chord_detection():
    """Test IV chord detection specifically"""
    
    print("\n🎸 Testing IV Chord Detection\n")
    
    # Create test data with "IV chord"
    test_words = [
        {"word": "here's", "start": 0.0, "end": 0.5, "score": 0.8},
        {"word": "a", "start": 0.6, "end": 0.8, "score": 0.9},
        {"word": "lick", "start": 0.9, "end": 1.2, "score": 0.7},
        {"word": "for", "start": 1.3, "end": 1.5, "score": 0.9},
        {"word": "the", "start": 1.6, "end": 1.8, "score": 0.9},
        {"word": "IV", "start": 1.9, "end": 2.2, "score": 0.3},  # Low confidence
        {"word": "chord", "start": 2.3, "end": 2.7, "score": 0.4},  # Low confidence
    ]
    
    transcription_data = {
        "text": " ".join([w["word"] for w in test_words]),
        "word_segments": test_words,
        "segments": [
            {
                "start": 0.0,
                "end": 3.0,
                "text": " ".join([w["word"] for w in test_words]),
                "words": test_words
            }
        ]
    }
    
    print(f"Test input: {[w['word'] for w in test_words]}")
    print(f"IV confidence: {test_words[5]['score']}, chord confidence: {test_words[6]['score']}")
    
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    
    # Convert to WordSegment objects
    segments = evaluator.extract_words_from_json(transcription_data)
    print(f"\nExtracted {len(segments)} word segments")
    
    # Test the _is_chord_number method directly
    iv_word = segments[5].word  # "IV"
    chord_word = segments[6].word  # "chord"
    
    print(f"\nTesting _is_chord_number('{iv_word}'): {evaluator._is_chord_number(iv_word)}")
    print(f"Testing _is_chord_number('IV'): {evaluator._is_chord_number('IV')}")
    print(f"Testing _is_chord_number('iv'): {evaluator._is_chord_number('iv')}")
    print(f"Testing _is_chord_number('4'): {evaluator._is_chord_number('4')}")
    
    # Test compound musical term detection directly
    print(f"\nTesting compound musical term detection...")
    for i, segment in enumerate(segments):
        if segment.word == "IV":
            print(f"Found IV at index {i}")
            compound_pattern = evaluator._check_compound_musical_terms(segments, i)
            if compound_pattern:
                print(f"✅ Detected compound pattern: {compound_pattern.pattern_type}")
                print(f"   Words: {compound_pattern.words}")
                print(f"   Description: {compound_pattern.description}")
            else:
                print(f"❌ No compound pattern detected for IV at index {i}")
                
                # Test Roman numeral chord detection directly
                roman_pattern = evaluator._check_roman_numeral_chord(segments, i)
                if roman_pattern:
                    print(f"✅ Roman numeral pattern detected: {roman_pattern}")
                else:
                    print(f"❌ Roman numeral pattern NOT detected")
                    # Debug why
                    if i + 1 < len(segments):
                        next_word = segments[i + 1].word
                        print(f"   Next word: '{next_word}' (lowercase: '{next_word.lower()}')")
                        print(f"   Is next word 'chord'? {next_word.lower() == 'chord'}")
    
    # Test full enhancement
    print(f"\n" + "="*50)
    print("FULL ENHANCEMENT TEST")
    print("="*50)
    
    result = evaluator.evaluate_and_boost(transcription_data)
    enhanced_words = result.get('word_segments', [])
    
    print(f"\nOriginal vs Enhanced confidence:")
    for i, (original, enhanced) in enumerate(zip(test_words, enhanced_words)):
        print(f"  {original['word']}: {original['score']:.2f} → {enhanced.get('score', 0):.2f}")
    
    # Check evaluation metadata
    eval_data = result.get('guitar_term_evaluation', {})
    print(f"\nEvaluation metadata:")
    print(f"  Musical terms found: {eval_data.get('musical_terms_found', 0)}")
    print(f"  Musical counting words found: {eval_data.get('musical_counting_words_found', 0)}")
    print(f"  Total enhanced words: {eval_data.get('total_enhanced_words', 0)}")
    
    # Check patterns
    patterns = eval_data.get('musical_counting_patterns', [])
    print(f"  Patterns detected: {len(patterns)}")
    for pattern in patterns:
        print(f"    - {pattern['pattern_type']}: {pattern['words']}")

def test_various_roman_numerals():
    """Test various Roman numeral formats"""
    
    print("\n🎸 Testing Various Roman Numeral Formats\n")
    
    test_cases = [
        ["I", "chord"],
        ["IV", "chord"], 
        ["V", "chord"],
        ["vi", "chord"],
        ["VII", "chord"],
        ["4", "chord"],
        ["5", "chord"],
        ["1", "chord"]
    ]
    
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    
    for case in test_cases:
        test_words = [
            {"word": case[0], "start": 0.0, "end": 0.5, "score": 0.3},
            {"word": case[1], "start": 0.6, "end": 1.0, "score": 0.4}
        ]
        
        transcription_data = {
            "text": " ".join(case),
            "word_segments": test_words,
            "segments": [{"start": 0.0, "end": 1.0, "text": " ".join(case), "words": test_words}]
        }
        
        result = evaluator.evaluate_and_boost(transcription_data)
        enhanced_words = result.get('word_segments', [])
        
        # Check if both words were boosted
        boosted = all(w.get('score', 0) == 1.0 for w in enhanced_words)
        status = "✅" if boosted else "❌"
        
        print(f"{status} {case[0]} {case[1]}: {test_words[0]['score']:.1f}, {test_words[1]['score']:.1f} → {enhanced_words[0].get('score', 0):.1f}, {enhanced_words[1].get('score', 0):.1f}")

def main():
    """Run all tests"""
    print("🎸 IV Chord Detection Debug Test Suite")
    print("=" * 60)
    
    try:
        test_iv_chord_detection()
        test_various_roman_numerals()
        print("\n✅ All tests completed!")
        return True
    except Exception as e:
        print(f"\n❌ Test failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 