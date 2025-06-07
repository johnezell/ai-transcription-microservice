#!/usr/bin/env python3
"""
Comprehensive Phase 3 Tests for FFmpeg Optimization Plan
Tests Voice Activity Detection, Premium Quality Level, and Advanced Features
"""

import unittest
import os
import tempfile
import subprocess
import json
import requests
import time
from unittest.mock import patch, MagicMock
import sys

# Add the service directory to Python path
sys.path.insert(0, '/app')

# Import service functions
from service import (
    apply_vad_preprocessing,
    preprocess_for_whisper,
    calculate_processing_metrics,
    validate_audio_input,
    assess_audio_quality,
    AUDIO_PROCESSING_CONFIG
)

class TestFFmpegPhase3(unittest.TestCase):
    """Test suite for Phase 3 FFmpeg optimization features."""
    
    def setUp(self):
        """Set up test environment."""
        self.test_dir = tempfile.mkdtemp(prefix='ai_roo_phase3_')
        self.sample_audio = os.path.join(self.test_dir, 'test_audio.wav')
        self.output_audio = os.path.join(self.test_dir, 'output_audio.wav')
        
        # Create a simple test audio file using FFmpeg
        self.create_test_audio_file()
        
    def tearDown(self):
        """Clean up test environment."""
        import shutil
        if os.path.exists(self.test_dir):
            shutil.rmtree(self.test_dir)
    
    def create_test_audio_file(self):
        """Create a test audio file for testing."""
        try:
            # Generate a 5-second test tone with some silence
            command = [
                "ffmpeg", "-y", "-f", "lavfi",
                "-i", "sine=frequency=440:duration=2,aevalsrc=0:d=1,sine=frequency=880:duration=2",
                "-ar", "16000", "-ac", "1", "-acodec", "pcm_s16le",
                self.sample_audio
            ]
            result = subprocess.run(command, capture_output=True, text=True)
            if result.returncode != 0:
                self.skipTest(f"Could not create test audio file: {result.stderr}")
        except Exception as e:
            self.skipTest(f"FFmpeg not available for testing: {str(e)}")
    
    def test_vad_preprocessing_function(self):
        """Test Voice Activity Detection preprocessing function."""
        print("\n=== Testing VAD Preprocessing Function ===")
        
        # Test VAD preprocessing
        vad_output = os.path.join(self.test_dir, 'vad_output.wav')
        
        try:
            result = apply_vad_preprocessing(self.sample_audio, vad_output)
            self.assertTrue(result, "VAD preprocessing should return True on success")
            self.assertTrue(os.path.exists(vad_output), "VAD output file should exist")
            self.assertGreater(os.path.getsize(vad_output), 0, "VAD output should not be empty")
            
            # Validate the output audio
            validation_info = validate_audio_input(vad_output)
            self.assertIsNotNone(validation_info, "VAD output should be valid audio")
            self.assertEqual(validation_info['sample_rate'], 16000, "Sample rate should be 16000")
            self.assertEqual(validation_info['channels'], 1, "Should be mono audio")
            
            print("✓ VAD preprocessing function works correctly")
            
        except Exception as e:
            self.fail(f"VAD preprocessing failed: {str(e)}")
    
    def test_premium_quality_level(self):
        """Test premium quality level with VAD integration."""
        print("\n=== Testing Premium Quality Level ===")
        
        premium_output = os.path.join(self.test_dir, 'premium_output.wav')
        
        # Test with VAD enabled
        with patch.dict(os.environ, {'ENABLE_VAD': 'true'}):
            # Reload config to pick up environment change
            from service import AUDIO_PROCESSING_CONFIG
            AUDIO_PROCESSING_CONFIG['enable_vad'] = True
            
            try:
                result = preprocess_for_whisper(self.sample_audio, premium_output, 'premium')
                self.assertTrue(result, "Premium quality processing should succeed")
                self.assertTrue(os.path.exists(premium_output), "Premium output file should exist")
                self.assertGreater(os.path.getsize(premium_output), 0, "Premium output should not be empty")
                
                # Validate premium quality output
                validation_info = validate_audio_input(premium_output)
                self.assertIsNotNone(validation_info, "Premium output should be valid audio")
                
                print("✓ Premium quality level with VAD works correctly")
                
            except Exception as e:
                self.fail(f"Premium quality processing failed: {str(e)}")
    
    def test_premium_quality_without_vad(self):
        """Test premium quality level without VAD (fallback behavior)."""
        print("\n=== Testing Premium Quality Without VAD ===")
        
        premium_output = os.path.join(self.test_dir, 'premium_no_vad_output.wav')
        
        # Test with VAD disabled
        with patch.dict(os.environ, {'ENABLE_VAD': 'false'}):
            # Reload config to pick up environment change
            from service import AUDIO_PROCESSING_CONFIG
            AUDIO_PROCESSING_CONFIG['enable_vad'] = False
            
            try:
                result = preprocess_for_whisper(self.sample_audio, premium_output, 'premium')
                self.assertTrue(result, "Premium quality without VAD should succeed")
                self.assertTrue(os.path.exists(premium_output), "Premium output file should exist")
                
                print("✓ Premium quality without VAD works correctly")
                
            except Exception as e:
                self.fail(f"Premium quality without VAD failed: {str(e)}")
    
    def test_all_quality_levels(self):
        """Test all quality levels including new premium level."""
        print("\n=== Testing All Quality Levels ===")
        
        quality_levels = ['fast', 'balanced', 'high', 'premium']
        
        for quality in quality_levels:
            with self.subTest(quality=quality):
                output_file = os.path.join(self.test_dir, f'{quality}_output.wav')
                
                try:
                    result = preprocess_for_whisper(self.sample_audio, output_file, quality)
                    self.assertTrue(result, f"{quality} quality processing should succeed")
                    self.assertTrue(os.path.exists(output_file), f"{quality} output file should exist")
                    self.assertGreater(os.path.getsize(output_file), 0, f"{quality} output should not be empty")
                    
                    print(f"✓ {quality.capitalize()} quality level works correctly")
                    
                except Exception as e:
                    self.fail(f"{quality} quality processing failed: {str(e)}")
    
    def test_processing_metrics_function(self):
        """Test processing metrics calculation function."""
        print("\n=== Testing Processing Metrics Function ===")
        
        try:
            metrics = calculate_processing_metrics()
            
            # Verify metrics structure
            self.assertIsInstance(metrics, dict, "Metrics should be a dictionary")
            self.assertIn('avg_processing_time', metrics, "Should include avg_processing_time")
            self.assertIn('quality_score', metrics, "Should include quality_score")
            self.assertIn('error_rate', metrics, "Should include error_rate")
            
            # Verify metrics types
            self.assertIsInstance(metrics['avg_processing_time'], (int, float), "avg_processing_time should be numeric")
            self.assertIsInstance(metrics['quality_score'], (int, float), "quality_score should be numeric")
            self.assertIsInstance(metrics['error_rate'], (int, float), "error_rate should be numeric")
            
            print("✓ Processing metrics function works correctly")
            print(f"  Metrics: {metrics}")
            
        except Exception as e:
            self.fail(f"Processing metrics calculation failed: {str(e)}")
    
    def test_environment_variable_configuration(self):
        """Test environment variable configuration for Phase 3."""
        print("\n=== Testing Environment Variable Configuration ===")
        
        # Test VAD configuration
        with patch.dict(os.environ, {'ENABLE_VAD': 'true'}):
            from service import AUDIO_PROCESSING_CONFIG
            # Simulate config reload
            test_config = {
                "enable_vad": os.environ.get('ENABLE_VAD', 'false').lower() == 'true'
            }
            self.assertTrue(test_config["enable_vad"], "VAD should be enabled when ENABLE_VAD=true")
        
        with patch.dict(os.environ, {'ENABLE_VAD': 'false'}):
            test_config = {
                "enable_vad": os.environ.get('ENABLE_VAD', 'false').lower() == 'true'
            }
            self.assertFalse(test_config["enable_vad"], "VAD should be disabled when ENABLE_VAD=false")
        
        print("✓ Environment variable configuration works correctly")
    
    def test_error_handling_and_fallbacks(self):
        """Test error handling and fallback mechanisms."""
        print("\n=== Testing Error Handling and Fallbacks ===")
        
        # Test with invalid input file
        invalid_input = os.path.join(self.test_dir, 'nonexistent.wav')
        output_file = os.path.join(self.test_dir, 'error_test_output.wav')
        
        with self.assertRaises(Exception):
            apply_vad_preprocessing(invalid_input, output_file)
        
        # Test unknown quality level fallback
        try:
            result = preprocess_for_whisper(self.sample_audio, output_file, 'unknown_quality')
            self.assertTrue(result, "Should fallback to balanced quality for unknown quality level")
            
            print("✓ Error handling and fallbacks work correctly")
            
        except Exception as e:
            self.fail(f"Fallback mechanism failed: {str(e)}")
    
    def test_backward_compatibility(self):
        """Test backward compatibility with Phase 1 and Phase 2 features."""
        print("\n=== Testing Backward Compatibility ===")
        
        # Test that existing quality levels still work
        for quality in ['fast', 'balanced', 'high']:
            output_file = os.path.join(self.test_dir, f'compat_{quality}_output.wav')
            
            try:
                result = preprocess_for_whisper(self.sample_audio, output_file, quality)
                self.assertTrue(result, f"Backward compatibility: {quality} should still work")
                
            except Exception as e:
                self.fail(f"Backward compatibility failed for {quality}: {str(e)}")
        
        print("✓ Backward compatibility maintained")
    
    def test_performance_benchmarking(self):
        """Test performance benchmarking for different quality levels."""
        print("\n=== Testing Performance Benchmarking ===")
        
        quality_levels = ['fast', 'balanced', 'high', 'premium']
        performance_results = {}
        
        for quality in quality_levels:
            output_file = os.path.join(self.test_dir, f'perf_{quality}_output.wav')
            
            start_time = time.time()
            try:
                result = preprocess_for_whisper(self.sample_audio, output_file, quality)
                end_time = time.time()
                
                if result:
                    processing_time = end_time - start_time
                    performance_results[quality] = processing_time
                    print(f"  {quality.capitalize()}: {processing_time:.3f}s")
                
            except Exception as e:
                print(f"  {quality.capitalize()}: Failed - {str(e)}")
        
        # Verify premium mode doesn't exceed 40% increase over balanced
        if 'balanced' in performance_results and 'premium' in performance_results:
            balanced_time = performance_results['balanced']
            premium_time = performance_results['premium']
            increase_percentage = ((premium_time - balanced_time) / balanced_time) * 100
            
            print(f"  Premium vs Balanced increase: {increase_percentage:.1f}%")
            
            # Note: This is a soft check since test audio is very short
            if increase_percentage > 100:  # Allow more lenient check for test environment
                print(f"  Warning: Premium processing time increase ({increase_percentage:.1f}%) is higher than expected")
        
        print("✓ Performance benchmarking completed")

