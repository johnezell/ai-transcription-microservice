#!/usr/bin/env python3
"""
Debug test to trace original_confidence values through the guitar term evaluator
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

def debug_confidence_flow():
    """Debug the flow of original_confidence values"""
    
    print("🔍 Debugging Original Confidence Flow")
    print("=" * 50)
    
    # Create test data with problematic original_confidence: 0 values
    test_transcript = {
        "text": "IV chord",
        "word_segments": [
            {"word": "IV", "start": 0.0, "end": 0.3, "score": 0.30, "original_confidence": 0},
            {"word": "chord", "start": 0.4, "end": 0.8, "score": 0.40, "original_confidence": 0},
        ],
        "segments": [
            {
                "start": 0.0,
                "end": 0.8,
                "text": "IV chord",
                "words": [
                    {"word": "IV", "start": 0.0, "end": 0.3, "score": 0.30, "original_confidence": 0},
                    {"word": "chord", "start": 0.4, "end": 0.8, "score": 0.40, "original_confidence": 0},
                ]
            }
        ]
    }
    
    print("📊 Input JSON:")
    for word in test_transcript["word_segments"]:
        print(f"  {word['word']}: score={word['score']}, original_confidence={word.get('original_confidence', 'MISSING')}")
    
    # Create evaluator and manually step through the process
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    
    print("\n🔄 Step 1: Extract WordSegments from JSON")
    segments = evaluator.extract_words_from_json(test_transcript)
    
    for i, seg in enumerate(segments):
        print(f"  Segment {i}: word='{seg.word}', confidence={seg.confidence}, original_confidence={seg.original_confidence}")
    
    print("\n🔄 Step 2: Detect Musical Patterns")
    patterns = evaluator._detect_musical_counting_patterns(segments)
    print(f"  Found {len(patterns)} patterns:")
    for pattern in patterns:
        print(f"    - {pattern.pattern_type}: {pattern.words} (indices {pattern.start_index}-{pattern.end_index})")
    
    print("\n🔄 Step 3: Apply Pattern Boosts")
    boosted_count = evaluator._apply_counting_pattern_boosts(segments, patterns)
    print(f"  Boosted {boosted_count} words")
    
    for i, seg in enumerate(segments):
        print(f"  Segment {i} after boost: word='{seg.word}', confidence={seg.confidence}, original_confidence={seg.original_confidence}")
    
    print("\n🔄 Step 4: Create Enhanced Terms Array")
    evaluated_terms = []
    
    # Manually create the enhanced terms like the actual code does
    for pattern in patterns:
        for i in range(pattern.start_index, pattern.end_index + 1):
            if i < len(segments):
                segment = segments[i]
                term_data = {
                    'word': segment.word,
                    'normalized_word': evaluator._normalize_word(segment.word),
                    'original_confidence': segment.original_confidence,
                    'new_confidence': segment.confidence,
                    'start': segment.start,
                    'end': segment.end,
                    'boost_reason': 'musical_counting_pattern',
                    'pattern_type': pattern.pattern_type,
                    'pattern_description': pattern.description
                }
                evaluated_terms.append(term_data)
                print(f"  Created term: {term_data}")
    
    print("\n🔄 Step 5: Check Final Enhanced Terms")
    for term in evaluated_terms:
        print(f"  Final term: {term['word']} -> original={term['original_confidence']}, new={term['new_confidence']}")
    
    # Now check what the actual evaluate_and_boost method returns
    print("\n🔄 Step 6: Full evaluate_and_boost() Method")
    result = evaluator.evaluate_and_boost(test_transcript)
    eval_data = result.get('guitar_term_evaluation', {})
    actual_enhanced_terms = eval_data.get('enhanced_terms', [])
    
    print(f"  Actual enhanced terms ({len(actual_enhanced_terms)}):")
    for term in actual_enhanced_terms:
        print(f"    {term['word']}: original={term['original_confidence']}, new={term['new_confidence']}, reason={term['boost_reason']}")
    
    return True

def main():
    """Run the debug test"""
    print("🔍 Original Confidence Flow Debug")
    print("=" * 40)
    
    try:
        success = debug_confidence_flow()
        return success
    except Exception as e:
        print(f"\n❌ Debug failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 