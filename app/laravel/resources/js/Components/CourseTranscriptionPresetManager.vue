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

const emit = defineEmits(['close', 'preset-updated']);

// State management
const isLoading = ref(false);
const isSaving = ref(false);
const currentPreset = ref('balanced');
const selectedPreset = ref('balanced');
const availablePresets = ref([]);
const courseInfo = ref(null);
const notifications = ref([]);

// Preset configurations with detailed descriptions for transcription
const presetConfigurations = {
    fast: {
        label: 'Fast',
        description: 'Quick transcription with basic accuracy',
        model: 'Whisper Tiny',
        modelSize: '39 MB',
        accuracy: 'Basic (85-90%)',
        speed: '1-2 min/hour',
        useCase: 'Quick content review, rapid drafts',
        color: 'text-green-600',
        bgColor: 'bg-green-50',
        borderColor: 'border-green-200',
        ringColor: 'ring-green-500',
        vramRequirement: '~1 GB',
        features: [
            'Fastest processing speed',
            'Lowest resource usage',
            'Good for initial content review',
            'Basic punctuation and formatting'
        ]
    },
    balanced: {
        label: 'Balanced',
        description: 'Optimal balance of speed and accuracy',
        model: 'Whisper Small',
        modelSize: '244 MB',
        accuracy: 'Good (90-95%)',
        speed: '3-5 min/hour',
        useCase: 'Standard transcription work',
        color: 'text-blue-600',
        bgColor: 'bg-blue-50',
        borderColor: 'border-blue-200',
        ringColor: 'ring-blue-500',
        vramRequirement: '~2 GB',
        features: [
            'Good balance of speed and quality',
            'Reliable for most content types',
            'Enhanced punctuation handling',
            'Better music terminology recognition'
        ]
    },
    high: {
        label: 'High Quality',
        description: 'Enhanced accuracy for professional use',
        model: 'Whisper Medium',
        modelSize: '769 MB',
        accuracy: 'High (95-98%)',
        speed: '8-12 min/hour',
        useCase: 'Professional transcription, detailed analysis',
        color: 'text-purple-600',
        bgColor: 'bg-purple-50',
        borderColor: 'border-purple-200',
        ringColor: 'ring-purple-500',
        vramRequirement: '~5 GB',
        features: [
            'High accuracy transcription',
            'Better handling of technical terms',
            'Improved speaker diarization',
            'Enhanced music terminology detection'
        ]
    },
    premium: {
        label: 'Premium',
        description: 'Maximum accuracy for critical content',
        model: 'Whisper Large-v3',
        modelSize: '1550 MB',
        accuracy: 'Maximum (98-99%)',
        speed: '15-25 min/hour',
        useCase: 'Critical accuracy requirements, research',
        color: 'text-amber-600',
        bgColor: 'bg-amber-50',
        borderColor: 'border-amber-200',
        ringColor: 'ring-amber-500',
        vramRequirement: '~10 GB',
        features: [
            'Highest accuracy available',
            'Superior handling of complex audio',
            'Advanced music terminology recognition',
            'Best performance on challenging content'
        ]
    }
};

// Computed properties
const hasUnsavedChanges = computed(() => {
    return currentPreset.value !== selectedPreset.value;
});

const selectedConfig = computed(() => {
    return presetConfigurations[selectedPreset.value] || presetConfigurations.balanced;
});

const currentConfig = computed(() => {
    return presetConfigurations[currentPreset.value] || presetConfigurations.balanced;
});

// Methods
const loadCurrentPreset = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get(`/api/courses/${props.courseId}/transcription-preset`);
        const data = response.data.data;
        
        currentPreset.value = data.preset || 'balanced';
        selectedPreset.value = currentPreset.value;
        availablePresets.value = data.available_presets || Object.keys(presetConfigurations);
        
        // Load course info for display
        await loadCourseInfo();
        
    } catch (error) {
        console.error('Failed to load current transcription preset:', error);
        showNotification('Failed to load current transcription preset settings', 'error');
    } finally {
        isLoading.value = false;
    }
};