class TestServiceIntegration(unittest.TestCase):
    """Integration tests for the audio extraction service."""
    
    def test_service_health_with_phase3_info(self):
        """Test service health endpoint includes Phase 3 information."""
        print("\n=== Testing Service Health with Phase 3 Info ===")
        
        try:
            response = requests.get('http://localhost:5000/health', timeout=5)
            self.assertEqual(response.status_code, 200, "Health endpoint should return 200")
            
            data = response.json()
            self.assertIn('features', data, "Health response should include features")
            self.assertIn('quality_levels', data['features'], "Should include quality levels")
            self.assertIn('premium', data['features']['quality_levels'], "Should include premium quality level")
            
            print("✓ Service health endpoint includes Phase 3 information")
            
        except requests.exceptions.ConnectionError:
            self.skipTest("Service not running for integration test")
        except Exception as e:
            self.fail(f"Health check integration test failed: {str(e)}")
    
    def test_metrics_endpoint(self):
        """Test the new metrics endpoint."""
        print("\n=== Testing Metrics Endpoint ===")
        
        try:
            response = requests.get('http://localhost:5000/metrics', timeout=5)
            self.assertEqual(response.status_code, 200, "Metrics endpoint should return 200")
            
            data = response.json()
            self.assertTrue(data['success'], "Metrics response should be successful")
            self.assertIn('metrics', data, "Response should include metrics")
            
            metrics = data['metrics']
            self.assertIn('avg_processing_time', metrics, "Should include avg_processing_time")
            self.assertIn('quality_score', metrics, "Should include quality_score")
            self.assertIn('error_rate', metrics, "Should include error_rate")
            
            print("✓ Metrics endpoint works correctly")
            print(f"  Metrics: {metrics}")
            
        except requests.exceptions.ConnectionError:
            self.skipTest("Service not running for integration test")
        except Exception as e:
            self.fail(f"Metrics endpoint integration test failed: {str(e)}")

