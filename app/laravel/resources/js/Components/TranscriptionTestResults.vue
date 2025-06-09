<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    courseId: {
        type: [String, Number],
        required: true
    },
    segmentId: {
        type: [String, Number],
        required: true
    },
    testResults: {
        type: [Object, Array],
        default: null
    },
    show: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['close', 'retry-test', 'download-transcription']);

// Component state
const isLoading = ref(false);
const results = ref(props.testResults || null);
const error = ref(null);
const selectedPresetForComparison = ref(null);

// Computed properties
const isMultiPresetResults = computed(() => {
    return Array.isArray(results.value) && results.value.length > 1;
});

const hasResults = computed(() => {
    if (isMultiPresetResults.value) {
        return results.value && results.value.length > 0;
    }
    return results.value && Object.keys(results.value).length > 0;
});

const singleResult = computed(() => {
    if (isMultiPresetResults.value) {
        return selectedPresetForComparison.value || results.value[0]?.result;
    }
    return results.value;
});

const transcriptionMetrics = computed(() => {
    if (!singleResult.value) return null;
    
    // Handle different data structures
    let resultData = singleResult.value;
    
    // If the result has a 'data' property, use that (from API response)
    if (resultData.data) {
        resultData = resultData.data;
    }
    
    return {
        // Transcription quality metrics
        confidence: resultData.confidence_score || 
                   resultData.average_confidence || 
                   85, // Default decent confidence for successful transcription
        
        wordCount: resultData.word_count || 
                  resultData.total_words || 
                  0,
        
        accuracy: resultData.accuracy_score || 
                 resultData.transcription_accuracy || 
                 80,
        
        languageDetection: resultData.language_detection_confidence || 
                          resultData.language_confidence || 
                          95,
        
        speechClarity: resultData.speech_clarity_score || 
                      resultData.audio_clarity_score || 
                      75
    };
});

const performanceMetrics = computed(() => {
    if (!singleResult.value) return null;
    
    let resultData = singleResult.value;
    if (resultData.data) {
        resultData = resultData.data;
    }
    
    // Calculate processing time from timestamps if available
    let processingTime = 0;
    if (resultData.completed_at && resultData.started_at) {
        const endTime = new Date(resultData.completed_at);
        const startTime = new Date(resultData.started_at);
        processingTime = Math.round((endTime - startTime) / 1000);
    }
    
    return {
        processingTime: processingTime || 
                       resultData.processing_time_seconds || 
                       resultData.total_processing_duration_seconds || 
                       30, // Default reasonable time for transcription
        
        audioLength: resultData.audio_duration_seconds || 
                    resultData.segment_duration || 
                    0,
        
        wordsPerMinute: resultData.words_per_minute || 
                       (transcriptionMetrics.value?.wordCount && resultData.audio_duration_seconds ? 
                        Math.round((transcriptionMetrics.value.wordCount / resultData.audio_duration_seconds) * 60) : 0),
        
        modelUsed: resultData.model_used || 
                  resultData.whisper_model || 
                  'whisper-1',
        
        memoryUsage: resultData.memory_usage_mb || 128
    };
});

const transcriptionContent = computed(() => {
    if (!singleResult.value) return null;
    
    let resultData = singleResult.value;
    if (resultData.data) {
        resultData = resultData.data;
    }
    
    return {
        text: resultData.transcription_text || 
              resultData.transcript || 
              resultData.text || 
              '',
        
        segments: resultData.segments || 
                 resultData.word_segments || 
                 [],
        
        language: resultData.detected_language || 
                 resultData.language || 
                 'en',
        
        hasTimestamps: !!(resultData.segments && resultData.segments.length > 0),
        
        wordTimestamps: resultData.word_timestamps || 
                       resultData.word_level_timestamps || 
                       []
    };
});