const loadCourseInfo = async () => {
    try {
        // First try to use course data from props if available
        if (props.course && Object.keys(props.course).length > 0) {
            let totalSegments = 0;
            
            // Use segments_count if available
            if (props.course.segments_count > 0) {
                totalSegments = props.course.segments_count;
            }
            // Otherwise calculate from channels if available
            else if (props.course.channels && Array.isArray(props.course.channels)) {
                totalSegments = props.course.channels.reduce((total, channel) => {
                    return total + (channel.segments ? channel.segments.length : 0);
                }, 0);
            }
            
            // If we have a valid segment count from props, use it
            if (totalSegments > 0) {
                courseInfo.value = {
                    total_segments: totalSegments,
                    title: props.course.name || props.course.title || `Course #${props.courseId}`
                };
                console.log(`Using props data: ${totalSegments} segments for "${courseInfo.value.title}"`);
                return;
            }
        }
        
        // Fallback: Fetch course info from API if props are incomplete or have no segments
        console.log('Course props incomplete or no segments found, fetching from API...');
        const response = await axios.get(`/truefire-courses/${props.courseId}`);
        const courseData = response.data.course || response.data;
        
        // Calculate total segments from API response
        let totalSegments = 0;
        if (courseData.channels && Array.isArray(courseData.channels)) {
            totalSegments = courseData.channels.reduce((total, channel) => {
                return total + (channel.segments ? channel.segments.length : 0);
            }, 0);
        }
        
        // Use segments_count if available, otherwise use calculated total
        totalSegments = courseData.segments_count || totalSegments || 0;
        
        courseInfo.value = {
            total_segments: totalSegments,
            title: courseData.name || courseData.title || `Course #${props.courseId}`
        };
        
        console.log(`Loaded from API: ${totalSegments} segments for "${courseInfo.value.title}"`);
        
    } catch (error) {
        console.error('Failed to load course info:', error);
        // Fallback to props data even if incomplete
        courseInfo.value = {
            total_segments: props.course?.segments_count || 0,
            title: props.course?.name || props.course?.title || `Course #${props.courseId}`
        };
    }
};

