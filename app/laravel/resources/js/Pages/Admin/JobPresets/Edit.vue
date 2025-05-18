<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    preset: Object,
    modelOptions: Array,
    languageOptions: Array
});

const form = useForm({
    name: props.preset.name,
    description: props.preset.description || '',
    model: props.preset.model,
    language: props.preset.language || '',
    options: props.preset.options || {
        timestamps: true,
        diarization: false,
        comprehensiveTimestamps: false,
        temperature: 0,
        promptBoost: '',
    },
    is_default: props.preset.is_default,
    is_active: props.preset.is_active,
});

const submit = () => {
    form.put(route('admin.job-presets.update', props.preset.id));
};
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
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <!-- Basic Information Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
                            
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
                        </div>
                        
                        <!-- Model & Language Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Transcription Settings</h3>
                            
                            <div class="mb-4">
                                <InputLabel for="model" value="Whisper Model" />
                                <select
                                    id="model"
                                    v-model="form.model"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                >
                                    <option v-for="option in modelOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.model" class="mt-2" />
                                <p class="mt-1 text-sm text-gray-500">
                                    Larger models provide better accuracy but take longer to process.
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
                                <p class="mt-1 text-sm text-gray-500">
                                    Auto-detect will identify the language automatically, but specifying a language may improve accuracy.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Advanced Options Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Advanced Options</h3>
                            
                            <div class="mb-2 flex items-center">
                                <Checkbox id="timestamps" v-model:checked="form.options.timestamps" name="timestamps" />
                                <InputLabel for="timestamps" value="Generate timestamps" class="ml-2" />
                            </div>
                            
                            <div class="mb-2 flex items-center">
                                <Checkbox id="diarization" v-model:checked="form.options.diarization" name="diarization" />
                                <InputLabel for="diarization" value="Speaker diarization (identify different speakers)" class="ml-2" />
                            </div>
                            
                            <div class="mb-2 flex items-center">
                                <Checkbox id="comprehensiveTimestamps" v-model:checked="form.options.comprehensiveTimestamps" name="comprehensiveTimestamps" />
                                <InputLabel for="comprehensiveTimestamps" value="Comprehensive timestamps (timestamp every word)" class="ml-2" />
                            </div>
                            
                            <div class="mt-4 mb-2">
                                <InputLabel for="temperature" value="Temperature" />
                                <input
                                    id="temperature"
                                    v-model="form.options.temperature"
                                    type="range"
                                    min="0"
                                    max="1"
                                    step="0.1"
                                    class="mt-1 block w-full"
                                />
                                <div class="flex justify-between text-xs text-gray-500">
                                    <span>0 (More precise)</span>
                                    <span class="font-medium">{{ form.options.temperature }}</span>
                                    <span>1 (More creative)</span>
                                </div>
                            </div>
                            
                            <div class="mt-4 mb-2">
                                <InputLabel for="promptBoost" value="Prompt Boost (Optional)" />
                                <textarea
                                    id="promptBoost"
                                    v-model="form.options.promptBoost"
                                    rows="2"
                                    placeholder="E.g., 'This video is about guitar techniques.'"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                ></textarea>
                                <p class="mt-1 text-sm text-gray-500">
                                    Provide context about the content to improve transcription quality.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Status Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Status</h3>
                            
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
                        </div>
                        
                        <div class="flex items-center justify-end mt-6">
                            <Link :href="route('admin.job-presets.index')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 mr-2">
                                Cancel
                            </Link>
                            <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing" @click="submit">
                                Update Preset
                            </PrimaryButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 