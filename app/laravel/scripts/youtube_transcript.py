#!/usr/bin/env python3
"""
YouTube Transcript Extractor
Usage: python youtube_transcript.py <video_id_or_url>
Returns: JSON with transcript text
"""

import sys
import json
import re

def extract_video_id(url_or_id):
    """Extract video ID from various YouTube URL formats"""
    if len(url_or_id) == 11 and re.match(r'^[a-zA-Z0-9_-]+$', url_or_id):
        return url_or_id
    
    patterns = [
        r'(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/v\/)([a-zA-Z0-9_-]{11})',
    ]
    
    for pattern in patterns:
        match = re.search(pattern, url_or_id)
        if match:
            return match.group(1)
    
    return None

def get_transcript(video_id):
    """Fetch transcript using youtube-transcript-api v1.2+"""
    try:
        from youtube_transcript_api import YouTubeTranscriptApi
    except ImportError:
        return {
            'success': False,
            'error': 'youtube-transcript-api not installed. Run: pip install youtube-transcript-api'
        }
    
    try:
        api = YouTubeTranscriptApi()
        
        # Fetch transcript (new API style)
        transcript_result = api.fetch(video_id)
        
        # Convert to list and extract text
        segments = list(transcript_result)
        
        if not segments:
            return {
                'success': False,
                'error': 'No transcripts available for this video'
            }
        
        # Combine all text segments
        full_text = ' '.join([segment.text for segment in segments])
        
        return {
            'success': True,
            'video_id': video_id,
            'language': 'en',  # Default assumption
            'transcript': full_text,
            'segments': len(segments)
        }
        
    except Exception as e:
        return {
            'success': False,
            'error': str(e)
        }

def main():
    if len(sys.argv) < 2:
        print(json.dumps({
            'success': False,
            'error': 'Usage: python youtube_transcript.py <video_id_or_url>'
        }))
        sys.exit(1)
    
    url_or_id = sys.argv[1]
    video_id = extract_video_id(url_or_id)
    
    if not video_id:
        print(json.dumps({
            'success': False,
            'error': f'Could not extract video ID from: {url_or_id}'
        }))
        sys.exit(1)
    
    result = get_transcript(video_id)
    print(json.dumps(result))
    
    sys.exit(0 if result.get('success') else 1)

if __name__ == '__main__':
    main()