const savePreset = async () => {
    if (!hasUnsavedChanges.value) return;
    
    isSaving.value = true;
    try {
        const response = await axios.put(`/api/courses/${props.courseId}/transcription-preset`, {
            preset: selectedPreset.value
        });
        
        if (response.data.success) {
            currentPreset.value = selectedPreset.value;
            showNotification(`Transcription preset updated to ${presetConfigurations[selectedPreset.value].label}`, 'success');
            emit('preset-updated', {
                courseId: props.courseId,
                preset: selectedPreset.value,
                previousPreset: response.data.data.previous_preset
            });
        }
    } catch (error) {
        console.error('Failed to save transcription preset:', error);
        showNotification('Failed to save transcription preset settings', 'error');
    } finally {
        isSaving.value = false;
    }
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
    emit('close');
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
        aria-labelledby="transcription-preset-manager"
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
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 px-6 py-4">
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
                                    Course Transcription Preset Manager
                                </h3>
                                <p class="text-sm text-indigo-100">
                                    Configure WhisperX model settings for optimal transcription quality
                                </p>
                            </div>
                        </div>
                        <button
                            @click="closePanel"
                            class="text-indigo-100 hover:text-white transition-colors duration-200"
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
                            <svg class="animate-spin w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-gray-600">Loading transcription preset settings...</span>
                        </div>
                    </div>

                    <!-- Main Configuration -->
                    <div v-if="!isLoading" class="space-y-6">
                        <!-- Current Preset Display -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="text-sm font-medium text-gray-900">Current Transcription Preset</h4>
                                    <p class="text-sm text-gray-600 mt-1">
                                        Active WhisperX model configuration for this course
                                    </p>
                                </div>
                                <div class="text-right">
                                    <div :class="currentConfig.color" class="text-lg font-semibold">
                                        {{ currentConfig.label }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ currentConfig.model }} • {{ currentConfig.speed }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Course Information -->
                        <div v-if="courseInfo" class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                            <div class="flex items-center space-x-3">
                                <div class="flex-shrink-0">
                                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h5 class="text-sm font-medium text-indigo-900">Course Information</h5>
                                    <p class="text-sm text-indigo-700 mt-1">
                                        <strong>{{ courseInfo.title }}</strong> • {{ courseInfo.total_segments }} segments available for transcription
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Preset Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-4">
                                Select Transcription Preset
                            </label>
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <div
                                    v-for="(config, preset) in presetConfigurations"
                                    :key="preset"
                                    class="relative cursor-pointer rounded-lg border-2 p-5 hover:shadow-lg transition-all duration-200"
                                    :class="[
                                        selectedPreset === preset 
                                            ? `${config.borderColor} ${config.bgColor} ring-2 ${config.ringColor} ring-opacity-50` 
                                            : 'border-gray-200 hover:border-gray-300 bg-white'
                                    ]"
                                    @click="selectedPreset = preset"
                                >
                                    <div class="flex items-start space-x-4">
                                        <input
                                            :id="`preset-${preset}`"
                                            v-model="selectedPreset"
                                            :value="preset"
                                            type="radio"
                                            name="transcription-preset"
                                            class="mt-1 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <div class="flex-1 min-w-0">
                                            <!-- Header -->
                                            <div class="flex items-center justify-between mb-2">
                                                <h5 :class="config.color" class="text-lg font-semibold">
                                                    {{ config.label }}
                                                </h5>
                                                <span class="text-xs font-medium text-gray-500 bg-gray-100 px-2 py-1 rounded">
                                                    {{ config.speed }}
                                                </span>
                                            </div>
                                            
                                            <!-- Model Information -->
                                            <div class="mb-3">
                                                <div class="flex items-center space-x-2 mb-1">
                                                    <span class="text-sm font-medium text-gray-900">{{ config.model }}</span>
                                                    <span class="text-xs text-gray-500">{{ config.modelSize }}</span>
                                                </div>
                                                <p class="text-sm text-gray-600">
                                                    {{ config.description }}
                                                </p>
                                            </div>
                                            
                                            <!-- Specifications -->
                                            <div class="grid grid-cols-2 gap-3 mb-3">
                                                <div>
                                                    <span class="text-xs font-medium text-gray-500">Accuracy</span>
                                                    <div class="text-sm font-medium text-gray-900">{{ config.accuracy }}</div>
                                                </div>
                                                <div>
                                                    <span class="text-xs font-medium text-gray-500">VRAM</span>
                                                    <div class="text-sm font-medium text-gray-900">{{ config.vramRequirement }}</div>
                                                </div>
                                            </div>
                                            
                                            <!-- Use Case -->
                                            <div class="mb-3">
                                                <span class="text-xs font-medium text-gray-500">Best For</span>
                                                <div class="text-sm text-gray-700">{{ config.useCase }}</div>
                                            </div>
                                            
                                            <!-- Features -->
                                            <div>
                                                <span class="text-xs font-medium text-gray-500 mb-1 block">Key Features</span>
                                                <ul class="text-xs text-gray-600 space-y-1">
                                                    <li v-for="feature in config.features" :key="feature" class="flex items-start">
                                                        <svg class="w-3 h-3 text-green-500 mt-0.5 mr-1 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                        </svg>
                                                        {{ feature }}
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Comparison Information -->
                        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-6">
                            <h4 class="text-lg font-semibold text-blue-900 mb-4">🎯 Choosing the Right Preset</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h5 class="font-medium text-blue-900 mb-2">Speed vs Quality Trade-offs</h5>
                                    <ul class="text-sm text-blue-700 space-y-1">
                                        <li>• <strong>Fast:</strong> Quick results, good for initial review</li>
                                        <li>• <strong>Balanced:</strong> Best overall choice for most content</li>
                                        <li>• <strong>High:</strong> Professional quality, detailed analysis</li>
                                        <li>• <strong>Premium:</strong> Maximum accuracy, research-grade</li>
                                    </ul>
                                </div>
                                <div>
                                    <h5 class="font-medium text-blue-900 mb-2">Music Education Optimization</h5>
                                    <ul class="text-sm text-blue-700 space-y-1">
                                        <li>• Enhanced recognition of guitar terminology</li>
                                        <li>• Better handling of music theory concepts</li>
                                        <li>• Improved accuracy for technical discussions</li>
                                        <li>• Optimized for instructional content patterns</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="bg-gray-50 px-6 py-4 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        <span v-if="hasUnsavedChanges">
                            You have unsaved preset changes
                        </span>
                        <span v-else>
                            Transcription preset configured and ready
                        </span>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <SecondaryButton @click="closePanel">
                            Close
                        </SecondaryButton>
                        
                        <PrimaryButton
                            v-if="hasUnsavedChanges"
                            @click="savePreset"
                            :disabled="isSaving"
                            class="bg-indigo-600 hover:bg-indigo-700 focus:bg-indigo-700"
                        >
                            {{ isSaving ? 'Saving...' : 'Save Preset' }}
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