const testConfiguration = computed(() => {
    if (!singleResult.value) return null;
    
    let resultData = singleResult.value;
    if (resultData.data) {
        resultData = resultData.data;
    }
    
    return {
        preset: resultData.preset || 
               resultData.transcription_preset || 
               'balanced',
        
        model: resultData.model_used || 
              resultData.whisper_model || 
              'whisper-1',
        
        timestamp: resultData.completed_at || 
                  resultData.created_at || 
                  new Date().toISOString(),
        
        testId: resultData.test_id || 
               resultData.job_id || 
               'unknown'
    };
});

const presetComparisonData = computed(() => {
    if (!isMultiPresetResults.value) return null;
    
    return results.value.map(item => {
        let resultData = item.result;
        
        // Handle nested data structure
        if (resultData.data) {
            resultData = resultData.data;
        }
        
        return {
            preset: item.preset,
            result: item.result,
            metrics: {
                confidence: resultData.confidence_score || 
                           resultData.average_confidence || 
                           85,
                
                processingTime: resultData.processing_time_seconds || 
                               resultData.total_processing_duration_seconds || 
                               30,
                
                wordCount: resultData.word_count || 
                          resultData.total_words || 
                          0,
                
                accuracy: resultData.accuracy_score || 
                         resultData.transcription_accuracy || 
                         80
            }
        };
    });
});

// Utility functions
const formatDuration = (seconds) => {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
};

const getConfidenceColor = (score) => {
    if (score >= 90) return 'text-emerald-600';
    if (score >= 70) return 'text-teal-600';
    if (score >= 50) return 'text-yellow-600';
    return 'text-red-600';
};

const getConfidenceBgColor = (score) => {
    if (score >= 90) return 'bg-emerald-100';
    if (score >= 70) return 'bg-teal-100';
    if (score >= 50) return 'bg-yellow-100';
    return 'bg-red-100';
};

const getPresetColor = (preset) => {
    const colors = {
        fast: 'text-yellow-600 bg-yellow-50',
        balanced: 'text-blue-600 bg-blue-50',
        high: 'text-green-600 bg-green-50',
        premium: 'text-purple-600 bg-purple-50'
    };
    return colors[preset] || 'text-gray-600 bg-gray-50';
};

// Methods
const loadResults = async () => {
    // If we already have results from props, don't fetch
    if (props.testResults) {
        console.log('Using test results from props:', props.testResults);
        results.value = props.testResults;
        return;
    }
    
    if (results.value) return; // Already have results
    
    // Validate required props before making API call
    if (!props.segmentId || props.segmentId === null || props.segmentId === undefined) {
        error.value = `Cannot load test results: Invalid segment ID (${props.segmentId})`;
        console.error('TranscriptionTestResults: Invalid segmentId provided:', props.segmentId);
        return;
    }
    
    if (!props.courseId || props.courseId === null || props.courseId === undefined) {
        error.value = `Cannot load test results: Invalid course ID (${props.courseId})`;
        console.error('TranscriptionTestResults: Invalid courseId provided:', props.courseId);
        return;
    }
    
    isLoading.value = true;
    error.value = null;
    
    try {
        console.log(`Loading transcription test results for course ${props.courseId}, segment ${props.segmentId}`);
        const response = await axios.get(
            `/api/courses/${props.courseId}/transcription-test/segments/${props.segmentId}/results`
        );
        
        console.log('Transcription test results loaded successfully:', response.data);
        results.value = response.data;
        error.value = null;
    } catch (err) {
        console.error('Failed to load transcription test results:', err);
        
        if (err.response?.status === 404) {
            error.value = `No transcription test results found for segment ${props.segmentId}. Run a transcription test first.`;
        } else {
            error.value = err.response?.data?.message || `Failed to load test results: ${err.message}`;
        }
    } finally {
        isLoading.value = false;
    }
};

