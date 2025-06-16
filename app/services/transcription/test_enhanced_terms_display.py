#!/usr/bin/env python3

import requests
import json

def test_enhanced_terms_display():
    """Test the enhanced terms data structure"""
    print("🔍 Testing Guitar Term Evaluator Enhanced Terms Display")
    print("=" * 60)
    
    try:
        # Test with mock data
        payload = {'audio_path': 'mock_data'}
        response = requests.post('http://localhost:5000/test-guitar-term-evaluator', 
                               json=payload, timeout=30)
        
        if response.status_code != 200:
            print(f"❌ Error: HTTP {response.status_code}")
            return False
        
        result = response.json()
        
        if not result.get('success'):
            print(f"❌ Test failed: {result.get('error_message', 'Unknown error')}")
            return False
        
        # Extract guitar term evaluation data
        eval_data = result.get('guitar_term_evaluation', {})
        enhanced_terms = eval_data.get('enhanced_terms', [])
        
        print(f"✅ Test successful!")
        print(f"📊 Summary:")
        print(f"   Total words evaluated: {eval_data.get('total_words_evaluated', 0)}")
        print(f"   Musical terms found: {eval_data.get('musical_terms_found', 0)}")
        print(f"   Enhanced terms in data: {len(enhanced_terms)}")
        print(f"   LLM used: {eval_data.get('llm_used', 'Unknown')}")
        print()
        
        if enhanced_terms:
            print(f"🎸 Enhanced Terms Details ({len(enhanced_terms)} terms):")
            print("-" * 60)
            
            for i, term in enumerate(enhanced_terms, 1):
                word = term.get('word', 'unknown')
                original_conf = term.get('original_confidence', 0)
                new_conf = term.get('new_confidence', 0) 
                start_time = term.get('start', 0)
                end_time = term.get('end', 0)
                boost_reason = term.get('boost_reason', 'unknown')
                
                print(f"   {i:2d}. \"{word}\"")
                print(f"       Confidence: {original_conf:.3f} → {new_conf:.3f} (+{new_conf - original_conf:.3f})")
                print(f"       Timing: {start_time:.1f}s - {end_time:.1f}s")
                print(f"       Reason: {boost_reason}")
                print()
                
        else:
            print("❌ No enhanced terms found in the response data!")
            print("   This suggests the guitar term evaluator is not finding any terms to enhance.")
            
        # Show the structure for debugging
        print("🔧 Data Structure for Frontend:")
        print(f"   result.guitar_term_evaluation.enhanced_terms = {len(enhanced_terms)} items")
        if enhanced_terms:
            term_keys = list(enhanced_terms[0].keys()) if enhanced_terms else []
            print(f"   Each term has keys: {term_keys}")
            
        return len(enhanced_terms) > 0
        
    except Exception as e:
        print(f"❌ Exception: {e}")
        return False

if __name__ == "__main__":
    test_enhanced_terms_display() 