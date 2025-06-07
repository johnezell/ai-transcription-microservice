<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: 'balanced'
    },
    disabled: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:modelValue']);

const qualityLevels = [
    {
        value: 'fast',
        label: 'Fast',
        description: 'Quick extraction with basic quality',
        icon: '⚡',
        color: 'text-yellow-600',
        bgColor: 'bg-yellow-50',
        borderColor: 'border-yellow-200'
    },
    {
        value: 'balanced',
        label: 'Balanced',
        description: 'Good balance of speed and quality',
        icon: '⚖️',
        color: 'text-blue-600',
        bgColor: 'bg-blue-50',
        borderColor: 'border-blue-200'
    },
    {
        value: 'high',
        label: 'High Quality',
        description: 'Enhanced quality with longer processing',
        icon: '🎯',
        color: 'text-green-600',
        bgColor: 'bg-green-50',
        borderColor: 'border-green-200'
    },
    {
        value: 'premium',
        label: 'Premium',
        description: 'Maximum quality with advanced processing',
        icon: '💎',
        color: 'text-purple-600',
        bgColor: 'bg-purple-50',
        borderColor: 'border-purple-200'
    }
];

const selectedLevel = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

const getQualityConfig = (level) => {
    return qualityLevels.find(q => q.value === level) || qualityLevels[1];
};
</script>

<template>
    <div class="space-y-3">
        <label class="block text-sm font-medium text-gray-700">
            Audio Quality Level
        </label>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div
                v-for="level in qualityLevels"
                :key="level.value"
                class="relative"
            >
                <input
                    :id="`quality-${level.value}`"
                    v-model="selectedLevel"
                    :value="level.value"
                    :disabled="disabled"
                    type="radio"
                    name="quality-level"
                    class="sr-only"
                />
                <label
                    :for="`quality-${level.value}`"
                    class="flex flex-col p-4 border-2 rounded-lg cursor-pointer transition-all duration-200 hover:shadow-md"
                    :class="[
                        selectedLevel === level.value
                            ? `${level.borderColor} ${level.bgColor} ring-2 ring-offset-2 ring-indigo-500`
                            : 'border-gray-200 bg-white hover:border-gray-300',
                        disabled ? 'opacity-50 cursor-not-allowed' : ''
                    ]"
                >
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-2xl">{{ level.icon }}</span>
                        <div
                            v-if="selectedLevel === level.value"
                            class="w-5 h-5 rounded-full bg-indigo-600 flex items-center justify-center"
                        >
                            <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                    
                    <div class="text-left">
                        <div class="font-semibold text-gray-900 mb-1">
                            {{ level.label }}
                        </div>
                        <div class="text-xs text-gray-600">
                            {{ level.description }}
                        </div>
                    </div>
                </label>
            </div>
        </div>
        
        <!-- Selected Quality Info -->
        <div
            v-if="selectedLevel"
            class="mt-4 p-3 rounded-lg"
            :class="getQualityConfig(selectedLevel).bgColor"
        >
            <div class="flex items-center space-x-2">
                <span class="text-lg">{{ getQualityConfig(selectedLevel).icon }}</span>
                <div>
                    <div class="text-sm font-medium" :class="getQualityConfig(selectedLevel).color">
                        Selected: {{ getQualityConfig(selectedLevel).label }}
                    </div>
                    <div class="text-xs text-gray-600">
                        {{ getQualityConfig(selectedLevel).description }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>