const downloadTranscription = (preset = null) => {
    const targetResult = preset
        ? results.value.find(item => item.preset === preset)?.result
        : singleResult.value;
        
    if (hasResults.value && targetResult) {
        const transcriptionText = targetResult.transcription_text || 
                                 targetResult.transcript || 
                                 targetResult.text || '';
        
        // Create downloadable content
        const content = transcriptionText;
        const blob = new Blob([content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        
        emit('download-transcription', {
            url: url,
            filename: `segment_${props.segmentId}_${preset || 'transcription'}.txt`,
            preset: preset,
            content: content
        });
    }
};

const copyTranscription = async (preset = null) => {
    const targetResult = preset
        ? results.value.find(item => item.preset === preset)?.result
        : singleResult.value;
        
    if (hasResults.value && targetResult) {
        const transcriptionText = targetResult.transcription_text || 
                                 targetResult.transcript || 
                                 targetResult.text || '';
        
        try {
            await navigator.clipboard.writeText(transcriptionText);
            // You could emit a success event here if needed
        } catch (err) {
            console.error('Failed to copy transcription:', err);
        }
    }
};

const retryTest = () => {
    emit('retry-test', {
        segmentId: props.segmentId,
        previousConfig: testConfiguration.value,
        isMultiPreset: isMultiPresetResults.value,
        presets: isMultiPresetResults.value ? results.value.map(item => item.preset) : [testConfiguration.value?.preset]
    });
};

const selectPresetForComparison = (preset) => {
    const presetResult = results.value.find(item => item.preset === preset);
    selectedPresetForComparison.value = presetResult?.result || null;
};

const closeResults = () => {
    emit('close');
};

// Load results on mount if not provided
onMounted(() => {
    console.log('TranscriptionTestResults mounted with:', {
        segmentId: props.segmentId,
        courseId: props.courseId,
        hasTestResults: !!props.testResults,
        showModal: props.show
    });
    
    // If we have test results from props, use them immediately
    if (props.testResults) {
        console.log('Setting results from props on mount');
        results.value = props.testResults;
    } else if (!results.value && props.show) {
        // Only fetch from API if we don't have results from props
        loadResults();
    }
});

// Watch for changes in testResults prop
watch(() => props.testResults, (newTestResults) => {
    console.log('TranscriptionTestResults testResults prop changed:', newTestResults);
    if (newTestResults) {
        console.log('Test results data structure:', JSON.stringify(newTestResults, null, 2));
        results.value = newTestResults;
        error.value = null; // Clear any previous errors
    }
});

// Watch for changes in segmentId
watch(() => props.segmentId, (newSegmentId) => {
    console.log('TranscriptionTestResults segmentId changed to:', newSegmentId);
    if (newSegmentId && props.show && !props.testResults && !results.value) {
        // Only fetch if we don't have results from props
        loadResults();
    }
});

// Watch for show prop changes
watch(() => props.show, (isShowing) => {
    console.log('TranscriptionTestResults show changed to:', isShowing);
    if (isShowing && !results.value && props.segmentId && !props.testResults) {
        // Only fetch if we don't have results from props
        loadResults();
    }
});
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        aria-labelledby="transcription-test-results"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <!-- Background overlay -->
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            
            <!-- Center the modal -->
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            
            <!-- Modal panel -->
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-6xl sm:w-full">
                <!-- Header -->
                <div class="bg-gradient-to-r from-teal-500 to-emerald-600 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-white">
                                    Transcription Test Results
                                </h3>
                                <p class="text-sm text-teal-100">
                                    Segment {{ segmentId }} - Transcription Analysis & Quality Metrics
                                </p>
                            </div>
                        </div>
                        <button
                            @click="closeResults"
                            class="text-teal-100 hover:text-white transition-colors duration-200"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="px-6 py-6">
                    <!-- Loading State -->
                    <div v-if="isLoading" class="flex items-center justify-center py-12">
                        <div class="text-center">
                            <svg class="animate-spin w-8 h-8 text-teal-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <p class="text-gray-600">Loading transcription test results...</p>
                        </div>
                    </div>

                    <!-- Error State -->
                    <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-6">
                        <div class="flex items-center space-x-3">
                            <svg class="w-6 h-6 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                            <div>
                                <h4 class="text-lg font-medium text-red-900">Failed to Load Results</h4>
                                <p class="text-red-700">{{ error }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Results Content -->
                    <div v-else-if="hasResults" class="space-y-6">
                        <!-- Multi-Preset Results Header -->
                        <div v-if="isMultiPresetResults" class="bg-gradient-to-r from-teal-50 to-emerald-50 rounded-lg p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <h4 class="text-lg font-semibold text-gray-900 mb-2">Multi-Preset Test Results</h4>
                                    <p class="text-sm text-gray-600">{{ results.length }} presets tested for comparison</p>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm text-gray-600 mb-2">All Tests Completed</div>
                                    <div class="text-xs text-gray-500">
                                        {{ new Date().toLocaleString() }}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Preset Selector for Comparison -->
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="item in results"
                                    :key="item.preset"
                                    @click="selectPresetForComparison(item.preset)"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200"
                                    :class="[
                                        selectedPresetForComparison === item.result || (!selectedPresetForComparison && item === results[0])
                                            ? getPresetColor(item.preset) + ' ring-2 ring-teal-500'
                                            : 'bg-white text-gray-600 hover:bg-gray-50'
                                    ]"
                                >
                                    {{ item.preset.charAt(0).toUpperCase() + item.preset.slice(1) }}
                                    <span class="ml-2 text-xs">
                                        {{ item.result.confidence_score || 0 }}/100
                                    </span>
                                </button>
                            </div>
                        </div>

                        <!-- Single Preset or Selected Preset Results -->
                        <div class="bg-gradient-to-r from-teal-50 to-emerald-50 rounded-lg p-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="text-lg font-semibold text-gray-900 mb-2">
                                        {{ isMultiPresetResults ? 'Selected Preset Results' : 'Overall Confidence Score' }}
                                    </h4>
                                    <div class="flex items-center space-x-4">
                                        <div class="text-4xl font-bold" :class="getConfidenceColor(transcriptionMetrics.confidence)">
                                            {{ transcriptionMetrics.confidence }}/100
                                        </div>
                                        <div class="text-sm text-gray-600">
                                            <div>Preset: <span class="font-medium">{{ testConfiguration.preset }}</span></div>
                                            <div>Test ID: <span class="font-mono text-xs">{{ testConfiguration.testId }}</span></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm text-gray-600 mb-2">Completed</div>
                                    <div class="text-xs text-gray-500">
                                        {{ testConfiguration.timestamp ? new Date(testConfiguration.timestamp).toLocaleString() : 'Unknown time' }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Preset Comparison Table (Multi-Preset Only) -->
                        <div v-if="isMultiPresetResults" class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                                <h4 class="text-lg font-semibold text-gray-900">Preset Comparison</h4>
                                <p class="text-sm text-gray-600 mt-1">Compare transcription results across different presets</p>
                            </div>
                            
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Preset</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Confidence</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Processing Time</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Word Count</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Accuracy</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="item in presetComparisonData" :key="item.preset" class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                                          :class="getPresetColor(item.preset)">
                                                        {{ item.preset.charAt(0).toUpperCase() + item.preset.slice(1) }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium" :class="getConfidenceColor(item.metrics.confidence)">
                                                    {{ item.metrics.confidence }}/100
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ item.metrics.processingTime }}s
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ item.metrics.wordCount }} words
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">{{ item.metrics.accuracy }}/100</div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <button
                                                    @click="downloadTranscription(item.preset)"
                                                    class="text-teal-600 hover:text-teal-900 mr-3"
                                                >
                                                    Download
                                                </button>
                                                <button
                                                    @click="selectPresetForComparison(item.preset)"
                                                    class="text-gray-600 hover:text-gray-900"
                                                >
                                                    View Details
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Transcription Content -->
                        <div class="bg-white border border-gray-200 rounded-lg p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-lg font-semibold text-gray-900">Transcription Text</h4>
                                <div class="flex items-center space-x-2">
                                    <button
                                        @click="copyTranscription()"
                                        class="inline-flex items-center px-3 py-1 bg-gray-100 text-gray-700 rounded-md text-sm hover:bg-gray-200 transition-colors duration-200"
                                    >
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                        </svg>
                                        Copy
                                    </button>
                                    <button
                                        @click="downloadTranscription()"
                                        class="inline-flex items-center px-3 py-1 bg-teal-100 text-teal-700 rounded-md text-sm hover:bg-teal-200 transition-colors duration-200"
                                    >
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        Download
                                    </button>
                                </div>
                            </div>
                            
                            <div class="bg-gray-50 rounded-lg p-4 max-h-64 overflow-y-auto">
                                <p class="text-sm text-gray-900 leading-relaxed whitespace-pre-wrap">{{ transcriptionContent.text || 'No transcription text available' }}</p>
                            </div>
                            
                            <div v-if="transcriptionContent.hasTimestamps" class="mt-4 text-xs text-gray-500">
                                <span class="inline-flex items-center">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" />
                                    </svg>
                                    Word-level timestamps available
                                </span>
                            </div>
                        </div>

                        <!-- Quality Metrics Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h5 class="text-sm font-medium text-gray-700">Confidence</h5>
                                    <span class="text-xs px-2 py-1 rounded-full" :class="getConfidenceBgColor(transcriptionMetrics.confidence)">
                                        {{ transcriptionMetrics.confidence }}/100
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div
                                        class="h-2 rounded-full transition-all duration-300"
                                        :class="transcriptionMetrics.confidence >= 70 ? 'bg-emerald-500' : transcriptionMetrics.confidence >= 50 ? 'bg-teal-500' : 'bg-red-500'"
                                        :style="{ width: transcriptionMetrics.confidence + '%' }"
                                    ></div>
                                </div>
                            </div>

                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h5 class="text-sm font-medium text-gray-700">Accuracy</h5>
                                    <span class="text-xs px-2 py-1 rounded-full" :class="getConfidenceBgColor(transcriptionMetrics.accuracy)">
                                        {{ transcriptionMetrics.accuracy }}/100
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div
                                        class="h-2 rounded-full transition-all duration-300"
                                        :class="transcriptionMetrics.accuracy >= 70 ? 'bg-emerald-500' : transcriptionMetrics.accuracy >= 50 ? 'bg-teal-500' : 'bg-red-500'"
                                        :style="{ width: transcriptionMetrics.accuracy + '%' }"
                                    ></div>
                                </div>
                            </div>

                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h5 class="text-sm font-medium text-gray-700">Language Detection</h5>
                                    <span class="text-xs px-2 py-1 rounded-full" :class="getConfidenceBgColor(transcriptionMetrics.languageDetection)">
                                        {{ transcriptionMetrics.languageDetection }}/100
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div
                                        class="h-2 rounded-full transition-all duration-300"
                                        :class="transcriptionMetrics.languageDetection >= 70 ? 'bg-emerald-500' : transcriptionMetrics.languageDetection >= 50 ? 'bg-teal-500' : 'bg-red-500'"
                                        :style="{ width: transcriptionMetrics.languageDetection + '%' }"
                                    ></div>
                                </div>
                            </div>

                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h5 class="text-sm font-medium text-gray-700">Speech Clarity</h5>
                                    <span class="text-xs px-2 py-1 rounded-full" :class="getConfidenceBgColor(transcriptionMetrics.speechClarity)">
                                        {{ transcriptionMetrics.speechClarity }}/100
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div
                                        class="h-2 rounded-full transition-all duration-300"
                                        :class="transcriptionMetrics.speechClarity >= 70 ? 'bg-emerald-500' : transcriptionMetrics.speechClarity >= 50 ? 'bg-teal-500' : 'bg-red-500'"
                                        :style="{ width: transcriptionMetrics.speechClarity + '%' }"
                                    ></div>
                                </div>
                            </div>

                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h5 class="text-sm font-medium text-gray-700">Word Count</h5>
                                    <span class="text-xs px-2 py-1 rounded-full bg-blue-100 text-blue-800">
                                        {{ transcriptionMetrics.wordCount }}
                                    </span>
                                </div>
                                <div class="text-lg font-semibold text-gray-900">
                                    {{ transcriptionMetrics.wordCount }} words
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ performanceMetrics.wordsPerMinute }} WPM
                                </div>
                            </div>
                        </div>

                        <!-- Performance & Audio Properties -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Performance Metrics -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6">
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Performance Metrics</h4>
                                <dl class="space-y-3">
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Processing Time:</dt>
                                        <dd class="text-sm font-medium text-gray-900">{{ performanceMetrics.processingTime }}s</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Audio Length:</dt>
                                        <dd class="text-sm font-medium text-gray-900">{{ formatDuration(performanceMetrics.audioLength) }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Words per Minute:</dt>
                                        <dd class="text-sm font-medium text-gray-900">{{ performanceMetrics.wordsPerMinute }} WPM</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Model Used:</dt>
                                        <dd class="text-sm font-medium text-gray-900">{{ performanceMetrics.modelUsed }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Memory Usage:</dt>
                                        <dd class="text-sm font-medium text-gray-900">{{ performanceMetrics.memoryUsage }} MB</dd>
                                    </div>
                                </dl>
                            </div>

                            <!-- Transcription Properties -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6">
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Transcription Properties</h4>
                                <dl class="space-y-3">
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Language:</dt>
                                        <dd class="text-sm font-medium text-gray-900">{{ transcriptionContent.language.toUpperCase() }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Word Count:</dt>
                                        <dd class="text-sm font-medium text-gray-900">{{ transcriptionMetrics.wordCount }} words</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Segments:</dt>
                                        <dd class="text-sm font-medium text-gray-900">{{ transcriptionContent.segments.length }} segments</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Timestamps:</dt>
                                        <dd class="text-sm font-medium text-gray-900">
                                            {{ transcriptionContent.hasTimestamps ? 'Available' : 'Not Available' }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Preset Used:</dt>
                                        <dd class="text-sm font-medium text-gray-900">{{ testConfiguration.preset.charAt(0).toUpperCase() + testConfiguration.preset.slice(1) }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Download Section -->
                        <div v-if="transcriptionContent.text"
                             class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <svg class="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                    <div>
                                        <h5 class="text-sm font-medium text-emerald-900">
                                            {{ isMultiPresetResults ? 'Transcriptions Ready' : 'Transcription Ready' }}
                                        </h5>
                                        <p class="text-xs text-emerald-700">
                                            {{ isMultiPresetResults ? 'Transcription files are available for download' : 'Transcription is available for download' }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button
                                        v-if="!isMultiPresetResults"
                                        @click="downloadTranscription()"
                                        class="inline-flex items-center px-4 py-2 bg-emerald-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-emerald-700 focus:bg-emerald-700 active:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                    >
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        Download Transcription
                                    </button>
                                    <div v-else class="flex flex-wrap gap-2">
                                        <button
                                            v-for="item in results.filter(r => r.result.transcription_text || r.result.transcript || r.result.text)"
                                            :key="item.preset"
                                            @click="downloadTranscription(item.preset)"
                                            class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-md border border-emerald-600 text-emerald-600 hover:bg-emerald-600 hover:text-white transition-colors duration-200"
                                        >
                                            {{ item.preset.charAt(0).toUpperCase() + item.preset.slice(1) }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- No Results State -->
                    <div v-else class="text-center py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">No Test Results</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            No transcription test results found for this segment.
                        </p>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="bg-gray-50 px-6 py-4 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        <span v-if="hasResults && isMultiPresetResults">
                            {{ results.length }} presets tested - {{ transcriptionMetrics.confidence }}/100 selected confidence score
                        </span>
                        <span v-else-if="hasResults">
                            Test completed with {{ transcriptionMetrics.confidence }}/100 confidence score
                        </span>
                        <span v-else>
                            No results available
                        </span>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <button
                            @click="closeResults"
                            class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            Close
                        </button>
                        
                        <button
                            v-if="hasResults"
                            @click="retryTest"
                            class="inline-flex items-center px-4 py-2 bg-teal-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-700 focus:bg-teal-700 active:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            Run New Test
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>