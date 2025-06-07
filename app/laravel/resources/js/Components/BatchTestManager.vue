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

const emit = defineEmits(['close', 'batch-started', 'batch-completed', 'batch-failed']);

// Batch configuration state
const selectedQuality = ref('balanced');
const batchConfiguration = ref({
    sampleRate: 44100,
    bitRate: 192,
    channels: 2,
    format: 'mp3',
    maxConcurrent: 3,
    retryAttempts: 2,
    skipExisting: true
});

// Segment selection
const selectedSegments = ref(new Set());
const selectAll = ref(false);

// Batch execution state
const isBatchRunning = ref(false);
const batchProgress = ref({
    status: 'idle', // idle, running, completed, failed, paused
    totalSegments: 0,
    completedSegments: 0,
    failedSegments: 0,
    skippedSegments: 0,
    currentlyProcessing: [],
    startTime: null,
    endTime: null,
    results: [],
    errors: []
});

// Computed properties
const availableSegments = computed(() => {
    return props.segments.filter(segment => segment.id); // Only segments with valid IDs
});

const allSegmentsSelected = computed(() => {
    return availableSegments.value.length > 0 && 
           availableSegments.value.every(segment => selectedSegments.value.has(segment.id));
});

const hasSelectedSegments = computed(() => selectedSegments.value.size > 0);

const canStartBatch = computed(() => {
    return hasSelectedSegments.value && !isBatchRunning.value;
});

const estimatedTotalDuration = computed(() => {
    const baseTimePerSegment = {
        fast: 30,
        balanced: 60,
        high: 120,
        premium: 300
    };
    
    const timePerSegment = baseTimePerSegment[selectedQuality.value] || 60;
    const totalTime = selectedSegments.value.size * timePerSegment;
    const concurrentFactor = Math.min(batchConfiguration.value.maxConcurrent, selectedSegments.value.size);
    
    return Math.ceil(totalTime / concurrentFactor);
});

const progressPercentage = computed(() => {
    if (batchProgress.value.totalSegments === 0) return 0;
    return Math.round((batchProgress.value.completedSegments / batchProgress.value.totalSegments) * 100);
});

// Methods
const toggleSegmentSelection = (segmentId) => {
    if (selectedSegments.value.has(segmentId)) {
        selectedSegments.value.delete(segmentId);
    } else {
        selectedSegments.value.add(segmentId);
    }
};

const toggleSelectAll = () => {
    if (allSegmentsSelected.value) {
        selectedSegments.value.clear();
        selectAll.value = false;
    } else {
        availableSegments.value.forEach(segment => {
            selectedSegments.value.add(segment.id);
        });
        selectAll.value = true;
    }
};

const selectByQuality = (minQualityScore = 70) => {
    selectedSegments.value.clear();
    availableSegments.value.forEach(segment => {
        // This would be based on previous test results if available
        // For now, select all segments as we don't have quality data
        selectedSegments.value.add(segment.id);
    });
};

const selectRandom = (count = 5) => {
    selectedSegments.value.clear();
    const shuffled = [...availableSegments.value].sort(() => 0.5 - Math.random());
    const selected = shuffled.slice(0, Math.min(count, availableSegments.value.length));
    selected.forEach(segment => {
        selectedSegments.value.add(segment.id);
    });
};

const startBatchTest = async () => {
    if (!canStartBatch.value) return;

    isBatchRunning.value = true;
    batchProgress.value = {
        status: 'running',
        totalSegments: selectedSegments.value.size,
        completedSegments: 0,
        failedSegments: 0,
        skippedSegments: 0,
        currentlyProcessing: [],
        startTime: new Date(),
        endTime: null,
        results: [],
        errors: []
    };

    emit('batch-started', {
        courseId: props.courseId,
        segmentIds: Array.from(selectedSegments.value),
        quality: selectedQuality.value,
        configuration: batchConfiguration.value
    });

    try {
        // Start the batch test
        const response = await axios.post(
            `/truefire-courses/${props.courseId}/batch-test-audio-extraction`,
            {
                segment_ids: Array.from(selectedSegments.value),
                quality_level: selectedQuality.value,
                batch_configuration: batchConfiguration.value
            }
        );

        // Poll for progress
        await pollBatchProgress(response.data.batch_id);

    } catch (error) {
        console.error('Failed to start batch audio extraction test:', error);
        batchProgress.value = {
            ...batchProgress.value,
            status: 'failed',
            endTime: new Date()
        };
        batchProgress.value.errors.push({
            type: 'batch_start_error',
            message: error.response?.data?.message || error.message
        });
        isBatchRunning.value = false;
        emit('batch-failed', error);
    }
};

