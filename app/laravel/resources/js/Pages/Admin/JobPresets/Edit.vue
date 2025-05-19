<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import Tabs from '@/Components/Tabs.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
    preset: Object,
    modelOptions: Array,
    languageOptions: Array,
    transcriptionOptions: Object,
    audioExtractionOptions: Object
});

// Define tabs for configuration sections
const configTabs = [
    { id: 'general', label: 'General Settings' },
    { id: 'transcription', label: 'Transcription' },
    { id: 'audio', label: 'Audio Processing' },
    { id: 'terminology', label: 'Terminology' }
];
const activeTab = ref('general');

// Create a more friendly version of the model_name options
const whisperModelOptions = computed(() => {
    if (props.transcriptionOptions?.common?.model_name) {
        return props.transcriptionOptions.common.model_name.options.map(option => ({
            value: option,
            label: `${option.charAt(0).toUpperCase() + option.slice(1)} - ${option === 'tiny' ? 'Fastest, least accurate' : 
                   option === 'base' ? 'Fast, good accuracy' : 
                   option === 'small' ? 'Good balance' : 
                   option === 'medium' ? 'Accurate, slower' : 'Most accurate, slowest'}`
        }));
    }
    return props.modelOptions;
});

// Create default configuration structure
const defaultConfig = {
    transcription: {
        initial_prompt: props.preset.old_options?.initial_prompt || props.preset.old_options?.promptBoost || '',
        temperature: props.preset.old_options?.temperature ?? props.transcriptionOptions?.advanced?.temperature?.default ?? 0,
        word_timestamps: props.preset.old_options?.word_timestamps ?? props.preset.old_options?.comprehensiveTimestamps ?? props.transcriptionOptions?.advanced?.word_timestamps?.default ?? true,
        condition_on_previous_text: props.preset.old_options?.condition_on_previous_text ?? props.transcriptionOptions?.advanced?.condition_on_previous_text?.default ?? false,
        beam_size: props.preset.old_options?.beam_size ?? props.transcriptionOptions?.advanced?.beam_size?.default ?? 5,
        compression_ratio_threshold: props.preset.old_options?.compression_ratio_threshold ?? props.transcriptionOptions?.advanced?.compression_ratio_threshold?.default ?? 2.4,
        logprob_threshold: props.preset.old_options?.logprob_threshold ?? props.transcriptionOptions?.advanced?.logprob_threshold?.default ?? -1.0,
        no_speech_threshold: props.preset.old_options?.no_speech_threshold ?? props.transcriptionOptions?.advanced?.no_speech_threshold?.default ?? 0.6,
    },
    audio: {
        sample_rate: props.audioExtractionOptions?.common?.sample_rate?.default || '16000',
        channels: props.audioExtractionOptions?.common?.channels?.default || '1',
        audio_codec: props.audioExtractionOptions?.advanced?.audio_codec?.default || 'pcm_s16le',
        noise_reduction: false,
        normalize_audio: false,
        volume_boost: 0,
        low_pass: null,
        high_pass: null,
    },
    terminology: {
        extraction_method: 'regex',
        case_sensitive: false,
        min_term_frequency: 1,
        spacy_model: 'en_core_web_sm',
        use_lemmatization: true,
        include_uncategorized: false,
    }
};

// Ensure we have a valid configuration
const existingConfig = props.preset.configuration || {};

// Merge any existing configuration with defaults
const mergedConfig = {
    transcription: { ...defaultConfig.transcription, ...(existingConfig.transcription || {}) },
    audio: { ...defaultConfig.audio, ...(existingConfig.audio || {}) },
    terminology: { ...defaultConfig.terminology, ...(existingConfig.terminology || {}) }
};

// Initialize form with preset values or defaults
const form = useForm({
    name: props.preset.name,
    description: props.preset.description || '',
    model: props.preset.model,
    language: props.preset.language || '',
    configuration: mergedConfig,
    is_default: props.preset.is_default,
    is_active: props.preset.is_active,
});

const submit = () => {
    form.put(route('admin.job-presets.update', props.preset.id));
};

// Helper function to determine if we have transcription options from the config
const hasTranscriptionOptions = computed(() => {
    return props.transcriptionOptions && (props.transcriptionOptions.common || props.transcriptionOptions.advanced);
});

// Helper function to determine if we have audio extraction options from the config
const hasAudioExtractionOptions = computed(() => {
    return props.audioExtractionOptions && (props.audioExtractionOptions.common || props.audioExtractionOptions.advanced);
});
</script>

