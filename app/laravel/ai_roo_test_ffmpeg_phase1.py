#!/usr/bin/env python3
"""
Unit tests for FFmpeg Phase 1 optimization - Audio normalization and validation
"""
import unittest
from unittest.mock import patch, MagicMock, call
import json
import subprocess
import tempfile
import os
import sys

# Add the service directory to the path
sys.path.insert(0, 'app/services/audio-extraction')

from service import (
    validate_audio_input,
    assess_audio_quality,
    convert_to_wav,
    convert_to_wav_original
)


class TestFFmpegPhase1(unittest.TestCase):
    """Test cases for FFmpeg Phase 1 optimization features."""
    
    def setUp(self):
        """Set up test fixtures."""
        self.test_input_path = "/tmp/test_input.mp4"
        self.test_output_path = "/tmp/test_output.wav"
        
        # Mock ffprobe response for validation
        self.mock_validation_response = {
            "streams": [{
                "codec_name": "aac",
                "sample_rate": "44100",
                "channels": "2",
                "duration": "120.5"
            }]
        }
        
        # Mock ffprobe response for quality assessment
        self.mock_quality_response = {
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

    @patch('service.subprocess.run')
    def test_validate_audio_input_success(self, mock_run):
        """Test successful audio input validation."""
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
    def test_validate_audio_input_no_streams(self, mock_run):
        """Test validation failure when no audio streams found."""
        # Mock ffprobe response with no streams
        mock_result = MagicMock()
        mock_result.returncode = 0
        mock_result.stdout = json.dumps({"streams": []})
        mock_run.return_value = mock_result
        
        with self.assertRaises(RuntimeError) as context:
            validate_audio_input(self.test_input_path)
        
        self.assertIn("No audio streams found", str(context.exception))

    @patch('service.subprocess.run')
    def test_validate_audio_input_ffprobe_error(self, mock_run):
        """Test validation failure when ffprobe returns error."""
        # Mock ffprobe failure
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stderr = "File not found"
        mock_run.return_value = mock_result
        
        with self.assertRaises(RuntimeError) as context:
            validate_audio_input(self.test_input_path)
        
        self.assertIn("FFprobe validation error", str(context.exception))

    @patch('service.subprocess.run')
    def test_assess_audio_quality_success(self, mock_run):
        """Test successful audio quality assessment."""
        # Mock successful ffprobe execution
        mock_result = MagicMock()
        mock_result.returncode = 0
        mock_result.stdout = json.dumps(self.mock_quality_response)
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
    def test_assess_audio_quality_failure(self, mock_run):
        """Test audio quality assessment failure handling."""
        # Mock ffprobe failure
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stderr = "Assessment failed"
        mock_run.return_value = mock_result
        
        result = assess_audio_quality(self.test_output_path)
        
        # Should return None on failure
        self.assertIsNone(result)

    @patch('service.assess_audio_quality')
    @patch('service.validate_audio_input')
    @patch('service.subprocess.run')
    def test_convert_to_wav_enhanced_success(self, mock_run, mock_validate, mock_assess):
        """Test successful enhanced WAV conversion with normalization."""
        # Mock validation
        mock_validate.return_value = {
            'codec': 'aac',
            'sample_rate': 44100,
            'channels': 2,
            'duration': 120.5
        }
        
        # Mock quality assessment
        mock_assess.return_value = {
            'bit_rate': 256000,
            'sample_rate': 16000,
            'channels': 1,
            'duration': 120.5
        }
        
        # Mock successful ffmpeg execution
        mock_result = MagicMock()
        mock_result.returncode = 0
        mock_run.return_value = mock_result
        
        result = convert_to_wav(self.test_input_path, self.test_output_path)
        
        # Verify success
        self.assertTrue(result)
        
        # Verify validation was called
        mock_validate.assert_called_once_with(self.test_input_path)
        
        # Verify quality assessment was called
        mock_assess.assert_called_once_with(self.test_output_path)
        
        # Verify enhanced ffmpeg command was used
        expected_command = [
            "ffmpeg", "-y", "-i", self.test_input_path, "-vn",
            "-af", "dynaudnorm=p=0.9:s=5",  # Audio normalization
            "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1",
            "-sample_fmt", "s16", self.test_output_path
        ]
        mock_run.assert_called_with(expected_command, capture_output=True, text=True)

    @patch('service.convert_to_wav_original')
    @patch('service.validate_audio_input')
    @patch('service.subprocess.run')
    def test_convert_to_wav_fallback_on_failure(self, mock_run, mock_validate, mock_fallback):
        """Test fallback to original method when enhanced conversion fails."""
        # Mock validation
        mock_validate.return_value = {
            'codec': 'aac',
            'sample_rate': 44100,
            'channels': 2,
            'duration': 120.5
        }
        
        # Mock ffmpeg failure
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stderr = "Enhanced conversion failed"
        mock_run.return_value = mock_result
        
        # Mock successful fallback
        mock_fallback.return_value = True
        
        result = convert_to_wav(self.test_input_path, self.test_output_path)
        
        # Verify fallback was called
        mock_fallback.assert_called_once_with(self.test_input_path, self.test_output_path)
        self.assertTrue(result)

    @patch('service.subprocess.run')
    def test_convert_to_wav_original_success(self, mock_run):
        """Test original WAV conversion method."""
        # Mock successful ffmpeg execution
        mock_result = MagicMock()
        mock_result.returncode = 0
        mock_run.return_value = mock_result
        
        result = convert_to_wav_original(self.test_input_path, self.test_output_path)
        
        # Verify success
        self.assertTrue(result)
        
        # Verify original ffmpeg command was used
        expected_command = [
            "ffmpeg", "-y",  # Overwrite output
            "-i", self.test_input_path,
            "-vn",  # Disable video
            "-acodec", "pcm_s16le",  # Force pcm format
            "-ar", "16000",  # Sample rate
            "-ac", "1",  # Mono
            self.test_output_path
        ]
        mock_run.assert_called_once_with(expected_command, capture_output=True, text=True)

    @patch('service.subprocess.run')
    def test_convert_to_wav_original_failure(self, mock_run):
        """Test original WAV conversion method failure."""
        # Mock ffmpeg failure
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stderr = "Conversion failed"
        mock_run.return_value = mock_result
        
        with self.assertRaises(RuntimeError) as context:
            convert_to_wav_original(self.test_input_path, self.test_output_path)
        
        self.assertIn("FFmpeg error", str(context.exception))

    def test_enhanced_command_structure(self):
        """Test that the enhanced FFmpeg command includes normalization."""
        # This test verifies the command structure without executing
        expected_normalization_filter = "dynaudnorm=p=0.9:s=5"
        
        # The command should include the audio filter for normalization
        with patch('service.validate_audio_input') as mock_validate, \
             patch('service.subprocess.run') as mock_run:
            
            mock_validate.return_value = {'codec': 'aac', 'sample_rate': 44100, 'channels': 2, 'duration': 120.5}
            mock_result = MagicMock()
            mock_result.returncode = 0
            mock_run.return_value = mock_result
            
            convert_to_wav(self.test_input_path, self.test_output_path)
            
            # Get the command that was called
            called_command = mock_run.call_args[0][0]
            
            # Verify normalization filter is present
            self.assertIn("-af", called_command)
            af_index = called_command.index("-af")
            self.assertEqual(called_command[af_index + 1], expected_normalization_filter)
            
            # Verify sample format is specified
            self.assertIn("-sample_fmt", called_command)
            fmt_index = called_command.index("-sample_fmt")
            self.assertEqual(called_command[fmt_index + 1], "s16")


class TestFFmpegCommandGeneration(unittest.TestCase):
    """Test FFmpeg command generation and structure."""
    
    def test_enhanced_vs_original_command_differences(self):
        """Test differences between enhanced and original commands."""
        with patch('service.subprocess.run') as mock_run:
            mock_result = MagicMock()
            mock_result.returncode = 0
            mock_run.return_value = mock_result
            
            # Test original command
            convert_to_wav_original("/input.mp4", "/output.wav")
            original_command = mock_run.call_args[0][0]
            
            # Reset mock
            mock_run.reset_mock()
            
            # Test enhanced command
            with patch('service.validate_audio_input') as mock_validate:
                mock_validate.return_value = {'codec': 'aac', 'sample_rate': 44100, 'channels': 2, 'duration': 120.5}
                convert_to_wav("/input.mp4", "/output.wav")
                enhanced_command = mock_run.call_args[0][0]
            
            # Enhanced command should have audio filter
            self.assertIn("-af", enhanced_command)
            self.assertNotIn("-af", original_command)
            
            # Enhanced command should have sample format
            self.assertIn("-sample_fmt", enhanced_command)
            self.assertNotIn("-sample_fmt", original_command)
            
            # Both should have common elements
            for common_arg in ["-y", "-vn", "-acodec", "pcm_s16le", "-ar", "16000", "-ac", "1"]:
                self.assertIn(common_arg, original_command)
                self.assertIn(common_arg, enhanced_command)


if __name__ == '__main__':
    # Run tests with verbose output
    unittest.main(verbosity=2)