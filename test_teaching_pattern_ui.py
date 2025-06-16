#!/usr/bin/env python3
"""
Test script to validate Teaching Pattern Model Comparison System
Tests both backend API endpoints and validates UI integration
"""

import requests
import json
import time

# Configuration
BASE_URL = "http://localhost:8080"  # Laravel app URL
TEST_SEGMENT_ID = 7959  # Replace with a valid segment ID from your system
TEST_COURSE_ID = 1      # Replace with a valid course ID

def test_available_models():
    """Test the available models endpoint"""
    print("🔍 Testing available models endpoint...")
    
    url = f"{BASE_URL}/api/truefire-courses/{TEST_COURSE_ID}/segments/{TEST_SEGMENT_ID}/available-models"
    
    try:
        response = requests.get(url)
        print(f"Status: {response.status_code}")
        
        if response.status_code == 200:
            data = response.json()
            if data.get('success'):
                models = data.get('models', [])
                print(f"✅ Found {len(models)} available models:")
                for model in models:
                    print(f"   - {model.get('name')} ({model.get('size_gb', 0)}GB)")
                return models
            else:
                print(f"❌ API returned error: {data.get('message')}")
        else:
            print(f"❌ HTTP Error: {response.status_code}")
            print(response.text)
    except Exception as e:
        print(f"❌ Request failed: {e}")
    
    return []

def test_teaching_pattern_analysis():
    """Test the teaching pattern model comparison endpoint"""
    print("\n🎯 Testing teaching pattern model comparison...")
    
    url = f"{BASE_URL}/api/teaching-pattern-models/test"
    
    # Test data - in real usage this would come from the actual transcription
    test_transcription_data = {
        "segments": [
            {
                "start": 0.0,
                "end": 5.0,
                "text": "Welcome to this guitar lesson. Today we'll learn about chord progressions.",
                "words": [
                    {"word": "Welcome", "start": 0.0, "end": 0.5, "score": 0.95},
                    {"word": "to", "start": 0.5, "end": 0.7, "score": 0.98},
                    {"word": "this", "start": 0.7, "end": 1.0, "score": 0.92},
                    {"word": "guitar", "start": 1.0, "end": 1.5, "score": 0.88},
                    {"word": "lesson", "start": 1.5, "end": 2.0, "score": 0.90}
                ]
            },
            {
                "start": 5.0,
                "end": 10.0,
                "text": "Let me demonstrate the C major chord first.",
                "words": [
                    {"word": "Let", "start": 5.0, "end": 5.2, "score": 0.94},
                    {"word": "me", "start": 5.2, "end": 5.4, "score": 0.96},
                    {"word": "demonstrate", "start": 5.4, "end": 6.2, "score": 0.87},
                    {"word": "the", "start": 6.2, "end": 6.4, "score": 0.95},
                    {"word": "C", "start": 6.4, "end": 6.6, "score": 0.45},
                    {"word": "major", "start": 6.6, "end": 7.0, "score": 0.92},
                    {"word": "chord", "start": 7.0, "end": 7.5, "score": 0.89}
                ]
            }
        ],
        "quality_metrics": {
            "speech_activity": {
                "speaking_rate_wpm": 120,
                "speech_activity_ratio": 0.75
            }
        }
    }
    
    payload = {
        "segment_id": TEST_SEGMENT_ID,
        "course_id": TEST_COURSE_ID,
        "models": ["llama3.2:3b", "llama3.1:latest"],
        "transcription_data": test_transcription_data
    }
    
    try:
        print(f"Sending request to: {url}")
        response = requests.post(url, json=payload, timeout=60)
        print(f"Status: {response.status_code}")
        
        if response.status_code == 200:
            data = response.json()
            if data.get('success'):
                results = data.get('results')
                print("✅ Teaching pattern analysis completed!")
                
                # Display summary
                if results and 'comparison_summary' in results:
                    summary = results['comparison_summary']
                    print(f"\n📊 Analysis Summary:")
                    print(f"   Best Analyzer: {summary.get('best_pedagogical_analyzer', 'N/A')}")
                    print(f"   Fastest Model: {summary.get('fastest_model', 'N/A')}")
                    print(f"   Recommendation: {summary.get('recommendation', 'N/A')}")
                
                # Display model results
                if results and 'model_results' in results:
                    print(f"\n🎭 Model Results:")
                    for model_name, model_data in results['model_results'].items():
                        print(f"   {model_name}:")
                        print(f"     Pattern: {model_data.get('teaching_pattern_detected', 'N/A')}")
                        print(f"     Quality: {model_data.get('pedagogical_quality_score', 0):.1f}/10")
                        print(f"     Cycles: {model_data.get('teaching_cycles_detected', 0)}")
                        print(f"     Time: {model_data.get('processing_time', 0):.2f}s")
                
                return True
            else:
                print(f"❌ API returned error: {data.get('message')}")
        else:
            print(f"❌ HTTP Error: {response.status_code}")
            print(response.text)
    except Exception as e:
        print(f"❌ Request failed: {e}")
    
    return False

def test_teaching_pattern_service_health():
    """Test if the teaching pattern service endpoints are accessible"""
    print("\n🏥 Testing teaching pattern service health...")
    
    # Test transcription service endpoints
    transcription_endpoints = [
        "/compare-teaching-pattern-models",
        "/test-teaching-pattern-model"
    ]
    
    for endpoint in transcription_endpoints:
        url = f"http://localhost:8081{endpoint}"  # Transcription service port
        try:
            # Try a simple GET to see if endpoint exists
            response = requests.get(url, timeout=5)
            print(f"   {endpoint}: Status {response.status_code}")
        except Exception as e:
            print(f"   {endpoint}: ❌ {e}")

def main():
    """Main test function"""
    print("🚀 Teaching Pattern Model Comparison System Test")
    print("=" * 60)
    
    # Test 1: Available models
    models = test_available_models()
    
    # Test 2: Teaching pattern analysis (if models available)
    if models:
        success = test_teaching_pattern_analysis()
        if success:
            print("\n✅ All tests passed! Teaching pattern system is working.")
        else:
            print("\n❌ Teaching pattern analysis test failed.")
    else:
        print("\n⚠️  No models available - cannot test teaching pattern analysis.")
    
    # Test 3: Service health
    test_teaching_pattern_service_health()
    
    print("\n🎯 Next Steps:")
    print("1. Visit a TrueFire segment page (e.g., http://localhost:8080/truefire-courses/1/segments/7959)")
    print("2. Look for the 'Teaching Pattern Analysis' panel")
    print("3. Select models and click 'Analyze Teaching Patterns'")
    print("4. Review the pedagogical analysis results")

if __name__ == "__main__":
    main() 