<template>
    <Head title="Edit Transcription Preset" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Transcription Preset</h2>
                <Link :href="route('admin.job-presets.index')" class="px-4 py-2 bg-gray-800 text-white rounded-md">
                    Back to Presets
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit">
                    <Tabs :tabs="configTabs" v-model="activeTab">
                        <template v-slot:default="{ activeTab }">
                            <!-- General Settings Tab -->
                            <div v-if="activeTab === 'general'">
                                <Card title="Basic Information">
                                    <div class="mb-4">
                                        <InputLabel for="name" value="Preset Name" />
                                        <TextInput
                                            id="name"
                                            v-model="form.name"
                                            type="text"
                                            class="mt-1 block w-full"
                                            required
                                            autofocus
                                        />
                                        <InputError :message="form.errors.name" class="mt-2" />
                                    </div>
                                    
                                    <div class="mb-4">
                                        <InputLabel for="description" value="Description (Optional)" />
                                        <textarea
                                            id="description"
                                            v-model="form.description"
                                            rows="2"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        ></textarea>
                                        <InputError :message="form.errors.description" class="mt-2" />
                                    </div>
                                </Card>

                                <Card title="Status Settings">
                                    <div class="mb-2 flex items-center">
                                        <Checkbox id="is_active" v-model:checked="form.is_active" name="is_active" />
                                        <InputLabel for="is_active" value="Active (available for selection)" class="ml-2" />
                                    </div>
                                    
                                    <div class="mb-2 flex items-center">
                                        <Checkbox id="is_default" v-model:checked="form.is_default" name="is_default" :disabled="preset.is_default" />
                                        <InputLabel for="is_default" value="Set as default preset" class="ml-2" />
                                        <span v-if="preset.is_default" class="ml-2 text-xs text-green-600">
                                            (This is already the default preset)
                                        </span>
                                    </div>
                                </Card>
                            </div>

                            <!-- Transcription Tab -->
                            <div v-if="activeTab === 'transcription'">
                                <Card title="Transcription Model & Language">
                                    <div class="mb-4">
                                        <InputLabel for="model" value="Whisper Model" />
                                        <select
                                            id="model"
                                            v-model="form.model"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        >
                                            <option v-for="option in whisperModelOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                        <InputError :message="form.errors.model" class="mt-2" />
                                        <p v-if="props.transcriptionOptions?.common?.model_name?.impact" class="mt-1 text-sm text-gray-500">
                                            {{ props.transcriptionOptions.common.model_name.impact }}
                                        </p>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <InputLabel for="language" value="Language" />
                                        <select
                                            id="language"
                                            v-model="form.language"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        >
                                            <option v-for="option in languageOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                        <InputError :message="form.errors.language" class="mt-2" />
                                        <p v-if="props.transcriptionOptions?.common?.language?.impact" class="mt-1 text-sm text-gray-500">
                                            {{ props.transcriptionOptions.common.language.impact }}
                                        </p>
                                    </div>

                                    <div class="mb-4">
                                        <InputLabel for="initial_prompt" value="Initial Prompt (Optional)" />
                                        <textarea
                                            id="initial_prompt"
                                            v-model="form.configuration.transcription.initial_prompt"
                                            rows="2"
                                            placeholder="E.g., 'This audio is about guitar techniques.'"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        ></textarea>
                                        <p v-if="props.transcriptionOptions?.common?.initial_prompt?.impact" class="mt-1 text-sm text-gray-500">
                                            {{ props.transcriptionOptions.common.initial_prompt.impact }}
                                        </p>
                                    </div>
                                </Card>

                                <Card title="Advanced Transcription Options">
                                    <div class="mb-4">
                                        <InputLabel for="temperature" value="Temperature" />
                                        <input
                                            id="temperature"
                                            v-model="form.configuration.transcription.temperature"
                                            type="range"
                                            min="0"
                                            max="1"
                                            step="0.1"
                                            class="mt-1 block w-full"
                                        />
                                        <div class="flex justify-between text-xs text-gray-500">
                                            <span>0 (More precise)</span>
                                            <span class="font-medium">{{ form.configuration.transcription.temperature }}</span>
                                            <span>1 (More creative)</span>
                                        </div>
                                        <p v-if="props.transcriptionOptions?.advanced?.temperature?.impact" class="mt-1 text-sm text-gray-500">
                                            {{ props.transcriptionOptions.advanced.temperature.impact }}
                                        </p>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <div class="flex items-center mb-2">
                                                <Checkbox id="word_timestamps" v-model:checked="form.configuration.transcription.word_timestamps" name="word_timestamps" />
                                                <InputLabel for="word_timestamps" value="Generate word-level timestamps" class="ml-2" />
                                            </div>
                                            <p v-if="props.transcriptionOptions?.advanced?.word_timestamps?.impact" class="ml-6 text-sm text-gray-500">
                                                {{ props.transcriptionOptions.advanced.word_timestamps.impact }}
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <div class="flex items-center mb-2">
                                                <Checkbox id="condition_on_previous_text" v-model:checked="form.configuration.transcription.condition_on_previous_text" name="condition_on_previous_text" />
                                                <InputLabel for="condition_on_previous_text" value="Condition on previous text" class="ml-2" />
                                            </div>
                                            <p v-if="props.transcriptionOptions?.advanced?.condition_on_previous_text?.impact" class="ml-6 text-sm text-gray-500">
                                                {{ props.transcriptionOptions.advanced.condition_on_previous_text.impact }}
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Advanced numerical parameters -->
                                        <div v-if="hasTranscriptionOptions && props.transcriptionOptions.advanced.beam_size">
                                            <InputLabel for="beam_size" value="Beam Size" />
                                            <input 
                                                type="number"
                                                id="beam_size"
                                                v-model="form.configuration.transcription.beam_size"
                                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                min="1"
                                                max="10"
                                                step="1"
                                            />
                                            <p class="mt-1 text-sm text-gray-500">
                                                {{ props.transcriptionOptions.advanced.beam_size.impact }}
                                            </p>
                                        </div>
                                        
                                        <div v-if="hasTranscriptionOptions && props.transcriptionOptions.advanced.no_speech_threshold">
                                            <InputLabel for="no_speech_threshold" value="No Speech Threshold" />
                                            <input 
                                                type="number"
                                                id="no_speech_threshold"
                                                v-model="form.configuration.transcription.no_speech_threshold"
                                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                min="0"
                                                max="1"
                                                step="0.05"
                                            />
                                            <p class="mt-1 text-sm text-gray-500">
                                                {{ props.transcriptionOptions.advanced.no_speech_threshold.impact }}
                                            </p>
                                        </div>
                                        
                                        <div v-if="hasTranscriptionOptions && props.transcriptionOptions.advanced.compression_ratio_threshold">
                                            <InputLabel for="compression_ratio_threshold" value="Compression Ratio Threshold" />
                                            <input 
                                                type="number"
                                                id="compression_ratio_threshold"
                                                v-model="form.configuration.transcription.compression_ratio_threshold"
                                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                min="1"
                                                max="5"
                                                step="0.1"
                                            />
                                            <p class="mt-1 text-sm text-gray-500">
                                                {{ props.transcriptionOptions.advanced.compression_ratio_threshold.impact }}
                                            </p>
                                        </div>
                                        
                                        <div v-if="hasTranscriptionOptions && props.transcriptionOptions.advanced.logprob_threshold">
                                            <InputLabel for="logprob_threshold" value="Log Probability Threshold" />
                                            <input 
                                                type="number"
                                                id="logprob_threshold"
                                                v-model="form.configuration.transcription.logprob_threshold"
                                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                min="-10"
                                                max="0"
                                                step="0.1"
                                            />
                                            <p class="mt-1 text-sm text-gray-500">
                                                {{ props.transcriptionOptions.advanced.logprob_threshold.impact }}
                                            </p>
                                        </div>
                                    </div>
                                </Card>
                            </div>

                            <!-- Audio Tab -->
                            <div v-if="activeTab === 'audio'">
                                <Card title="Common Audio Settings">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <InputLabel for="sample_rate" value="Sample Rate" />
                                            <select
                                                id="sample_rate"
                                                v-model="form.configuration.audio.sample_rate"
                                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                            >
                                                <option v-for="option in props.audioExtractionOptions?.common?.sample_rate?.options || ['16000']" 
                                                        :key="option" 
                                                        :value="option">
                                                    {{ option }} Hz
                                                </option>
                                            </select>
                                            <p v-if="props.audioExtractionOptions?.common?.sample_rate?.impact" class="mt-1 text-sm text-gray-500">
                                                {{ props.audioExtractionOptions.common.sample_rate.impact }}
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <InputLabel for="channels" value="Audio Channels" />
                                            <select
                                                id="channels"
                                                v-model="form.configuration.audio.channels"
                                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                            >
                                                <option value="1">Mono (1)</option>
                                                <option value="2">Stereo (2)</option>
                                            </select>
                                            <p v-if="props.audioExtractionOptions?.common?.channels?.impact" class="mt-1 text-sm text-gray-500">
                                                {{ props.audioExtractionOptions.common.channels.impact }}
                                            </p>
                                        </div>
                                    </div>
                                </Card>
                                
                                <Card title="Advanced Audio Settings">
                                    <div class="mb-4">
                                        <InputLabel for="audio_codec" value="Audio Codec" />
                                        <select
                                            id="audio_codec"
                                            v-model="form.configuration.audio.audio_codec"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        >
                                            <option v-for="option in props.audioExtractionOptions?.advanced?.audio_codec?.options || ['pcm_s16le']" 
                                                    :key="option" 
                                                    :value="option">
                                                {{ option }}
                                            </option>
                                        </select>
                                        <p v-if="props.audioExtractionOptions?.advanced?.audio_codec?.impact" class="mt-1 text-sm text-gray-500">
                                            {{ props.audioExtractionOptions.advanced.audio_codec.impact }}
                                        </p>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <div class="flex items-center mb-2">
                                                <Checkbox 
                                                    id="noise_reduction" 
                                                    v-model:checked="form.configuration.audio.noise_reduction" 
                                                    name="noise_reduction" 
                                                />
                                                <InputLabel for="noise_reduction" value="Apply Noise Reduction" class="ml-2" />
                                            </div>
                                            <p v-if="props.audioExtractionOptions?.advanced?.noise_reduction?.impact" class="text-sm text-gray-500">
                                                {{ props.audioExtractionOptions.advanced.noise_reduction.impact }}
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <div class="flex items-center mb-2">
                                                <Checkbox 
                                                    id="normalize_audio" 
                                                    v-model:checked="form.configuration.audio.normalize_audio" 
                                                    name="normalize_audio" 
                                                />
                                                <InputLabel for="normalize_audio" value="Normalize Audio Levels" class="ml-2" />
                                            </div>
                                            <p v-if="props.audioExtractionOptions?.advanced?.normalize_audio?.impact" class="text-sm text-gray-500">
                                                {{ props.audioExtractionOptions.advanced.normalize_audio.impact }}
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-6">
                                        <InputLabel for="volume_boost" value="Volume Boost (%)" />
                                        <input
                                            id="volume_boost"
                                            v-model="form.configuration.audio.volume_boost"
                                            type="range"
                                            min="0"
                                            max="100"
                                            step="5"
                                            class="mt-1 block w-full"
                                        />
                                        <div class="flex justify-between text-xs text-gray-500">
                                            <span>0% (No boost)</span>
                                            <span class="font-medium">{{ form.configuration.audio.volume_boost }}%</span>
                                            <span>100% (Double volume)</span>
                                        </div>
                                        <p v-if="props.audioExtractionOptions?.advanced?.volume_boost?.impact" class="mt-1 text-sm text-gray-500">
                                            {{ props.audioExtractionOptions.advanced.volume_boost.impact }}
                                        </p>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div v-if="hasAudioExtractionOptions && props.audioExtractionOptions.advanced.low_pass">
                                            <InputLabel for="low_pass" value="Low-Pass Filter (Hz)" />
                                            <input
                                                id="low_pass"
                                                v-model="form.configuration.audio.low_pass"
                                                type="number"
                                                :min="props.audioExtractionOptions.advanced.low_pass.range?.[0] || 100"
                                                :max="props.audioExtractionOptions.advanced.low_pass.range?.[1] || 20000"
                                                step="100"
                                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                placeholder="Leave empty to disable"
                                            />
                                            <p class="mt-1 text-sm text-gray-500">
                                                {{ props.audioExtractionOptions.advanced.low_pass.impact }}
                                            </p>
                                        </div>
                                        
                                        <div v-if="hasAudioExtractionOptions && props.audioExtractionOptions.advanced.high_pass">
                                            <InputLabel for="high_pass" value="High-Pass Filter (Hz)" />
                                            <input
                                                id="high_pass"
                                                v-model="form.configuration.audio.high_pass"
                                                type="number"
                                                :min="props.audioExtractionOptions.advanced.high_pass.range?.[0] || 20"
                                                :max="props.audioExtractionOptions.advanced.high_pass.range?.[1] || 2000"
                                                step="10"
                                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                placeholder="Leave empty to disable"
                                            />
                                            <p class="mt-1 text-sm text-gray-500">
                                                {{ props.audioExtractionOptions.advanced.high_pass.impact }}
                                            </p>
                                        </div>
                                    </div>
                                </Card>
                            </div>

                            <!-- Terminology Tab -->
                            <div v-if="activeTab === 'terminology'">
                                <Card title="Terminology Recognition Settings">
                                    <p class="text-sm text-gray-500 mb-4">
                                        Configure how terminology and concepts are extracted from transcripts.
                                    </p>
                                    
                                    <div class="mb-4">
                                        <InputLabel for="extraction_method" value="Extraction Method" />
                                        <select
                                            id="extraction_method"
                                            v-model="form.configuration.terminology.extraction_method"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        >
                                            <option value="regex">Regex Pattern Matching</option>
                                            <option value="spacy">spaCy NLP Processing</option>
                                            <option value="hybrid">Hybrid (Both Methods)</option>
                                        </select>
                                        <p class="mt-1 text-sm text-gray-500">
                                            Regex is faster for exact matches, spaCy provides better linguistic understanding, hybrid uses both approaches.
                                        </p>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <div class="flex items-center mb-2">
                                                <Checkbox 
                                                    id="case_sensitive" 
                                                    v-model:checked="form.configuration.terminology.case_sensitive" 
                                                    name="case_sensitive" 
                                                />
                                                <InputLabel for="case_sensitive" value="Case Sensitive Matching" class="ml-2" />
                                            </div>
                                            <p class="text-sm text-gray-500">
                                                When enabled, "AWS" and "aws" would be considered different terms.
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <InputLabel for="min_term_frequency" value="Minimum Term Frequency" />
                                            <input 
                                                type="number"
                                                id="min_term_frequency"
                                                v-model="form.configuration.terminology.min_term_frequency"
                                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                min="1"
                                                max="10"
                                                step="1"
                                            />
                                            <p class="mt-1 text-sm text-gray-500">
                                                Minimum number of occurrences required to include a term.
                                            </p>
                                        </div>
                                    </div>
                                </Card>
                                
                                <Card title="Advanced Terminology Options" v-if="form.configuration.terminology.extraction_method !== 'regex'">
                                    <div class="mb-4">
                                        <InputLabel for="spacy_model" value="spaCy Model" />
                                        <select
                                            id="spacy_model"
                                            v-model="form.configuration.terminology.spacy_model"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        >
                                            <option value="en_core_web_sm">Small (Faster)</option>
                                            <option value="en_core_web_md">Medium (Balanced)</option>
                                            <option value="en_core_web_lg">Large (More Accurate)</option>
                                        </select>
                                        <p class="mt-1 text-sm text-gray-500">
                                            Larger models have better accuracy but use more memory and are slower to process.
                                        </p>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <div class="flex items-center mb-2">
                                                <Checkbox 
                                                    id="use_lemmatization" 
                                                    v-model:checked="form.configuration.terminology.use_lemmatization" 
                                                    name="use_lemmatization" 
                                                />
                                                <InputLabel for="use_lemmatization" value="Use Lemmatization" class="ml-2" />
                                            </div>
                                            <p class="text-sm text-gray-500">
                                                When enabled, different forms of a word (e.g., "running", "runs") are treated as the same term ("run").
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <div class="flex items-center mb-2">
                                                <Checkbox 
                                                    id="include_uncategorized" 
                                                    v-model:checked="form.configuration.terminology.include_uncategorized" 
                                                    name="include_uncategorized" 
                                                />
                                                <InputLabel for="include_uncategorized" value="Include Uncategorized Terms" class="ml-2" />
                                            </div>
                                            <p class="text-sm text-gray-500">
                                                When enabled, extracts potentially relevant terms even if they don't match predefined categories.
                                            </p>
                                        </div>
                                    </div>
                                </Card>
                            </div>
                        </template>
                    </Tabs>

                    <div class="flex items-center justify-end mt-6">
                        <Link :href="route('admin.job-presets.index')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 mr-2">
                            Cancel
                        </Link>
                        <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing" @click="submit">
                            Update Preset
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 