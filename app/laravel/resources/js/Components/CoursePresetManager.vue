<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import axios from 'axios';
import PrimaryButton from './PrimaryButton.vue';
import SecondaryButton from './SecondaryButton.vue';

const props = defineProps({
    courseId: {
        type: [String, Number],
        required: true
    },
    course: {
        type: Object,
        default: () => ({})
    },
    show: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['close', 'preset-updated', 'batch-started', 'batch-completed', 'batch-failed']);

// State management
const isLoading = ref(false);
const isSaving = ref(false);
const isProcessing = ref(false);
const currentPreset = ref('balanced');
const selectedPreset = ref('balanced');
const availablePresets = ref([]);
const courseInfo = ref(null);
const notifications = ref([]);

// Batch processing state
const batchProgress = ref({
    status: 'idle', // idle, running, completed, failed
    progress: 0,
    message: '',
    totalSegments: 0,
    completedSegments: 0,
    failedSegments: 0,
    startTime: null,
    endTime: null,
    jobId: null
});

// Preset configurations with detailed descriptions
const presetConfigurations = {
    fast: {
        label: 'Fast',
        description: 'Basic processing for quick results',
        avgTime: '15s per segment',
        quality: 'Standard',
        useCase: 'Quick testing and previews',
        color: 'text-green-600',
        bgColor: 'bg-green-50',
        borderColor: 'border-green-200'
    },
    balanced: {
        label: 'Balanced',
        description: 'Optimal balance of speed and quality',
        avgTime: '30s per segment',
        quality: 'Good',
        useCase: 'General transcription work',
        color: 'text-blue-600',
        bgColor: 'bg-blue-50',
        borderColor: 'border-blue-200'
    },
    high: {
        label: 'High',
        description: 'Enhanced quality processing',
        avgTime: '45s per segment',
        quality: 'High',
        useCase: 'Professional transcription',
        color: 'text-purple-600',
        bgColor: 'bg-purple-50',
        borderColor: 'border-purple-200'
    },
    premium: {
        label: 'Premium',
        description: 'Maximum quality processing',
        avgTime: '60s per segment',
        quality: 'Premium',
        useCase: 'Critical audio analysis',
        color: 'text-amber-600',
        bgColor: 'bg-amber-50',
        borderColor: 'border-amber-200'
    }
};

// Computed properties
const hasUnsavedChanges = computed(() => {
    return currentPreset.value !== selectedPreset.value;
});

const estimatedBatchTime = computed(() => {
    if (!courseInfo.value?.total_segments) return 0;
    
    const timePerSegment = {
        fast: 15,
        balanced: 30,
        high: 45,
        premium: 60
    };
    
    const totalSeconds = (timePerSegment[selectedPreset.value] || 30) * courseInfo.value.total_segments;
    return Math.ceil(totalSeconds / 60); // Return minutes
});

const canStartBatch = computed(() => {
    return !isProcessing.value && 
           !hasUnsavedChanges.value && 
           courseInfo.value?.total_segments > 0 &&
           batchProgress.value.status !== 'running';
});

// Methods
const loadCurrentPreset = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get(`/truefire-courses/${props.courseId}/audio-preset`);
        const data = response.data.data;
        
        currentPreset.value = data.preset || 'balanced';
        selectedPreset.value = currentPreset.value;
        availablePresets.value = data.available_presets || Object.keys(presetConfigurations);
        
        // Load course info for segment count
        await loadCourseInfo();
        
    } catch (error) {
        console.error('Failed to load current preset:', error);
        showNotification('Failed to load current preset settings', 'error');
    } finally {
        isLoading.value = false;
    }
};

const loadCourseInfo = async () => {
    try {
        // Use course data from props if available
        if (props.course && Object.keys(props.course).length > 0) {
            courseInfo.value = {
                total_segments: props.course.segments_count || 0,
                title: props.course.name || props.course.title || `Course #${props.courseId}`
            };
        } else {
            // Fallback to default values
            courseInfo.value = {
                total_segments: 0,
                title: `Course #${props.courseId}`
            };
        }
    } catch (error) {
        console.error('Failed to load course info:', error);
    }
};

const savePreset = async () => {
    if (!hasUnsavedChanges.value) return;
    
    isSaving.value = true;
    try {
        const response = await axios.put(`/truefire-courses/${props.courseId}/audio-preset`, {
            preset: selectedPreset.value
        });
        
        if (response.data.success) {
            currentPreset.value = selectedPreset.value;
            showNotification(`Preset updated to ${presetConfigurations[selectedPreset.value].label}`, 'success');
            emit('preset-updated', {
                courseId: props.courseId,
                preset: selectedPreset.value,
                previousPreset: response.data.data.previous_preset
            });
        }
    } catch (error) {
        console.error('Failed to save preset:', error);
        showNotification('Failed to save preset settings', 'error');
    } finally {
        isSaving.value = false;
    }
};

