#!/usr/bin/env python3
"""
WhisperX Data Transformation & Validation Module
Phase 5 Implementation - Enhanced output formats, validation, and compatibility layer.
"""

import json
import logging
from datetime import datetime
from typing import Dict, List, Any, Optional, Union, Tuple
import re
from pathlib import Path

# Set up logging
logger = logging.getLogger(__name__)

class WhisperXDataTransformer:
    """
    Enhanced data transformer for WhisperX output with validation and compatibility layers.
    Handles output format transformation, speaker diarization data, confidence scoring,
    and backward compatibility.
    """
    
    def __init__(self):
        """Initialize the WhisperX data transformer."""
        self.validation_rules = self._initialize_validation_rules()
        self.format_handlers = self._initialize_format_handlers()
    
    def _initialize_validation_rules(self) -> Dict[str, Any]:
        """Initialize validation rules for WhisperX output formats."""
        return {
            'required_fields': {
                'basic': ['text', 'segments'],
                'enhanced': ['confidence_score', 'whisperx_processing', 'performance_metrics'],
                'alignment': ['alignment_info', 'alignment_metadata'],
                'diarization': ['speaker_info', 'diarization_metadata']
            },
            'confidence_range': {'min': 0.0, 'max': 1.0},
            'timing_constraints': {
                'min_segment_duration': 0.01,  # 10ms minimum
                'max_segment_duration': 300.0,  # 5 minutes maximum
                'max_overlap': 0.5  # 500ms maximum overlap
            },
            'speaker_constraints': {
                'max_speakers': 10,
                'min_speakers': 1,
                'valid_speaker_pattern': r'^SPEAKER_\d+$'
            }
        }
    
    def _initialize_format_handlers(self) -> Dict[str, callable]:
        """Initialize format-specific handlers."""
        return {
            'json': self._format_json,
            'srt': self._format_srt,
            'vtt': self._format_vtt,
            'txt': self._format_txt,
            'enhanced_json': self._format_enhanced_json
        }
    
    def validate_whisperx_output(self, transcription_result: Dict[str, Any]) -> Dict[str, Any]:
        """
        Comprehensive validation of WhisperX output format.
        
        Args:
            transcription_result: WhisperX transcription result
            
        Returns:
            Dictionary containing validation results and metrics
        """
        validation_result = {
            'timestamp': datetime.now().isoformat(),
            'overall_valid': True,
            'validation_details': {},
            'quality_metrics': {},
            'warnings': [],
            'errors': []
        }
        
        try:
            # Validate basic structure
            basic_validation = self._validate_basic_structure(transcription_result)
            validation_result['validation_details']['basic_structure'] = basic_validation
            
            # Validate enhanced features
            enhanced_validation = self._validate_enhanced_features(transcription_result)
            validation_result['validation_details']['enhanced_features'] = enhanced_validation
            
            # Validate timing accuracy
            timing_validation = self._validate_timing_accuracy(transcription_result)
            validation_result['validation_details']['timing_accuracy'] = timing_validation
            
            # Validate confidence scores
            confidence_validation = self._validate_confidence_scores(transcription_result)
            validation_result['validation_details']['confidence_scores'] = confidence_validation
            
            # Validate speaker diarization (if present)
            if self._has_speaker_diarization(transcription_result):
                speaker_validation = self._validate_speaker_diarization(transcription_result)
                validation_result['validation_details']['speaker_diarization'] = speaker_validation
            
            # Calculate quality metrics
            validation_result['quality_metrics'] = self._calculate_quality_metrics(transcription_result)
            
            # Determine overall validation status
            validation_result['overall_valid'] = self._determine_overall_validity(validation_result)
            
            logger.info(f"WhisperX output validation completed - Valid: {validation_result['overall_valid']}")
            
        except Exception as e:
            logger.error(f"Validation error: {str(e)}")
            validation_result['overall_valid'] = False
            validation_result['errors'].append(f"Validation exception: {str(e)}")
        
        return validation_result
    
    def _validate_basic_structure(self, result: Dict[str, Any]) -> Dict[str, bool]:
        """Validate basic structure requirements."""
        validation = {}
        
        # Check required basic fields
        for field in self.validation_rules['required_fields']['basic']:
            validation[f'has_{field}'] = field in result
        
        # Validate text content
        validation['text_not_empty'] = bool(result.get('text', '').strip())
        
        # Validate segments structure
        segments = result.get('segments', [])
        validation['segments_is_list'] = isinstance(segments, list)
        validation['segments_not_empty'] = len(segments) > 0
        
        if segments:
            first_segment = segments[0]
            validation['segment_has_timing'] = all(field in first_segment for field in ['start', 'end', 'text'])
        
        return validation
    
    def _validate_enhanced_features(self, result: Dict[str, Any]) -> Dict[str, bool]:
        """Validate enhanced WhisperX features."""
        validation = {}
        
        # Check WhisperX processing info
        wx_processing = result.get('whisperx_processing', {})
        validation['has_whisperx_processing'] = bool(wx_processing)
        validation['transcription_completed'] = wx_processing.get('transcription') == 'completed'
        
        # Check performance metrics
        perf_metrics = result.get('performance_metrics', {})
        validation['has_performance_metrics'] = bool(perf_metrics)
        validation['has_processing_times'] = 'total_processing_time' in perf_metrics
        
        # Check model metadata
        model_metadata = result.get('model_metadata', {})
        validation['has_model_metadata'] = bool(model_metadata)
        validation['has_device_info'] = 'device' in model_metadata
        
        return validation
    
    def _validate_timing_accuracy(self, result: Dict[str, Any]) -> Dict[str, Any]:
        """Validate timing accuracy and alignment quality."""
        validation = {
            'alignment_applied': False,
            'word_level_timestamps': False,
            'timing_consistency': True,
            'segment_boundaries_valid': True,
            'accuracy_metrics': {}
        }
        
        # Check if alignment was applied
        wx_processing = result.get('whisperx_processing', {})
        validation['alignment_applied'] = wx_processing.get('alignment') == 'completed'
        
        segments = result.get('segments', [])
        if not segments:
            return validation
        
        # Validate word-level timestamps
        word_count = 0
        valid_word_timestamps = 0
        
        for segment in segments:
            # Validate segment timing
            if 'start' in segment and 'end' in segment:
                duration = segment['end'] - segment['start']
                if duration <= 0 or duration > self.validation_rules['timing_constraints']['max_segment_duration']:
                    validation['segment_boundaries_valid'] = False
            
            # Check word-level timestamps
            if 'words' in segment:
                for word in segment['words']:
                    word_count += 1
                    if ('start' in word and 'end' in word and 
                        isinstance(word['start'], (int, float)) and 
                        isinstance(word['end'], (int, float)) and
                        word['end'] > word['start']):
                        valid_word_timestamps += 1
        
        if word_count > 0:
            validation['word_level_timestamps'] = True
            word_accuracy_ratio = valid_word_timestamps / word_count
            validation['accuracy_metrics']['word_timestamp_accuracy'] = word_accuracy_ratio
            validation['accuracy_metrics']['total_words'] = word_count
            validation['accuracy_metrics']['valid_word_timestamps'] = valid_word_timestamps
        
        return validation
    
    def _validate_confidence_scores(self, result: Dict[str, Any]) -> Dict[str, Any]:
        """Validate confidence score system."""
        validation = {
            'has_overall_confidence': False,
            'confidence_in_range': False,
            'has_word_confidence': False,
            'confidence_metrics': {}
        }
        
        # Validate overall confidence
        overall_confidence = result.get('confidence_score')
        if isinstance(overall_confidence, (int, float)):
            validation['has_overall_confidence'] = True
            validation['confidence_in_range'] = (
                self.validation_rules['confidence_range']['min'] <= 
                overall_confidence <= 
                self.validation_rules['confidence_range']['max']
            )
            validation['confidence_metrics']['overall_confidence'] = overall_confidence
        
        # Validate word-level confidence
        word_confidences = []
        segments = result.get('segments', [])
        
        for segment in segments:
            if 'words' in segment:
                for word in segment['words']:
                    if 'probability' in word:
                        prob = word['probability']
                        if isinstance(prob, (int, float)):
                            word_confidences.append(prob)
        
        if word_confidences:
            validation['has_word_confidence'] = True
            validation['confidence_metrics']['word_confidence_count'] = len(word_confidences)
            validation['confidence_metrics']['avg_word_confidence'] = sum(word_confidences) / len(word_confidences)
            validation['confidence_metrics']['min_word_confidence'] = min(word_confidences)
            validation['confidence_metrics']['max_word_confidence'] = max(word_confidences)
        
        return validation
    
    def _validate_speaker_diarization(self, result: Dict[str, Any]) -> Dict[str, Any]:
        """Validate speaker diarization data."""
        validation = {
            'has_speaker_info': False,
            'speaker_labels_valid': False,
            'speaker_consistency': True,
            'speaker_metrics': {}
        }
        
        speaker_info = result.get('speaker_info', {})
        if speaker_info:
            validation['has_speaker_info'] = True
            
            # Validate speaker labels
            speaker_labels = speaker_info.get('speaker_labels', [])
            detected_speakers = speaker_info.get('detected_speakers', 0)
            
            validation['speaker_metrics']['detected_speakers'] = detected_speakers
            validation['speaker_metrics']['speaker_labels'] = speaker_labels
            
            # Check speaker label format
            pattern = re.compile(self.validation_rules['speaker_constraints']['valid_speaker_pattern'])
            valid_labels = all(pattern.match(label) for label in speaker_labels if label)
            validation['speaker_labels_valid'] = valid_labels
            
            # Check consistency between segments and speaker info
            segments = result.get('segments', [])
            segment_speakers = set()
            for segment in segments:
                if 'speaker' in segment and segment['speaker']:
                    segment_speakers.add(segment['speaker'])
            
            expected_speakers = set(speaker_labels)
            validation['speaker_consistency'] = segment_speakers.issubset(expected_speakers)
            validation['speaker_metrics']['segment_speakers'] = list(segment_speakers)
        
        return validation
    
    def _has_speaker_diarization(self, result: Dict[str, Any]) -> bool:
        """Check if result contains speaker diarization data."""
        return (
            'speaker_info' in result or 
            'diarization_metadata' in result or
            any('speaker' in segment for segment in result.get('segments', []))
        )
    
    def _calculate_quality_metrics(self, result: Dict[str, Any]) -> Dict[str, Any]:
        """Calculate comprehensive quality metrics."""
        metrics = {
            'transcription_quality': {},
            'timing_quality': {},
            'feature_completeness': {},
            'overall_score': 0.0
        }
        
        # Transcription quality metrics
        confidence_score = result.get('confidence_score', 0.0)
        metrics['transcription_quality']['confidence_score'] = confidence_score
        
        segments = result.get('segments', [])
        if segments:
            total_duration = sum(
                segment.get('end', 0) - segment.get('start', 0) 
                for segment in segments 
                if 'start' in segment and 'end' in segment
            )
            metrics['transcription_quality']['total_duration'] = total_duration
            metrics['transcription_quality']['segment_count'] = len(segments)
            metrics['transcription_quality']['avg_segment_duration'] = total_duration / len(segments) if segments else 0
        
        # Timing quality metrics
        wx_processing = result.get('whisperx_processing', {})
        alignment_status = wx_processing.get('alignment', 'skipped')
        metrics['timing_quality']['alignment_applied'] = alignment_status == 'completed'
        
        if 'processing_times' in wx_processing:
            processing_times = wx_processing['processing_times']
            metrics['timing_quality']['processing_efficiency'] = {
                'transcription_time': processing_times.get('transcription_seconds', 0),
                'alignment_time': processing_times.get('alignment_seconds', 0),
                'total_time': processing_times.get('total_seconds', 0)
            }
        
        # Feature completeness
        features = {
            'basic_transcription': bool(result.get('text')),
            'word_timestamps': any('words' in segment for segment in segments),
            'confidence_scores': bool(result.get('confidence_score')),
            'alignment': alignment_status == 'completed',
            'diarization': wx_processing.get('diarization') == 'completed',
            'performance_metrics': bool(result.get('performance_metrics'))
        }
        
        metrics['feature_completeness'] = features
        feature_score = sum(features.values()) / len(features)
        
        # Calculate overall quality score
        quality_components = [
            confidence_score * 0.4,  # 40% weight on transcription confidence
            (1.0 if alignment_status == 'completed' else 0.5) * 0.3,  # 30% weight on alignment
            feature_score * 0.3  # 30% weight on feature completeness
        ]
        
        metrics['overall_score'] = sum(quality_components)
        
        return metrics
    
    def _determine_overall_validity(self, validation_result: Dict[str, Any]) -> bool:
        """Determine overall validation status based on validation details."""
        critical_failures = []
        
        # Check basic structure
        basic = validation_result['validation_details'].get('basic_structure', {})
        if not basic.get('has_text') or not basic.get('has_segments'):
            critical_failures.append("Missing basic structure")
        
        # Check confidence scores
        confidence = validation_result['validation_details'].get('confidence_scores', {})
        if confidence.get('has_overall_confidence') and not confidence.get('confidence_in_range'):
            critical_failures.append("Confidence score out of range")
        
        # Log any critical failures
        if critical_failures:
            validation_result['errors'].extend(critical_failures)
            logger.warning(f"Critical validation failures: {critical_failures}")
        
        return len(critical_failures) == 0
    
    def transform_to_format(self, transcription_result: Dict[str, Any], format_type: str, 
                          enhanced: bool = True, speaker_info: bool = True) -> Union[str, Dict[str, Any]]:
        """
        Transform WhisperX result to specified output format.
        
        Args:
            transcription_result: WhisperX transcription result
            format_type: Output format ('json', 'srt', 'vtt', 'txt', 'enhanced_json')
            enhanced: Include enhanced metadata
            speaker_info: Include speaker information in output
            
        Returns:
            Formatted output as string or dictionary
        """
        if format_type not in self.format_handlers:
            raise ValueError(f"Unsupported format: {format_type}")
        
        handler = self.format_handlers[format_type]
        return handler(transcription_result, enhanced=enhanced, speaker_info=speaker_info)
    
    def _format_json(self, result: Dict[str, Any], enhanced: bool = True, speaker_info: bool = True) -> Dict[str, Any]:
        """Format as standard JSON with optional enhancements."""
        output = {
            'text': result.get('text', ''),
            'segments': result.get('segments', []),
            'confidence_score': result.get('confidence_score', 0.0)
        }
        
        if enhanced:
            # Add enhanced metadata
            output['metadata'] = {
                'service': 'transcription-service',
                'backend': 'WhisperX',
                'timestamp': datetime.now().isoformat(),
                'whisperx_processing': result.get('whisperx_processing', {}),
                'performance_metrics': result.get('performance_metrics', {})
            }
            
            # Add alignment info if available
            if 'alignment_info' in result:
                output['alignment_info'] = result['alignment_info']
        
        if speaker_info and 'speaker_info' in result:
            output['speaker_info'] = result['speaker_info']
        
        return output
    
    def _format_enhanced_json(self, result: Dict[str, Any], enhanced: bool = True, speaker_info: bool = True) -> Dict[str, Any]:
        """Format as enhanced JSON with full WhisperX metadata."""
        return {
            'transcription': {
                'text': result.get('text', ''),
                'confidence_score': result.get('confidence_score', 0.0),
                'segments': result.get('segments', [])
            },
            'whisperx_metadata': {
                'processing': result.get('whisperx_processing', {}),
                'performance_metrics': result.get('performance_metrics', {}),
                'model_metadata': result.get('model_metadata', {}),
                'alignment_metadata': result.get('alignment_metadata', {}),
                'diarization_metadata': result.get('diarization_metadata', {})
            },
            'quality_assessment': self._calculate_quality_metrics(result),
            'speaker_analysis': result.get('speaker_info', {}) if speaker_info else {},
            'alignment_details': result.get('alignment_info', {}),
            'export_metadata': {
                'format': 'enhanced_json',
                'version': '1.0',
                'timestamp': datetime.now().isoformat(),
                'enhanced_features_enabled': enhanced,
                'speaker_info_included': speaker_info
            }
        }
    
    def _format_srt(self, result: Dict[str, Any], enhanced: bool = True, speaker_info: bool = True) -> str:
        """Format as SRT subtitle file with optional speaker labels."""
        segments = result.get('segments', [])
        srt_content = []
        
        for i, segment in enumerate(segments, start=1):
            start_time = self._seconds_to_srt_time(segment.get('start', 0))
            end_time = self._seconds_to_srt_time(segment.get('end', 0))
            text = segment.get('text', '').strip()
            
            # Add speaker label if available and requested
            if speaker_info and 'speaker' in segment:
                speaker = segment['speaker']
                text = f"[{speaker}] {text}"
            
            srt_content.append(f"{i}")
            srt_content.append(f"{start_time} --> {end_time}")
            srt_content.append(text)
            srt_content.append("")  # Empty line between subtitles
        
        return "\n".join(srt_content)
    
    def _format_vtt(self, result: Dict[str, Any], enhanced: bool = True, speaker_info: bool = True) -> str:
        """Format as WebVTT subtitle file with optional speaker labels."""
        segments = result.get('segments', [])
        vtt_content = ["WEBVTT", ""]
        
        # Add metadata if enhanced
        if enhanced:
            vtt_content.extend([
                "NOTE",
                f"Generated by WhisperX Transcription Service",
                f"Timestamp: {datetime.now().isoformat()}",
                f"Confidence: {result.get('confidence_score', 0.0):.3f}",
                ""
            ])
        
        for segment in segments:
            start_time = self._seconds_to_vtt_time(segment.get('start', 0))
            end_time = self._seconds_to_vtt_time(segment.get('end', 0))
            text = segment.get('text', '').strip()
            
            # Add speaker label if available and requested
            if speaker_info and 'speaker' in segment:
                speaker = segment['speaker']
                text = f"<v {speaker}>{text}"
            
            vtt_content.append(f"{start_time} --> {end_time}")
            vtt_content.append(text)
            vtt_content.append("")
        
        return "\n".join(vtt_content)
    
    def _format_txt(self, result: Dict[str, Any], enhanced: bool = True, speaker_info: bool = True) -> str:
        """Format as plain text with optional timestamps and speaker labels."""
        segments = result.get('segments', [])
        txt_content = []
        
        # Add header if enhanced
        if enhanced:
            txt_content.extend([
                "WhisperX Transcription",
                f"Generated: {datetime.now().isoformat()}",
                f"Confidence: {result.get('confidence_score', 0.0):.3f}",
                "-" * 50,
                ""
            ])
        
        for segment in segments:
            text = segment.get('text', '').strip()
            
            if enhanced:
                # Add timestamp
                start_time = self._seconds_to_readable_time(segment.get('start', 0))
                line = f"[{start_time}] "
            else:
                line = ""
            
            # Add speaker label if available and requested
            if speaker_info and 'speaker' in segment:
                speaker = segment['speaker']
                line += f"{speaker}: "
            
            line += text
            txt_content.append(line)
        
        return "\n".join(txt_content)
    
    def _seconds_to_srt_time(self, seconds: float) -> str:
        """Convert seconds to SRT time format (HH:MM:SS,mmm)."""
        hours = int(seconds // 3600)
        minutes = int((seconds % 3600) // 60)
        secs = int(seconds % 60)
        milliseconds = int((seconds % 1) * 1000)
        return f"{hours:02d}:{minutes:02d}:{secs:02d},{milliseconds:03d}"
    
    def _seconds_to_vtt_time(self, seconds: float) -> str:
        """Convert seconds to WebVTT time format (HH:MM:SS.mmm)."""
        hours = int(seconds // 3600)
        minutes = int((seconds % 3600) // 60)
        secs = int(seconds % 60)
        milliseconds = int((seconds % 1) * 1000)
        return f"{hours:02d}:{minutes:02d}:{secs:02d}.{milliseconds:03d}"
    
    def _seconds_to_readable_time(self, seconds: float) -> str:
        """Convert seconds to readable time format (MM:SS)."""
        minutes = int(seconds // 60)
        secs = int(seconds % 60)
        return f"{minutes:02d}:{secs:02d}"
    
    def create_compatibility_layer(self, transcription_result: Dict[str, Any]) -> Dict[str, Any]:
        """
        Create backward compatibility layer for existing output consumers.
        
        Args:
            transcription_result: WhisperX transcription result
            
        Returns:
            Backward-compatible output format
        """
        # Ensure basic required fields are present
        compatible_output = {
            'text': transcription_result.get('text', ''),
            'segments': transcription_result.get('segments', []),
            'confidence_score': transcription_result.get('confidence_score', 0.0)
        }
        
        # Add legacy timing correction info if present (for compatibility)
        if 'timing_correction' in transcription_result:
            compatible_output['timing_correction'] = transcription_result['timing_correction']
        else:
            # Indicate that WhisperX alignment was used instead
            compatible_output['timing_correction'] = {
                'applied': False,
                'reason': 'WhisperX alignment provides superior accuracy',
                'whisperx_alignment_used': True,
                'correction_timestamp': datetime.now().isoformat()
            }
        
        # Add optional enhanced fields that don't break legacy consumers
        if 'whisperx_processing' in transcription_result:
            compatible_output['processing_info'] = transcription_result['whisperx_processing']
        
        if 'performance_metrics' in transcription_result:
            compatible_output['performance'] = transcription_result['performance_metrics']
        
        return compatible_output


# Global transformer instance
_data_transformer: Optional[WhisperXDataTransformer] = None

def get_data_transformer() -> WhisperXDataTransformer:
    """Get or create the global data transformer instance."""
    global _data_transformer
    if _data_transformer is None:
        _data_transformer = WhisperXDataTransformer()
    return _data_transformer

def validate_transcription_output(transcription_result: Dict[str, Any]) -> Dict[str, Any]:
    """
    Validate WhisperX transcription output.
    
    Args:
        transcription_result: WhisperX transcription result
        
    Returns:
        Validation results
    """
    transformer = get_data_transformer()
    return transformer.validate_whisperx_output(transcription_result)

def transform_output_format(transcription_result: Dict[str, Any], format_type: str, 
                          enhanced: bool = True, speaker_info: bool = True) -> Union[str, Dict[str, Any]]:
    """
    Transform transcription result to specified format.
    
    Args:
        transcription_result: WhisperX transcription result
        format_type: Output format type
        enhanced: Include enhanced metadata
        speaker_info: Include speaker information
        
    Returns:
        Formatted output
    """
    transformer = get_data_transformer()
    return transformer.transform_to_format(transcription_result, format_type, enhanced, speaker_info)

def create_backward_compatible_output(transcription_result: Dict[str, Any]) -> Dict[str, Any]:
    """
    Create backward-compatible output format.
    
    Args:
        transcription_result: WhisperX transcription result
        
    Returns:
        Backward-compatible output
    """
    transformer = get_data_transformer()
    return transformer.create_compatibility_layer(transcription_result)