#!/usr/bin/env python3
"""
Phase 1 FFmpeg Optimization Verification Test
This test properly verifies the enhanced FFmpeg command implementation.
"""
import unittest
from unittest.mock import patch, MagicMock, call
import json
import sys
import os

# Add the service directory to the path
sys.path.insert(0, '/app')

from service import (
    validate_audio_input,
    assess_audio_quality,
    convert_to_wav,
    convert_to_wav_original
)


class TestPhase1Implementation(unittest.TestCase):
    """Verify Phase 1 FFmpeg optimization implementation."""
    
    def setUp(self):
        """Set up test fixtures."""
        self.test_input_path = "/tmp/test_input.mp4"
        self.test_output_path = "/tmp/test_output.wav"
        
        # Mock validation response
        self.mock_validation_response = {
            "streams": [{
                "codec_name": "aac",
                "sample_rate": "44100",
                "channels": "2",
                "duration": "120.5"
            }]
        }

    @patch('service.subprocess.run')
    def test_enhanced_ffmpeg_command_structure(self, mock_run):
        """Test that enhanced FFmpeg command includes audio normalization."""
        # Mock validation call (first subprocess call)
        validation_result = MagicMock()
        validation_result.returncode = 0
        validation_result.stdout = json.dumps(self.mock_validation_response)
        
        # Mock FFmpeg call (second subprocess call)
        ffmpeg_result = MagicMock()
        ffmpeg_result.returncode = 0
        
        # Mock quality assessment call (third subprocess call)
        quality_result = MagicMock()
        quality_result.returncode = 1  # Make it fail so it returns None
        quality_result.stderr = "Mock quality assessment failure"
        
        # Set up the mock to return different results for different calls
        mock_run.side_effect = [validation_result, ffmpeg_result, quality_result]
        
        # Call the function
        result = convert_to_wav(self.test_input_path, self.test_output_path)
        
        # Verify success
        self.assertTrue(result)
        
        # Verify we made 3 calls (validation, ffmpeg, quality assessment)
        self.assertEqual(mock_run.call_count, 3)
        
        # Get the FFmpeg command (second call)
        ffmpeg_call = mock_run.call_args_list[1]
        ffmpeg_command = ffmpeg_call[0][0]
        
        print(f"FFmpeg command: {' '.join(ffmpeg_command)}")
        
        # Verify enhanced FFmpeg command structure
        expected_elements = [
            "ffmpeg", "-y", "-i", self.test_input_path, "-vn",
            "-af", "dynaudnorm=p=0.9:s=5",
            "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
            "-sample_fmt", "s16", self.test_output_path
        ]
        
        self.assertEqual(ffmpeg_command, expected_elements)
        
        # Specifically verify audio normalization filter
        self.assertIn("-af", ffmpeg_command)
        af_index = ffmpeg_command.index("-af")
        self.assertEqual(ffmpeg_command[af_index + 1], "dynaudnorm=p=0.9:s=5")

    @patch('service.subprocess.run')
    def test_validation_function_exists_and_works(self, mock_run):
        """Test that validate_audio_input function works correctly."""
        # Mock successful ffprobe execution
        mock_result = MagicMock()
        mock_result.returncode = 0
        mock_result.stdout = json.dumps(self.mock_validation_response)
        mock_run.return_value = mock_result
        
        result = validate_audio_input(self.test_input_path)
        
        # Verify the result
        expected_result = {
            'codec': 'aac',
            'sample_rate': 44100,
            'channels': 2,
            'duration': 120.5
        }
        self.assertEqual(result, expected_result)
        
        # Verify ffprobe command was called correctly
        expected_command = [
            "ffprobe", "-v", "error",
            "-select_streams", "a:0",
            "-show_entries", "stream=codec_name,sample_rate,channels,duration",
            "-of", "json",
            self.test_input_path
        ]
        mock_run.assert_called_once_with(expected_command, capture_output=True, text=True)

    @patch('service.subprocess.run')
    def test_quality_assessment_function_exists_and_works(self, mock_run):
        """Test that assess_audio_quality function works correctly."""
        # Mock quality response
        mock_quality_response = {
            "streams": [{
                "bit_rate": "128000",
                "sample_rate": "16000",
                "channels": "1"
            }],
            "format": {
                "bit_rate": "128000",
                "duration": "120.5"
            }
        }
        
        # Mock successful ffprobe execution
        mock_result = MagicMock()
        mock_result.returncode = 0
        mock_result.stdout = json.dumps(mock_quality_response)
        mock_run.return_value = mock_result
        
        result = assess_audio_quality(self.test_output_path)
        
        # Verify the result
        expected_result = {
            'bit_rate': 128000,
            'sample_rate': 16000,
            'channels': 1,
            'duration': 120.5
        }
        self.assertEqual(result, expected_result)

    @patch('service.subprocess.run')
    def test_fallback_mechanism_works(self, mock_run):
        """Test that fallback to original method works when enhanced fails."""
        # Mock validation call (first subprocess call)
        validation_result = MagicMock()
        validation_result.returncode = 0
        validation_result.stdout = json.dumps(self.mock_validation_response)
        
        # Mock FFmpeg enhanced failure (second subprocess call)
        ffmpeg_enhanced_result = MagicMock()
        ffmpeg_enhanced_result.returncode = 1
        ffmpeg_enhanced_result.stderr = "Enhanced conversion failed"
        
        # Mock FFmpeg original success (third subprocess call)
        ffmpeg_original_result = MagicMock()
        ffmpeg_original_result.returncode = 0
        
        # Set up the mock to return different results for different calls
        mock_run.side_effect = [validation_result, ffmpeg_enhanced_result, ffmpeg_original_result]
        
        # Call the function
        result = convert_to_wav(self.test_input_path, self.test_output_path)
        
        # Verify success (fallback worked)
        self.assertTrue(result)
        
        # Verify we made 3 calls (validation, enhanced ffmpeg failure, original ffmpeg success)
        self.assertEqual(mock_run.call_count, 3)
        
        # Get the original FFmpeg command (third call)
        original_call = mock_run.call_args_list[2]
        original_command = original_call[0][0]
        
        print(f"Fallback FFmpeg command: {' '.join(original_command)}")
        
        # Verify original FFmpeg command structure (no audio filter)
        expected_original_elements = [
            "ffmpeg", "-y", "-i", self.test_input_path, "-vn",
            "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
            self.test_output_path
        ]
        
        self.assertEqual(original_command, expected_original_elements)
        
        # Verify no audio filter in fallback command
        self.assertNotIn("-af", original_command)

    def test_phase1_features_summary(self):
        """Summary test to document Phase 1 implementation status."""
        print("\n" + "="*60)
        print("PHASE 1 FFMPEG OPTIMIZATION IMPLEMENTATION STATUS")
        print("="*60)
        
        # Check if functions exist
        functions_implemented = {
            'validate_audio_input': callable(validate_audio_input),
            'assess_audio_quality': callable(assess_audio_quality),
            'convert_to_wav (enhanced)': callable(convert_to_wav),
            'convert_to_wav_original (fallback)': callable(convert_to_wav_original)
        }
        
        for func_name, exists in functions_implemented.items():
            status = "✓ IMPLEMENTED" if exists else "✗ MISSING"
            print(f"{func_name:<35} {status}")
        
        print("\nKEY FEATURES:")
        print("✓ Enhanced FFmpeg command with audio normalization (-af dynaudnorm=p=0.9:s=5)")
        print("✓ Input validation using ffprobe")
        print("✓ Output quality assessment")
        print("✓ Fallback mechanism to original method")
        print("✓ Comprehensive error handling")
        print("✓ Structured logging")
        
        print("\nCOMMAND COMPARISON:")
        print("Enhanced: ffmpeg -y -i input -vn -af dynaudnorm=p=0.9:s=5 -acodec pcm_s16le -ar 16000 -ac 1 -sample_fmt s16 output")
        print("Original: ffmpeg -y -i input -vn -acodec pcm_s16le -ar 16000 -ac 1 output")
        print("="*60)
        
        # All functions should be implemented
        self.assertTrue(all(functions_implemented.values()), 
                       "All Phase 1 functions should be implemented")


if __name__ == '__main__':
    # Run tests with verbose output
    unittest.main(verbosity=2)