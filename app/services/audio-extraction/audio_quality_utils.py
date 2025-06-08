#!/usr/bin/env python3
"""
Audio Quality Utilities Module

Provides audio statistics and volume analysis functions for the speech quality analyzer.
Uses ffprobe and ffmpeg for audio analysis.
"""

import os
import json
import subprocess
import logging
from typing import Dict, Optional

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


def get_audio_stats(audio_path: str) -> Optional[Dict]:
    """
    Get basic audio statistics using ffprobe.
    
    Args:
        audio_path: Path to audio file
        
    Returns:
        Dictionary with sample_rate, bit_rate, duration, channels, or None if failed
    """
    try:
        logger.debug(f"Getting audio stats for: {audio_path}")
        
        if not os.path.exists(audio_path):
            logger.error(f"Audio file not found: {audio_path}")
            return None
        
        command = [
            "ffprobe", "-v", "error",
            "-select_streams", "a:0",
            "-show_entries", "stream=bit_rate,sample_rate,channels",
            "-show_entries", "format=bit_rate,duration",
            "-of", "json",
            str(audio_path)
        ]
        
        result = subprocess.run(command, capture_output=True, text=True)
        
        if result.returncode != 0:
            logger.error(f"ffprobe failed: {result.stderr}")
            return None
        
        probe_data = json.loads(result.stdout)
        stream = probe_data.get('streams', [{}])[0]
        format_info = probe_data.get('format', {})
        
        # Extract statistics
        stats = {
            'sample_rate': int(stream.get('sample_rate', 0)),
            'channels': int(stream.get('channels', 0)),
            'duration': float(format_info.get('duration', 0)),
            'bit_rate': int(stream.get('bit_rate', 0)) or int(format_info.get('bit_rate', 0))
        }
        
        logger.debug(f"Audio stats extracted: {stats}")
        return stats
        
    except Exception as e:
        logger.error(f"Error getting audio stats: {str(e)}")
        return None


def get_audio_volume_stats(audio_path: str) -> Optional[Dict]:
    """
    Get audio volume statistics using ffmpeg's volumedetect filter.
    
    Args:
        audio_path: Path to audio file
        
    Returns:
        Dictionary with mean_volume, max_volume as strings with "dB" suffix, or None if failed
    """
    try:
        logger.debug(f"Getting volume stats for: {audio_path}")
        
        if not os.path.exists(audio_path):
            logger.error(f"Audio file not found: {audio_path}")
            return None
        
        # Use ffmpeg with volumedetect filter to get volume statistics
        command = [
            "ffmpeg", "-i", str(audio_path), 
            "-af", "volumedetect", 
            "-f", "null", "-"
        ]
        
        result = subprocess.run(command, capture_output=True, text=True)
        
        # ffmpeg outputs volume info to stderr, and this is expected behavior
        stderr_output = result.stderr
        
        if not stderr_output:
            logger.warning("No volume detection output received")
            return None
        
        # Parse volume detection output
        mean_volume = None
        max_volume = None
        
        for line in stderr_output.split('\n'):
            if 'mean_volume:' in line:
                # Extract mean volume: [Parsed_volumedetect_0 @ ...] mean_volume: -23.1 dB
                parts = line.split('mean_volume:')
                if len(parts) > 1:
                    try:
                        volume_value = float(parts[1].strip().replace('dB', '').strip())
                        mean_volume = f"{volume_value:.1f}dB"
                    except ValueError:
                        pass
                        
            elif 'max_volume:' in line:
                # Extract max volume: [Parsed_volumedetect_0 @ ...] max_volume: -5.0 dB
                parts = line.split('max_volume:')
                if len(parts) > 1:
                    try:
                        volume_value = float(parts[1].strip().replace('dB', '').strip())
                        max_volume = f"{volume_value:.1f}dB"
                    except ValueError:
                        pass
        
        if mean_volume is None or max_volume is None:
            logger.warning("Could not parse volume statistics from ffmpeg output")
            return None
        
        volume_stats = {
            'mean_volume': mean_volume,
            'max_volume': max_volume
        }
        
        logger.debug(f"Volume stats extracted: {volume_stats}")
        return volume_stats
        
    except Exception as e:
        logger.error(f"Error getting volume stats: {str(e)}")
        return None


def assess_audio_quality(audio_path: str) -> Optional[Dict]:
    """
    Comprehensive audio quality assessment combining basic stats and volume analysis.
    
    Args:
        audio_path: Path to audio file
        
    Returns:
        Dictionary with all available audio metrics, or None if failed
    """
    try:
        logger.info(f"Assessing comprehensive audio quality: {audio_path}")
        
        # Get basic audio statistics
        audio_stats = get_audio_stats(audio_path)
        if not audio_stats:
            logger.error("Failed to get basic audio statistics")
            return None
        
        # Get volume statistics
        volume_stats = get_audio_volume_stats(audio_path)
        if not volume_stats:
            logger.warning("Failed to get volume statistics, continuing with basic stats only")
            volume_stats = {}
        
        # Combine all statistics
        comprehensive_stats = {
            **audio_stats,
            **volume_stats,
            'analysis_timestamp': str(subprocess.run(['date'], capture_output=True, text=True).stdout.strip())
        }
        
        logger.info(f"Comprehensive audio quality assessment complete: {comprehensive_stats}")
        return comprehensive_stats
        
    except Exception as e:
        logger.error(f"Error in comprehensive audio quality assessment: {str(e)}")
        return None


# Legacy function aliases for backward compatibility
def get_audio_duration(audio_path: str) -> Optional[float]:
    """Get audio duration in seconds."""
    stats = get_audio_stats(audio_path)
    return stats.get('duration') if stats else None


def get_audio_sample_rate(audio_path: str) -> Optional[int]:
    """Get audio sample rate in Hz."""
    stats = get_audio_stats(audio_path)
    return stats.get('sample_rate') if stats else None


if __name__ == "__main__":
    # Test the functions if run directly
    import sys
    
    if len(sys.argv) != 2:
        print("Usage: python audio_quality_utils.py <audio_file>")
        sys.exit(1)
    
    test_file = sys.argv[1]
    
    print(f"Testing audio quality utils on: {test_file}")
    print()
    
    # Test basic stats
    stats = get_audio_stats(test_file)
    print(f"Audio Stats: {stats}")
    
    # Test volume stats
    volume = get_audio_volume_stats(test_file)
    print(f"Volume Stats: {volume}")
    
    # Test comprehensive assessment
    quality = assess_audio_quality(test_file)
    print(f"Comprehensive Quality: {quality}") 