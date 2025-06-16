#!/usr/bin/env python3
"""
Quick test to validate the teaching pattern model comparison fixes
"""

import requests
import json

def test_teaching_pattern_api():
    """Test the fixed teaching pattern API endpoint"""
    
    # Test with a segment that should exist
    url = "https://transcriptions.ngrok.dev/api/teaching-pattern-models/test"
    
    # Test data
    test_data = {
        "segment_id": 2231,
        "course_id": 85,
        "models": ["llama3.2:3b"],  # Only test with one model for speed
        "transcription_data": {
            "segments": [
                {
                    "start": 0.0,
                    "end": 5.0,
                    "text": "Welcome to this guitar lesson. Today we'll learn fingerpicking technique."
                },
                {
                    "start": 5.0,
                    "end": 10.0,
                    "text": "Let me demonstrate on the fretboard first, then you can try."
                }
            ],
            "text": "Welcome to this guitar lesson. Today we'll learn fingerpicking technique. Let me demonstrate on the fretboard first, then you can try.",
            "quality_metrics": {
                "speech_activity": {
                    "speech_ratio": 0.6,
                    "silence_ratio": 0.4,
                    "total_duration_seconds": 10.0
                }
            }
        }
    }
    
    try:
        print("🧪 Testing teaching pattern model comparison API...")
        print(f"📍 URL: {url}")
        print(f"📊 Test data: {json.dumps({k: v for k, v in test_data.items() if k != 'transcription_data'}, indent=2)}")
        
        response = requests.post(url, json=test_data, timeout=60)
        
        print(f"📈 Status Code: {response.status_code}")
        
        if response.status_code == 200:
            data = response.json()
            print("✅ API call successful!")
            
            if data.get('success'):
                print("✅ Backend processing successful!")
                
                results = data.get('results')
                if results:
                    print("✅ Results structure received!")
                    print(f"📋 Results keys: {list(results.keys())}")
                    
                    # Check for expected structure
                    if 'model_results' in results:
                        print("✅ model_results found")
                        print(f"📊 Models tested: {list(results['model_results'].keys())}")
                    
                    if 'comparison_summary' in results:
                        print("✅ comparison_summary found")
                        summary = results['comparison_summary']
                        print(f"🎯 Best analyzer: {summary.get('best_pedagogical_analyzer', 'N/A')}")
                        print(f"⚡ Fastest model: {summary.get('fastest_model', 'N/A')}")
                        print(f"📝 Recommendation: {summary.get('recommendation', 'N/A')}")
                    
                    print("\n🎉 Teaching pattern comparison is working correctly!")
                    return True
                else:
                    print("❌ No results in response")
                    print(f"Response: {json.dumps(data, indent=2)}")
            else:
                print(f"❌ Backend error: {data.get('error', 'Unknown error')}")
        else:
            print(f"❌ HTTP Error: {response.status_code}")
            print(f"Response: {response.text}")
    
    except Exception as e:
        print(f"❌ Request failed: {e}")
    
    return False

def test_model_availability():
    """Test if models are available"""
    url = "https://transcriptions.ngrok.dev/api/truefire-courses/85/segments/2231/available-models"
    
    try:
        print("\n🔍 Testing available models...")
        response = requests.get(url, timeout=10)
        
        if response.status_code == 200:
            data = response.json()
            if data.get('success'):
                models = data.get('models', [])
                print(f"✅ Found {len(models)} available models:")
                for model in models:
                    print(f"   📦 {model.get('name')} ({model.get('size_gb', 0)}GB)")
                return models
            else:
                print(f"❌ API error: {data.get('message')}")
        else:
            print(f"❌ HTTP error: {response.status_code}")
    except Exception as e:
        print(f"❌ Request failed: {e}")
    
    return []

if __name__ == "__main__":
    print("🚀 Teaching Pattern Model Comparison Fix Validation")
    print("=" * 60)
    
    # Test 1: Check available models
    models = test_model_availability()
    
    # Test 2: Test teaching pattern comparison
    if models:
        success = test_teaching_pattern_api()
        
        if success:
            print("\n🎉 SUCCESS: Teaching pattern comparison is fixed and working!")
            print("👉 You can now test it in the UI at:")
            print("   https://transcriptions.ngrok.dev/truefire-courses/85/segments/2231")
        else:
            print("\n❌ FAILURE: Teaching pattern comparison still has issues")
    else:
        print("\n⚠️  No models available yet - they may still be downloading")
        print("💡 Check Ollama status: docker logs ollama-service --tail 20")
    
    print("\n📝 Next steps:")
    print("1. Wait for more models to download (phi3:medium, phi3.5:latest, etc.)")
    print("2. Test the UI with multiple models once they're ready")
    print("3. Compare the pedagogical analysis quality between models") 