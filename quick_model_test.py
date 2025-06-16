#!/usr/bin/env python3
"""
Quick Model Test - Test which models are working and should be kept/removed
"""

import requests
import json
import time

OLLAMA_ENDPOINT = "http://localhost:11435"

def get_available_models():
    try:
        response = requests.get(f"{OLLAMA_ENDPOINT}/api/tags", timeout=10)
        if response.status_code == 200:
            data = response.json()
            models = []
            for model_info in data.get('models', []):
                models.append({
                    'name': model_info.get('name', ''),
                    'size_gb': round(model_info.get('size', 0) / (1024**3), 1)
                })
            return sorted(models, key=lambda x: x['size_gb'])
        else:
            print(f"❌ Failed to get models: HTTP {response.status_code}")
            return []
    except Exception as e:
        print(f"❌ Error getting models: {e}")
        return []

def test_model_basic(model_name):
    """Quick test if model responds to basic prompt"""
    try:
        print(f"   Testing {model_name}...", end="", flush=True)
        start_time = time.time()
        
        response = requests.post(
            f"{OLLAMA_ENDPOINT}/api/generate",
            json={
                "model": model_name,
                "prompt": "Is 'fretboard' related to guitar? Answer YES or NO.",
                "stream": False,
                "options": {"temperature": 0.1, "num_predict": 5}
            },
            timeout=30  # 30 second timeout
        )
        
        response_time = time.time() - start_time
        
        if response.status_code == 200:
            data = response.json()
            response_text = data.get('response', '').strip().upper()
            is_correct = response_text.startswith('YES')
            
            print(f" ✅ {response_time:.1f}s - Guitar: {'YES' if is_correct else 'NO'}")
            return True, response_time, is_correct
        else:
            print(f" ❌ HTTP {response.status_code}")
            return False, 0, False
            
    except Exception as e:
        print(f" ❌ Error: {str(e)[:50]}")
        return False, 0, False

def main():
    print("🧪 Quick Model Test")
    print("=" * 50)
    
    models = get_available_models()
    if not models:
        print("No models found!")
        return
    
    print(f"Testing {len(models)} models:\n")
    
    working_models = []
    failed_models = []
    
    for model in models:
        model_name = model['name']
        size_gb = model['size_gb']
        
        print(f"📦 {model_name} ({size_gb}GB)")
        
        works, response_time, guitar_correct = test_model_basic(model_name)
        
        if works:
            working_models.append({
                'name': model_name,
                'size_gb': size_gb,
                'response_time': response_time,
                'guitar_correct': guitar_correct
            })
        else:
            failed_models.append({
                'name': model_name,
                'size_gb': size_gb
            })
    
    print("\n" + "=" * 50)
    print("📋 SUMMARY")
    print("=" * 50)
    
    if working_models:
        print(f"\n✅ Working Models ({len(working_models)}):")
        for model in working_models:
            guitar_icon = "🎸" if model['guitar_correct'] else "❌"
            speed_icon = "⚡" if model['response_time'] < 3 else "🐌" if model['response_time'] > 10 else "⏱️"
            print(f"   {guitar_icon} {speed_icon} {model['name']} ({model['size_gb']}GB, {model['response_time']:.1f}s)")
    
    if failed_models:
        print(f"\n❌ Failed Models ({len(failed_models)}):")
        for model in failed_models:
            print(f"   🗑️  {model['name']} ({model['size_gb']}GB)")
        
        print(f"\n🔧 To remove failed models:")
        for model in failed_models:
            print(f"   docker exec ollama-service ollama rm {model['name']}")
    
    # Recommendations
    if working_models:
        # Find best for guitar terminology
        guitar_models = [m for m in working_models if m['guitar_correct']]
        if guitar_models:
            best_guitar = min(guitar_models, key=lambda x: x['response_time'])
            print(f"\n🏆 RECOMMENDED for Guitar Terms: {best_guitar['name']}")
        
        # Find fastest overall
        fastest = min(working_models, key=lambda x: x['response_time'])
        print(f"⚡ FASTEST: {fastest['name']} ({fastest['response_time']:.1f}s)")
        
        # Recommended config
        recommended_model = guitar_models[0]['name'] if guitar_models else working_models[0]['name']
        print(f"\n⚙️  RECOMMENDED CONFIG:")
        print(f"   Update docker-compose.yml:")
        print(f"   - OLLAMA_MODELS={recommended_model}")
        print(f"   - LLM_MODEL={recommended_model}")

if __name__ == "__main__":
    main() 