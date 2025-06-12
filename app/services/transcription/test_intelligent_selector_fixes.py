#!/usr/bin/env python3
"""
Test script to verify intelligent selector fixes work properly.

This script tests the fixes for:
1. Unrealistic escalation thresholds
2. Temporal quality score calculation bug
3. Infinite loop prevention
4. Redundancy reduction
"""

import sys
import os
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from intelligent_selector import IntelligentModelSelector, ComprehensiveQualityMetrics
from quality_metrics import AdvancedQualityAnalyzer
import logging

# Set up logging
logging.basicConfig(level=logging.INFO, format='%(levelname)s: %(message)s')
logger = logging.getLogger(__name__)

def test_escalation_thresholds():
    """Test that escalation thresholds are now realistic."""
    logger.info("=== Testing Escalation Thresholds ===")
    
    selector = IntelligentModelSelector()
    
    # Test case 1: Good quality (84.3%) should NOT escalate for small model
    good_metrics = ComprehensiveQualityMetrics(
        overall_quality_score=0.843,  # This was escalating before
        confidence_score=0.824,
        speech_activity_score=0.8,
        content_quality_score=0.7,
        temporal_quality_score=0.5,  # This was 0.0 before causing issues
        model_performance_score=0.6,
        processing_time=60.0,
        cost_efficiency=0.7
    )
    
    should_escalate, reason = selector.decision_matrix.should_escalate(good_metrics, 'small')
    
    if should_escalate:
        logger.error(f"❌ FAIL: Good quality (84.3%) still escalating for small model: {reason}")
        return False
    else:
        logger.info(f"✅ PASS: Good quality (84.3%) correctly NOT escalating for small model: {reason}")
    
    # Test case 2: Excellent confidence should prevent escalation
    excellent_confidence_metrics = ComprehensiveQualityMetrics(
        overall_quality_score=0.70,  # Below threshold
        confidence_score=0.86,       # But excellent confidence
        speech_activity_score=0.8,
        content_quality_score=0.7,
        temporal_quality_score=0.5,
        model_performance_score=0.6,
        processing_time=60.0,
        cost_efficiency=0.7
    )
    
    should_escalate, reason = selector.decision_matrix.should_escalate(excellent_confidence_metrics, 'small')
    
    if should_escalate:
        logger.error(f"❌ FAIL: Excellent confidence (86%) still escalating: {reason}")
        return False
    else:
        logger.info(f"✅ PASS: Excellent confidence (86%) correctly prevents escalation: {reason}")
    
    return True

def test_temporal_quality_fix():
    """Test that temporal quality calculation no longer returns 0.0 inappropriately."""
    logger.info("=== Testing Temporal Quality Fix ===")
    
    analyzer = AdvancedQualityAnalyzer()
    
    # Test case 1: Empty segments should return reasonable default
    empty_consistency = analyzer.calculate_timing_consistency([], [])
    
    if empty_consistency == 0.0:
        logger.error(f"❌ FAIL: Empty segments still returning 0.0 consistency: {empty_consistency}")
        return False
    elif empty_consistency >= 0.3:
        logger.info(f"✅ PASS: Empty segments return reasonable default: {empty_consistency}")
    else:
        logger.error(f"❌ FAIL: Empty segments returning too low consistency: {empty_consistency}")
        return False
        
    # Test case 2: Normal segments with guitar lesson gaps should be acceptable
    normal_segments = [
        {'start': 0, 'end': 5},
        {'start': 10, 'end': 15},  # 5 second gap for guitar demonstration
        {'start': 20, 'end': 25}
    ]
    
    normal_words = [
        {'start': 0, 'end': 1, 'word': 'now'},
        {'start': 1, 'end': 2, 'word': 'play'},
        {'start': 2, 'end': 3, 'word': 'this'},
        {'start': 10, 'end': 11, 'word': 'good'},  # After demonstration gap
        {'start': 11, 'end': 12, 'word': 'job'}
    ]
    
    normal_consistency = analyzer.calculate_timing_consistency(normal_segments, normal_words)
    
    if normal_consistency < 0.3:
        logger.error(f"❌ FAIL: Normal guitar lesson timing getting low consistency: {normal_consistency}")
        return False
    else:
        logger.info(f"✅ PASS: Normal guitar lesson timing gets reasonable consistency: {normal_consistency}")
        
    return True

