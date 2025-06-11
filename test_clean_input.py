#!/usr/bin/env python3
"""
Test with clean input data (no original_confidence field) to see if system works properly
"""

import sys
import os
import json

# Add the transcription service to the Python path
sys.path.append('app/services/transcription')

try:
    from guitar_term_evaluator import GuitarTerminologyEvaluator, WordSegment
    print("✅ Successfully imported guitar term evaluator")
except ImportError as e:
    print(f"❌ Failed to import guitar term evaluator: {e}")
    sys.exit(1)

def test_clean_input():
    """Test with clean input data (no original_confidence field)"""
    
    print("🎸 Testing with Clean Input Data")
    print("=" * 40)
    
    # Create test data WITHOUT any original_confidence field
    test_transcript = {
        "text": "IV chord progression",
        "word_segments": [
            {"word": "IV", "start": 0.0, "end": 0.3, "score": 0.30},      # No original_confidence field
            {"word": "chord", "start": 0.4, "end": 0.8, "score": 0.40},  # No original_confidence field  
            {"word": "progression", "start": 0.9, "end": 1.5, "score": 0.35},  # No original_confidence field
        ],
        "segments": [
            {
                "start": 0.0,
                "end": 1.5,
                "text": "IV chord progression",
                "words": [
                    {"word": "IV", "start": 0.0, "end": 0.3, "score": 0.30},
                    {"word": "chord", "start": 0.4, "end": 0.8, "score": 0.40},
                    {"word": "progression", "start": 0.9, "end": 1.5, "score": 0.35},
                ]
            }
        ]
    }
    
    print("📊 Input Data (clean - no original_confidence field):")
    for word in test_transcript["word_segments"]:
        print(f"  {word['word']}: score={word['score']}, original_confidence={word.get('original_confidence', 'MISSING')}")
    
    # Test guitar term evaluator
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    
    # Process with guitar term evaluator
    result = evaluator.evaluate_and_boost(test_transcript)
    
    print("\n🔍 Enhanced Terms Data:")
    eval_data = result.get('guitar_term_evaluation', {})
    enhanced_terms = eval_data.get('enhanced_terms', [])
    
    success = True
    
    if not enhanced_terms:
        print("❌ ERROR: No enhanced_terms found in result!")
        success = False
    else:
        for term in enhanced_terms:
            word = term.get('word', 'UNKNOWN')
            original = term.get('original_confidence', 'MISSING')
            current = term.get('new_confidence', 'MISSING')
            boost_reason = term.get('boost_reason', 'none')
            
            print(f"  {word}: original={original}, new={current}, reason={boost_reason}")
            
            # Check that original_confidence is meaningful (not 0 or null)
            if original == 0 or original is None or original == 'MISSING':
                print(f"    ❌ ERROR: Original confidence is 0/null for '{word}'")
                success = False
            elif original > 0:
                print(f"    ✅ SUCCESS: Original confidence properly preserved for '{word}' ({original})")
    
    print(f"\n📈 Summary:")
    print(f"  Enhanced terms found: {len(enhanced_terms)}")
    print(f"  Musical terms: {eval_data.get('musical_terms_found', 0)}")
    print(f"  Musical counting words: {eval_data.get('musical_counting_words_found', 0)}")
    print(f"  Total enhanced: {eval_data.get('total_enhanced_words', 0)}")
    
    if success:
        print("\n✅ SUCCESS: Clean input data works correctly!")
    else:
        print("\n❌ FAILURE: Even clean input data has issues!")
    
    return success

def main():
    """Run the test"""
    print("🎸 Clean Input Data Test")
    print("=" * 30)
    
    try:
        success = test_clean_input()
        return success
    except Exception as e:
        print(f"\n❌ Test failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 