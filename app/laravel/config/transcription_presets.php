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
            'initial_prompt' => 'This is {{#course_title}}a {{course_title}} lesson{{/course_title}}{{^course_title}}a guitar lesson{{/course_title}}{{#instructor_name}} taught by {{instructor_name}}{{/instructor_name}}. Focus on guitar instruction, musical terminology, and educational content. Essential terminology: Always transcribe "chord" never "cord" when referring to musical chords.',
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
            
            // Processing Configuration
            'enable_analytics_processing' => true, // Quality metrics, advanced features
            
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
            'initial_prompt' => 'This is {{#course_title}}a {{course_title}} lesson{{/course_title}}{{^course_title}}a guitar lesson{{/course_title}}{{#instructor_name}} taught by {{instructor_name}}{{/instructor_name}}. Focus on guitar terminology and music instruction. {{#musical_genre}}This is {{musical_genre}} style instruction. {{/musical_genre}}Essential terminology: Always transcribe "chord" never "cord", "C sharp" not "see sharp", "D flat" not "the flat", "fretboard" not "freight board", "fingerpicking" not "finger picking".',
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
            
            // Processing Configuration
            'enable_analytics_processing' => true, // Quality metrics, advanced features
            
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
            'initial_prompt' => 'This is {{#course_title}}a {{course_title}} lesson{{/course_title}}{{^course_title}}a guitar lesson{{/course_title}}{{#instructor_name}} taught by {{instructor_name}}{{/instructor_name}}. {{#musical_genre}}This is {{musical_genre}} style instruction. {{/musical_genre}}{{#lesson_topic}}Today\'s topic: {{lesson_topic}}. {{/lesson_topic}}Focus on chords, scales, techniques, and music theory. Essential terminology: Always transcribe "chord" never "cord", "C sharp" not "see sharp", "D flat" not "the flat", "fretboard" not "freight board", "fingerpicking" not "finger picking", "hammer-on" not "hammering", "pull-off" not "pulling off".',
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
            
            // Processing Configuration
            'enable_analytics_processing' => true, // Quality metrics, advanced features
            
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
            'initial_prompt' => 'This is {{#course_title}}a {{course_title}} lesson{{/course_title}}{{^course_title}}a guitar lesson{{/course_title}}{{#instructor_name}} taught by {{instructor_name}}{{/instructor_name}}. {{#musical_genre}}This is {{musical_genre}} style instruction. {{/musical_genre}}{{#lesson_topic}}Today\'s topic: {{lesson_topic}}. {{/lesson_topic}}{{#specific_techniques}}Techniques covered: {{specific_techniques}}. {{/specific_techniques}}Focus on comprehensive music theory, chords, scales, fingerpicking, intervals, and progressions. Essential terminology: Always transcribe "chord" never "cord", musical notes with proper spelling: C sharp (not "see sharp"), D flat (not "the flat"), F sharp, B flat. Guitar terms: fretboard (not "freight board"), fingerpicking (not "finger picking"), hammer-on, pull-off, capo (not "cape-o"), pickup (not "pick up").',
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
            
            // Processing Configuration
            'enable_analytics_processing' => true, // Quality metrics, advanced features
            
            // Validation Rules
            'min_audio_duration' => 1, // seconds
            'max_audio_duration' => 14400, // 4 hours
            'supported_formats' => ['wav', 'mp3', 'mp4', 'm4a', 'flac', 'ogg', 'wma', 'aac'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Template Variables
    |--------------------------------------------------------------------------
    |
    | Available variables for mustache templating in initial prompts.
    | These variables can be populated from course data, segment information,
    | user preferences, and other contextual sources.
    |
    */

    'template_variables' => [
        
        // Course Information
        'course_title' => [
            'name' => 'Course Title',
            'description' => 'The full title of the course or lesson series',
            'example' => 'Advanced Jazz Guitar Masterclass',
            'source' => 'course.title',
            'data_type' => 'string',
            'category' => 'course',
            'required' => false,
        ],
        
        'course_difficulty' => [
            'name' => 'Course Difficulty',
            'description' => 'The difficulty level of the course',
            'example' => 'intermediate',
            'source' => 'course.difficulty_level',
            'data_type' => 'string',
            'category' => 'course',
            'required' => false,
            'allowed_values' => ['beginner', 'intermediate', 'advanced', 'expert'],
        ],
        
        // Instructor Information
        'instructor_name' => [
            'name' => 'Instructor Name',
            'description' => 'The name of the course instructor',
            'example' => 'John Doe',
            'source' => 'course.instructor_name',
            'data_type' => 'string',
            'category' => 'instructor',
            'required' => false,
        ],
        
        'instructor_credentials' => [
            'name' => 'Instructor Credentials',
            'description' => 'Professional credentials or qualifications of the instructor',
            'example' => 'Berklee College of Music, Grammy-nominated guitarist',
            'source' => 'course.instructor_credentials',
            'data_type' => 'string',
            'category' => 'instructor',
            'required' => false,
        ],
        
        // Lesson/Segment Information
        'lesson_topic' => [
            'name' => 'Lesson Topic',
            'description' => 'The main topic or subject of the current lesson',
            'example' => 'pentatonic scales and blues improvisation',
            'source' => 'segment.topic || course.topic',
            'data_type' => 'string',
            'category' => 'lesson',
            'required' => false,
        ],
        
        'segment_title' => [
            'name' => 'Segment Title',
            'description' => 'The title of the specific video segment being transcribed',
            'example' => 'Bending Techniques and Vibrato',
            'source' => 'segment.title',
            'data_type' => 'string',
            'category' => 'lesson',
            'required' => false,
        ],
        
        'lesson_duration' => [
            'name' => 'Lesson Duration',
            'description' => 'The duration of the lesson in minutes',
            'example' => '25',
            'source' => 'segment.duration_minutes',
            'data_type' => 'integer',
            'category' => 'lesson',
            'required' => false,
        ],
        
        // Musical Information
        'musical_genre' => [
            'name' => 'Musical Genre',
            'description' => 'The primary musical genre or style being taught',
            'example' => 'jazz',
            'source' => 'course.genre || segment.genre',
            'data_type' => 'string',
            'category' => 'musical',
            'required' => false,
            'allowed_values' => ['rock', 'jazz', 'blues', 'classical', 'country', 'folk', 'metal', 'acoustic', 'electric'],
        ],
        
        'skill_level' => [
            'name' => 'Target Skill Level',
            'description' => 'The target skill level for the lesson content',
            'example' => 'intermediate',
            'source' => 'course.skill_level || segment.skill_level',
            'data_type' => 'string',
            'category' => 'musical',
            'required' => false,
            'allowed_values' => ['beginner', 'intermediate', 'advanced', 'expert'],
        ],
        
        'specific_techniques' => [
            'name' => 'Specific Techniques',
            'description' => 'Specific guitar techniques covered in this lesson',
            'example' => 'fingerpicking, hammer-ons, pull-offs',
            'source' => 'segment.techniques || course.techniques',
            'data_type' => 'string',
            'category' => 'musical',
            'required' => false,
        ],
        
        // Educational Information
        'educational_objectives' => [
            'name' => 'Educational Objectives',
            'description' => 'Learning objectives or goals for the lesson',
            'example' => 'master the minor pentatonic scale, develop improvisational skills',
            'source' => 'segment.objectives || course.objectives',
            'data_type' => 'string',
            'category' => 'educational',
            'required' => false,
        ],
        
        // Contextual Information
        'equipment_used' => [
            'name' => 'Equipment Used',
            'description' => 'Musical equipment or instruments used in the lesson',
            'example' => 'electric guitar, tube amplifier, effects pedals',
            'source' => 'segment.equipment || course.equipment',
            'data_type' => 'string',
            'category' => 'contextual',
            'required' => false,
        ],
        
        'lesson_series' => [
            'name' => 'Lesson Series',
            'description' => 'Name of the lesson series or curriculum this belongs to',
            'example' => 'Blues Guitar Fundamentals Series',
            'source' => 'course.series_name',
            'data_type' => 'string',
            'category' => 'contextual',
            'required' => false,
        ],
        
        // Dynamic Content
        'previous_topics' => [
            'name' => 'Previous Topics',
            'description' => 'Topics covered in previous lessons (for context)',
            'example' => 'basic chord progressions, strumming patterns',
            'source' => 'course.previous_segments_topics',
            'data_type' => 'string',
            'category' => 'contextual',
            'required' => false,
        ],
        
        'upcoming_topics' => [
            'name' => 'Upcoming Topics',
            'description' => 'Topics that will be covered in future lessons',
            'example' => 'advanced chord substitutions, modal improvisation',
            'source' => 'course.upcoming_segments_topics',
            'data_type' => 'string',
            'category' => 'contextual',
            'required' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Template Categories
    |--------------------------------------------------------------------------
    |
    | Grouping of template variables by category for easier management
    | and UI organization.
    |
    */

    'template_categories' => [
        'course' => [
            'name' => 'Course Information',
            'description' => 'Variables related to the overall course or lesson series',
            'color' => 'blue',
        ],
        'instructor' => [
            'name' => 'Instructor Information',
            'description' => 'Variables about the course instructor',
            'color' => 'green',
        ],
        'lesson' => [
            'name' => 'Lesson/Segment Information',
            'description' => 'Variables specific to the current lesson or video segment',
            'color' => 'purple',
        ],
        'musical' => [
            'name' => 'Musical Content',
            'description' => 'Variables related to musical genre, techniques, and skill level',
            'color' => 'red',
        ],
        'educational' => [
            'name' => 'Educational Context',
            'description' => 'Variables about learning objectives and educational goals',
            'color' => 'yellow',
        ],
        'contextual' => [
            'name' => 'Contextual Information',
            'description' => 'Additional context about equipment, series, and related content',
            'color' => 'gray',
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
