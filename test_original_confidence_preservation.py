#!/usr/bin/env python3
"""
Test script to verify original confidence preservation after filtering fix
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

def test_original_confidence_preservation():
    """Test that original confidence scores are properly preserved"""
    
    print("🎸 Testing Original Confidence Preservation")
    print("=" * 50)
    
    # Create test data that mimics real low-confidence IV chord transcription
    test_transcript = {
        "text": "play the I chord and the IV chord",
        "word_segments": [
            {"word": "play", "start": 0.0, "end": 0.5, "score": 0.85},
            {"word": "the", "start": 0.6, "end": 0.8, "score": 0.92},
            {"word": "I", "start": 0.9, "end": 1.1, "score": 0.30},     # Low confidence Roman numeral
            {"word": "chord", "start": 1.2, "end": 1.6, "score": 0.40}, # Low confidence
            {"word": "and", "start": 1.7, "end": 1.9, "score": 0.88},
            {"word": "the", "start": 2.0, "end": 2.2, "score": 0.91},
            {"word": "IV", "start": 2.3, "end": 2.6, "score": 0.35},    # Low confidence Roman numeral
            {"word": "chord", "start": 2.7, "end": 3.1, "score": 0.42}, # Low confidence
        ],
        "segments": [
            {
                "start": 0.0,
                "end": 3.1,
                "text": "play the I chord and the IV chord",
                "words": [
                    {"word": "play", "start": 0.0, "end": 0.5, "score": 0.85},
                    {"word": "the", "start": 0.6, "end": 0.8, "score": 0.92},
                    {"word": "I", "start": 0.9, "end": 1.1, "score": 0.30},
                    {"word": "chord", "start": 1.2, "end": 1.6, "score": 0.40},
                    {"word": "and", "start": 1.7, "end": 1.9, "score": 0.88},
                    {"word": "the", "start": 2.0, "end": 2.2, "score": 0.91},
                    {"word": "IV", "start": 2.3, "end": 2.6, "score": 0.35},
                    {"word": "chord", "start": 2.7, "end": 3.1, "score": 0.42},
                ]
            }
        ]
    }
    
    print("📊 Original Confidence Scores:")
    for word in test_transcript["word_segments"]:
        print(f"  {word['word']}: {word['score']:.2f}")
    
    # Test guitar term evaluator
    evaluator = GuitarTerminologyEvaluator(confidence_threshold=0.75, target_confidence=1.0)
    
    # Process with guitar term evaluator
    result = evaluator.evaluate_and_boost(test_transcript)
    
    print("\n🔍 After Guitar Term Enhancement:")
    enhanced_words = result.get('word_segments', [])
    
    success = True
    for word in enhanced_words:
        original = word.get('original_confidence', 'MISSING')
        current = word.get('score', word.get('confidence', 'MISSING'))
        boosted = word.get('guitar_term_boosted', False)
        boost_reason = word.get('boost_reason', 'none')
        
        print(f"  {word['word']}: original={original}, current={current}, boosted={boosted}, reason={boost_reason}")
        
        # Check if original confidence is properly preserved
        if boosted and (original == 'MISSING' or original == 0 or original is None):
            print(f"    ❌ ERROR: Original confidence missing or zero for boosted word '{word['word']}'")
            success = False
        elif boosted and current != 1.0:
            print(f"    ❌ ERROR: Boosted word '{word['word']}' should have confidence 1.0, got {current}")
            success = False
        elif boosted:
            print(f"    ✅ SUCCESS: '{word['word']}' properly boosted {original} → {current}")
    
    # Check specific Roman numeral chords
    print("\n🎯 Roman Numeral Chord Analysis:")
    
    # Find I chord
    i_chord_words = []
    iv_chord_words = []
    
    for i, word in enumerate(enhanced_words):
        if word['word'] in ['I', 'IV'] and i + 1 < len(enhanced_words) and enhanced_words[i + 1]['word'] == 'chord':
            if word['word'] == 'I':
                i_chord_words = [word, enhanced_words[i + 1]]
                print(f"  I chord: {word['word']} ({word.get('original_confidence', 'MISSING')}→{word.get('score', 'MISSING')}) + chord ({enhanced_words[i + 1].get('original_confidence', 'MISSING')}→{enhanced_words[i + 1].get('score', 'MISSING')})")
            elif word['word'] == 'IV':
                iv_chord_words = [word, enhanced_words[i + 1]]
                print(f"  IV chord: {word['word']} ({word.get('original_confidence', 'MISSING')}→{word.get('score', 'MISSING')}) + chord ({enhanced_words[i + 1].get('original_confidence', 'MISSING')}→{enhanced_words[i + 1].get('score', 'MISSING')})")
    
    # Verify patterns were detected
    eval_data = result.get('guitar_term_evaluation', {})
    patterns = eval_data.get('musical_counting_patterns', [])
    print(f"\n📈 Patterns Detected: {len(patterns)}")
    for pattern in patterns:
        print(f"  - {pattern['pattern_type']}: {pattern['words']} ({pattern['description']})")
    
    print(f"\n📊 Enhancement Summary:")
    print(f"  Musical terms enhanced: {eval_data.get('musical_terms_found', 0)}")
    print(f"  Musical counting words enhanced: {eval_data.get('musical_counting_words_found', 0)}")
    print(f"  Total enhanced: {eval_data.get('total_enhanced_words', 0)}")
    
    if success:
        print("\n✅ SUCCESS: Original confidence scores are properly preserved!")
    else:
        print("\n❌ FAILURE: Issues with original confidence preservation detected!")
    
    return success

def main():
    """Run the test"""
    print("🎸 Original Confidence Preservation Test")
    print("=" * 55)
    
    try:
        success = test_original_confidence_preservation()
        return success
    except Exception as e:
        print(f"\n❌ Test failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 