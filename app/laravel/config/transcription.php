<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | Transcription Options
    |--------------------------------------------------------------------------
    |
    | This file contains all the configuration options for the transcription
    | system, including both Whisper transcription and audio extraction settings.
    |
    */
    
    'options' => [
        'transcription' => [
            'common' => [
                'model_name' => [
                    'description' => 'Size/complexity of Whisper model to use',
                    'options' => ['tiny', 'base', 'small', 'medium', 'large'],
                    'default' => 'base',
                    'impact' => 'Larger models provide higher accuracy but require more processing time and resources. "tiny" is fastest but least accurate, "large" is most accurate but slowest.'
                ],
                'language' => [
                    'description' => 'Language code for transcription',
                    'default' => 'en',
                    'impact' => 'Setting the correct language improves accuracy. Using null enables auto-detection.'
                ],
                'initial_prompt' => [
                    'description' => 'Text to guide the model at the beginning of transcription',
                    'default' => null,
                    'impact' => 'Useful for domain-specific terms or context. Helps model understand specialized vocabulary or expected content.'
                ]
            ],
            'advanced' => [
                'temperature' => [
                    'description' => 'Controls randomness in model predictions',
                    'default' => 0,
                    'range' => [0, 1],
                    'impact' => '0 gives deterministic, consistent output. Higher values introduce variability, may help with difficult audio but reduce consistency.'
                ],
                'word_timestamps' => [
                    'description' => 'Generate timestamps for each word',
                    'default' => true,
                    'impact' => 'When enabled, provides precise timing for each word. Useful for subtitle generation and audio-text alignment.'
                ],
                'condition_on_previous_text' => [
                    'description' => 'Use previous segments to improve current segment',
                    'default' => false,
                    'impact' => 'When enabled, maintains context between segments for potentially more coherent transcription, but may propagate errors.'
                ],
                'compression_ratio_threshold' => [
                    'description' => 'Filter for repeated content',
                    'default' => 2.4,
                    'impact' => 'Helps detect and filter hallucinations where the model generates repetitive text.'
                ],
                'logprob_threshold' => [
                    'description' => 'Confidence threshold for transcription',
                    'default' => -1.0,
                    'impact' => 'Filters out words/segments with confidence below this threshold. Lower values keep more content.'
                ],
                'no_speech_threshold' => [
                    'description' => 'Threshold for detecting silence/no speech',
                    'default' => 0.6,
                    'impact' => 'Higher values more aggressively filter out audio portions detected as non-speech.'
                ],
                'beam_size' => [
                    'description' => 'Number of parallel decoding paths',
                    'default' => 5,
                    'impact' => 'Larger values may improve accuracy but increase processing time.'
                ],
                'patience' => [
                    'description' => 'Beam search patience factor',
                    'default' => null,
                    'impact' => 'Controls early stopping in beam search. Higher values improve quality but increase processing time.'
                ]
            ]
        ],
        'audio' => [
            'common' => [
                'sample_rate' => [
                    'description' => 'Audio sampling rate in Hz',
                    'options' => ['8000', '16000', '22050', '44100', '48000'],
                    'default' => '16000',
                    'impact' => 'Higher values capture more audio detail but increase file size. 16kHz is optimal for speech recognition, 44.1/48kHz for music.'
                ],
                'channels' => [
                    'description' => 'Number of audio channels',
                    'options' => ['1', '2'],
                    'default' => '1',
                    'impact' => 'Mono (1) is recommended for speech recognition. Stereo (2) preserves left/right separation but doubles file size.'
                ]
            ],
            'advanced' => [
                'audio_codec' => [
                    'description' => 'Audio encoding format',
                    'options' => ['pcm_s16le', 'pcm_s24le', 'pcm_f32le', 'flac'],
                    'default' => 'pcm_s16le',
                    'impact' => 'PCM 16-bit provides good quality for speech. Higher bit depths (24/32-bit) or lossless compression (FLAC) may improve quality but increase processing time.'
                ],
                'noise_reduction' => [
                    'description' => 'Apply noise reduction filter',
                    'default' => false,
                    'impact' => 'Can improve transcription accuracy for noisy recordings but may distort speech if set too aggressively.'
                ],
                'normalize_audio' => [
                    'description' => 'Normalize audio levels',
                    'default' => false,
                    'impact' => 'Ensures consistent volume throughout the audio, which can improve transcription of quiet sections.'
                ],
                'volume_boost' => [
                    'description' => 'Boost audio volume by percentage',
                    'default' => 0,
                    'range' => [0, 100],
                    'impact' => 'Can help with very quiet recordings, but may cause distortion if set too high.'
                ],
                'low_pass' => [
                    'description' => 'Apply low-pass filter (Hz)',
                    'default' => null,
                    'range' => [100, 20000],
                    'impact' => 'Filters out high frequencies, can help reduce hissing sounds.'
                ],
                'high_pass' => [
                    'description' => 'Apply high-pass filter (Hz)',
                    'default' => null,
                    'range' => [20, 2000],
                    'impact' => 'Filters out low frequencies, can help reduce background rumble.'
                ]
            ]
        ],
        'terminology' => [
            'common' => [
                'extraction_method' => [
                    'description' => 'Method used to extract terminology',
                    'options' => ['regex', 'spacy', 'hybrid'],
                    'default' => 'regex',
                    'impact' => 'Regex is faster for exact matches, spaCy provides better linguistic understanding, hybrid uses both approaches.'
                ],
                'case_sensitive' => [
                    'description' => 'Whether term matching should be case sensitive',
                    'default' => false,
                    'impact' => 'When enabled, "AWS" and "aws" would be considered different terms. Usually disabled for better recall.'
                ],
                'min_term_frequency' => [
                    'description' => 'Minimum number of occurrences to include a term',
                    'default' => 1,
                    'range' => [1, 10],
                    'impact' => 'Higher values filter out rare terms, useful for focusing on frequently mentioned concepts.'
                ]
            ],
            'advanced' => [
                'spacy_model' => [
                    'description' => 'spaCy model to use for NLP processing',
                    'options' => ['en_core_web_sm', 'en_core_web_md', 'en_core_web_lg'],
                    'default' => 'en_core_web_sm',
                    'impact' => 'Larger models (md, lg) have better accuracy but use more memory and are slower to process.'
                ],
                'use_lemmatization' => [
                    'description' => 'Convert words to their base form',
                    'default' => true,
                    'impact' => 'When enabled, different forms of a word (e.g., "running", "runs") are treated as the same term ("run").'
                ],
                'max_terms' => [
                    'description' => 'Maximum number of terms to extract',
                    'default' => 200,
                    'range' => [10, 1000],
                    'impact' => 'Limits the total number of terms extracted, preventing overwhelming results for long transcripts.'
                ],
                'context_window' => [
                    'description' => 'Number of words around each term to capture as context',
                    'default' => 5,
                    'range' => [0, 20],
                    'impact' => 'Larger windows provide more context but can make results verbose.'
                ],
                'include_uncategorized' => [
                    'description' => 'Include terms not in predefined categories',
                    'default' => false,
                    'impact' => 'When enabled, extracts potentially relevant terms even if they don\'t match predefined categories.'
                ],
                'entity_types' => [
                    'description' => 'Entity types to recognize with spaCy',
                    'options' => ['ORG', 'PRODUCT', 'GPE', 'PERSON', 'TECH', 'ALL'],
                    'default' => ['ORG', 'PRODUCT', 'TECH'],
                    'impact' => 'Determines what types of entities are recognized. "ALL" includes all entity types that spaCy can detect.'
                ]
            ]
        ]
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Model Label Mappings
    |--------------------------------------------------------------------------
    |
    | Friendly labels for display purposes.
    |
    */
    
    'model_labels' => [
        'tiny' => 'Tiny - Fastest, least accurate',
        'base' => 'Base - Fast, good accuracy',
        'small' => 'Small - Good balance',
        'medium' => 'Medium - Accurate, slower',
        'large' => 'Large - Most accurate, slowest',
        'large-v2' => 'Large v2 - Improved large model',
        'large-v3' => 'Large v3 - Latest, most accurate model',
    ],

    /*
    |--------------------------------------------------------------------------
    | Language Options
    |--------------------------------------------------------------------------
    |
    | Available language options for transcription.
    |
    */
    
    'languages' => [
        ['value' => '', 'label' => 'Auto-detect language'],
        ['value' => 'en', 'label' => 'English'],
        ['value' => 'es', 'label' => 'Spanish'],
        ['value' => 'fr', 'label' => 'French'],
        ['value' => 'de', 'label' => 'German'],
        ['value' => 'it', 'label' => 'Italian'],
        ['value' => 'pt', 'label' => 'Portuguese'],
        ['value' => 'nl', 'label' => 'Dutch'],
        ['value' => 'ja', 'label' => 'Japanese'],
        ['value' => 'zh', 'label' => 'Chinese'],
        ['value' => 'ru', 'label' => 'Russian'],
    ],
]; 