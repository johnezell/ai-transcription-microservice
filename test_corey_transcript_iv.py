#!/usr/bin/env python3
"""
Test script to check if IV is being stripped from the Corey transcript
"""

import sys
import os
import json

# Add the transcription service to the Python path
sys.path.append('app/services/transcription')

def test_corey_transcript_iv():
    """Test the actual Corey transcript text for IV chord mentions"""
    
    corey_text = """Hi, I'm Corey Congilio, and welcome to the Texas blues Solo factory! To solo in any form of music, especially the blues, players first need to develop a vocabulary of licks and the harmonic rules for stringing those licks together to create articulate solos. If you think of notes as words, then licks can be thought of as sentences. And just like you string sentences together to tell a story, you'll string licks together to form solos, using blues harmony as the grammatical ruleset. This edition of SoloFactory will expand your vocabulary with forty killer Texas blues licks, and it's going to teach you how to connect those licks to form compelling solos. You'll learn ten licks to play over the I chord, ten licks for the IV chord, ten for the V chord, and ten licks that you can play anywhere in the standard 12-bar blues progression. I'll demonstrate the lick, break it down, explain the underlying harmony, and then I'm going to show you how to connect those licks to form solos. For example, this is one of the licks that you'll learn to play over the I chord. And here's a lick that sounds great over the IV chord. And now, one for the V chord in turnaround. Connect just these three licks over a 12-bar progression, and you'll get a solo that sounds like this. Follow this process and play along with the first three licks, We'll follow this process to form solos for shuffles. We'll work with straight eighth feels. Part punished We'll examine a slow, 12-8, Stevie Ray Vaughan-inspired groove. And, of course, some funky blues a la Freddie King. Get a grip on these 40 licks, and then mix and match them, tweak and twist them, add your own licks, and put your newfound knowledge to work creating countless original solos. All of the licks are tabbed and notated. Plus, you'll get the power tab and guitar profiles. You'll also get all of the rhythm tracks that I used to demonstrate over to practice with on your own. So let's get to work in the factory and start cranking out some hot Texas blues solo."""
    
    print("🎸 Checking Corey's Transcript for IV Chord References")
    print("=" * 60)
    
    # Find all instances of "IV" in the text
    words = corey_text.split()
    iv_positions = []
    
    for i, word in enumerate(words):
        clean_word = word.strip('.,!?;:')  # Remove punctuation
        if clean_word == "IV":
            iv_positions.append((i, word))
            print(f"Found 'IV' at position {i}: '{word}'")
    
    if not iv_positions:
        print("❌ No 'IV' found in the transcript text!")
        return False
    
    print(f"\n✅ Found {len(iv_positions)} instances of 'IV' in the text")
    
    # Show context around each IV
    for pos, word in iv_positions:
        start_ctx = max(0, pos - 3)
        end_ctx = min(len(words), pos + 4)
        context = words[start_ctx:end_ctx]
        context[pos - start_ctx] = f"**{context[pos - start_ctx]}**"  # Highlight IV
        print(f"Context: ...{' '.join(context)}...")
    
    return True

def test_word_segments_creation():
    """Test how word segments are created from text"""
    
    print("\n🔍 Testing Word Segments Creation")
    print("=" * 40)
    
    # Simple test with IV chord
    test_text = "play the IV chord"
    words = test_text.split()
    
    print(f"Original text: '{test_text}'")
    print(f"Split words: {words}")
    
    # Create mock word segments like WhisperX would
    word_segments = []
    current_time = 0.0
    
    for word in words:
        # Check for length filtering (this might be the issue)
        if len(word.strip()) < 2:
            print(f"⚠️  Word '{word}' would be filtered out (length < 2)")
            continue
            
        word_segments.append({
            "word": word,
            "start": current_time,
            "end": current_time + 0.5,
            "score": 0.8
        })
        current_time += 0.6
    
    print(f"Generated word_segments: {[w['word'] for w in word_segments]}")
    
    # Test with actual WhisperX if available
    try:
        from guitar_term_evaluator import GuitarTerminologyEvaluator
        
        transcript_data = {
            "text": test_text,
            "word_segments": word_segments,
            "segments": [{
                "start": 0.0,
                "end": current_time,
                "text": test_text,
                "words": word_segments
            }]
        }
        
        evaluator = GuitarTerminologyEvaluator()
        segments = evaluator.extract_words_from_json(transcript_data)
        
        print(f"Extracted segments: {[seg.word for seg in segments]}")
        
        # Check if IV is in the segments
        iv_found = any(seg.word == "IV" for seg in segments)
        print(f"IV found in segments: {iv_found}")
        
        return iv_found
        
    except ImportError:
        print("Guitar term evaluator not available for testing")
        return True

def test_length_filtering():
    """Test various word length scenarios"""
    
    print("\n📏 Testing Word Length Filtering")
    print("=" * 35)
    
    test_words = ["I", "IV", "V", "a", "the", "chord", "hi"]
    
    for word in test_words:
        length = len(word)
        would_filter_2 = length < 2
        would_filter_3 = length < 3
        
        print(f"'{word}' (len={length}): " + 
              f"filter<2: {would_filter_2}, filter<3: {would_filter_3}")
    
    print("\n⚠️  If there's a word length filter anywhere in the pipeline,")
    print("    it would remove single-character Roman numerals like 'I' and 'V'")
    print("    but 'IV' (length=2) should survive a <2 filter")

def main():
    """Run all tests"""
    print("🎸 IV Chord Detection Debug - Word Stripping Test")
    print("=" * 65)
    
    try:
        test1 = test_corey_transcript_iv()
        test2 = test_word_segments_creation()
        test_length_filtering()
        
        if test1 and test2:
            print("\n✅ IV is present in text and should be in word_segments")
            print("🔍 The issue may be in the transcription service itself")
            print("    or in how word_segments are processed/filtered")
        else:
            print("\n❌ IV is being lost somewhere in the pipeline")
            
        return True
    except Exception as e:
        print(f"\n❌ Test failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 