const pollBatchProgress = async (batchId) => {
    const maxPollingTime = estimatedTotalDuration.value * 2 * 1000; // 2x estimated duration
    const pollInterval = 3000; // 3 seconds
    const startTime = Date.now();

    const poll = async () => {
        try {
            const response = await axios.get(
                `/truefire-courses/${props.courseId}/batch-test-progress/${batchId}`
            );

            const result = response.data;
            
            // Update progress
            batchProgress.value = {
                ...batchProgress.value,
                completedSegments: result.completed_count || 0,
                failedSegments: result.failed_count || 0,
                skippedSegments: result.skipped_count || 0,
                currentlyProcessing: result.currently_processing || [],
                results: result.results || [],
                errors: result.errors || []
            };

            if (result.status === 'completed') {
                batchProgress.value = {
                    ...batchProgress.value,
                    status: 'completed',
                    endTime: new Date()
                };
                isBatchRunning.value = false;
                emit('batch-completed', result);
                return;
            }

            if (result.status === 'failed') {
                batchProgress.value = {
                    ...batchProgress.value,
                    status: 'failed',
                    endTime: new Date()
                };
                isBatchRunning.value = false;
                emit('batch-failed', result);
                return;
            }

            // Continue polling if not complete and within time limit
            if (Date.now() - startTime < maxPollingTime) {
                setTimeout(poll, pollInterval);
            } else {
                // Timeout
                batchProgress.value = {
                    ...batchProgress.value,
                    status: 'failed',
                    endTime: new Date()
                };
                batchProgress.value.errors.push({
                    type: 'timeout',
                    message: 'Batch test timeout - processing took too long'
                });
                isBatchRunning.value = false;
                emit('batch-failed', { error: 'timeout' });
            }

        } catch (error) {
            console.error('Error polling batch progress:', error);
            setTimeout(poll, pollInterval); // Continue polling despite errors
        }
    };

    // Start polling
    setTimeout(poll, pollInterval);
};

const pauseBatch = async () => {
    // Implementation for pausing batch processing
    // This would be a future enhancement
    console.log('Pause batch functionality - to be implemented');
};

const resumeBatch = async () => {
    // Implementation for resuming batch processing
    // This would be a future enhancement
    console.log('Resume batch functionality - to be implemented');
};

const cancelBatch = async () => {
    if (!confirm('Are you sure you want to cancel the batch test?')) {
        return;
    }
    
    try {
        await axios.post(`/truefire-courses/${props.courseId}/cancel-batch-test`);
        batchProgress.value.status = 'cancelled';
        isBatchRunning.value = false;
    } catch (error) {
        console.error('Failed to cancel batch test:', error);
    }
};

const resetBatch = () => {
    batchProgress.value = {
        status: 'idle',
        totalSegments: 0,
        completedSegments: 0,
        failedSegments: 0,
        skippedSegments: 0,
        currentlyProcessing: [],
        startTime: null,
        endTime: null,
        results: [],
        errors: []
    };
    isBatchRunning.value = false;
};

const closePanel = () => {
    if (!isBatchRunning.value) {
        resetBatch();
        emit('close');
    }
};

// Utility functions
const formatDuration = (seconds) => {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
};

const formatEstimatedTime = (seconds) => {
    if (seconds < 60) return `~${seconds}s`;
    if (seconds < 3600) return `~${Math.ceil(seconds / 60)}m`;
    return `~${Math.ceil(seconds / 3600)}h`;
};