const startBatchProcessing = async () => {
    if (!canStartBatch.value) return;
    
    isProcessing.value = true;
    batchProgress.value = {
        status: 'running',
        progress: 0,
        message: 'Initializing batch processing...',
        totalSegments: courseInfo.value.total_segments,
        completedSegments: 0,
        failedSegments: 0,
        startTime: new Date(),
        endTime: null,
        jobId: null
    };
    
    try {
        const response = await axios.post(`/truefire-courses/${props.courseId}/process-all-videos`, {
            for_transcription: true // This creates MP3 files for transcription
        });
        
        if (response.data.success) {
            batchProgress.value.jobId = response.data.data.job_id;
            batchProgress.value.message = `Batch processing started with ${currentPreset.value} quality`;
            
            showNotification(`Batch processing started for ${courseInfo.value.total_segments} segments`, 'success');
            emit('batch-started', {
                courseId: props.courseId,
                jobId: response.data.data.job_id,
                preset: currentPreset.value,
                totalSegments: courseInfo.value.total_segments
            });
            
            // Start monitoring progress
            monitorBatchProgress();
        }
    } catch (error) {
        console.error('Failed to start batch processing:', error);
        batchProgress.value.status = 'failed';
        batchProgress.value.message = `Failed to start batch processing: ${error.response?.data?.message || error.message}`;
        showNotification('Failed to start batch processing', 'error');
        emit('batch-failed', error);
    } finally {
        isProcessing.value = false;
    }
};

const monitorBatchProgress = async () => {
    const maxPollingTime = 60 * 60 * 1000; // 1 hour
    const pollInterval = 5000; // 5 seconds
    const startTime = Date.now();
    
    const poll = async () => {
        try {
            const response = await axios.get(`/truefire-courses/${props.courseId}/audio-extraction-progress`);
            const progress = response.data.data;
            
            batchProgress.value.progress = Math.round((progress.completed_segments / progress.total_segments) * 100);
            batchProgress.value.completedSegments = progress.completed_segments;
            batchProgress.value.failedSegments = progress.failed_segments || 0;
            batchProgress.value.message = `Processing: ${progress.completed_segments}/${progress.total_segments} segments completed`;
            
            // Check if completed
            if (progress.completed_segments >= progress.total_segments) {
                batchProgress.value.status = 'completed';
                batchProgress.value.endTime = new Date();
                batchProgress.value.message = `Batch processing completed successfully!`;
                
                showNotification('Batch processing completed successfully!', 'success');
                emit('batch-completed', {
                    courseId: props.courseId,
                    completedSegments: progress.completed_segments,
                    failedSegments: progress.failed_segments,
                    duration: batchProgress.value.endTime - batchProgress.value.startTime
                });
                return;
            }
            
            // Continue polling if not complete and within time limit
            if (Date.now() - startTime < maxPollingTime) {
                setTimeout(poll, pollInterval);
            } else {
                // Timeout
                batchProgress.value.status = 'failed';
                batchProgress.value.message = 'Batch processing timeout - may still be running in background';
                showNotification('Batch processing monitoring timeout', 'warning');
            }
            
        } catch (error) {
            console.error('Error monitoring batch progress:', error);
            // Continue polling despite errors
            if (Date.now() - startTime < maxPollingTime) {
                setTimeout(poll, pollInterval * 2); // Exponential backoff
            }
        }
    };
    
    // Start polling after initial delay
    setTimeout(poll, pollInterval);
};

const resetBatchProgress = () => {
    batchProgress.value = {
        status: 'idle',
        progress: 0,
        message: '',
        totalSegments: 0,
        completedSegments: 0,
        failedSegments: 0,
        startTime: null,
        endTime: null,
        jobId: null
    };
};

const showNotification = (message, type = 'info') => {
    const id = Date.now();
    notifications.value.push({
        id,
        message,
        type,
        show: true
    });
    
    setTimeout(() => {
        removeNotification(id);
    }, 5000);
};

const removeNotification = (id) => {
    const index = notifications.value.findIndex(n => n.id === id);
    if (index > -1) {
        notifications.value.splice(index, 1);
    }
};

const closePanel = () => {
    if (!isProcessing.value && batchProgress.value.status !== 'running') {
        emit('close');
    }
};

// Lifecycle
onMounted(() => {
    if (props.show) {
        loadCurrentPreset();
    }
});

