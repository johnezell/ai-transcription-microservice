#!/usr/bin/env python3
"""
Test Roman numeral chord detection against actual Corey Congilio transcript
"""

import sys
import random
import json
from typing import List

try:
    from guitar_term_evaluator import GuitarTerminologyEvaluator, WordSegment
    print("✅ Successfully imported guitar term evaluator")
except ImportError as e:
    print(f"❌ Failed to import guitar term evaluator: {e}")
    sys.exit(1)

def create_mock_word_segments_from_text(text: str, confidence_range=(0.3, 0.9)):
    """Create mock word segments from real text with simulated confidence scores"""
    words = text.split()
    word_segments = []
    current_time = 0.0
    
    for word in words:
        # Clean up punctuation
        clean_word = word.strip('.,!?;:()[]')
        if not clean_word:
            continue
            
        # Assign low confidence to Roman numerals to test boosting
        if clean_word in ['I', 'IV', 'V', 'ii', 'iii', 'vi', 'vii']:
            confidence = 0.35  # Low confidence for Roman numerals
        else:
            confidence = random.uniform(*confidence_range)
        
        duration = len(clean_word) * 0.08 + random.uniform(0.1, 0.3)
        
        word_segments.append({
            'word': clean_word,
            'start': current_time,
            'end': current_time + duration,
            'confidence': confidence
        })
        
        current_time += duration + random.uniform(0.05, 0.15)
    
    return word_segments

def test_corey_transcript():
    """Test Roman numeral detection in Corey Congilio's actual transcript"""
    
    # Actual transcript from Corey Congilio
    corey_text = """Hi, I'm Corey Congilio, and welcome to the Texas blues Solo factory! To solo in any form of music, especially the blues, players first need to develop a vocabulary of licks and the harmonic rules for stringing those licks together to create articulate solos. If you think of notes as words, then licks can be thought of as sentences. And just like you string sentences together to tell a story, you'll string licks together to form solos, using blues harmony as the grammatical ruleset. This edition of SoloFactory will expand your vocabulary with forty killer Texas blues licks, and it's going to teach you how to connect those licks to form compelling solos. You'll learn ten licks to play over the I chord, ten licks for the IV chord, ten for the V chord, and ten licks that you can play anywhere in the standard 12-bar blues progression. I'll demonstrate the lick, break it down, explain the underlying harmony, and then I'm going to show you how to connect those licks to form solos. For example, this is one of the licks that you'll learn to play over the I chord. And here's a lick that sounds great over the IV chord. And now, one for the V chord in turnaround. Connect just these three licks over a 12-bar progression, and you'll get a solo that sounds like this. We'll follow this process to form solos for shuffles, we'll work with straight eighth feels, We'll examine a slow, 12-8 Stevie Ray Vaughan-inspired groove. And, of course, some funky blues a la Freddie King. Get a grip on these 40 licks, and then mix and match them, tweak and twist them, add your own licks, and put your newfound knowledge to work creating countless original solos. All of the licks are tabbed and notated. Plus, you'll get the power tab and guitar profiles. You'll also get all of the rhythm tracks that I used to demonstrate over to practice with on your own. So let's get to work in the factory and start cranking out some hot Texas blues solo. Here we go."""
    
    print("=== Testing Corey Congilio Transcript ===")
    print("Looking for Roman numeral chord references...")
    
    # Create word segments from the text
    word_segments = create_mock_word_segments_from_text(corey_text)
    
    # Find Roman numeral + chord combinations
    roman_numeral_phrases = []
    for i, segment in enumerate(word_segments):
        if segment['word'] in ['I', 'IV', 'V'] and i + 1 < len(word_segments):
            if word_segments[i + 1]['word'] == 'chord':
                roman_numeral_phrases.append({
                    'phrase': f"{segment['word']} chord",
                    'position': i,
                    'original_confidences': [segment['confidence'], word_segments[i + 1]['confidence']]
                })
    
    print(f"\nFound {len(roman_numeral_phrases)} Roman numeral chord phrases:")
    for phrase in roman_numeral_phrases:
        print(f"  - {phrase['phrase']} (confidences: {phrase['original_confidences']})")
    
    # Create the transcription_json format expected by the evaluator
    transcription_json = {
        'word_segments': word_segments
    }
    
    # Test with the guitar term evaluator
    evaluator = GuitarTerminologyEvaluator()
    result = evaluator.evaluate_and_boost(transcription_json)
    
    print(f"\n=== Evaluation Results ===")
    guitar_eval = result.get('guitar_term_evaluation', {})
    print(f"Total words processed: {len(word_segments)}")
    print(f"Guitar terms found: {guitar_eval.get('guitar_terms_found', 0)}")
    print(f"Total enhanced words: {guitar_eval.get('total_words_enhanced', 0)}")
    
    # Check if compound musical terms were detected
    if 'compound_musical_terms' in guitar_eval:
        print(f"\nCompound musical terms detected: {len(guitar_eval['compound_musical_terms'])}")
        for term in guitar_eval['compound_musical_terms']:
            print(f"  - {term}")
    else:
        print("\n❌ No compound musical terms detected!")
    
    # Check specific Roman numeral chord detections
    print(f"\n=== Roman Numeral Chord Analysis ===")
    enhanced_word_segments = result['word_segments']
    
    for phrase in roman_numeral_phrases:
        pos = phrase['position']
        roman_word = enhanced_word_segments[pos]
        chord_word = enhanced_word_segments[pos + 1]
        
        original_conf_roman = phrase['original_confidences'][0]
        original_conf_chord = phrase['original_confidences'][1]
        
        print(f"\n{phrase['phrase']}:")
        print(f"  {roman_word['word']}: {original_conf_roman:.2f} → {roman_word['confidence']:.2f}")
        print(f"  {chord_word['word']}: {original_conf_chord:.2f} → {chord_word['confidence']:.2f}")
        
        if roman_word['confidence'] == 1.0 and chord_word['confidence'] == 1.0:
            print(f"  ✅ Both words boosted to 100% confidence!")
        else:
            print(f"  ❌ Words not properly boosted")
            # Show boost reasons if available
            if 'boost_reason' in roman_word:
                print(f"    Roman numeral boost reason: {roman_word['boost_reason']}")
            if 'boost_reason' in chord_word:
                print(f"    Chord boost reason: {chord_word['boost_reason']}")
    
    return result

if __name__ == '__main__':
    test_corey_transcript() 