// Watch for segment selection changes
watch(() => selectedSegments.value.size, (newSize) => {
    selectAll.value = newSize === availableSegments.value.length;
});
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        aria-labelledby="batch-test-manager"
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
                <div class="bg-gradient-to-r from-orange-500 to-red-600 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-white">
                                    Batch Audio Testing
                                </h3>
                                <p class="text-sm text-orange-100">
                                    Test multiple segments simultaneously - Phase 3 Preview
                                </p>
                            </div>
                        </div>
                        <button
                            @click="closePanel"
                            :disabled="isBatchRunning"
                            class="text-orange-100 hover:text-white transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="px-6 py-6 space-y-6">
                    <!-- Phase 3 Notice -->
                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                        <div class="flex items-center space-x-3">
                            <svg class="w-5 h-5 text-amber-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                            <div>
                                <h4 class="text-sm font-medium text-amber-900">Phase 3 Preview</h4>
                                <p class="text-sm text-amber-700">This batch testing interface is a preview for Phase 3. Backend implementation required for full functionality.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Batch Progress (when running) -->
                    <div v-if="isBatchRunning" class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h4 class="text-lg font-medium text-blue-900">Batch Test in Progress</h4>
                                <p class="text-sm text-blue-700">Processing {{ batchProgress.totalSegments }} segments...</p>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold text-blue-600">{{ progressPercentage }}%</div>
                                <div class="text-xs text-blue-500">Complete</div>
                            </div>
                        </div>
                        
                        <!-- Progress bar -->
                        <div class="w-full bg-blue-200 rounded-full h-3 mb-4">
                            <div
                                class="bg-blue-600 h-3 rounded-full transition-all duration-300"
                                :style="{ width: progressPercentage + '%' }"
                            ></div>
                        </div>
                        
                        <!-- Progress stats -->
                        <div class="grid grid-cols-4 gap-4 text-center">
                            <div class="bg-white rounded-lg p-3">
                                <div class="text-lg font-bold text-green-600">{{ batchProgress.completedSegments }}</div>
                                <div class="text-xs text-gray-600">Completed</div>
                            </div>
                            <div class="bg-white rounded-lg p-3">
                                <div class="text-lg font-bold text-blue-600">{{ batchProgress.currentlyProcessing.length }}</div>
                                <div class="text-xs text-gray-600">Processing</div>
                            </div>
                            <div class="bg-white rounded-lg p-3">
                                <div class="text-lg font-bold text-red-600">{{ batchProgress.failedSegments }}</div>
                                <div class="text-xs text-gray-600">Failed</div>
                            </div>
                            <div class="bg-white rounded-lg p-3">
                                <div class="text-lg font-bold text-gray-600">{{ batchProgress.skippedSegments }}</div>
                                <div class="text-xs text-gray-600">Skipped</div>
                            </div>
                        </div>

                        <!-- Currently processing -->
                        <div v-if="batchProgress.currentlyProcessing.length > 0" class="mt-4">
                            <h5 class="text-sm font-medium text-blue-900 mb-2">Currently Processing:</h5>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="segmentId in batchProgress.currentlyProcessing"
                                    :key="segmentId"
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"
                                >
                                    Segment {{ segmentId }}
                                </span>
                            </div>
                        </div>

                        <!-- Batch controls -->
                        <div class="flex items-center justify-center space-x-3 mt-6">
                            <button
                                @click="pauseBatch"
                                class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                disabled
                            >
                                <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                                Pause (Coming Soon)
                            </button>
                            <button
                                @click="cancelBatch"
                                class="inline-flex items-center px-3 py-2 border border-red-300 rounded-md text-sm font-medium text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                            >
                                <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                                Cancel Batch
                            </button>
                        </div>
                    </div>

                    <!-- Configuration Section (when not running) -->
                    <div v-if="!isBatchRunning" class="space-y-6">
                        <!-- Segment Selection -->
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-lg font-medium text-gray-900">Select Segments for Batch Testing</h4>
                                <div class="flex items-center space-x-3">
                                    <button
                                        @click="selectRandom(5)"
                                        class="text-sm text-indigo-600 hover:text-indigo-800"
                                    >
                                        Select Random (5)
                                    </button>
                                    <button
                                        @click="selectByQuality()"
                                        class="text-sm text-indigo-600 hover:text-indigo-800"
                                    >
                                        Select All
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Select All Checkbox -->
                            <div class="flex items-center space-x-3 mb-4 p-3 bg-gray-50 rounded-lg">
                                <input
                                    :id="'select-all-segments'"
                                    :checked="allSegmentsSelected"
                                    @change="toggleSelectAll"
                                    type="checkbox"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <label :for="'select-all-segments'" class="text-sm font-medium text-gray-700">
                                    Select All Segments ({{ availableSegments.length }} total)
                                </label>
                                <span class="text-sm text-gray-500">
                                    {{ selectedSegments.size }} selected
                                </span>
                            </div>

                            <!-- Segment List -->
                            <div class="grid grid-cols-1 gap-2 max-h-60 overflow-y-auto border border-gray-200 rounded-lg p-4">
                                <div
                                    v-for="segment in availableSegments"
                                    :key="segment.id"
                                    class="flex items-center space-x-3 p-2 hover:bg-gray-50 rounded-md"
                                >
                                    <input
                                        :id="`batch-segment-${segment.id}`"
                                        :checked="selectedSegments.has(segment.id)"
                                        @change="toggleSegmentSelection(segment.id)"
                                        type="checkbox"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
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
                        </div>

                        <!-- Quality Level Selection -->
                        <QualityLevelSelector v-model="selectedQuality" />

                        <!-- Batch Configuration -->
                        <div class="border-t pt-6">
                            <h4 class="text-sm font-medium text-gray-900 mb-4">Batch Configuration</h4>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Max Concurrent Tests
                                    </label>
                                    <select
                                        v-model="batchConfiguration.maxConcurrent"
                                        class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option :value="1">1 (Sequential)</option>
                                        <option :value="2">2 Concurrent</option>
                                        <option :value="3">3 Concurrent</option>
                                        <option :value="5">5 Concurrent</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Retry Attempts
                                    </label>
                                    <select
                                        v-model="batchConfiguration.retryAttempts"
                                        class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option :value="0">No Retries</option>
                                        <option :value="1">1 Retry</option>
                                        <option :value="2">2 Retries</option>
                                        <option :value="3">3 Retries</option>
                                    </select>
                                </div>
                                
                                <div class="col-span-2">
                                    <label class="flex items-center space-x-2">
                                        <input
                                            v-model="batchConfiguration.skipExisting"
                                            type="checkbox"
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <span class="text-sm text-gray-700">Skip segments with existing test results</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Estimated Duration -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="grid grid-cols-3 gap-4 text-center">
                                <div>
                                    <div class="text-lg font-bold text-gray-900">{{ selectedSegments.size }}</div>
                                    <div class="text-xs text-gray-600">Segments Selected</div>
                                </div>
                                <div>
                                    <div class="text-lg font-bold text-gray-900">{{ batchConfiguration.maxConcurrent }}</div>
                                    <div class="text-xs text-gray-600">Max Concurrent</div>
                                </div>
                                <div>
                                    <div class="text-lg font-bold text-gray-900">{{ formatEstimatedTime(estimatedTotalDuration) }}</div>
                                    <div class="text-xs text-gray-600">Estimated Duration</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="bg-gray-50 px-6 py-4 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        <span v-if="isBatchRunning">
                            Batch test in progress: {{ batchProgress.completedSegments }}/{{ batchProgress.totalSegments }} completed
                        </span>
                        <span v-else-if="hasSelectedSegments">
                            {{ selectedSegments.size }} segment{{ selectedSegments.size !== 1 ? 's' : '' }} selected for batch testing
                        </span>
                        <span v-else>
                            Select segments to begin batch testing
                        </span>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <SecondaryButton
                            @click="closePanel"
                            :disabled="isBatchRunning"
                        >
                            {{ isBatchRunning ? 'Test Running...' : 'Close' }}
                        </SecondaryButton>
                        
                        <PrimaryButton
                            v-if="!isBatchRunning"
                            @click="startBatchTest"
                            :disabled="!canStartBatch"
                        >
                            Start Batch Test
                        </PrimaryButton>
                        
                        <PrimaryButton
                            v-if="batchProgress.status === 'completed'"
                            @click="resetBatch"
                        >
                            Run New Batch
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>