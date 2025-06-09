<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    preset: {
        type: String,
        required: true
    },
    showConfiguration: {
        type: Boolean,
        default: true
    },
    compact: {
        type: Boolean,
        default: false
    }
});

// Component state
const isLoading = ref(false);
const presetConfig = ref(null);
const error = ref(null);

// Fetch preset configuration from API
const fetchPresetConfig = async () => {
    if (!props.preset) return;
    
    isLoading.value = true;
    error.value = null;
    
    try {
        const response = await axios.get(`/api/transcription-presets/${props.preset}`);
        
        if (response.data.success) {
            presetConfig.value = response.data.preset;
        } else {
            error.value = response.data.message || 'Failed to load preset configuration';
        }
    } catch (err) {
        console.error('Error fetching preset configuration:', err);
        error.value = err.response?.data?.message || 'Error loading preset configuration';
    } finally {
        isLoading.value = false;
    }
};

// Computed properties
const hasPrompt = computed(() => {
    return presetConfig.value?.whisper_configuration?.initial_prompt;
});

const whisperConfig = computed(() => {
    return presetConfig.value?.whisper_configuration || {};
});

const performanceInfo = computed(() => {
    return presetConfig.value?.performance || {};
});

const validationInfo = computed(() => {
    return presetConfig.value?.validation || {};
});

// Watch for preset changes
watch(() => props.preset, (newPreset) => {
    if (newPreset) {
        fetchPresetConfig();
    }
}, { immediate: true });

// Helper function to format boolean values
const formatBoolean = (value) => {
    return value ? 'Yes' : 'No';
};

// Helper function to format arrays
const formatArray = (value) => {
    return Array.isArray(value) ? value.join(', ') : value;
};
</script>

<template>
    <div class="whisper-prompt-display">
        <!-- Loading state -->
        <div v-if="isLoading" class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <div class="flex items-center space-x-3">
                <svg class="animate-spin w-4 h-4 text-gray-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-sm text-gray-600">Loading Whisper configuration...</span>
            </div>
        </div>

        <!-- Error state -->
        <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="flex items-center space-x-3">
                <svg class="w-4 h-4 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
                <span class="text-sm text-red-700">{{ error }}</span>
            </div>
        </div>

        <!-- Preset configuration display -->
        <div v-else-if="presetConfig" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <!-- Header -->
                    <h4 class="text-sm font-medium text-blue-900 mb-3">
                        Whisper AI Configuration - {{ presetConfig.name }} Preset
                    </h4>

                    <!-- Main prompt display -->
                    <div v-if="hasPrompt" class="bg-white border border-blue-100 rounded-md p-3 mb-3">
                        <div class="mb-2">
                            <span class="text-xs font-medium text-blue-800 uppercase tracking-wide">Initial Prompt</span>
                        </div>
                        <p class="text-sm text-gray-700 font-mono leading-relaxed">
                            "{{ whisperConfig.initial_prompt }}"
                        </p>
                    </div>

                    <!-- Basic configuration (always shown) -->
                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <div class="bg-white border border-blue-100 rounded-md p-2">
                            <div class="font-medium text-blue-800 mb-1">Model</div>
                            <div class="text-gray-700">{{ whisperConfig.model || 'Unknown' }}</div>
                        </div>
                        <div class="bg-white border border-blue-100 rounded-md p-2">
                            <div class="font-medium text-blue-800 mb-1">Temperature</div>
                            <div class="text-gray-700">{{ whisperConfig.temperature }}</div>
                        </div>
                    </div>

                    <!-- Extended configuration (if not compact) -->
                    <div v-if="!compact && showConfiguration" class="mt-3 space-y-3">
                        <!-- Whisper Parameters -->
                        <div class="bg-white border border-blue-100 rounded-md p-3">
                            <h5 class="text-xs font-medium text-blue-800 mb-2 uppercase tracking-wide">Whisper Parameters</h5>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <span class="font-medium text-gray-600">Word Timestamps:</span>
                                    <span class="ml-1 text-gray-700">{{ formatBoolean(whisperConfig.word_timestamps) }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Beam Size:</span>
                                    <span class="ml-1 text-gray-700">{{ whisperConfig.beam_size || 1 }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Best Of:</span>
                                    <span class="ml-1 text-gray-700">{{ whisperConfig.best_of || 1 }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Patience:</span>
                                    <span class="ml-1 text-gray-700">{{ whisperConfig.patience || 1.0 }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">FP16:</span>
                                    <span class="ml-1 text-gray-700">{{ formatBoolean(whisperConfig.fp16) }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">No Speech Threshold:</span>
                                    <span class="ml-1 text-gray-700">{{ whisperConfig.no_speech_threshold || 0.6 }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Performance Characteristics -->
                        <div class="bg-white border border-blue-100 rounded-md p-3">
                            <h5 class="text-xs font-medium text-blue-800 mb-2 uppercase tracking-wide">Performance</h5>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <span class="font-medium text-gray-600">Expected Accuracy:</span>
                                    <span class="ml-1 text-gray-700">{{ performanceInfo.expected_accuracy || 'Unknown' }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Processing Time:</span>
                                    <span class="ml-1 text-gray-700">{{ performanceInfo.estimated_processing_time || 'Unknown' }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Relative Speed:</span>
                                    <span class="ml-1 text-gray-700">{{ performanceInfo.relative_speed || 'Unknown' }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Memory Usage:</span>
                                    <span class="ml-1 text-gray-700">{{ performanceInfo.memory_usage || 'Unknown' }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Use Case -->
                        <div v-if="presetConfig.use_case" class="bg-white border border-blue-100 rounded-md p-3">
                            <h5 class="text-xs font-medium text-blue-800 mb-2 uppercase tracking-wide">Recommended Use Case</h5>
                            <p class="text-xs text-gray-700">{{ presetConfig.use_case }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- No configuration available -->
        <div v-else class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <div class="flex items-center space-x-3">
                <svg class="w-4 h-4 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
                <span class="text-sm text-gray-600">No Whisper configuration available for this preset</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.whisper-prompt-display {
    /* Component-specific styles if needed */
}
</style> 