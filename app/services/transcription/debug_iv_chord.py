#!/usr/bin/env python3
"""
Debug the IV chord detection issue - investigate why one instance didn't work
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

def debug_iv_chord_detection():
    """Debug why one IV chord instance isn't working properly"""
    
    # Use the same transcript
    corey_text = """Hi, I'm Corey Congilio, and welcome to the Texas blues Solo factory! To solo in any form of music, especially the blues, players first need to develop a vocabulary of licks and the harmonic rules for stringing those licks together to create articulate solos. If you think of notes as words, then licks can be thought of as sentences. And just like you string sentences together to tell a story, you'll string licks together to form solos, using blues harmony as the grammatical ruleset. This edition of SoloFactory will expand your vocabulary with forty killer Texas blues licks, and it's going to teach you how to connect those licks to form compelling solos. You'll learn ten licks to play over the I chord, ten licks for the IV chord, ten for the V chord, and ten licks that you can play anywhere in the standard 12-bar blues progression. I'll demonstrate the lick, break it down, explain the underlying harmony, and then I'm going to show you how to connect those licks to form solos. For example, this is one of the licks that you'll learn to play over the I chord. And here's a lick that sounds great over the IV chord. And now, one for the V chord in turnaround. Connect just these three licks over a 12-bar progression, and you'll get a solo that sounds like this. We'll follow this process to form solos for shuffles, we'll work with straight eighth feels, We'll examine a slow, 12-8 Stevie Ray Vaughan-inspired groove. And, of course, some funky blues a la Freddie King. Get a grip on these 40 licks, and then mix and match them, tweak and twist them, add your own licks, and put your newfound knowledge to work creating countless original solos. All of the licks are tabbed and notated. Plus, you'll get the power tab and guitar profiles. You'll also get all of the rhythm tracks that I used to demonstrate over to practice with on your own. So let's get to work in the factory and start cranking out some hot Texas blues solo. Here we go."""
    
    print("=== Debugging IV Chord Detection ===")
    
    # Create word segments from the text
    word_segments = create_mock_word_segments_from_text(corey_text)
    
    # Find ALL Roman numeral + chord combinations with more context
    roman_numeral_phrases = []
    for i, segment in enumerate(word_segments):
        if segment['word'] in ['I', 'IV', 'V'] and i + 1 < len(word_segments):
            if word_segments[i + 1]['word'] == 'chord':
                # Get surrounding context
                context_before = []
                context_after = []
                
                # 3 words before
                for j in range(max(0, i-3), i):
                    context_before.append(word_segments[j]['word'])
                
                # 3 words after
                for j in range(i+2, min(len(word_segments), i+5)):
                    context_after.append(word_segments[j]['word'])
                
                roman_numeral_phrases.append({
                    'phrase': f"{segment['word']} chord",
                    'position': i,
                    'original_confidences': [segment['confidence'], word_segments[i + 1]['confidence']],
                    'context_before': ' '.join(context_before),
                    'context_after': ' '.join(context_after),
                    'word_spacing': word_segments[i + 1]['start'] - segment['end'],  # Gap between words
                    'timing': {
                        'roman_start': segment['start'],
                        'roman_end': segment['end'],
                        'chord_start': word_segments[i + 1]['start'],
                        'chord_end': word_segments[i + 1]['end']
                    }
                })
    
    print(f"\nFound {len(roman_numeral_phrases)} Roman numeral chord phrases with context:")
    for i, phrase in enumerate(roman_numeral_phrases):
        print(f"\n--- Instance {i+1}: {phrase['phrase']} ---")
        print(f"Context: ...{phrase['context_before']} [{phrase['phrase']}] {phrase['context_after']}...")
        print(f"Original confidences: {phrase['original_confidences']}")
        print(f"Word spacing: {phrase['word_spacing']:.3f}s")
        print(f"Timing: {phrase['timing']}")
    
    # Create the transcription_json format expected by the evaluator
    transcription_json = {
        'word_segments': word_segments
    }
    
    # Test with the guitar term evaluator
    evaluator = GuitarTerminologyEvaluator()
    result = evaluator.evaluate_and_boost(transcription_json)
    
    print(f"\n=== Detailed Results Analysis ===")
    enhanced_word_segments = result['word_segments']
    
    for i, phrase in enumerate(roman_numeral_phrases):
        pos = phrase['position']
        roman_word = enhanced_word_segments[pos]
        chord_word = enhanced_word_segments[pos + 1]
        
        print(f"\n--- Instance {i+1}: {phrase['phrase']} Analysis ---")
        print(f"Context: ...{phrase['context_before']} [{phrase['phrase']}] {phrase['context_after']}...")
        
        # Roman numeral analysis
        print(f"Roman numeral '{roman_word['word']}':")
        print(f"  Confidence: {phrase['original_confidences'][0]:.2f} → {roman_word['confidence']:.2f}")
        if 'boost_reason' in roman_word:
            print(f"  Boost reason: {roman_word['boost_reason']}")
        if 'original_confidence' in roman_word:
            print(f"  Original confidence preserved: {roman_word['original_confidence']}")
        
        # Chord word analysis
        print(f"Chord word '{chord_word['word']}':")
        print(f"  Confidence: {phrase['original_confidences'][1]:.2f} → {chord_word['confidence']:.2f}")
        if 'boost_reason' in chord_word:
            print(f"  Boost reason: {chord_word['boost_reason']}")
        else:
            print(f"  ❌ NO BOOST REASON - this word wasn't enhanced!")
        if 'original_confidence' in chord_word:
            print(f"  Original confidence preserved: {chord_word['original_confidence']}")
        
        # Overall result
        roman_boosted = roman_word['confidence'] == 1.0
        chord_boosted = chord_word['confidence'] == 1.0
        
        if roman_boosted and chord_boosted:
            print(f"  ✅ SUCCESS: Both words boosted to 100%")
        elif roman_boosted and not chord_boosted:
            print(f"  ⚠️  PARTIAL: Only Roman numeral boosted")
        elif not roman_boosted and chord_boosted:
            print(f"  ⚠️  PARTIAL: Only chord boosted")
        else:
            print(f"  ❌ FAILED: Neither word boosted")
    
    # Check if there are any patterns in the failed instances
    print(f"\n=== Pattern Analysis ===")
    failed_instances = []
    success_instances = []
    
    for i, phrase in enumerate(roman_numeral_phrases):
        pos = phrase['position']
        roman_word = enhanced_word_segments[pos]
        chord_word = enhanced_word_segments[pos + 1]
        
        if roman_word['confidence'] == 1.0 and chord_word['confidence'] == 1.0:
            success_instances.append((i, phrase))
        else:
            failed_instances.append((i, phrase))
    
    if failed_instances:
        print(f"Failed instances ({len(failed_instances)}):")
        for i, phrase in failed_instances:
            print(f"  Instance {i+1}: {phrase['phrase']} - spacing: {phrase['word_spacing']:.3f}s")
    
    if success_instances:
        print(f"Successful instances ({len(success_instances)}):")
        for i, phrase in success_instances:
            print(f"  Instance {i+1}: {phrase['phrase']} - spacing: {phrase['word_spacing']:.3f}s")
    
    return result

if __name__ == '__main__':
    debug_iv_chord_detection() 