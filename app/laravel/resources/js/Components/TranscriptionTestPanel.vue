<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import PrimaryButton from './PrimaryButton.vue';
import SecondaryButton from './SecondaryButton.vue';

const props = defineProps({
    courseId: {
        type: [String, Number],
        required: true
    },
    show: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['close', 'test-started', 'test-completed', 'test-failed']);

// Test configuration state
const selectedPreset = ref('balanced');
const selectedPresets = ref(['balanced']); // Multi-preset selection
const useMultiPreset = ref(false); // Toggle between single and multi-preset
const selectedSegmentId = ref(null);

// Test execution state
const isTestRunning = ref(false);
const currentTestIndex = ref(0);
const testResults = ref([]);
const testProgress = ref({
    status: 'idle', // idle, running, completed, failed
    progress: 0,
    message: '',
    startTime: null,
    endTime: null,
    results: null,
    currentPreset: null,
    totalTests: 0,
    completedTests: 0
});

// Loading state for fetching available segments
const isLoadingSegments = ref(false);
const segmentsError = ref(null);
const availableSegmentsData = ref([]);

// Loading state for fetching preset configurations
const isLoadingPresets = ref(false);
const presetConfigurations = ref({});
const presetsError = ref(null);

// Template variables and preview state
const templateVariables = ref({});
const templateCategories = ref({});
const isLoadingTemplateData = ref(false);
const showTemplatePreview = ref(false);
const templatePreview = ref('');
const showVariablesList = ref(false);

// Available segments for testing (only those with WAV files)
const availableSegments = computed(() => {
    return availableSegmentsData.value || [];
});

// Fetch available segments from API
const fetchAvailableSegments = async () => {
    if (!props.courseId) return;
    
    isLoadingSegments.value = true;
    segmentsError.value = null;
    
    try {
        const response = await axios.get(`/api/courses/${props.courseId}/transcription-test/segments`);
        
        if (response.data.success) {
            availableSegmentsData.value = response.data.available_segments.map(segment => ({
                id: segment.segment_id,
                title: segment.title,
                channel_id: segment.channel_id,
                channel_name: segment.channel_name,
                wav_file: segment.wav_file,
                wav_file_path: segment.wav_file_path,
                file_size: segment.file_size,
                last_modified: segment.last_modified,
                // Add computed properties for compatibility
                has_wav_file: true,
                is_available_for_testing: true
            }));
            
            console.log('Available segments loaded:', {
                course_id: props.courseId,
                total_segments: response.data.total_segments,
                available_count: response.data.available_segments_count,
                segments: availableSegmentsData.value
            });
        } else {
            segmentsError.value = response.data.message || 'Failed to load available segments';
            availableSegmentsData.value = [];
        }
    } catch (error) {
        console.error('Error fetching available segments:', error);
        segmentsError.value = error.response?.data?.message || 'Error loading available segments';
        availableSegmentsData.value = [];
    } finally {
        isLoadingSegments.value = false;
    }
};

// Fetch preset configurations from API
const fetchPresetConfigurations = async () => {
    isLoadingPresets.value = true;
    presetsError.value = null;
    
    try {
        const response = await axios.get('/api/transcription-presets');
        
        if (response.data.success) {
            presetConfigurations.value = response.data.presets || {};
            console.log('Preset configurations loaded:', Object.keys(presetConfigurations.value));
        } else {
            presetsError.value = response.data.message || 'Failed to load preset configurations';
        }
    } catch (error) {
        console.error('Error fetching preset configurations:', error);
        presetsError.value = error.response?.data?.message || 'Error loading preset configurations';
    } finally {
        isLoadingPresets.value = false;
    }
};

// Fetch template variables and categories
const fetchTemplateVariables = async () => {
    isLoadingTemplateData.value = true;
    
    try {
        const response = await axios.get('/api/transcription-presets/template/variables');
        
        if (response.data.success) {
            templateVariables.value = response.data.variables || {};
            templateCategories.value = response.data.categories || {};
            console.log('Template variables loaded:', Object.keys(templateVariables.value));
        }
    } catch (error) {
        console.error('Error fetching template variables:', error);
    } finally {
        isLoadingTemplateData.value = false;
    }
};

// Generate template preview for selected preset
const generateTemplatePreview = async (presetName) => {
    if (!presetName) return;
    
    try {
        const response = await axios.post(`/api/transcription-presets/${presetName}/preview`, {
            context: {
                // Add any current course/segment context if available
                ...(props.courseId ? { course_id: props.courseId } : {}),
                ...(selectedSegmentId.value ? { segment_id: selectedSegmentId.value } : {}),
            }
        });
        
        if (response.data.success) {
            templatePreview.value = response.data.preview;
            return response.data;
        }
    } catch (error) {
        console.error('Error generating template preview:', error);
    }
    
    return null;
};

// Toggle template preview visibility
const toggleTemplatePreview = async () => {
    showTemplatePreview.value = !showTemplatePreview.value;
    
    if (showTemplatePreview.value && !templatePreview.value) {
        const presetName = useMultiPreset.value ? selectedPresets.value[0] : selectedPreset.value;
        if (presetName) {
            await generateTemplatePreview(presetName);
        }
    }
};

// Toggle variables list visibility
const toggleVariablesList = () => {
    showVariablesList.value = !showVariablesList.value;
};

// Computed property that merges static and dynamic preset data
const enhancedPresetOptions = computed(() => {
    const staticPresets = [
        {
            value: 'fast',
            label: 'Fast',
            description: 'Quick transcription with basic accuracy',
            estimatedTime: 30,
            color: 'yellow'
        },
        {
            value: 'balanced',
            label: 'Balanced', 
            description: 'Good balance of speed and accuracy',
            estimatedTime: 60,
            color: 'blue'
        },
        {
            value: 'high',
            label: 'High Quality',
            description: 'Higher accuracy with longer processing time',
            estimatedTime: 120,
            color: 'green'
        },
        {
            value: 'premium',
            label: 'Premium',
            description: 'Maximum accuracy with advanced processing',
            estimatedTime: 300,
            color: 'purple'
        }
    ];

    // Enhance with API data if available
    return staticPresets.map(preset => {
        const apiConfig = presetConfigurations.value[preset.value];
        
        if (apiConfig) {
            return {
                ...preset,
                // API-provided configuration
                name: apiConfig.name || preset.label,
                description: apiConfig.description || preset.description,
                use_case: apiConfig.use_case || '',
                whisper_model: apiConfig.whisper_model || 'base',
                model_size: apiConfig.model_size || 'Unknown',
                vram_requirement: apiConfig.vram_requirement || 'Unknown',
                initial_prompt: apiConfig.initial_prompt || '',
                temperature: apiConfig.temperature || 0.0,
                word_timestamps: apiConfig.word_timestamps || false,
                expected_accuracy: apiConfig.expected_accuracy || 'Unknown',
                estimated_processing_time: apiConfig.estimated_processing_time || preset.estimatedTime + 's',
                relative_speed: apiConfig.relative_speed || 'Unknown',
                cpu_usage: apiConfig.cpu_usage || 'Unknown',
                memory_usage: apiConfig.memory_usage || 'Unknown',
                include_confidence_scores: apiConfig.include_confidence_scores || false,
            };
        }
        
        return preset;
    });
});

// Legacy preset options for backward compatibility
const presetOptions = [
    {
        value: 'fast',
        label: 'Fast',
        description: 'Quick transcription with basic accuracy',
        estimatedTime: 30,
        model: 'whisper-1',
        color: 'yellow'
    },
    {
        value: 'balanced',
        label: 'Balanced',
        description: 'Good balance of speed and accuracy',
        estimatedTime: 60,
        model: 'whisper-1',
        color: 'blue'
    },
    {
        value: 'high',
        label: 'High Quality',
        description: 'Higher accuracy with longer processing time',
        estimatedTime: 120,
        model: 'whisper-1',
        color: 'green'
    },
    {
        value: 'premium',
        label: 'Premium',
        description: 'Maximum accuracy with advanced processing',
        estimatedTime: 300,
        model: 'whisper-1',
        color: 'purple'
    }
];

// Computed properties
const canStartTest = computed(() => {
    const hasSegment = selectedSegmentId.value;
    const hasPreset = useMultiPreset.value
        ? selectedPresets.value.length > 0
        : selectedPreset.value;
    return hasSegment && hasPreset && !isTestRunning.value;
});

const estimatedDuration = computed(() => {
    const presetTimes = {
        fast: 30,
        balanced: 60,
        high: 120,
        premium: 300
    };
    
    if (useMultiPreset.value) {
        return selectedPresets.value.reduce((total, preset) => {
            return total + (presetTimes[preset] || 60);
        }, 0);
    }
    
    return presetTimes[selectedPreset.value] || 60;
});

const presetsForTesting = computed(() => {
    return useMultiPreset.value ? selectedPresets.value : [selectedPreset.value];
});

const getPresetInfo = (presetValue) => {
    return enhancedPresetOptions.value.find(p => p.value === presetValue) || enhancedPresetOptions.value[1];
};

const getPresetColor = (preset) => {
    const info = getPresetInfo(preset);
    const colors = {
        yellow: 'text-yellow-600 bg-yellow-50',
        blue: 'text-blue-600 bg-blue-50',
        green: 'text-green-600 bg-green-50',
        purple: 'text-purple-600 bg-purple-50'
    };
    return colors[info.color] || 'text-gray-600 bg-gray-50';
};

// Get category color for template variables
const getCategoryColor = (category) => {
    const colors = {
        course: 'text-blue-600 bg-blue-100',
        instructor: 'text-green-600 bg-green-100',
        lesson: 'text-purple-600 bg-purple-100',
        musical: 'text-red-600 bg-red-100',
        educational: 'text-yellow-600 bg-yellow-100',
        contextual: 'text-gray-600 bg-gray-100'
    };
    return colors[category] || 'text-gray-600 bg-gray-100';
};

// Mustache example for template display
const mustacheExample = computed(() => '{{variable_name}}');

// Utility functions
const formatFileSize = (bytes) => {
    if (!bytes || bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

// Methods
const selectRandomSegment = () => {
    if (availableSegments.value.length > 0) {
        const randomIndex = Math.floor(Math.random() * availableSegments.value.length);
        selectedSegmentId.value = availableSegments.value[randomIndex].id;
    }
};

const startTest = async () => {
    if (!canStartTest.value) return;

    isTestRunning.value = true;
    currentTestIndex.value = 0;
    testResults.value = [];
    
    const totalTests = presetsForTesting.value.length;
    
    testProgress.value = {
        status: 'running',
        progress: 0,
        message: 'Initializing transcription test...',
        startTime: new Date(),
        endTime: null,
        results: null,
        currentPreset: null,
        totalTests: totalTests,
        completedTests: 0
    };

    emit('test-started', {
        segmentId: selectedSegmentId.value,
        presets: presetsForTesting.value,
        isMultiPreset: useMultiPreset.value
    });

    try {
        // Dispatch all jobs at once to the Laravel Queue
        const requestPayload = {
            is_multi_preset: useMultiPreset.value
        };

        if (useMultiPreset.value) {
            requestPayload.presets = presetsForTesting.value;
        } else {
            requestPayload.preset = presetsForTesting.value[0];
        }

        const response = await axios.post(
            `/api/courses/${props.courseId}/transcription-test/${selectedSegmentId.value}`,
            requestPayload
        );

        // Single test job dispatched
        const testId = response.data.test_id;
        const dispatchedJobs = [{
            test_id: testId,
            preset: presetsForTesting.value[0],
            index: 1
        }];

        testProgress.value.message = `Transcription test dispatched to queue. Monitoring progress...`;

        // Monitor progress for the test
        await monitorTestProgress(testId);

    } catch (error) {
        console.error('Failed to start transcription test:', error);
        testProgress.value = {
            ...testProgress.value,
            status: 'failed',
            message: `Test failed: ${error.response?.data?.message || error.message}`,
            endTime: new Date()
        };
        emit('test-failed', error);
    } finally {
        isTestRunning.value = false;
        console.log('Test execution finished, isTestRunning set to false');
    }
};

const monitorTestProgress = async (testId) => {
    const maxPollingTime = 20 * 60 * 1000; // 20 minutes total
    const pollInterval = 3000; // 3 seconds
    const startTime = Date.now();

    return new Promise((resolve, reject) => {
        const poll = async () => {
            try {
                const response = await axios.get(`/api/transcription-test/results/${testId}`);
                const result = response.data;
                
                // Update progress from API response
                testProgress.value.progress = result.progress_percentage || 0;
                testProgress.value.message = result.status_message || 'Processing...';
                
                if (result.status === 'completed') {
                    testResults.value = [result.results];
                    
                    testProgress.value = {
                        ...testProgress.value,
                        status: 'completed',
                        progress: 100,
                        message: 'Transcription test completed successfully!',
                        endTime: new Date(),
                        results: testResults.value
                    };
                    
                    isTestRunning.value = false;
                    emit('test-completed', testResults.value);
                    resolve(testResults.value);
                    return;
                } else if (result.status === 'failed') {
                    testProgress.value.status = 'failed';
                    testProgress.value.message = result.status_message || 'Test failed';
                    reject(new Error('Test failed'));
                    return;
                }
                
                // Continue polling if not complete and within time limit
                if (Date.now() - startTime < maxPollingTime) {
                    setTimeout(poll, pollInterval);
                } else {
                    reject(new Error('Test timeout'));
                }

            } catch (error) {
                if (error.response?.status === 404) {
                    // 404 is expected when test results don't exist yet - continue polling
                    console.log(`Test results not ready yet for ${testId}`);
                    
                    if (Date.now() - startTime < maxPollingTime) {
                        setTimeout(poll, pollInterval);
                    } else {
                        reject(new Error('Test timeout'));
                    }
                } else {
                    console.error('Error polling test progress:', error);
                    
                    if (Date.now() - startTime < maxPollingTime) {
                        const backoffInterval = Math.min(pollInterval * 2, 10000);
                        setTimeout(poll, backoffInterval);
                    } else {
                        reject(new Error(`Polling timeout: ${error.message}`));
                    }
                }
            }
        };

        // Start polling
        setTimeout(poll, pollInterval);
    });
};

const resetTest = () => {
    testProgress.value = {
        status: 'idle',
        progress: 0,
        message: '',
        startTime: null,
        endTime: null,
        results: null,
        currentPreset: null,
        totalTests: 0,
        completedTests: 0
    };
    testResults.value = [];
    currentTestIndex.value = 0;
    isTestRunning.value = false;
};

const closePanel = () => {
    // Allow closing if not running OR if completed/failed
    if (!isTestRunning.value || testProgress.value.status === 'completed' || testProgress.value.status === 'failed') {
        resetTest();
        emit('close');
    } else {
        console.log('Cannot close panel: test is still running', { 
            isTestRunning: isTestRunning.value, 
            status: testProgress.value.status 
        });
    }
};

// Computed property for the selected preset's configuration including Whisper prompt
const selectedPresetConfig = computed(() => {
    if (useMultiPreset.value) {
        // For multi-preset, return configs for all selected presets
        return selectedPresets.value.map(preset => getPresetInfo(preset));
    }
    return getPresetInfo(selectedPreset.value);
});

// Watch for courseId changes and fetch segments
watch(() => props.courseId, (newCourseId) => {
    if (newCourseId) {
        fetchAvailableSegments();
    }
}, { immediate: true });

// Fetch preset configurations on component mount
watch(() => props.show, (isShowing) => {
    if (isShowing && Object.keys(presetConfigurations.value).length === 0) {
        fetchPresetConfigurations();
        fetchTemplateVariables();
    }
}, { immediate: true });

// Watch for preset selection changes to update template preview
watch([selectedPreset, selectedPresets], () => {
    if (showTemplatePreview.value) {
        const presetName = useMultiPreset.value ? selectedPresets.value[0] : selectedPreset.value;
        if (presetName) {
            generateTemplatePreview(presetName);
        }
    }
});

// Watch for available segments and auto-select first one
watch(availableSegments, (segments) => {
    if (segments.length > 0 && !selectedSegmentId.value) {
        selectedSegmentId.value = segments[0].id;
    }
});
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        aria-labelledby="transcription-test-panel"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <!-- Background overlay -->
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            
            <!-- Center the modal -->
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            
            <!-- Modal panel -->
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
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
                                    Transcription Testing
                                </h3>
                                <p class="text-sm text-teal-100">
                                    Test transcription quality and performance with different presets
                                </p>
                            </div>
                        </div>
                        <button
                            @click="closePanel"
                            :disabled="isTestRunning"
                            class="text-teal-100 hover:text-white transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="px-6 py-6 space-y-6">
                    <!-- Test Progress (when running) -->
                    <div v-if="isTestRunning" class="bg-teal-50 border border-teal-200 rounded-lg p-4">
                        <div class="flex items-center space-x-3 mb-3">
                            <div class="flex-shrink-0">
                                <svg class="animate-spin w-5 h-5 text-teal-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-teal-900">Transcription Test in Progress</h4>
                                <p class="text-sm text-teal-700">{{ testProgress.message }}</p>
                            </div>
                        </div>
                        
                        <!-- Progress bar -->
                        <div class="w-full bg-teal-200 rounded-full h-2">
                            <div
                                class="bg-teal-600 h-2 rounded-full transition-all duration-300"
                                :style="{ width: testProgress.progress + '%' }"
                            ></div>
                        </div>
                        
                        <div class="flex justify-between text-xs text-teal-600 mt-2">
                            <span>{{ testProgress.progress }}% complete</span>
                            <span v-if="testProgress.totalTests > 1">
                                {{ testProgress.completedTests }}/{{ testProgress.totalTests }} tests
                            </span>
                            <span v-else-if="testProgress.startTime">
                                Started {{ new Date(testProgress.startTime).toLocaleTimeString() }}
                            </span>
                        </div>
                        
                        <!-- Multi-preset progress details -->
                        <div v-if="testProgress.totalTests > 1" class="mt-3 text-xs text-teal-700">
                            <div class="flex items-center justify-between">
                                <span>Current: {{ testProgress.currentPreset || 'Initializing...' }}</span>
                                <span>{{ testProgress.completedTests }} completed</span>
                            </div>
                        </div>
                    </div>

                    <!-- Test Results (when completed) -->
                    <div v-if="testProgress.status === 'completed' && testProgress.results" class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                        <div class="flex items-center space-x-3 mb-3">
                            <div class="flex-shrink-0">
                                <svg class="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-emerald-900">Transcription Test Completed Successfully</h4>
                                <p class="text-sm text-emerald-700">Transcription finished in {{ Math.round((new Date(testProgress.endTime) - new Date(testProgress.startTime)) / 1000) }}s</p>
                            </div>
                        </div>
                        
                        <!-- Quick results preview -->
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-emerald-700 font-medium">Words Transcribed:</span>
                                <span class="text-emerald-600 ml-2">{{ testProgress.results[0]?.result?.word_count || 'N/A' }}</span>
                            </div>
                            <div>
                                <span class="text-emerald-700 font-medium">Confidence Score:</span>
                                <span class="text-emerald-600 ml-2">{{ testProgress.results[0]?.result?.confidence_score || 'N/A' }}/100</span>
                            </div>
                        </div>
                    </div>

                    <!-- Test Failed -->
                    <div v-if="testProgress.status === 'failed'" class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-red-900">Transcription Test Failed</h4>
                                <p class="text-sm text-red-700">{{ testProgress.message }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Configuration Section (when not running) -->
                    <div v-if="!isTestRunning" class="space-y-6">
                        <!-- Segment Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                Select Test Segment (WAV files only)
                            </label>
                            
                            <!-- Loading State -->
                            <div v-if="isLoadingSegments" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex items-center space-x-3">
                                    <svg class="animate-spin w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <div>
                                        <h4 class="text-sm font-medium text-blue-900">Loading Available Segments</h4>
                                        <p class="text-sm text-blue-700">Checking for segments with WAV files...</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Error State -->
                            <div v-else-if="segmentsError" class="bg-red-50 border border-red-200 rounded-lg p-4">
                                <div class="flex items-center space-x-3">
                                    <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                    </svg>
                                    <div>
                                        <h4 class="text-sm font-medium text-red-900">Error Loading Segments</h4>
                                        <p class="text-sm text-red-700">{{ segmentsError }}</p>
                                        <button
                                            @click="fetchAvailableSegments"
                                            class="mt-2 text-xs text-red-600 hover:text-red-800 font-medium underline"
                                        >
                                            Retry
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- No Segments Available -->
                            <div v-else-if="availableSegments.length === 0" class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                                <div class="flex items-center space-x-3">
                                    <svg class="w-5 h-5 text-amber-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                    <div>
                                        <h4 class="text-sm font-medium text-amber-900">No WAV Files Available for Transcription Testing</h4>
                                        <p class="text-sm text-amber-700">
                                            To run transcription tests, segments need WAV files extracted from video files.
                                            <br>
                                            <span class="font-medium">Next steps:</span> Extract audio from video files to create WAV files for transcription testing.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Available Segments List -->
                            <div v-else class="grid grid-cols-1 gap-2 max-h-40 overflow-y-auto">
                                <div
                                    v-for="segment in availableSegments"
                                    :key="segment.id"
                                    class="flex items-center space-x-3 p-3 border rounded-lg hover:bg-gray-50 cursor-pointer transition-colors duration-200"
                                    :class="selectedSegmentId === segment.id ? 'border-teal-500 bg-teal-50' : 'border-gray-200'"
                                    @click="selectedSegmentId = segment.id"
                                >
                                    <input
                                        :id="`segment-${segment.id}`"
                                        v-model="selectedSegmentId"
                                        :value="segment.id"
                                        type="radio"
                                        name="test-segment"
                                        class="text-teal-600 focus:ring-teal-500"
                                    />
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-gray-900">
                                            Segment {{ segment.id }}
                                        </div>
                                        <div class="text-xs text-gray-500 truncate">
                                            {{ segment.title || 'No title available' }}
                                        </div>
                                        <div class="text-xs text-gray-400 truncate">
                                            {{ segment.channel_name || `Channel #${segment.channel_id}` }}
                                        </div>
                                        <div class="text-xs mt-1 flex items-center justify-between">
                                            <div class="flex items-center text-teal-600">
                                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span>WAV File Available</span>
                                            </div>
                                            <div v-if="segment.file_size" class="text-gray-500 text-xs">
                                                {{ formatFileSize(segment.file_size) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div v-if="availableSegments.length > 0" class="mt-2 flex justify-between items-center">
                                <button
                                    @click="fetchAvailableSegments"
                                    class="text-xs text-gray-600 hover:text-gray-800 font-medium"
                                >
                                    Refresh List
                                </button>
                                <button
                                    @click="selectRandomSegment"
                                    class="text-xs text-teal-600 hover:text-teal-800 font-medium"
                                >
                                    Select Random Segment
                                </button>
                            </div>
                        </div>

                        <!-- Preset Selection Mode Toggle -->
                        <div class="mb-4">
                            <div class="flex items-center space-x-4">
                                <label class="flex items-center">
                                    <input
                                        v-model="useMultiPreset"
                                        :value="false"
                                        type="radio"
                                        name="preset-mode"
                                        class="text-teal-600 focus:ring-teal-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Single Preset</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        v-model="useMultiPreset"
                                        :value="true"
                                        type="radio"
                                        name="preset-mode"
                                        class="text-teal-600 focus:ring-teal-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Multi-Preset Comparison</span>
                                </label>
                            </div>
                        </div>

                        <!-- Preset Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                {{ useMultiPreset ? 'Select Transcription Presets' : 'Select Transcription Preset' }}
                            </label>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div
                                    v-for="preset in enhancedPresetOptions"
                                    :key="preset.value"
                                    class="relative"
                                >
                                    <label
                                        :for="`preset-${preset.value}`"
                                        class="flex items-start p-4 border rounded-lg cursor-pointer hover:bg-gray-50 transition-colors duration-200"
                                        :class="[
                                            useMultiPreset 
                                                ? (selectedPresets.includes(preset.value) ? 'border-teal-500 bg-teal-50' : 'border-gray-200')
                                                : (selectedPreset === preset.value ? 'border-teal-500 bg-teal-50' : 'border-gray-200')
                                        ]"
                                    >
                                        <input
                                            :id="`preset-${preset.value}`"
                                            v-if="useMultiPreset"
                                            v-model="selectedPresets"
                                            :value="preset.value"
                                            type="checkbox"
                                            class="mt-1 text-teal-600 focus:ring-teal-500"
                                        />
                                        <input
                                            :id="`preset-${preset.value}`"
                                            v-else
                                            v-model="selectedPreset"
                                            :value="preset.value"
                                            type="radio"
                                            name="transcription-preset"
                                            class="mt-1 text-teal-600 focus:ring-teal-500"
                                        />
                                        <div class="ml-3 flex-1">
                                            <div class="flex items-center justify-between">
                                                <div class="text-sm font-medium text-gray-900">
                                                    {{ preset.name || preset.label }}
                                                </div>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="getPresetColor(preset.value)">
                                                    {{ preset.whisper_model || preset.model || 'whisper-1' }}
                                                </span>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                {{ preset.description }}
                                            </div>
                                            <div class="text-xs text-gray-400 mt-2 flex items-center justify-between">
                                                <span>Est. {{ preset.estimated_processing_time || (preset.estimatedTime + 's') }}</span>
                                                <span>{{ preset.expected_accuracy || 'Unknown accuracy' }}</span>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Whisper Prompt Display -->
                            <div v-if="!useMultiPreset && selectedPresetConfig?.initial_prompt" class="mt-4">
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <div class="flex items-start space-x-3">
                                        <div class="flex-shrink-0">
                                            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                        <div class="flex-1">
                                            <div class="flex items-center justify-between mb-2">
                                                <h4 class="text-sm font-medium text-blue-900">
                                                    Whisper AI Prompt Template - {{ selectedPresetConfig.name || selectedPresetConfig.label }} Preset
                                                </h4>
                                                <div class="flex space-x-2">
                                                    <button
                                                        @click="toggleTemplatePreview"
                                                        class="text-xs text-blue-600 hover:text-blue-800 font-medium underline"
                                                    >
                                                        {{ showTemplatePreview ? 'Hide Preview' : 'Show Preview' }}
                                                    </button>
                                                    <button
                                                        @click="toggleVariablesList"
                                                        class="text-xs text-blue-600 hover:text-blue-800 font-medium underline"
                                                    >
                                                        {{ showVariablesList ? 'Hide Variables' : 'Show Variables' }}
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <!-- Template (with mustache syntax) -->
                                            <div class="bg-white border border-blue-100 rounded-md p-3 mb-3">
                                                <div class="text-xs text-blue-800 font-medium mb-1">Template (with variables)</div>
                                                <p class="text-sm text-gray-700 font-mono leading-relaxed">
                                                    "{{ selectedPresetConfig.initial_prompt }}"
                                                </p>
                                            </div>
                                            
                                            <!-- Template Preview (rendered) -->
                                            <div v-if="showTemplatePreview" class="bg-green-50 border border-green-100 rounded-md p-3 mb-3">
                                                <div class="text-xs text-green-800 font-medium mb-1">Preview (with sample data)</div>
                                                <p class="text-sm text-gray-700 leading-relaxed">
                                                    "{{ templatePreview || 'Loading preview...' }}"
                                                </p>
                                            </div>
                                            
                                            <div class="mt-2 text-xs text-blue-700">
                                                <div class="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <span class="font-medium">Temperature:</span> {{ selectedPresetConfig.temperature }}
                                                    </div>
                                                    <div>
                                                        <span class="font-medium">Word Timestamps:</span> {{ selectedPresetConfig.word_timestamps ? 'Yes' : 'No' }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Template Variables Display -->
                            <div v-if="showVariablesList && Object.keys(templateVariables).length > 0" class="mt-4">
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-start space-x-3">
                                        <div class="flex-shrink-0">
                                            <svg class="w-5 h-5 text-gray-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a1.994 1.994 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                            </svg>
                                        </div>
                                        <div class="flex-1">
                                            <h4 class="text-sm font-medium text-gray-900 mb-3">Available Template Variables</h4>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-60 overflow-y-auto">
                                                <div 
                                                    v-for="(variable, key) in templateVariables" 
                                                    :key="key"
                                                    class="bg-white border border-gray-100 rounded-md p-2"
                                                >
                                                    <div class="flex items-center justify-between mb-1">
                                                        <span class="text-xs font-medium text-gray-800">{{key}}</span>
                                                        <span 
                                                            class="text-xs px-1.5 py-0.5 rounded-full"
                                                            :class="getCategoryColor(variable.category)"
                                                        >
                                                            {{ variable.category }}
                                                        </span>
                                                    </div>
                                                    <div class="text-xs text-gray-600 mb-1">{{ variable.description }}</div>
                                                    <div class="text-xs text-gray-500 font-mono">
                                                        Example: "{{ variable.example }}"
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-3 text-xs text-gray-500">
                                                Variables are automatically populated from course and segment data when available.
                                                Use mustache syntax: <code class="bg-gray-100 px-1 rounded" v-text="mustacheExample"></code>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Multi-Preset Whisper Prompts Display -->
                            <div v-else-if="useMultiPreset && Array.isArray(selectedPresetConfig) && selectedPresetConfig.length > 0" class="mt-4">
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <div class="flex items-start space-x-3 mb-3">
                                        <div class="flex-shrink-0">
                                            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                        <div class="flex-1">
                                            <h4 class="text-sm font-medium text-blue-900 mb-3">
                                                Whisper AI Prompts for Selected Presets
                                            </h4>
                                            <div class="space-y-3">
                                                <div 
                                                    v-for="config in selectedPresetConfig.filter(c => c.initial_prompt)" 
                                                    :key="config.value"
                                                    class="bg-white border border-blue-100 rounded-md p-3"
                                                >
                                                    <div class="flex items-center justify-between mb-2">
                                                        <span class="text-xs font-medium text-blue-800 uppercase tracking-wide">
                                                            {{ config.name || config.label }} ({{ config.whisper_model || config.model }})
                                                        </span>
                                                        <span class="text-xs text-blue-600">
                                                            Temp: {{ config.temperature }}
                                                        </span>
                                                    </div>
                                                    <p class="text-sm text-gray-700 font-mono leading-relaxed">
                                                        "{{ config.initial_prompt }}"
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Loading presets -->
                            <div v-if="isLoadingPresets" class="mt-4 bg-gray-50 border border-gray-200 rounded-lg p-3">
                                <div class="flex items-center space-x-2 text-sm text-gray-600">
                                    <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Loading preset configurations with Whisper prompts...</span>
                                </div>
                            </div>
                            
                            <!-- Preset loading error -->
                            <div v-if="presetsError" class="mt-4 bg-red-50 border border-red-200 rounded-lg p-3">
                                <div class="flex items-center space-x-2 text-sm text-red-700">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                    </svg>
                                    <span>{{ presetsError }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Estimated Duration -->
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">
                                    {{ useMultiPreset ? 'Total Estimated Duration:' : 'Estimated Duration:' }}
                                </span>
                                <span class="font-medium text-gray-900">
                                    ~{{ Math.floor(estimatedDuration / 60) }}m {{ estimatedDuration % 60 }}s
                                </span>
                            </div>
                            <div v-if="useMultiPreset && selectedPresets.length > 1" class="text-xs text-gray-500 mt-1">
                                {{ selectedPresets.length }} presets will be tested sequentially
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="bg-gray-50 px-6 py-4 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        <span v-if="testProgress.status === 'completed'">
                            Test completed successfully
                        </span>
                        <span v-else-if="testProgress.status === 'failed'">
                            Test failed - ready to retry
                        </span>
                        <span v-else-if="isTestRunning">
                            Test in progress...
                        </span>
                        <span v-else>
                            Ready to start transcription test
                        </span>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <SecondaryButton
                            @click="closePanel"
                            :disabled="isTestRunning"
                        >
                            {{ isTestRunning ? 'Test Running...' : 'Close' }}
                        </SecondaryButton>
                        
                        <PrimaryButton
                            v-if="testProgress.status === 'failed' || testProgress.status === 'idle'"
                            @click="startTest"
                            :disabled="!canStartTest"
                        >
                            {{ testProgress.status === 'failed' ? 'Retry Test' : 'Start Test' }}
                        </PrimaryButton>
                        
                        <PrimaryButton
                            v-if="testProgress.status === 'completed'"
                            @click="resetTest"
                        >
                            Run New Test
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