def test_escalation_safety():
    """Test escalation safety mechanisms."""
    logger.info("=== Testing Escalation Safety ===")
    
    selector = IntelligentModelSelector()
    
    # Test case 1: Quality regression should stop escalation
    decisions = [
        type('Decision', (), {
            'model_used': 'small',
            'quality_score': 0.84,
            'processing_time': 60
        })(),
        type('Decision', (), {
            'model_used': 'medium', 
            'quality_score': 0.83,  # Worse quality
            'processing_time': 66
        })()
    ]
    
    should_stop, reason = selector._should_stop_escalation(decisions, 'large-v3')
    
    if not should_stop:
        logger.error(f"❌ FAIL: Quality regression not detected: {reason}")
        return False
    else:
        logger.info(f"✅ PASS: Quality regression correctly detected: {reason}")
    
    # Test case 2: Repeated model attempts should be prevented
    repeated_decisions = [
        type('Decision', (), {'model_used': 'small', 'quality_score': 0.70})(),
        type('Decision', (), {'model_used': 'medium', 'quality_score': 0.72})()
    ]
    
    should_stop, reason = selector._should_stop_escalation(repeated_decisions, 'small')  # Trying small again
    
    if not should_stop:
        logger.error(f"❌ FAIL: Repeated model attempt not prevented: {reason}")
        return False
    else:
        logger.info(f"✅ PASS: Repeated model attempt correctly prevented: {reason}")
        
    return True

def test_realistic_thresholds():
    """Test that the new thresholds are realistic for actual transcription quality."""
    logger.info("=== Testing Realistic Quality Thresholds ===")
    
    selector = IntelligentModelSelector()
    
    # These should be realistic quality scores that don't trigger unnecessary escalation
    realistic_scores = [
        ('tiny', 0.66, "Good tiny model result"),
        ('small', 0.72, "Good small model result"), 
        ('medium', 0.78, "Good medium model result"),
        ('large-v3', 0.82, "Good large model result")
    ]
    
    all_passed = True
    
    for model, score, description in realistic_scores:
        metrics = ComprehensiveQualityMetrics(
            overall_quality_score=score,
            confidence_score=score + 0.02,  # Slightly higher confidence
            speech_activity_score=0.8,
            content_quality_score=0.7,
            temporal_quality_score=0.6,
            model_performance_score=0.6,
            processing_time=60.0,
            cost_efficiency=0.7
        )
        
        should_escalate, reason = selector.decision_matrix.should_escalate(metrics, model)
        
        if should_escalate:
            logger.error(f"❌ FAIL: {description} (quality={score:.2f}) unnecessarily escalating: {reason}")
            all_passed = False
        else:
            logger.info(f"✅ PASS: {description} (quality={score:.2f}) correctly accepted: {reason}")
    
    return all_passed

def main():
    """Run all tests."""
    logger.info("🔧 Testing Intelligent Selector Fixes")
    logger.info("=" * 60)
    
    tests = [
        ("Escalation Thresholds", test_escalation_thresholds),
        ("Temporal Quality Fix", test_temporal_quality_fix),
        ("Escalation Safety", test_escalation_safety),
        ("Realistic Thresholds", test_realistic_thresholds)
    ]
    
    passed = 0
    total = len(tests)
    
    for test_name, test_func in tests:
        logger.info(f"\n--- {test_name} ---")
        try:
            if test_func():
                passed += 1
                logger.info(f"✅ {test_name}: PASSED")
            else:
                logger.error(f"❌ {test_name}: FAILED")
        except Exception as e:
            logger.error(f"❌ {test_name}: ERROR - {e}")
    
    logger.info("\n" + "=" * 60)
    logger.info(f"🏁 Test Results: {passed}/{total} tests passed")
    
    if passed == total:
        logger.info("🎉 All fixes working correctly! Infinite loop prevention implemented.")
        return True
    else:
        logger.error("⚠️  Some fixes need attention.")
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1) 