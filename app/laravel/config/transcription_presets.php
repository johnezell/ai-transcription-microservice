<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Transcription Presets Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file defines the available transcription presets for
    | the AI transcription microservice. Each preset includes Whisper model
    | selection, processing parameters, quality expectations, and use cases.
    |
    | Presets are designed for different balance points between speed, accuracy,
    | and resource usage, specifically optimized for music education content.
    |
    */

    'presets' => [
        'fast' => [
            'name' => 'Fast',
            'description' => 'Quick transcription with basic accuracy for rapid content review',
            'use_case' => 'Initial content review, quick drafts, time-sensitive processing',
            
            // Whisper Model Configuration
            'whisper_model' => 'tiny',
            'model_size' => '39 MB',
            'vram_requirement' => '~1 GB',
            
            // Processing Parameters
            'temperature' => 0.0,
            'best_of' => 1,
            'beam_size' => 1,
            'patience' => 1.0,
            'length_penalty' => 1.0,
            'suppress_tokens' => [-1],
            'initial_prompt' => 'This is a guitar lesson with music instruction.',
            'condition_on_previous_text' => true,
            'fp16' => true,
            'compression_ratio_threshold' => 2.4,
            'logprob_threshold' => -1.0,
            'no_speech_threshold' => 0.6,
            
            // Timestamp Configuration
            'word_timestamps' => false,
            'prepend_punctuations' => '"\'([{-',
            'append_punctuations' => '"\'.,!?:;)}]',
            
            // Quality and Performance
            'expected_accuracy' => '85-90%',
            'estimated_processing_time' => '0.1x real-time',
            'relative_speed' => 'Fastest',
            'cpu_usage' => 'Low',
            'memory_usage' => 'Low',
            
            // Output Configuration
            'output_format' => ['txt', 'json'],
            'include_confidence_scores' => false,
            'include_speaker_detection' => false,
            
            // Validation Rules
            'min_audio_duration' => 1, // seconds
            'max_audio_duration' => 3600, // 1 hour
            'supported_formats' => ['wav', 'mp3', 'mp4', 'm4a', 'flac'],
        ],

        'balanced' => [
            'name' => 'Balanced',
            'description' => 'Good accuracy with reasonable processing time for general use',
            'use_case' => 'Standard transcription, course content processing, general music lessons',
            
            // Whisper Model Configuration
            'whisper_model' => 'small',
            'model_size' => '244 MB',
            'vram_requirement' => '~2 GB',
            
            // Processing Parameters
            'temperature' => 0.0,
            'best_of' => 2,
            'beam_size' => 2,
            'patience' => 1.0,
            'length_penalty' => 1.0,
            'suppress_tokens' => [-1],
            'initial_prompt' => 'This is a guitar lesson with music instruction. The instructor discusses guitar techniques, chords, scales, and musical concepts.',
            'condition_on_previous_text' => true,
            'fp16' => true,
            'compression_ratio_threshold' => 2.4,
            'logprob_threshold' => -1.0,
            'no_speech_threshold' => 0.6,
            
            // Timestamp Configuration
            'word_timestamps' => true,
            'prepend_punctuations' => '"\'([{-',
            'append_punctuations' => '"\'.,!?:;)}]',
            
            // Quality and Performance
            'expected_accuracy' => '92-95%',
            'estimated_processing_time' => '0.3x real-time',
            'relative_speed' => 'Fast',
            'cpu_usage' => 'Medium',
            'memory_usage' => 'Medium',
            
            // Output Configuration
            'output_format' => ['txt', 'json', 'srt'],
            'include_confidence_scores' => true,
            'include_speaker_detection' => false,
            
            // Validation Rules
            'min_audio_duration' => 1, // seconds
            'max_audio_duration' => 7200, // 2 hours
            'supported_formats' => ['wav', 'mp3', 'mp4', 'm4a', 'flac', 'ogg'],
        ],

        'high' => [
            'name' => 'High Quality',
            'description' => 'High accuracy with detailed music instruction recognition',
            'use_case' => 'Professional transcription, detailed music analysis, technical content',
            
            // Whisper Model Configuration
            'whisper_model' => 'medium',
            'model_size' => '769 MB',
            'vram_requirement' => '~5 GB',
            
            // Processing Parameters
            'temperature' => 0.2,
            'best_of' => 3,
            'beam_size' => 3,
            'patience' => 1.2,
            'length_penalty' => 1.0,
            'suppress_tokens' => [-1],
            'initial_prompt' => 'This is a detailed guitar lesson with comprehensive music instruction. The instructor covers guitar techniques, music theory, chord progressions, scales, fingerpicking patterns, strumming techniques, and musical terminology. Listen carefully for technical terms, note names, chord names, and specific musical instructions.',
            'condition_on_previous_text' => true,
            'fp16' => true,
            'compression_ratio_threshold' => 2.4,
            'logprob_threshold' => -1.0,
            'no_speech_threshold' => 0.5,
            
            // Timestamp Configuration
            'word_timestamps' => true,
            'prepend_punctuations' => '"\'([{-',
            'append_punctuations' => '"\'.,!?:;)}]',
            
            // Quality and Performance
            'expected_accuracy' => '96-98%',
            'estimated_processing_time' => '0.8x real-time',
            'relative_speed' => 'Moderate',
            'cpu_usage' => 'High',
            'memory_usage' => 'High',
            
            // Output Configuration
            'output_format' => ['txt', 'json', 'srt', 'vtt'],
            'include_confidence_scores' => true,
            'include_speaker_detection' => true,
            
            // Validation Rules
            'min_audio_duration' => 1, // seconds
            'max_audio_duration' => 10800, // 3 hours
            'supported_formats' => ['wav', 'mp3', 'mp4', 'm4a', 'flac', 'ogg', 'wma'],
        ],

        'premium' => [
            'name' => 'Premium',
            'description' => 'Maximum accuracy with comprehensive music education analysis',
            'use_case' => 'Professional music education, detailed analysis, archival transcription',
            
            // Whisper Model Configuration
            'whisper_model' => 'large-v3',
            'model_size' => '1550 MB',
            'vram_requirement' => '~10 GB',
            
            // Processing Parameters
            'temperature' => 0.3,
            'best_of' => 5,
            'beam_size' => 5,
            'patience' => 1.5,
            'length_penalty' => 1.0,
            'suppress_tokens' => [-1],
            'initial_prompt' => 'This is a comprehensive guitar lesson with advanced music instruction and education content. The instructor provides detailed explanations of guitar techniques, advanced music theory concepts, chord progressions, scale patterns, fingerpicking and strumming techniques, musical terminology, and educational guidance. Pay special attention to technical musical terms, note names, chord names, scale degrees, time signatures, key signatures, musical intervals, and specific instructional language. The content may include references to musical styles, artists, songs, and educational methodologies.',
            'condition_on_previous_text' => true,
            'fp16' => true,
            'compression_ratio_threshold' => 2.4,
            'logprob_threshold' => -1.0,
            'no_speech_threshold' => 0.4,
            
            // Timestamp Configuration
            'word_timestamps' => true,
            'prepend_punctuations' => '"\'([{-',
            'append_punctuations' => '"\'.,!?:;)}]',
            
            // Quality and Performance
            'expected_accuracy' => '98-99%',
            'estimated_processing_time' => '1.5x real-time',
            'relative_speed' => 'Slow',
            'cpu_usage' => 'Very High',
            'memory_usage' => 'Very High',
            
            // Output Configuration
            'output_format' => ['txt', 'json', 'srt', 'vtt', 'tsv'],
            'include_confidence_scores' => true,
            'include_speaker_detection' => true,
            
            // Validation Rules
            'min_audio_duration' => 1, // seconds
            'max_audio_duration' => 14400, // 4 hours
            'supported_formats' => ['wav', 'mp3', 'mp4', 'm4a', 'flac', 'ogg', 'wma', 'aac'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    |
    | Default configuration values that apply across all presets unless
    | specifically overridden in individual preset configurations.
    |
    */

    'defaults' => [
        'preset' => 'balanced',
        'language' => 'en',
        'task' => 'transcribe',
        'output_dir' => 'transcriptions',
        'cleanup_temp_files' => true,
        'max_retries' => 3,
        'retry_delay' => 5, // seconds
        'timeout' => 1800, // 30 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Specifications
    |--------------------------------------------------------------------------
    |
    | Detailed specifications for each Whisper model including performance
    | characteristics and system requirements.
    |
    */

    'models' => [
        'tiny' => [
            'parameters' => '39M',
            'multilingual' => false,
            'required_vram' => '~1 GB',
            'relative_speed' => 32,
            'english_only' => true,
        ],
        'tiny.en' => [
            'parameters' => '39M',
            'multilingual' => false,
            'required_vram' => '~1 GB',
            'relative_speed' => 32,
            'english_only' => true,
        ],
        'base' => [
            'parameters' => '74M',
            'multilingual' => true,
            'required_vram' => '~1 GB',
            'relative_speed' => 16,
            'english_only' => false,
        ],
        'base.en' => [
            'parameters' => '74M',
            'multilingual' => false,
            'required_vram' => '~1 GB',
            'relative_speed' => 16,
            'english_only' => true,
        ],
        'small' => [
            'parameters' => '244M',
            'multilingual' => true,
            'required_vram' => '~2 GB',
            'relative_speed' => 6,
            'english_only' => false,
        ],
        'small.en' => [
            'parameters' => '244M',
            'multilingual' => false,
            'required_vram' => '~2 GB',
            'relative_speed' => 6,
            'english_only' => true,
        ],
        'medium' => [
            'parameters' => '769M',
            'multilingual' => true,
            'required_vram' => '~5 GB',
            'relative_speed' => 2,
            'english_only' => false,
        ],
        'medium.en' => [
            'parameters' => '769M',
            'multilingual' => false,
            'required_vram' => '~5 GB',
            'relative_speed' => 2,
            'english_only' => true,
        ],
        'large' => [
            'parameters' => '1550M',
            'multilingual' => true,
            'required_vram' => '~10 GB',
            'relative_speed' => 1,
            'english_only' => false,
        ],
        'large-v2' => [
            'parameters' => '1550M',
            'multilingual' => true,
            'required_vram' => '~10 GB',
            'relative_speed' => 1,
            'english_only' => false,
        ],
        'large-v3' => [
            'parameters' => '1550M',
            'multilingual' => true,
            'required_vram' => '~10 GB',
            'relative_speed' => 1,
            'english_only' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Global validation rules and constraints for transcription processing.
    |
    */

    'validation' => [
        'max_file_size' => 500 * 1024 * 1024, // 500 MB
        'min_file_size' => 1024, // 1 KB
        'allowed_mime_types' => [
            'audio/wav',
            'audio/mpeg',
            'audio/mp4',
            'audio/x-m4a',
            'audio/flac',
            'audio/ogg',
            'audio/x-ms-wma',
            'audio/aac',
        ],
        'max_duration' => 14400, // 4 hours
        'min_duration' => 1, // 1 second
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for performance monitoring and optimization.
    |
    */

    'monitoring' => [
        'track_processing_time' => true,
        'track_accuracy_metrics' => true,
        'track_resource_usage' => true,
        'log_performance_data' => true,
        'alert_on_slow_processing' => true,
        'slow_processing_threshold' => 2.0, // 2x real-time
    ],
];
