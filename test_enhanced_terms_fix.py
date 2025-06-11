#!/usr/bin/env python3
"""
Test script to verify enhanced_terms shows correct original_confidence values after fix
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

def test_enhanced_terms_data():
    """Test that enhanced_terms shows correct original_confidence values"""
    
    print("🎸 Testing Enhanced Terms Data Fix")
    print("=" * 45)
    
    # Create test data with problematic original_confidence: 0 values 
    # (simulating what might come from the transcription service after filtering)
    test_transcript = {
        "text": "play the IV chord progression",
        "word_segments": [
            {"word": "play", "start": 0.0, "end": 0.5, "score": 0.85, "original_confidence": 0},  # Bad original_confidence
            {"word": "the", "start": 0.6, "end": 0.8, "score": 0.92, "original_confidence": 0},   # Bad original_confidence
            {"word": "IV", "start": 0.9, "end": 1.2, "score": 0.30, "original_confidence": 0},   # Bad original_confidence  
            {"word": "chord", "start": 1.3, "end": 1.7, "score": 0.40, "original_confidence": 0}, # Bad original_confidence
            {"word": "progression", "start": 1.8, "end": 2.5, "score": 0.35, "original_confidence": 0}, # Bad original_confidence
        ],
        "segments": [
            {
                "start": 0.0,
                "end": 2.5,
                "text": "play the IV chord progression",
                "words": [
                    {"word": "play", "start": 0.0, "end": 0.5, "score": 0.85, "original_confidence": 0},
                    {"word": "the", "start": 0.6, "end": 0.8, "score": 0.92, "original_confidence": 0},
                    {"word": "IV", "start": 0.9, "end": 1.2, "score": 0.30, "original_confidence": 0},
                    {"word": "chord", "start": 1.3, "end": 1.7, "score": 0.40, "original_confidence": 0},
                    {"word": "progression", "start": 1.8, "end": 2.5, "score": 0.35, "original_confidence": 0},
                ]
            }
        ]
    }
    
    print("📊 Input Data (with problematic original_confidence: 0):")
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
                print(f"    ❌ ERROR: Original confidence is still 0/null for '{word}'")
                success = False
            elif original > 0:
                print(f"    ✅ SUCCESS: Original confidence properly preserved for '{word}' ({original})")
    
    print(f"\n📈 Summary:")
    print(f"  Enhanced terms found: {len(enhanced_terms)}")
    print(f"  Musical terms: {eval_data.get('musical_terms_found', 0)}")
    print(f"  Musical counting words: {eval_data.get('musical_counting_words_found', 0)}")
    print(f"  Total enhanced: {eval_data.get('total_enhanced_words', 0)}")
    
    # Check specific IV chord terms
    print(f"\n🎯 IV Chord Analysis:")
    iv_found = False
    chord_found = False
    
    for term in enhanced_terms:
        if term.get('word') == 'IV':
            iv_found = True
            print(f"  IV: original={term.get('original_confidence')}, new={term.get('new_confidence')}, reason={term.get('boost_reason')}")
        elif term.get('word') == 'chord':
            chord_found = True
            print(f"  chord: original={term.get('original_confidence')}, new={term.get('new_confidence')}, reason={term.get('boost_reason')}")
    
    if not iv_found:
        print("  ❌ ERROR: IV not found in enhanced terms")
        success = False
    if not chord_found:
        print("  ❌ ERROR: chord not found in enhanced terms") 
        success = False
    
    if success:
        print("\n✅ SUCCESS: Enhanced terms data now shows correct original confidence values!")
    else:
        print("\n❌ FAILURE: Enhanced terms data still has issues with original confidence!")
    
    return success

def main():
    """Run the test"""
    print("🎸 Enhanced Terms Data Fix Test")
    print("=" * 40)
    
    try:
        success = test_enhanced_terms_data()
        return success
    except Exception as e:
        print(f"\n❌ Test failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 