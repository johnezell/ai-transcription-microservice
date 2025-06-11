#!/usr/bin/env python3
"""
Test script to verify word_segments are updated correctly with enhanced confidence scores
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

def test_word_segments_update():
    """Test that word_segments are updated correctly with enhanced confidence scores"""
    
    print("\n🎸 Testing Word Segments Update for IV Chord\n")
    
    # Create test transcript data that mimics the real structure
    test_transcript = {
        "text": "here's a lick for the IV chord",
        "word_segments": [
            {"word": "here's", "start": 0.0, "end": 0.5, "score": 0.8},
            {"word": "a", "start": 0.6, "end": 0.8, "score": 0.9},
            {"word": "lick", "start": 0.9, "end": 1.2, "score": 0.7},
            {"word": "for", "start": 1.3, "end": 1.5, "score": 0.9},
            {"word": "the", "start": 1.6, "end": 1.8, "score": 0.9},
            {"word": "IV", "start": 1.9, "end": 2.2, "score": 0.3},      # Low confidence
            {"word": "chord", "start": 2.3, "end": 2.7, "score": 0.4},  # Low confidence
        ],
        "segments": [
            {
                "start": 0.0,
                "end": 2.7,
                "text": "here's a lick for the IV chord",
                "words": [
                    {"word": "here's", "start": 0.0, "end": 0.5, "score": 0.8},
                    {"word": "a", "start": 0.6, "end": 0.8, "score": 0.9},
                    {"word": "lick", "start": 0.9, "end": 1.2, "score": 0.7},
                    {"word": "for", "start": 1.3, "end": 1.5, "score": 0.9},
                    {"word": "the", "start": 1.6, "end": 1.8, "score": 0.9},
                    {"word": "IV", "start": 1.9, "end": 2.2, "score": 0.3},
                    {"word": "chord", "start": 2.3, "end": 2.7, "score": 0.4},
                ]
            }
        ]
    }
    
    print("Original transcript word_segments:")
    for i, word in enumerate(test_transcript["word_segments"]):
        print(f"  {i}: {word['word']} -> {word['score']:.2f}")
    
    # Run guitar term evaluation
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    result = evaluator.evaluate_and_boost(test_transcript)
    
    print("\nEnhanced transcript word_segments:")
    enhanced_word_segments = result.get("word_segments", [])
    for i, word in enumerate(enhanced_word_segments):
        confidence = word.get('score', 0)
        original = word.get('original_confidence', 'N/A')
        boosted = word.get('guitar_term_boosted', False)
        boost_reason = word.get('boost_reason', 'N/A')
        
        status = "🎸" if boosted else "  "
        print(f"  {status} {i}: {word['word']} -> {confidence:.2f} (orig: {original}, boosted: {boosted}, reason: {boost_reason})")
    
    # Check specifically for IV chord enhancement
    iv_word = None
    chord_word = None
    
    for word in enhanced_word_segments:
        if word['word'] == 'IV':
            iv_word = word
        elif word['word'] == 'chord' and word.get('start', 0) > 2.0:  # The chord after IV
            chord_word = word
    
    print(f"\nIV Chord Analysis:")
    if iv_word:
        print(f"  IV: {iv_word.get('score', 0):.2f} (original: {iv_word.get('original_confidence', 'N/A')}) boosted: {iv_word.get('guitar_term_boosted', False)}")
    else:
        print("  ❌ IV word not found in enhanced results!")
    
    if chord_word:
        print(f"  chord: {chord_word.get('score', 0):.2f} (original: {chord_word.get('original_confidence', 'N/A')}) boosted: {chord_word.get('guitar_term_boosted', False)}")
    else:
        print("  ❌ chord word not found in enhanced results!")
    
    # Check if both words were boosted to 100%
    iv_boosted = iv_word and iv_word.get('score', 0) == 1.0
    chord_boosted = chord_word and chord_word.get('score', 0) == 1.0
    
    if iv_boosted and chord_boosted:
        print("  ✅ Both IV and chord boosted to 100% - SUCCESS!")
        return True
    else:
        print("  ❌ IV chord not properly enhanced - FAILED!")
        
        # Debug compound musical term detection
        segments = evaluator.extract_words_from_json(test_transcript)
        patterns = evaluator._detect_musical_counting_patterns(segments)
        
        print(f"\nDEBUG: Found {len(patterns)} patterns:")
        for pattern in patterns:
            print(f"  - {pattern.pattern_type}: {pattern.words} ({pattern.description})")
        
        return False

def test_segments_alignment():
    """Test that the segments alignment works correctly"""
    
    print("\n🔍 Testing Segments Alignment\n")
    
    # Test data with different structures
    test_data = {
        "text": "play the I chord and IV chord",
        "word_segments": [
            {"word": "play", "start": 0.0, "end": 0.4, "score": 0.8},
            {"word": "the", "start": 0.5, "end": 0.7, "score": 0.9},
            {"word": "I", "start": 0.8, "end": 1.0, "score": 0.3},      # Low confidence
            {"word": "chord", "start": 1.1, "end": 1.5, "score": 0.4},  # Low confidence
            {"word": "and", "start": 1.6, "end": 1.8, "score": 0.9},
            {"word": "IV", "start": 1.9, "end": 2.2, "score": 0.3},     # Low confidence
            {"word": "chord", "start": 2.3, "end": 2.7, "score": 0.4},  # Low confidence
        ],
        "segments": [
            {
                "start": 0.0,
                "end": 2.7,
                "text": "play the I chord and IV chord",
                "words": [
                    {"word": "play", "start": 0.0, "end": 0.4, "score": 0.8},
                    {"word": "the", "start": 0.5, "end": 0.7, "score": 0.9},
                    {"word": "I", "start": 0.8, "end": 1.0, "score": 0.3},
                    {"word": "chord", "start": 1.1, "end": 1.5, "score": 0.4},
                    {"word": "and", "start": 1.6, "end": 1.8, "score": 0.9},
                    {"word": "IV", "start": 1.9, "end": 2.2, "score": 0.3},
                    {"word": "chord", "start": 2.3, "end": 2.7, "score": 0.4},
                ]
            }
        ]
    }
    
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    
    print("Original alignment test:")
    for i, word in enumerate(test_data["word_segments"]):
        print(f"  {i}: word_segments[{i}] = {word['word']} ({word['score']:.2f})")
    
    # Test segment extraction
    segments = evaluator.extract_words_from_json(test_data)
    print(f"\nExtracted {len(segments)} segments:")
    for i, seg in enumerate(segments):
        print(f"  {i}: segments[{i}] = {seg.word} ({seg.confidence:.2f})")
    
    # Test enhancement
    result = evaluator.evaluate_and_boost(test_data)
    enhanced_segments = result.get("word_segments", [])
    
    print(f"\nEnhanced word_segments:")
    for i, word in enumerate(enhanced_segments):
        confidence = word.get('score', 0)
        boosted = word.get('guitar_term_boosted', False)
        status = "🎸" if boosted else "  "
        print(f"  {status} {i}: {word['word']} -> {confidence:.2f}")
    
    # Count enhanced roman numeral chords
    enhanced_count = 0
    for word in enhanced_segments:
        if word.get('guitar_term_boosted') and word.get('boost_reason') == 'musical_counting_pattern':
            enhanced_count += 1
    
    print(f"\nRoman numeral chord words enhanced: {enhanced_count}/4 expected")
    
    # Check evaluation metadata
    eval_data = result.get('guitar_term_evaluation', {})
    patterns = eval_data.get('musical_counting_patterns', [])
    print(f"Patterns detected: {len(patterns)}")
    for pattern in patterns:
        print(f"  - {pattern['pattern_type']}: {pattern['words']}")
    
    return enhanced_count == 4  # Should enhance both "I chord" and "IV chord" = 4 words

def main():
    """Run all tests"""
    print("🎸 Word Segments Update Test Suite")
    print("=" * 60)
    
    try:
        test1_success = test_word_segments_update()
        test2_success = test_segments_alignment()
        
        if test1_success and test2_success:
            print("\n✅ All tests passed!")
            return True
        else:
            print("\n❌ Some tests failed!")
            return False
    except Exception as e:
        print(f"\n❌ Test failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 