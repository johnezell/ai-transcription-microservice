<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import QualityLevelSelector from './QualityLevelSelector.vue';
import PrimaryButton from './PrimaryButton.vue';
import SecondaryButton from './SecondaryButton.vue';

const props = defineProps({
    courseId: {
        type: [String, Number],
        required: true
    },
    segments: {
        type: Array,
        default: () => []
    },
    show: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['close', 'test-started', 'test-completed', 'test-failed']);

// Test configuration state
const selectedQuality = ref('balanced');
const selectedQualities = ref(['balanced']); // Multi-quality selection
const useMultiQuality = ref(false); // Toggle between single and multi-quality
const enableQualityAnalysis = ref(false); // Enable WAV quality analysis
const selectedSegmentId = ref(null);
const testConfiguration = ref({
    sampleRate: 44100,
    bitRate: 192,
    channels: 2,
    format: 'mp3'
});

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
    currentQuality: null,
    totalTests: 0,
    completedTests: 0
});

// Available segments for testing
const availableSegments = computed(() => {
    return props.segments.slice(0, 10); // Limit to first 10 for testing
});

// Test configuration options
const sampleRateOptions = [22050, 44100, 48000];
const bitRateOptions = [128, 192, 256, 320];
const channelOptions = [1, 2];
const formatOptions = ['mp3', 'wav', 'flac'];

// Computed properties
const canStartTest = computed(() => {
    const hasSegment = selectedSegmentId.value;
    const hasQuality = useMultiQuality.value
        ? selectedQualities.value.length > 0
        : selectedQuality.value;
    return hasSegment && hasQuality && !isTestRunning.value;
});

const estimatedDuration = computed(() => {
    const baseTime = {
        fast: 30,
        balanced: 60,
        high: 120,
        premium: 300
    };
    
    if (useMultiQuality.value) {
        return selectedQualities.value.reduce((total, quality) => {
            return total + (baseTime[quality] || 60);
        }, 0);
    }
    
    return baseTime[selectedQuality.value] || 60;
});

