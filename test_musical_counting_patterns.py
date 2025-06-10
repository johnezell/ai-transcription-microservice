#!/usr/bin/env python3
"""
Test script for musical counting pattern detection in guitar term evaluator
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

def test_counting_pattern_detection():
    """Test various musical counting patterns"""
    
    print("\n🎸 Testing Musical Counting Pattern Detection\n")
    
    # Test Case 1: Basic "1, 2, 3, 4, 5" pattern
    print("Test Case 1: Basic counting pattern")
    test_words_1 = [
        {"word": "1", "start": 0.0, "end": 0.5, "score": 0.6},
        {"word": "2", "start": 0.6, "end": 1.1, "score": 0.5},
        {"word": "3", "start": 1.2, "end": 1.7, "score": 0.4},
        {"word": "4", "start": 1.8, "end": 2.3, "score": 0.5},
        {"word": "5", "start": 2.4, "end": 2.9, "score": 0.6},
    ]
    
    transcription_1 = create_test_transcription_data(test_words_1)
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    
    # Convert to WordSegment objects to test pattern detection directly
    segments = evaluator.extract_words_from_json(transcription_1)
    patterns = evaluator._detect_musical_counting_patterns(segments)
    
    print(f"Original confidence scores: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_1]}")
    print(f"Detected patterns: {len(patterns)}")
    for pattern in patterns:
        print(f"  - {pattern.pattern_type}: {pattern.words} ({pattern.description})")
    
    # Test full enhancement
    result_1 = evaluator.evaluate_and_boost(transcription_1)
    enhanced_words = result_1.get('word_segments', [])
    print(f"Enhanced confidence scores: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_words]}")
    
    eval_data = result_1.get('guitar_term_evaluation', {})
    print(f"Musical counting words found: {eval_data.get('musical_counting_words_found', 0)}")
    print(f"Patterns detected: {eval_data.get('pattern_statistics', {}).get('total_patterns_found', 0)}")
    print()
    
    # Test Case 2: Word form numbers
    print("Test Case 2: Word form counting")
    test_words_2 = [
        {"word": "one", "start": 0.0, "end": 0.5, "score": 0.7},
        {"word": "two", "start": 0.6, "end": 1.1, "score": 0.6},
        {"word": "three", "start": 1.2, "end": 1.7, "score": 0.5},
        {"word": "four", "start": 1.8, "end": 2.3, "score": 0.4},
    ]
    
    transcription_2 = create_test_transcription_data(test_words_2)
    result_2 = evaluator.evaluate_and_boost(transcription_2)
    
    enhanced_words_2 = result_2.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_2]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_words_2]}")
    
    eval_data_2 = result_2.get('guitar_term_evaluation', {})
    print(f"Musical counting words found: {eval_data_2.get('musical_counting_words_found', 0)}")
    print()
    
    # Test Case 3: Counting with context
    print("Test Case 3: Counting with musical context")
    test_words_3 = [
        {"word": "ok", "start": 0.0, "end": 0.3, "score": 0.9},
        {"word": "1", "start": 0.4, "end": 0.8, "score": 0.6},
        {"word": "2", "start": 0.9, "end": 1.3, "score": 0.5},
        {"word": "and", "start": 1.4, "end": 1.6, "score": 0.8},
        {"word": "ready", "start": 1.7, "end": 2.1, "score": 0.7},
        {"word": "go", "start": 2.2, "end": 2.5, "score": 0.8},
    ]
    
    transcription_3 = create_test_transcription_data(test_words_3)
    result_3 = evaluator.evaluate_and_boost(transcription_3)
    
    enhanced_words_3 = result_3.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_3]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_words_3]}")
    
    eval_data_3 = result_3.get('guitar_term_evaluation', {})
    print(f"Musical counting words found: {eval_data_3.get('musical_counting_words_found', 0)}")
    print()
    
    # Test Case 4: Mixed with guitar terms
    print("Test Case 4: Counting mixed with guitar terms")
    test_words_4 = [
        {"word": "play", "start": 0.0, "end": 0.4, "score": 0.8},
        {"word": "chord", "start": 0.5, "end": 0.9, "score": 0.4},  # Low confidence guitar term
        {"word": "1", "start": 1.0, "end": 1.3, "score": 0.6},
        {"word": "2", "start": 1.4, "end": 1.7, "score": 0.5},
        {"word": "3", "start": 1.8, "end": 2.1, "score": 0.4},
        {"word": "4", "start": 2.2, "end": 2.5, "score": 0.5},
        {"word": "fretboard", "start": 2.6, "end": 3.2, "score": 0.3},  # Low confidence guitar term
    ]
    
    transcription_4 = create_test_transcription_data(test_words_4)
    result_4 = evaluator.evaluate_and_boost(transcription_4)
    
    enhanced_words_4 = result_4.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_4]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_words_4]}")
    
    eval_data_4 = result_4.get('guitar_term_evaluation', {})
    print(f"Guitar terms found: {eval_data_4.get('musical_terms_found', 0)}")
    print(f"Musical counting words found: {eval_data_4.get('musical_counting_words_found', 0)}")
    print(f"Total enhanced: {eval_data_4.get('total_enhanced_words', 0)}")
    
    # Show boost reasons
    boost_details = []
    for word in enhanced_words_4:
        if word.get('guitar_term_boosted'):
            boost_reason = word.get('boost_reason', 'unknown')
            boost_details.append(f"{word['word']}:{boost_reason}")
    print(f"Boost reasons: {boost_details}")
    print()
    
    # Test Case 5: Non-musical numbers (should not boost)
    print("Test Case 5: Non-musical isolated numbers")
    test_words_5 = [
        {"word": "this", "start": 0.0, "end": 0.3, "score": 0.9},
        {"word": "is", "start": 0.4, "end": 0.6, "score": 0.8},
        {"word": "lesson", "start": 0.7, "end": 1.1, "score": 0.7},
        {"word": "5", "start": 1.2, "end": 1.5, "score": 0.6},  # Isolated number
        {"word": "about", "start": 1.6, "end": 1.9, "score": 0.8},
        {"word": "guitar", "start": 2.0, "end": 2.4, "score": 0.7},
    ]
    
    transcription_5 = create_test_transcription_data(test_words_5)
    result_5 = evaluator.evaluate_and_boost(transcription_5)
    
    enhanced_words_5 = result_5.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_5]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_words_5]}")
    
    eval_data_5 = result_5.get('guitar_term_evaluation', {})
    print(f"Musical counting words found: {eval_data_5.get('musical_counting_words_found', 0)} (should be 0)")
    print()
    
    print("✅ Musical counting pattern detection tests completed!")
    
    return True

def test_musical_instruction_patterns():
    """Test various musical instruction patterns beyond counting"""
    
    print("\n🎸 Testing Advanced Musical Instruction Patterns\n")
    
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    
    # Test Case 1: Rhythm vocalization patterns
    print("Test Case 1: Rhythm vocalization patterns")
    test_words_rhythm = [
        {"word": "da", "start": 0.0, "end": 0.3, "score": 0.5},
        {"word": "da", "start": 0.4, "end": 0.7, "score": 0.4},
        {"word": "dum", "start": 0.8, "end": 1.2, "score": 0.3},
        {"word": "boom", "start": 1.3, "end": 1.6, "score": 0.6},
        {"word": "chick", "start": 1.7, "end": 2.0, "score": 0.4},
        {"word": "boom", "start": 2.1, "end": 2.4, "score": 0.5},
        {"word": "chick", "start": 2.5, "end": 2.8, "score": 0.4},
    ]
    
    transcription_rhythm = create_test_transcription_data(test_words_rhythm)
    result_rhythm = evaluator.evaluate_and_boost(transcription_rhythm)
    
    enhanced_rhythm = result_rhythm.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_rhythm]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_rhythm]}")
    
    eval_data_rhythm = result_rhythm.get('guitar_term_evaluation', {})
    patterns_rhythm = eval_data_rhythm.get('musical_counting_patterns', [])
    print(f"Patterns found: {len(patterns_rhythm)}")
    for pattern in patterns_rhythm:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    print()
    
    # Test Case 2: Strumming patterns
    print("Test Case 2: Strumming patterns")
    test_words_strum = [
        {"word": "down", "start": 0.0, "end": 0.4, "score": 0.6},
        {"word": "up", "start": 0.5, "end": 0.8, "score": 0.5},
        {"word": "down", "start": 0.9, "end": 1.3, "score": 0.4},
        {"word": "up", "start": 1.4, "end": 1.7, "score": 0.5},
        {"word": "down", "start": 1.8, "end": 2.2, "score": 0.6},
        {"word": "down", "start": 2.3, "end": 2.7, "score": 0.4},
    ]
    
    transcription_strum = create_test_transcription_data(test_words_strum)
    result_strum = evaluator.evaluate_and_boost(transcription_strum)
    
    enhanced_strum = result_strum.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_strum]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_strum]}")
    
    eval_data_strum = result_strum.get('guitar_term_evaluation', {})
    patterns_strum = eval_data_strum.get('musical_counting_patterns', [])
    print(f"Strumming patterns found: {len(patterns_strum)}")
    for pattern in patterns_strum:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    print()
    
    # Test Case 3: Note/chord sequences
    print("Test Case 3: Note/chord sequences")
    test_words_notes = [
        {"word": "C", "start": 0.0, "end": 0.3, "score": 0.7},
        {"word": "G", "start": 0.4, "end": 0.7, "score": 0.5},
        {"word": "Am", "start": 0.8, "end": 1.2, "score": 0.4},
        {"word": "F", "start": 1.3, "end": 1.6, "score": 0.6},
        {"word": "do", "start": 2.0, "end": 2.3, "score": 0.3},
        {"word": "re", "start": 2.4, "end": 2.7, "score": 0.4},
        {"word": "mi", "start": 2.8, "end": 3.1, "score": 0.5},
    ]
    
    transcription_notes = create_test_transcription_data(test_words_notes)
    result_notes = evaluator.evaluate_and_boost(transcription_notes)
    
    enhanced_notes = result_notes.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_notes]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_notes]}")
    
    eval_data_notes = result_notes.get('guitar_term_evaluation', {})
    patterns_notes = eval_data_notes.get('musical_counting_patterns', [])
    print(f"Note sequence patterns found: {len(patterns_notes)}")
    for pattern in patterns_notes:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    print()
    
    # Test Case 4: Fingerpicking patterns
    print("Test Case 4: Fingerpicking patterns")
    test_words_finger = [
        {"word": "thumb", "start": 0.0, "end": 0.4, "score": 0.6},
        {"word": "index", "start": 0.5, "end": 0.9, "score": 0.4},
        {"word": "middle", "start": 1.0, "end": 1.4, "score": 0.3},
        {"word": "ring", "start": 1.5, "end": 1.8, "score": 0.5},
        {"word": "T", "start": 2.0, "end": 2.2, "score": 0.4},
        {"word": "I", "start": 2.3, "end": 2.5, "score": 0.3},
        {"word": "M", "start": 2.6, "end": 2.8, "score": 0.5},
        {"word": "R", "start": 2.9, "end": 3.1, "score": 0.4},
    ]
    
    transcription_finger = create_test_transcription_data(test_words_finger)
    result_finger = evaluator.evaluate_and_boost(transcription_finger)
    
    enhanced_finger = result_finger.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_finger]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_finger]}")
    
    eval_data_finger = result_finger.get('guitar_term_evaluation', {})
    patterns_finger = eval_data_finger.get('musical_counting_patterns', [])
    print(f"Fingerpicking patterns found: {len(patterns_finger)}")
    for pattern in patterns_finger:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    print()
    
    # Test Case 5: Timing/metronome patterns
    print("Test Case 5: Timing/metronome patterns")
    test_words_timing = [
        {"word": "tick", "start": 0.0, "end": 0.2, "score": 0.5},
        {"word": "tick", "start": 0.3, "end": 0.5, "score": 0.4},
        {"word": "tick", "start": 0.6, "end": 0.8, "score": 0.6},
        {"word": "tick", "start": 0.9, "end": 1.1, "score": 0.3},
        {"word": "click", "start": 1.5, "end": 1.7, "score": 0.4},
        {"word": "click", "start": 1.8, "end": 2.0, "score": 0.5},
        {"word": "click", "start": 2.1, "end": 2.3, "score": 0.4},
    ]
    
    transcription_timing = create_test_transcription_data(test_words_timing)
    result_timing = evaluator.evaluate_and_boost(transcription_timing)
    
    enhanced_timing = result_timing.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_timing]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_timing]}")
    
    eval_data_timing = result_timing.get('guitar_term_evaluation', {})
    patterns_timing = eval_data_timing.get('musical_counting_patterns', [])
    print(f"Timing patterns found: {len(patterns_timing)}")
    for pattern in patterns_timing:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    print()
    
    # Test Case 6: Guitar effect sound patterns
    print("Test Case 6: Guitar effect sound patterns")
    test_words_effects = [
        {"word": "wah", "start": 0.0, "end": 0.3, "score": 0.4},
        {"word": "wah", "start": 0.4, "end": 0.7, "score": 0.3},
        {"word": "wah", "start": 0.8, "end": 1.1, "score": 0.5},
        {"word": "ring", "start": 1.5, "end": 1.8, "score": 0.6},
        {"word": "ring", "start": 1.9, "end": 2.2, "score": 0.4},
        {"word": "buzz", "start": 2.5, "end": 2.8, "score": 0.3},
        {"word": "buzz", "start": 2.9, "end": 3.2, "score": 0.5},
    ]
    
    transcription_effects = create_test_transcription_data(test_words_effects)
    result_effects = evaluator.evaluate_and_boost(transcription_effects)
    
    enhanced_effects = result_effects.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_effects]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_effects]}")
    
    eval_data_effects = result_effects.get('guitar_term_evaluation', {})
    patterns_effects = eval_data_effects.get('musical_counting_patterns', [])
    print(f"Effect sound patterns found: {len(patterns_effects)}")
    for pattern in patterns_effects:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    print()
    
    # Test Case 7: Mixed patterns with regular content
    print("Test Case 7: Mixed patterns with regular words")
    test_words_mixed = [
        {"word": "now", "start": 0.0, "end": 0.3, "score": 0.8},
        {"word": "let's", "start": 0.4, "end": 0.7, "score": 0.7},
        {"word": "try", "start": 0.8, "end": 1.0, "score": 0.9},
        {"word": "da", "start": 1.1, "end": 1.3, "score": 0.4},
        {"word": "da", "start": 1.4, "end": 1.6, "score": 0.3},
        {"word": "dum", "start": 1.7, "end": 2.0, "score": 0.5},
        {"word": "with", "start": 2.1, "end": 2.4, "score": 0.8},
        {"word": "down", "start": 2.5, "end": 2.8, "score": 0.6},
        {"word": "up", "start": 2.9, "end": 3.1, "score": 0.4},
        {"word": "down", "start": 3.2, "end": 3.5, "score": 0.5},
        {"word": "strumming", "start": 3.6, "end": 4.1, "score": 0.7},
    ]
    
    transcription_mixed = create_test_transcription_data(test_words_mixed)
    result_mixed = evaluator.evaluate_and_boost(transcription_mixed)
    
    enhanced_mixed = result_mixed.get('word_segments', [])
    print(f"Original: {[f'{w['word']}:{w['score']:.1f}' for w in test_words_mixed]}")
    print(f"Enhanced: {[f'{w['word']}:{w.get('score', 0):.1f}' for w in enhanced_mixed]}")
    
    eval_data_mixed = result_mixed.get('guitar_term_evaluation', {})
    total_enhanced = eval_data_mixed.get('total_enhanced_words', 0)
    guitar_terms = eval_data_mixed.get('musical_terms_found', 0)
    counting_words = eval_data_mixed.get('musical_counting_words_found', 0)
    patterns_mixed = eval_data_mixed.get('musical_counting_patterns', [])
    
    print(f"Total enhanced: {total_enhanced} ({guitar_terms} guitar terms + {counting_words} pattern words)")
    print(f"Mixed patterns found: {len(patterns_mixed)}")
    for pattern in patterns_mixed:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    
    # Show boost reasons for mixed content
    boost_details = []
    for word in enhanced_mixed:
        if word.get('guitar_term_boosted'):
            boost_reason = word.get('boost_reason', 'unknown')
            boost_details.append(f"{word['word']}:{boost_reason}")
    print(f"Boost reasons: {boost_details}")
    print()
    
    print("✅ Advanced musical instruction pattern tests completed!")
    
    return True

def main():
    """Run all tests"""
    print("🎸 Musical Counting Pattern Detection Test Suite")
    print("=" * 60)
    
    try:
        test_counting_pattern_detection()
        test_musical_instruction_patterns()
        print("\n✅ All tests completed successfully!")
        return True
    except Exception as e:
        print(f"\n❌ Test failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 