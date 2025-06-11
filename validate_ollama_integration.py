#!/usr/bin/env python3
"""
Validation script for containerized Ollama integration.

This script tests that the transcription service can properly communicate
with the containerized Ollama service for guitar term evaluation.
"""

import requests
import json
import time
import sys
from datetime import datetime

def test_ollama_health():
    """Test if Ollama service is accessible and responding"""
    print("🔍 Testing Ollama service health...")
    
    try:
        # Test external access (host exposed port)
        response = requests.post(
            "http://localhost:11434/api/generate",
            json={
                "model": "llama3:latest",
                "prompt": "Test",
                "stream": False,
                "options": {"num_predict": 3}
            },
            timeout=30
        )
        
        if response.status_code == 200:
            result = response.json()
            print(f"✅ Ollama external endpoint responding: {result.get('response', 'No response')[:50]}...")
            return True
        else:
            print(f"❌ Ollama external endpoint error: HTTP {response.status_code}")
            return False
            
    except Exception as e:
        print(f"❌ Ollama external endpoint failed: {e}")
        return False

def test_transcription_service_health():
    """Test if transcription service is running"""
    print("🔍 Testing transcription service health...")
    
    try:
        response = requests.get("http://localhost:5051/health", timeout=10)
        
        if response.status_code == 200:
            data = response.json()
            print(f"✅ Transcription service healthy: {data.get('status', 'unknown')}")
            return True
        else:
            print(f"❌ Transcription service error: HTTP {response.status_code}")
            return False
            
    except Exception as e:
        print(f"❌ Transcription service failed: {e}")
        return False

def test_guitar_term_evaluator():
    """Test the guitar terminology evaluator with containerized Ollama"""
    print("🎸 Testing guitar term evaluator integration...")
    
    try:
        # Test the evaluator endpoint without specifying llm_endpoint
        # It should use the environment variables pointing to ollama-service
        response = requests.post(
            "http://localhost:5051/test-guitar-term-evaluator",
            json={},  # No llm_endpoint specified - should use environment variable
            timeout=60
        )
        
        if response.status_code == 200:
            data = response.json()
            if data.get('success'):
                print("✅ Guitar term evaluator working with containerized Ollama!")
                
                # Print some key results
                eval_data = data.get('guitar_term_evaluation', {})
                print(f"   📊 Musical terms found: {eval_data.get('musical_terms_found', 0)}")
                print(f"   📊 Total words evaluated: {eval_data.get('total_words_evaluated', 0)}")
                
                # Check LLM configuration
                llm_config = eval_data.get('llm_configuration', {})
                endpoint = llm_config.get('endpoint', 'unknown')
                model = llm_config.get('model', 'unknown')
                print(f"   🔗 LLM Endpoint: {endpoint}")
                print(f"   🤖 LLM Model: {model}")
                
                # Verify it's using the containerized service
                if 'ollama-service' in endpoint:
                    print("✅ Confirmed: Using containerized Ollama service!")
                    return True
                else:
                    print(f"⚠️  Warning: Not using containerized service. Endpoint: {endpoint}")
                    return False
            else:
                print(f"❌ Guitar term evaluator failed: {data.get('error_message', 'Unknown error')}")
                return False
        else:
            print(f"❌ Guitar term evaluator HTTP error: {response.status_code}")
            print(f"Response: {response.text[:200]}...")
            return False
            
    except Exception as e:
        print(f"❌ Guitar term evaluator test failed: {e}")
        return False

def test_service_capabilities():
    """Test that service capabilities reflect Ollama integration"""
    print("🔍 Testing service capabilities...")
    
    try:
        response = requests.get("http://localhost:5051/features/capabilities", timeout=10)
        
        if response.status_code == 200:
            data = response.json()
            
            # Check for guitar terminology evaluation capability
            guitar_eval = data.get('guitar_terminology_evaluation', {})
            if guitar_eval.get('enabled'):
                print("✅ Guitar terminology evaluation capability enabled")
                print(f"   📝 Description: {guitar_eval.get('description', 'N/A')[:80]}...")
                return True
            else:
                print("❌ Guitar terminology evaluation not enabled in capabilities")
                return False
        else:
            print(f"❌ Service capabilities error: HTTP {response.status_code}")
            return False
            
    except Exception as e:
        print(f"❌ Service capabilities test failed: {e}")
        return False

def main():
    """Main validation routine"""
    print("🚀 Ollama Containerization Validation")
    print("=" * 50)
    print(f"⏰ Timestamp: {datetime.now().isoformat()}")
    print()
    
    tests = [
        ("Transcription Service Health", test_transcription_service_health),
        ("Ollama Service Health", test_ollama_health),
        ("Service Capabilities", test_service_capabilities),
        ("Guitar Term Evaluator Integration", test_guitar_term_evaluator),
    ]
    
    results = []
    
    for test_name, test_func in tests:
        print(f"🧪 Running: {test_name}")
        result = test_func()
        results.append((test_name, result))
        print()
        
        # Short delay between tests
        time.sleep(1)
    
    # Summary
    print("📋 VALIDATION SUMMARY")
    print("=" * 30)
    
    passed = 0
    for test_name, result in results:
        status = "✅ PASS" if result else "❌ FAIL"
        print(f"{status} {test_name}")
        if result:
            passed += 1
    
    print()
    print(f"📊 Results: {passed}/{len(results)} tests passed")
    
    if passed == len(results):
        print("🎉 ALL TESTS PASSED! Ollama containerization is working correctly!")
        print()
        print("🎸 Your guitar terminology evaluator is now using containerized Ollama!")
        print("   • No more local Ollama dependency")
        print("   • Automatic model management")
        print("   • Improved reliability and easier scaling")
        return True
    else:
        print("❌ Some tests failed. Please check the errors above.")
        print()
        print("💡 Troubleshooting tips:")
        print("   • Ensure all services are running: docker-compose up -d")
        print("   • Check service logs: docker-compose logs ollama-service")
        print("   • Wait for model download to complete (first startup)")
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 