#!/usr/bin/env python3
"""
Test script for the Guitar Terminology Evaluator integration.

This script tests the guitar terminology evaluator both with mock data
and with real audio files if available.
"""

import requests
import json
import sys
import os
from datetime import datetime

# Configuration
TRANSCRIPTION_SERVICE_URL = "http://localhost:5051"  # Updated to match transcription service port
LLM_ENDPOINT = "http://localhost:11434/api/generate"  # External testing endpoint (Ollama exposed on host)
MODEL_NAME = "llama3:latest"  # Updated to match containerized model

def test_with_mock_data():
    """Test the guitar terminology evaluator with mock data"""
    print("Testing Guitar Terminology Evaluator with Mock Data")
    print("=" * 60)
    
    url = f"{TRANSCRIPTION_SERVICE_URL}/test-guitar-term-evaluator"
    payload = {
        "llm_endpoint": LLM_ENDPOINT,
        "model_name": MODEL_NAME
    }
    
    try:
        print(f"Making request to: {url}")
        response = requests.post(url, json=payload, timeout=60)
        
        if response.status_code == 200:
            result = response.json()
            
            print("[PASS] Test completed successfully!")
            print(f"Test Type: {result.get('test_type')}")
            print(f"Message: {result.get('message')}")
            
            # Display comparison results
            comparison = result.get('enhancement_comparison', [])
            if comparison:
                print(f"\n[Enhanced] Enhanced {len(comparison)} musical terms:")
                for term in comparison:
                    print(f"  • '{term['word']}': {term['original_confidence']:.2f} → {term['enhanced_confidence']:.2f} (+{term['improvement']:.2f})")
            
            # Display evaluation metrics
            eval_data = result.get('guitar_term_evaluation', {})
            if eval_data:
                print(f"\n[Results] Evaluation Results:")
                print(f"  • Total words evaluated: {eval_data.get('total_words_evaluated', 0)}")
                print(f"  • Musical terms found: {eval_data.get('musical_terms_found', 0)}")
                print(f"  • Non-musical terms unchanged: {eval_data.get('non_musical_terms_unchanged', 0)}")
                print(f"  • LLM model used: {eval_data.get('llm_used', 'N/A')}")
                print(f"  • Cache hits: {eval_data.get('cache_hits', 0)}")
                print(f"  • Note: {eval_data.get('note', 'N/A')}")
            
            # Display before/after averages
            original = result.get('original_result', {})
            enhanced = result.get('enhanced_result', {})
            
            print(f"\n[Stats] Average Confidence Improvement:")
            print(f"  • Before: {original.get('average_confidence', 0):.3f}")
            print(f"  • After:  {enhanced.get('average_confidence', 0):.3f}")
            print(f"  • Improvement: +{enhanced.get('average_confidence', 0) - original.get('average_confidence', 0):.3f}")
            
            return True
            
        else:
            print(f"[FAIL] Test failed with status {response.status_code}")
            print(f"Response: {response.text}")
            return False
            
    except requests.exceptions.ConnectionError:
        print("[FAIL] Failed to connect to transcription service")
        print(f"Make sure the service is running at {TRANSCRIPTION_SERVICE_URL}")
        return False
    except Exception as e:
        print(f"[FAIL] Test failed with error: {e}")
        return False