const qualitiesForTesting = computed(() => {
    return useMultiQuality.value ? selectedQualities.value : [selectedQuality.value];
});

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
    
    const totalTests = qualitiesForTesting.value.length;
    
    testProgress.value = {
        status: 'running',
        progress: 0,
        message: 'Initializing audio extraction test...',
        startTime: new Date(),
        endTime: null,
        results: null,
        currentQuality: null,
        totalTests: totalTests,
        completedTests: 0
    };

    emit('test-started', {
        segmentId: selectedSegmentId.value,
        qualities: qualitiesForTesting.value,
        configuration: testConfiguration.value,
        isMultiQuality: useMultiQuality.value,
        enableQualityAnalysis: enableQualityAnalysis.value
    });

    try {
        // Dispatch all jobs at once to the Laravel Queue
        const requestPayload = {
            is_multi_quality: useMultiQuality.value,
            test_configuration: testConfiguration.value,
            enable_quality_analysis: enableQualityAnalysis.value
        };

        if (useMultiQuality.value) {
            requestPayload.quality_levels = qualitiesForTesting.value;
        } else {
            requestPayload.quality_level = qualitiesForTesting.value[0];
        }

        const response = await axios.post(
            `/truefire-courses/${props.courseId}/test-audio-extraction/${selectedSegmentId.value}`,
            requestPayload
        );

        // All jobs are now dispatched to the queue
        const dispatchedJobs = response.data.jobs || [{
            job_id: response.data.job_id,
            quality_level: qualitiesForTesting.value[0],
            index: 1
        }];

        testProgress.value.message = `${dispatchedJobs.length} test job(s) dispatched to queue. Monitoring progress...`;

        // Monitor progress for all dispatched jobs
        await monitorMultiQualityProgress(dispatchedJobs);

    } catch (error) {
        console.error('Failed to start audio extraction test:', error);
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

const monitorMultiQualityProgress = async (dispatchedJobs) => {
    const totalJobs = dispatchedJobs.length;
    const completedJobs = new Set();
    let allJobsCompleted = false;

    const maxPollingTime = 15 * 60 * 1000; // 15 minutes total
    const pollInterval = 3000; // 3 seconds
    const startTime = Date.now();

    return new Promise((resolve, reject) => {
        const poll = async () => {
            try {
                // Poll for each job's status
                for (const job of dispatchedJobs) {
                    if (completedJobs.has(job.job_id)) {
                        continue; // Skip already completed jobs
                    }

                    try {
                        const response = await axios.get(
                            `/truefire-courses/${props.courseId}/audio-test-results/${selectedSegmentId.value}`,
                            {
                                params: {
                                    quality_level: job.quality_level,
                                    test_id: job.job_id
                                }
                            }
                        );

                        const result = response.data;
                        
                        if (result.status === 'completed') {
                            completedJobs.add(job.job_id);
                            
                            testResults.value.push({
                                quality: job.quality_level,
                                result: result,
                                testIndex: job.index - 1
                            });
                            
                            testProgress.value.completedTests = completedJobs.size;
                            
                            console.log(`Completed ${job.quality_level} quality test`);
                        } else if (result.status === 'failed') {
                            testProgress.value.status = 'failed';
                            testProgress.value.message = `Test failed for ${job.quality_level} quality: ${result.error_message || 'Unknown error'}`;
                            reject(new Error(`Test failed for ${job.quality_level} quality`));
                            return;
                        }
                        
                    } catch (jobError) {
                        // Handle 404 and other errors for individual job polling
                        if (jobError.response?.status === 404) {
                            // 404 is expected when test results don't exist yet - continue polling
                            console.log(`Test results not ready yet for ${job.quality_level} (${job.job_id})`);
                        } else {
                            // Log other errors but continue polling
                            console.warn(`Error polling for ${job.quality_level} quality:`, jobError.message);
                        }
                    }
                }

                // Update overall progress
                const overallProgress = (completedJobs.size / totalJobs) * 100;
                testProgress.value.progress = Math.round(overallProgress);
                
                // Update message with current status
                if (completedJobs.size === 0) {
                    testProgress.value.message = `Waiting for queue processing... (${totalJobs} jobs dispatched)`;
                } else if (completedJobs.size < totalJobs) {
                    const remaining = totalJobs - completedJobs.size;
                    testProgress.value.message = `Processing: ${completedJobs.size}/${totalJobs} completed, ${remaining} remaining...`;
                }

                // Check if all jobs are completed
                if (completedJobs.size === totalJobs) {
                    testProgress.value = {
                        ...testProgress.value,
                        status: 'completed',
                        progress: 100,
                        message: `All tests completed successfully! (${testResults.value.length}/${totalJobs})`,
                        endTime: new Date(),
                        results: testResults.value
                    };
                    
                    // Stop the test running state
                    isTestRunning.value = false;
                    
                    console.log('All tests completed, stopping polling and enabling modal close');
                    emit('test-completed', testResults.value);
                    resolve(testResults.value);
                    return;
                }

                // Continue polling if not complete and within time limit
                if (Date.now() - startTime < maxPollingTime) {
                    setTimeout(poll, pollInterval);
                } else {
                    // Timeout
                    reject(new Error(`Test timeout - only ${completedJobs.size}/${totalJobs} jobs completed`));
                }

            } catch (error) {
                console.error('Error polling multi-quality test progress:', error);
                
                // If we're still within the time limit, continue polling with backoff
                if (Date.now() - startTime < maxPollingTime) {
                    const backoffInterval = Math.min(pollInterval * 2, 10000);
                    console.log(`Retrying polling in ${backoffInterval}ms due to error:`, error.message);
                    setTimeout(poll, backoffInterval);
                } else {
                    // If we've exceeded the time limit, reject with timeout
                    reject(new Error(`Polling timeout after ${maxPollingTime/1000}s - last error: ${error.message}`));
                }
            }
        };

        // Start polling
        setTimeout(poll, pollInterval);
    });
};

const runSingleTest = async (quality, testIndex) => {
    // This function is now deprecated but kept for backward compatibility
    // The new approach uses monitorMultiQualityProgress instead
    try {
        const result = await pollTestProgress(null, quality, testIndex);
        
        if (result) {
            testResults.value.push({
                quality: quality,
                result: result,
                testIndex: testIndex
            });
            
            testProgress.value.completedTests++;
            
            // Update overall progress
            const overallProgress = ((testIndex + 1) / qualitiesForTesting.value.length) * 100;
            testProgress.value.progress = Math.round(overallProgress);
        }
        
    } catch (error) {
        console.error(`Failed to run test for ${quality} quality:`, error);
        testProgress.value.status = 'failed';
        testProgress.value.message = `Test failed for ${quality} quality: ${error.message}`;
        throw error;
    }
};

const pollTestProgress = async (testId, quality, testIndex) => {
    const baseTime = {
        fast: 30,
        balanced: 60,
        high: 120,
        premium: 300
    };
    
    const maxPollingTime = (baseTime[quality] || 60) * 2 * 1000; // 2x estimated duration for this quality
    const pollInterval = 2000; // 2 seconds
    const startTime = Date.now();

    return new Promise((resolve, reject) => {
        const poll = async () => {
            try {
                const response = await axios.get(
                    `/truefire-courses/${props.courseId}/audio-test-results/${selectedSegmentId.value}`,
                    {
                        params: {
                            quality_level: quality,
                            test_id: testId
                        }
                    }
                );

                const result = response.data;
                
                // Update progress for current test
                const testProgress = result.progress_percentage || 0;
                const baseProgress = (testIndex / qualitiesForTesting.value.length) * 100;
                const currentTestProgress = (testProgress / 100) * (100 / qualitiesForTesting.value.length);
                const overallProgress = baseProgress + currentTestProgress;
                
                testProgress.value.progress = Math.round(overallProgress);
                testProgress.value.message = `Testing ${quality} quality: ${result.status_message || 'Processing...'}`;

                if (result.status === 'completed') {
                    resolve(result);
                    return;
                }

                if (result.status === 'failed') {
                    reject(new Error(result.error_message || 'Test failed'));
                    return;
                }

                // Continue polling if not complete and within time limit
                if (Date.now() - startTime < maxPollingTime) {
                    setTimeout(poll, pollInterval);
                } else {
                    // Timeout for this specific test
                    reject(new Error(`Test timeout for ${quality} quality - processing took too long`));
                }

            } catch (error) {
                console.error('Error polling test progress:', error);
                // Continue polling despite errors, but with exponential backoff
                setTimeout(poll, Math.min(pollInterval * 2, 10000));
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
        currentQuality: null,
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

// Auto-select first segment on mount
if (availableSegments.value.length > 0 && !selectedSegmentId.value) {
    selectedSegmentId.value = availableSegments.value[0].id;
}
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        aria-labelledby="audio-test-panel"
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
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-white">
                                    Audio Extraction Testing
                                </h3>
                                <p class="text-sm text-indigo-100">
                                    Test audio extraction quality and performance
                                </p>
                            </div>
                        </div>
                        <button
                            @click="closePanel"
                            :disabled="isTestRunning"
                            class="text-indigo-100 hover:text-white transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
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
                    <div v-if="isTestRunning" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex items-center space-x-3 mb-3">
                            <div class="flex-shrink-0">
                                <svg class="animate-spin w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-blue-900">Test in Progress</h4>
                                <p class="text-sm text-blue-700">{{ testProgress.message }}</p>
                            </div>
                        </div>
                        
                        <!-- Progress bar -->
                        <div class="w-full bg-blue-200 rounded-full h-2">
                            <div
                                class="bg-blue-600 h-2 rounded-full transition-all duration-300"
                                :style="{ width: testProgress.progress + '%' }"
                            ></div>
                        </div>
                        
                        <div class="flex justify-between text-xs text-blue-600 mt-2">
                            <span>{{ testProgress.progress }}% complete</span>
                            <span v-if="testProgress.totalTests > 1">
                                {{ testProgress.completedTests }}/{{ testProgress.totalTests }} tests
                            </span>
                            <span v-else-if="testProgress.startTime">
                                Started {{ new Date(testProgress.startTime).toLocaleTimeString() }}
                            </span>
                        </div>
                        
                        <!-- Multi-quality progress details -->
                        <div v-if="testProgress.totalTests > 1" class="mt-3 text-xs text-blue-700">
                            <div class="flex items-center justify-between">
                                <span>Current: {{ testProgress.currentQuality || 'Initializing...' }}</span>
                                <span>{{ testProgress.completedTests }} completed</span>
                            </div>
                        </div>
                    </div>

                    <!-- Test Results (when completed) -->
                    <div v-if="testProgress.status === 'completed' && testProgress.results" class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="flex items-center space-x-3 mb-3">
                            <div class="flex-shrink-0">
                                <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-green-900">Test Completed Successfully</h4>
                                <p class="text-sm text-green-700">Audio extraction finished in {{ Math.round((new Date(testProgress.endTime) - new Date(testProgress.startTime)) / 1000) }}s</p>
                            </div>
                        </div>
                        
                        <!-- Quick results preview -->
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-green-700 font-medium">File Size:</span>
                                <span class="text-green-600 ml-2">{{ testProgress.results.file_size_mb }}MB</span>
                            </div>
                            <div>
                                <span class="text-green-700 font-medium">Quality Score:</span>
                                <span class="text-green-600 ml-2">{{ testProgress.results.quality_score }}/100</span>
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
                                <h4 class="text-sm font-medium text-red-900">Test Failed</h4>
                                <p class="text-sm text-red-700">{{ testProgress.message }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Configuration Section (when not running) -->
                    <div v-if="!isTestRunning" class="space-y-6">
                        <!-- Segment Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                Select Test Segment
                            </label>
                            <div class="grid grid-cols-1 gap-2 max-h-40 overflow-y-auto">
                                <div
                                    v-for="segment in availableSegments"
                                    :key="segment.id"
                                    class="flex items-center space-x-3 p-3 border rounded-lg hover:bg-gray-50 cursor-pointer transition-colors duration-200"
                                    :class="selectedSegmentId === segment.id ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200'"
                                    @click="selectedSegmentId = segment.id"
                                >
                                    <input
                                        :id="`segment-${segment.id}`"
                                        v-model="selectedSegmentId"
                                        :value="segment.id"
                                        type="radio"
                                        name="test-segment"
                                        class="text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-gray-900">
                                            Segment {{ segment.id }}
                                        </div>
                                        <div class="text-xs text-gray-500 truncate">
                                            {{ segment.title || 'No title available' }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-2 flex justify-end">
                                <button
                                    @click="selectRandomSegment"
                                    class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                                >
                                    Select Random Segment
                                </button>
                            </div>
                        </div>

                        <!-- Quality Level Selection Mode Toggle -->
                        <div class="mb-4">
                            <div class="flex items-center space-x-4">
                                <label class="flex items-center">
                                    <input
                                        v-model="useMultiQuality"
                                        :value="false"
                                        type="radio"
                                        name="quality-mode"
                                        class="text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Single Quality</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        v-model="useMultiQuality"
                                        :value="true"
                                        type="radio"
                                        name="quality-mode"
                                        class="text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Multi-Quality Comparison</span>
                                </label>
                            </div>
                        </div>

                        <!-- Quality Level Selection -->
                        <QualityLevelSelector
                            v-if="!useMultiQuality"
                            v-model="selectedQuality"
                        />
                        <QualityLevelSelector
                            v-else
                            v-model="selectedQualities"
                            :multiple="true"
                        />

                        <!-- WAV Quality Analysis Option -->
                        <div class="border border-amber-200 bg-amber-50 rounded-lg p-4">
                            <div class="flex items-start space-x-3">
                                <div class="flex items-center h-5">
                                    <input
                                        id="enable-quality-analysis"
                                        v-model="enableQualityAnalysis"
                                        type="checkbox"
                                        class="h-4 w-4 text-amber-600 focus:ring-amber-500 border-amber-300 rounded"
                                    />
                                </div>
                                <div class="flex-1">
                                    <label for="enable-quality-analysis" class="text-sm font-medium text-amber-900">
                                        Enable WAV Quality Analysis
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 ml-2">
                                            New Feature
                                        </span>
                                    </label>
                                    <p class="text-xs text-amber-700 mt-1">
                                        Generate multiple quality levels (Fast, Balanced, High, Premium) and automatically select the best WAV file for Whisper transcription.
                                        Creates analysis file with detailed quality metrics and selection reasoning.
                                    </p>
                                    <div class="mt-2 text-xs text-amber-600">
                                        <div class="flex items-center space-x-4">
                                            <span>• 5-metric quality scoring</span>
                                            <span>• Whisper-optimized selection</span>
                                            <span>• Detailed analysis reports</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Advanced Configuration -->
                        <div class="border-t pt-6">
                            <h4 class="text-sm font-medium text-gray-900 mb-4">Advanced Configuration</h4>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Sample Rate (Hz)
                                    </label>
                                    <select
                                        v-model="testConfiguration.sampleRate"
                                        class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option v-for="rate in sampleRateOptions" :key="rate" :value="rate">
                                            {{ rate.toLocaleString() }} Hz
                                        </option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Bit Rate (kbps)
                                    </label>
                                    <select
                                        v-model="testConfiguration.bitRate"
                                        class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option v-for="rate in bitRateOptions" :key="rate" :value="rate">
                                            {{ rate }} kbps
                                        </option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Channels
                                    </label>
                                    <select
                                        v-model="testConfiguration.channels"
                                        class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option v-for="channel in channelOptions" :key="channel" :value="channel">
                                            {{ channel === 1 ? 'Mono' : 'Stereo' }} ({{ channel }})
                                        </option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Output Format
                                    </label>
                                    <select
                                        v-model="testConfiguration.format"
                                        class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option v-for="format in formatOptions" :key="format" :value="format">
                                            {{ format.toUpperCase() }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Estimated Duration -->
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">
                                    {{ useMultiQuality ? 'Total Estimated Duration:' : 'Estimated Duration:' }}
                                </span>
                                <span class="font-medium text-gray-900">
                                    ~{{ Math.floor(estimatedDuration / 60) }}m {{ estimatedDuration % 60 }}s
                                </span>
                            </div>
                            <div v-if="useMultiQuality && selectedQualities.length > 1" class="text-xs text-gray-500 mt-1">
                                {{ selectedQualities.length }} quality levels will be tested sequentially
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
                            Ready to start audio extraction test
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