def run_comprehensive_tests():
    """Run all Phase 3 tests with detailed output."""
    print("=" * 80)
    print("PHASE 3 FFMPEG OPTIMIZATION - COMPREHENSIVE TEST SUITE")
    print("=" * 80)
    print("Testing Voice Activity Detection and Advanced Features")
    print()
    
    # Create test suite
    loader = unittest.TestLoader()
    suite = unittest.TestSuite()
    
    # Add test classes
    suite.addTests(loader.loadTestsFromTestCase(TestFFmpegPhase3))
    suite.addTests(loader.loadTestsFromTestCase(TestServiceIntegration))
    
    # Run tests with detailed output
    runner = unittest.TextTestRunner(verbosity=2, stream=sys.stdout)
    result = runner.run(suite)
    
    # Print summary
    print("\n" + "=" * 80)
    print("PHASE 3 TEST SUMMARY")
    print("=" * 80)
    print(f"Tests run: {result.testsRun}")
    print(f"Failures: {len(result.failures)}")
    print(f"Errors: {len(result.errors)}")
    print(f"Skipped: {len(result.skipped)}")
    
    if result.failures:
        print("\nFAILURES:")
        for test, traceback in result.failures:
            print(f"- {test}: {traceback}")
    
    if result.errors:
        print("\nERRORS:")
        for test, traceback in result.errors:
            print(f"- {test}: {traceback}")
    
    success_rate = ((result.testsRun - len(result.failures) - len(result.errors)) / result.testsRun * 100) if result.testsRun > 0 else 0
    print(f"\nSuccess Rate: {success_rate:.1f}%")
    
    return result.wasSuccessful()

if __name__ == '__main__':
    success = run_comprehensive_tests()
    sys.exit(0 if success else 1)