watch(() => props.show, (newValue) => {
    if (newValue) {
        loadCurrentPreset();
    }
});
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        aria-labelledby="course-preset-manager"
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
                <div class="bg-gradient-to-r from-blue-500 to-indigo-600 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-white">
                                    Course Audio Preset Manager
                                </h3>
                                <p class="text-sm text-blue-100">
                                    Configure audio extraction settings and batch processing
                                </p>
                            </div>
                        </div>
                        <button
                            @click="closePanel"
                            :disabled="isProcessing || batchProgress.status === 'running'"
                            class="text-blue-100 hover:text-white transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="px-6 py-6 space-y-6">
                    <!-- Loading State -->
                    <div v-if="isLoading" class="flex items-center justify-center py-8">
                        <div class="flex items-center space-x-3">
                            <svg class="animate-spin w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-gray-600">Loading preset settings...</span>
                        </div>
                    </div>

                    <!-- Batch Progress (when running) -->
                    <div v-if="batchProgress.status === 'running'" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex items-center space-x-3 mb-3">
                            <div class="flex-shrink-0">
                                <svg class="animate-spin w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-blue-900">Batch Processing in Progress</h4>
                                <p class="text-sm text-blue-700">{{ batchProgress.message }}</p>
                            </div>
                        </div>
                        
                        <!-- Progress bar -->
                        <div class="w-full bg-blue-200 rounded-full h-3">
                            <div
                                class="bg-blue-600 h-3 rounded-full transition-all duration-300"
                                :style="{ width: batchProgress.progress + '%' }"
                            ></div>
                        </div>
                        
                        <div class="flex justify-between text-xs text-blue-600 mt-2">
                            <span>{{ batchProgress.progress }}% complete</span>
                            <span>{{ batchProgress.completedSegments }}/{{ batchProgress.totalSegments }} segments</span>
                            <span v-if="batchProgress.startTime">
                                Started {{ new Date(batchProgress.startTime).toLocaleTimeString() }}
                            </span>
                        </div>
                    </div>

                    <!-- Batch Completed -->
                    <div v-if="batchProgress.status === 'completed'" class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="flex items-center space-x-3 mb-3">
                            <div class="flex-shrink-0">
                                <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-green-900">Batch Processing Completed</h4>
                                <p class="text-sm text-green-700">
                                    Successfully processed {{ batchProgress.completedSegments }} segments
                                    <span v-if="batchProgress.endTime && batchProgress.startTime">
                                        in {{ Math.round((new Date(batchProgress.endTime) - new Date(batchProgress.startTime)) / 1000 / 60) }} minutes
                                    </span>
                                </p>
                            </div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button
                                @click="resetBatchProgress"
                                class="text-sm text-green-600 hover:text-green-800 font-medium"
                            >
                                Start New Batch
                            </button>
                        </div>
                    </div>

                    <!-- Batch Failed -->
                    <div v-if="batchProgress.status === 'failed'" class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-red-900">Batch Processing Failed</h4>
                                <p class="text-sm text-red-700">{{ batchProgress.message }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Main Configuration (when not processing) -->
                    <div v-if="!isLoading && batchProgress.status !== 'running'" class="space-y-6">
                        <!-- Current Preset Display -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="text-sm font-medium text-gray-900">Current Audio Extraction Preset</h4>
                                    <p class="text-sm text-gray-600 mt-1">
                                        Active preset for this course's audio extraction
                                    </p>
                                </div>
                                <div class="text-right">
                                    <div :class="presetConfigurations[currentPreset]?.color || 'text-gray-600'" class="text-lg font-semibold">
                                        {{ presetConfigurations[currentPreset]?.label || currentPreset }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ presetConfigurations[currentPreset]?.avgTime || 'Unknown' }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Preset Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                Select Audio Extraction Preset
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div
                                    v-for="(config, preset) in presetConfigurations"
                                    :key="preset"
                                    class="relative cursor-pointer rounded-lg border p-4 hover:shadow-md transition-all duration-200"
                                    :class="[
                                        selectedPreset === preset 
                                            ? `${config.borderColor} ${config.bgColor} ring-2 ring-blue-500 ring-opacity-50` 
                                            : 'border-gray-200 hover:border-gray-300'
                                    ]"
                                    @click="selectedPreset = preset"
                                >
                                    <div class="flex items-start space-x-3">
                                        <input
                                            :id="`preset-${preset}`"
                                            v-model="selectedPreset"
                                            :value="preset"
                                            type="radio"
                                            name="audio-preset"
                                            class="mt-1 text-blue-600 focus:ring-blue-500"
                                        />
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between">
                                                <h5 :class="config.color" class="text-sm font-semibold">
                                                    {{ config.label }}
                                                </h5>
                                                <span class="text-xs text-gray-500">
                                                    {{ config.avgTime }}
                                                </span>
                                            </div>
                                            <p class="text-xs text-gray-600 mt-1">
                                                {{ config.description }}
                                            </p>
                                            <div class="flex items-center justify-between mt-2">
                                                <span class="text-xs text-gray-500">
                                                    Quality: {{ config.quality }}
                                                </span>
                                                <span class="text-xs text-gray-500">
                                                    {{ config.useCase }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Batch Processing Section -->
                        <div class="border-t pt-6">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <h4 class="text-sm font-medium text-gray-900">Batch Processing</h4>
                                    <p class="text-sm text-gray-600 mt-1">
                                        Process all course videos for transcription (MP3 output)
                                    </p>
                                </div>
                                <div class="text-right text-sm text-gray-500">
                                    <div>{{ courseInfo?.total_segments || 0 }} segments</div>
                                    <div>~{{ estimatedBatchTime }} min estimated</div>
                                </div>
                            </div>

                            <!-- Batch Processing Info -->
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0">
                                        <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <h5 class="text-sm font-medium text-blue-900">Batch Processing Information</h5>
                                        <ul class="text-xs text-blue-700 mt-2 space-y-1">
                                            <li>• Processes all {{ courseInfo?.total_segments || 0 }} video segments in the course</li>
                                            <li>• Uses the selected preset ({{ presetConfigurations[selectedPreset]?.label }}) for consistent quality</li>
                                            <li>• Generates MP3 files optimized for transcription services</li>
                                            <li>• Runs in background queue system for reliable processing</li>
                                            <li>• Real-time progress monitoring and status updates</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Workflow Distinction -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div class="bg-purple-50 border border-purple-200 rounded-lg p-3">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <h6 class="text-sm font-medium text-purple-900">Testing Workflow</h6>
                                    </div>
                                    <p class="text-xs text-purple-700">
                                        Individual segment testing with WAV output for quality analysis and parameter tuning.
                                    </p>
                                </div>
                                <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                        </svg>
                                        <h6 class="text-sm font-medium text-green-900">Transcription Workflow</h6>
                                    </div>
                                    <p class="text-xs text-green-700">
                                        Batch processing of entire course with MP3 output optimized for transcription services.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="bg-gray-50 px-6 py-4 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        <span v-if="batchProgress.status === 'completed'">
                            Batch processing completed successfully
                        </span>
                        <span v-else-if="batchProgress.status === 'failed'">
                            Batch processing failed - ready to retry
                        </span>
                        <span v-else-if="batchProgress.status === 'running'">
                            Batch processing in progress...
                        </span>
                        <span v-else-if="hasUnsavedChanges">
                            You have unsaved preset changes
                        </span>
                        <span v-else>
                            Ready to configure presets and start batch processing
                        </span>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <SecondaryButton
                            @click="closePanel"
                            :disabled="isProcessing || batchProgress.status === 'running'"
                        >
                            {{ (isProcessing || batchProgress.status === 'running') ? 'Processing...' : 'Close' }}
                        </SecondaryButton>
                        
                        <PrimaryButton
                            v-if="hasUnsavedChanges"
                            @click="savePreset"
                            :disabled="isSaving"
                        >
                            {{ isSaving ? 'Saving...' : 'Save Preset' }}
                        </PrimaryButton>
                        
                        <PrimaryButton
                            v-if="batchProgress.status === 'idle' || batchProgress.status === 'completed'"
                            @click="startBatchProcessing"
                            :disabled="!canStartBatch"
                            class="bg-green-600 hover:bg-green-700 focus:bg-green-700"
                        >
                            {{ batchProgress.status === 'completed' ? 'Process Again' : 'Process All Videos' }}
                        </PrimaryButton>
                        
                        <PrimaryButton
                            v-if="batchProgress.status === 'failed'"
                            @click="startBatchProcessing"
                            :disabled="!canStartBatch"
                            class="bg-red-600 hover:bg-red-700 focus:bg-red-700"
                        >
                            Retry Batch Processing
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>

        <!-- Toast Notifications -->
        <div class="fixed top-4 right-4 z-50 space-y-2">
            <div
                v-for="notification in notifications"
                :key="notification.id"
                class="transform transition-all duration-300 ease-in-out"
                :class="{
                    'translate-x-0 opacity-100': notification.show,
                    'translate-x-full opacity-0': !notification.show
                }"
            >
                <div
                    class="bg-white rounded-lg shadow-lg border-l-4 p-4 max-w-sm"
                    :class="{
                        'border-blue-500': notification.type === 'info',
                        'border-green-500': notification.type === 'success',
                        'border-red-500': notification.type === 'error',
                        'border-yellow-500': notification.type === 'warning'
                    }"
                >
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg v-if="notification.type === 'success'" class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <svg v-else-if="notification.type === 'error'" class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            <svg v-else-if="notification.type === 'warning'" class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <svg v-else class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="ml-3 flex-1">
                            <p class="text-sm font-medium text-gray-900">
                                {{ notification.message }}
                            </p>
                        </div>
                        <div class="ml-4 flex-shrink-0">
                            <button
                                @click="removeNotification(notification.id)"
                                class="inline-flex text-gray-400 hover:text-gray-600 focus:outline-none focus:text-gray-600"
                            >
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>