def test_with_real_audio(audio_path):
    """Test the guitar terminology evaluator with a real audio file"""
    print(f"Testing Guitar Terminology Evaluator with Real Audio")
    print("=" * 60)
    print(f"Audio file: {audio_path}")
    
    if not os.path.exists(audio_path):
        print(f"[FAIL] Audio file not found: {audio_path}")
        return False
    
    url = f"{TRANSCRIPTION_SERVICE_URL}/test-guitar-term-evaluator"
    payload = {
        "audio_path": audio_path,
        "llm_endpoint": LLM_ENDPOINT,
        "model_name": MODEL_NAME
    }
    
    try:
        print(f"Making request to: {url}")
        print("[Processing] Processing audio file (this may take a while)...")
        response = requests.post(url, json=payload, timeout=300)  # 5 min timeout for real audio
        
        if response.status_code == 200:
            result = response.json()
            
            print("[PASS] Test completed successfully!")
            print(f"Test Type: {result.get('test_type')}")
            
            # Display transcript preview
            enhanced = result.get('enhanced_result', {})
            text = enhanced.get('text', '')
            if text:
                preview = text[:200] + "..." if len(text) > 200 else text
                print(f"\n[Transcript] Transcript Preview:\n{preview}")
            
            # Display comparison results
            comparison = result.get('enhancement_comparison', [])
            if comparison:
                print(f"\n[Enhanced] Enhanced {len(comparison)} musical terms:")
                for term in comparison[:10]:  # Show first 10
                    print(f"  • '{term['word']}': {term['original_confidence']:.2f} → {term['enhanced_confidence']:.2f} (+{term['improvement']:.2f})")
                if len(comparison) > 10:
                    print(f"  ... and {len(comparison) - 10} more terms")
            
            # Display evaluation metrics
            eval_data = result.get('guitar_term_evaluation', {})
            if eval_data:
                print(f"\n[Results] Evaluation Results:")
                print(f"  • Total words evaluated: {eval_data.get('total_words_evaluated', 0)}")
                print(f"  • Musical terms found: {eval_data.get('musical_terms_found', 0)}")
                print(f"  • Non-musical terms unchanged: {eval_data.get('non_musical_terms_unchanged', 0)}")
                print(f"  • Enhancement percentage: {(eval_data.get('musical_terms_found', 0) / max(1, eval_data.get('total_words_evaluated', 1))) * 100:.1f}%")
                print(f"  • Note: {eval_data.get('note', 'N/A')}")
            
            return True
            
        else:
            print(f"[FAIL] Test failed with status {response.status_code}")
            try:
                error_data = response.json()
                print(f"Error: {error_data.get('error_message', 'Unknown error')}")
            except:
                print(f"Response: {response.text}")
            return False
            
    except requests.exceptions.Timeout:
        print("[FAIL] Test timed out - audio file may be too large")
        return False
    except requests.exceptions.ConnectionError:
        print("[FAIL] Failed to connect to transcription service")
        print(f"Make sure the service is running at {TRANSCRIPTION_SERVICE_URL}")
        return False
    except Exception as e:
        print(f"[FAIL] Test failed with error: {e}")
        return False

def check_service_health():
    """Check if the transcription service is running"""
    try:
        response = requests.get(f"{TRANSCRIPTION_SERVICE_URL}/health", timeout=5)
        return response.status_code == 200
    except:
        return False

def check_ollama_health():
    """Check if Ollama is running and accessible"""
    try:
        response = requests.post(
            LLM_ENDPOINT,
            json={
                "model": MODEL_NAME,
                "prompt": "Test",
                "stream": False,
                "options": {"num_predict": 1}
            },
            timeout=10
        )
        return response.status_code == 200
    except:
        return False

def main():
    print("Guitar Terminology Evaluator Test Suite")
    print("=" * 60)
    print(f"Timestamp: {datetime.now().isoformat()}")
    print(f"Service URL: {TRANSCRIPTION_SERVICE_URL}")
    print(f"LLM Endpoint: {LLM_ENDPOINT}")
    print(f"LLM Model: {MODEL_NAME}")
    print()
    
    # Check service health
    print("[Checking] Checking service health...")
    if not check_service_health():
        print("[FAIL] Transcription service is not accessible")
        print("Please start the transcription service and try again")
        sys.exit(1)
    print("[PASS] Transcription service is running")
    
    # Check Ollama health
    print("[Checking] Checking Ollama/LLM health...")
    if not check_ollama_health():
        print("[Warning]  Ollama/LLM is not accessible")
        print("The evaluator will use fallback dictionary mode")
        print("To use AI evaluation, please:")
        print("  1. Install Ollama: curl -fsSL https://ollama.ai/install.sh | sh")
        print("  2. Pull model: ollama pull llama2")
        print("  3. Start server: ollama serve")
    else:
        print("[PASS] Ollama/LLM is running and accessible")
    
    print()
    
    # Test with mock data
    mock_success = test_with_mock_data()
    print()
    
    # Test with real audio if provided
    if len(sys.argv) > 1:
        audio_path = sys.argv[1]
        real_success = test_with_real_audio(audio_path)
    else:
        print("[Note] To test with real audio, run: python test_guitar_evaluator.py /path/to/audio.wav")
        real_success = True  # Don't count as failure
    
    print()
    print(" Test Summary")
    print("=" * 30)
    print(f"Mock Data Test: {'[PASS] PASSED' if mock_success else '[FAIL] FAILED'}")
    if len(sys.argv) > 1:
        print(f"Real Audio Test: {'[PASS] PASSED' if real_success else '[FAIL] FAILED'}")
    
    if mock_success and (len(sys.argv) == 1 or real_success):
        print("\n[Success] All tests passed! Guitar terminology evaluator is working correctly.")
        sys.exit(0)
    else:
        print("\n[FAIL] Some tests failed. Please check the service configuration.")
        sys.exit(1)

if __name__ == "__main__":
    main() 