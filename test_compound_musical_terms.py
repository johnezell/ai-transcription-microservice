#!/usr/bin/env python3
"""
Test script to verify compound musical term detection (like "4 chord", "V chord", etc.)
"""

import sys
import os
import json
from typing import Dict, Any

# Add the transcription service to the Python path
sys.path.append('app/services/transcription')

try:
    from guitar_term_evaluator import GuitarTerminologyEvaluator, WordSegment, MusicalCountingPattern
    print("✅ Successfully imported guitar term evaluator")
except ImportError as e:
    print(f"❌ Failed to import guitar term evaluator: {e}")
    sys.exit(1)

def create_test_transcription_data(word_segments_data):
    """Create a mock transcription JSON structure"""
    return {
        "text": " ".join([w["word"] for w in word_segments_data]),
        "word_segments": word_segments_data,
        "segments": [
            {
                "start": 0.0,
                "end": 10.0,
                "text": " ".join([w["word"] for w in word_segments_data]),
                "words": word_segments_data
            }
        ]
    }

def test_compound_musical_terms():
    """Test compound musical terms that should be treated as units"""
    
    print("\n🎸 Testing Compound Musical Term Detection\n")
    
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    
    # Test Case 1: Roman numeral chord analysis
    print("Test Case 1: Roman numeral chord references")
    test_words_roman = [
        {"word": "play", "start": 0.0, "end": 0.3, "score": 0.8},
        {"word": "the", "start": 0.4, "end": 0.6, "score": 0.9},
        {"word": "4", "start": 0.7, "end": 1.0, "score": 0.5},  # Low confidence
        {"word": "chord", "start": 1.1, "end": 1.5, "score": 0.4},  # Low confidence
        {"word": "then", "start": 1.6, "end": 1.9, "score": 0.8},
        {"word": "the", "start": 2.0, "end": 2.2, "score": 0.9},
        {"word": "5", "start": 2.3, "end": 2.6, "score": 0.6},  # Low confidence
        {"word": "chord", "start": 2.7, "end": 3.1, "score": 0.3},  # Low confidence
        {"word": "and", "start": 3.2, "end": 3.4, "score": 0.8},
        {"word": "back", "start": 3.5, "end": 3.8, "score": 0.7},
        {"word": "to", "start": 3.9, "end": 4.1, "score": 0.9},
        {"word": "1", "start": 4.2, "end": 4.5, "score": 0.4},  # Low confidence
        {"word": "chord", "start": 4.6, "end": 5.0, "score": 0.5},  # Low confidence
    ]
    
    transcription_roman = create_test_transcription_data(test_words_roman)
    result_roman = evaluator.evaluate_and_boost(transcription_roman)
    
    enhanced_roman = result_roman.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_roman]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_roman]}")
    
    eval_data_roman = result_roman.get('guitar_term_evaluation', {})
    patterns_roman = eval_data_roman.get('musical_counting_patterns', [])
    print(f"Patterns found: {len(patterns_roman)}")
    for pattern in patterns_roman:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    
    # Show what boost reasons were applied
    boost_details = []
    for word in enhanced_roman:
        if word.get('guitar_term_boosted'):
            boost_reason = word.get('boost_reason', 'unknown')
            boost_details.append(f"{word['word']}:{boost_reason}")
    print(f"Boost reasons: {boost_details}")
    print()
    
    # Test Case 2: Other compound musical terms
    print("Test Case 2: Other compound musical terms")
    test_words_compound = [
        {"word": "this", "start": 0.0, "end": 0.3, "score": 0.8},
        {"word": "7", "start": 0.4, "end": 0.7, "score": 0.5},  # Should be part of "7 chord"
        {"word": "chord", "start": 0.8, "end": 1.2, "score": 0.4},
        {"word": "has", "start": 1.3, "end": 1.5, "score": 0.9},
        {"word": "a", "start": 1.6, "end": 1.7, "score": 0.9},
        {"word": "flat", "start": 1.8, "end": 2.1, "score": 0.6},  # Should be part of "flat 7"
        {"word": "7", "start": 2.2, "end": 2.5, "score": 0.3},
        {"word": "and", "start": 2.6, "end": 2.8, "score": 0.8},
        {"word": "sharp", "start": 2.9, "end": 3.3, "score": 0.4},  # Should be part of "sharp 11"
        {"word": "11", "start": 3.4, "end": 3.8, "score": 0.5},
    ]
    
    transcription_compound = create_test_transcription_data(test_words_compound)
    result_compound = evaluator.evaluate_and_boost(transcription_compound)
    
    enhanced_compound = result_compound.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_compound]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_compound]}")
    
    eval_data_compound = result_compound.get('guitar_term_evaluation', {})
    patterns_compound = eval_data_compound.get('musical_counting_patterns', [])
    print(f"Patterns found: {len(patterns_compound)}")
    for pattern in patterns_compound:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    
    boost_details_compound = []
    for word in enhanced_compound:
        if word.get('guitar_term_boosted'):
            boost_reason = word.get('boost_reason', 'unknown')
            boost_details_compound.append(f"{word['word']}:{boost_reason}")
    print(f"Boost reasons: {boost_details_compound}")
    print()
    
    # Test Case 3: Chord quality + number combinations
    print("Test Case 3: Chord quality + number combinations")
    test_words_quality = [
        {"word": "play", "start": 0.0, "end": 0.3, "score": 0.8},
        {"word": "a", "start": 0.4, "end": 0.5, "score": 0.9},
        {"word": "minor", "start": 0.6, "end": 1.0, "score": 0.6},  # Should be part of "minor 7"
        {"word": "7", "start": 1.1, "end": 1.4, "score": 0.4},
        {"word": "chord", "start": 1.5, "end": 1.9, "score": 0.5},
        {"word": "then", "start": 2.0, "end": 2.3, "score": 0.8},
        {"word": "major", "start": 2.4, "end": 2.8, "score": 0.7},  # Should be part of "major 9"
        {"word": "9", "start": 2.9, "end": 3.2, "score": 0.3},
        {"word": "chord", "start": 3.3, "end": 3.7, "score": 0.4},
    ]
    
    transcription_quality = create_test_transcription_data(test_words_quality)
    result_quality = evaluator.evaluate_and_boost(transcription_quality)
    
    enhanced_quality = result_quality.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_quality]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_quality]}")
    
    eval_data_quality = result_quality.get('guitar_term_evaluation', {})
    total_enhanced = eval_data_quality.get('total_enhanced_words', 0)
    print(f"Total enhanced words: {total_enhanced}")
    
    boost_details_quality = []
    for word in enhanced_quality:
        if word.get('guitar_term_boosted'):
            boost_reason = word.get('boost_reason', 'unknown')
            boost_details_quality.append(f"{word['word']}:{boost_reason}")
    print(f"Boost reasons: {boost_details_quality}")
    print()
    
    print("✅ Compound musical term tests completed!")
    print("\n🔍 ISSUE ANALYSIS:")
    print("Current system treats '4 chord', '5 chord', '7 chord' as separate words")
    print("Numbers get detected as counting patterns, 'chord' as guitar term")
    print("Missing: Compound musical term detection for Roman numeral analysis")
    
    return True

def main():
    """Run compound musical term tests"""
    print("🎸 Compound Musical Term Detection Test Suite")
    print("=" * 60)
    
    try:
        test_compound_musical_terms()
        print("\n✅ Tests completed - Issue confirmed!")
        print("\nNEEDED FIX: Add compound musical term detection for:")
        print("  - Roman numeral chords: '4 chord', '5 chord', '1 chord'")
        print("  - Extended chords: '7 chord', 'minor 7', 'major 9'")
        print("  - Altered chords: 'flat 7', 'sharp 11', 'flat 5'")
        return True
    except Exception as e:
        print(f"\n❌ Test failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 