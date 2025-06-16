#!/usr/bin/env python3

import requests
import json

def test_python_service():
    """Test the Python contextual evaluation service directly"""
    
    url = 'http://localhost:5000/compare-contextual-models'
    data = {
        'transcription_data': {
            'word_segments': [
                {'word': 'test', 'start': 0.0, 'end': 1.0, 'score': 0.5},
                {'word': 'guitar', 'start': 1.0, 'end': 2.0, 'score': 0.4}
            ]
        },
        'models': ['llama3.2:3b']
    }

    try:
        print("🔍 Testing Python contextual evaluation service...")
        response = requests.post(url, json=data, timeout=30)
        print(f"📈 Status: {response.status_code}")
        
        if response.status_code == 200:
            print("✅ Python service working!")
            result = response.json()
            print(f"📋 Response keys: {list(result.keys())}")
            return True
        else:
            print(f"❌ Python service error: {response.text[:200]}")
            return False
            
    except Exception as e:
        print(f"❌ Python service error: {e}")
        return False

if __name__ == "__main__":
    test_python_service() 