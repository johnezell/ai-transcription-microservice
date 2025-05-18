<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    courses: Array,
    presets: Array,
    defaultPresetId: Number
});

const form = useForm({
    videos: [],
    course_id: '',
    lesson_number_start: 1,
    preset_id: props.defaultPresetId || ''
});

// Wizard state
const currentStep = ref(1);
const totalSteps = 3;
const stepTitles = [
    'Select Videos',
    'Configure Options',
    'Upload & Process'
];

// UI state
const fileInput = ref(null);
const previews = ref([]);
const isDragging = ref(false);
const uploadProgress = ref(0);
const isUploading = ref(false);
const uploadCompletedCount = ref(0);

// Determine if we can move to the next step
const canProceedToStep2 = computed(() => form.videos.length > 0);
const canProceedToStep3 = computed(() => {
    // If a course is selected, validate the lesson number
    if (form.course_id) {
        return form.lesson_number_start > 0;
    }
    return true;
});

// Compute total file size
const totalSize = ref(0);
const updateTotalSize = () => {
    totalSize.value = form.videos.reduce((sum, file) => sum + file.size, 0);
};

// Navigation functions
const nextStep = () => {
    if (currentStep.value < totalSteps) {
        currentStep.value++;
    }
};

const prevStep = () => {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
};

// Format upload time remaining
const timeRemaining = ref(null);
const updateTimeRemaining = (progress) => {
    if (progress <= 0 || progress >= 100) {
        timeRemaining.value = null;
        return;
    }
    
    const elapsedTime = Date.now() - uploadStartTime;
    const estimatedTotalTime = (elapsedTime / progress) * 100;
    const remainingTime = estimatedTotalTime - elapsedTime;
    
    if (remainingTime <= 0) {
        timeRemaining.value = 'Almost done...';
        return;
    }
    
    // Format the remaining time
    const minutes = Math.floor(remainingTime / 60000);
    const seconds = Math.floor((remainingTime % 60000) / 1000);
    
    if (minutes > 0) {
        timeRemaining.value = `${minutes}m ${seconds}s remaining`;
    } else {
        timeRemaining.value = `${seconds}s remaining`;
    }
};

let uploadStartTime = 0;

const submit = () => {
    isUploading.value = true;
    uploadStartTime = Date.now();
    
    // Create form data to append multiple files
    const formData = new FormData();
    
    // Add each video file
    form.videos.forEach((file, index) => {
        formData.append(`videos[${index}]`, file);
    });
    
    // Add course and lesson info if applicable
    if (form.course_id) {
        formData.append('course_id', form.course_id);
        formData.append('lesson_number_start', form.lesson_number_start);
    }
    
    // Use Inertia's post method but with FormData
    form.post(route('videos.store'), {
        preserveScroll: true,
        forceFormData: true,
        data: formData,
        onProgress: (progress) => {
            uploadProgress.value = progress.percentage;
            updateTimeRemaining(progress.percentage);
        },
        onSuccess: () => {
            form.reset();
            previews.value = [];
            isUploading.value = false;
            uploadProgress.value = 100;
            uploadCompletedCount.value = previews.value.length;
            totalSize.value = 0;
        },
        onError: () => {
            isUploading.value = false;
            timeRemaining.value = null;
        }
    });
};

const handleFileInput = (e) => {
    const files = Array.from(e.target.files);
    if (files.length > 0) {
        handleFiles(files);
    }
};

const handleFiles = (files) => {
    // Check if they're video files and add them
    files.forEach(file => {
        if (file.type.match('video.*')) {
            form.videos.push(file);
            
            // Create a preview
            previews.value.push({
                url: URL.createObjectURL(file),
                name: file.name,
                size: file.size
            });
        }
    });
    
    // Update total size
    updateTotalSize();
};

const onDrop = (e) => {
    e.preventDefault();
    isDragging.value = false;
    
    if (e.dataTransfer.files.length > 0) {
        handleFiles(Array.from(e.dataTransfer.files));
    }
};

const onDragOver = (e) => {
    e.preventDefault();
    isDragging.value = true;
};

const onDragLeave = () => {
    isDragging.value = false;
};

const removeFile = (index) => {
    // Revoke object URL to prevent memory leaks
    URL.revokeObjectURL(previews.value[index].url);
    
    // Remove from arrays
    previews.value.splice(index, 1);
    form.videos.splice(index, 1);
    
    // Update total size
    updateTotalSize();
};

const resetAll = () => {
    // Revoke all object URLs
    previews.value.forEach(preview => {
        URL.revokeObjectURL(preview.url);
    });
    
    // Reset form and previews
    form.reset();
    previews.value = [];
    totalSize.value = 0;
    
    // Reset the file input
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

// Restart the upload process
const resetUpload = () => {
    resetAll();
    currentStep.value = 1;
    isUploading.value = false;
    uploadProgress.value = 0;
    uploadCompletedCount.value = 0;
    timeRemaining.value = null;
};

// Format file sizes
const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};
</script>

<template>
    <Head title="Upload Videos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Upload Videos</h2>
                <Link :href="route('videos.index')" class="px-4 py-2 bg-gray-800 text-white rounded-md">
                    Back to Videos
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <!-- Stepper component -->
                    <div class="px-6 pt-6">
                        <div class="mb-8">
                            <div class="flex items-center justify-between">
                                <div v-for="step in totalSteps" :key="step" class="flex-1 relative">
                                    <!-- Step connector line -->
                                    <div v-if="step !== totalSteps" class="absolute top-1/2 w-full h-0.5" :class="step < currentStep ? 'bg-indigo-600' : 'bg-gray-300'"></div>
                                    
                                    <!-- Step circle -->
                                    <div class="relative flex items-center justify-center">
                                        <div 
                                            class="w-10 h-10 rounded-full flex items-center justify-center z-10"
                                            :class="[
                                                step < currentStep ? 'bg-indigo-600 text-white' : 
                                                step === currentStep ? 'bg-indigo-100 text-indigo-600 border-2 border-indigo-600' : 
                                                'bg-white text-gray-400 border-2 border-gray-300'
                                            ]"
                                        >
                                            <span v-if="step < currentStep" class="text-lg">✓</span>
                                            <span v-else>{{ step }}</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Step title -->
                                    <div class="mt-2 text-center">
                                        <p class="text-xs font-medium" :class="step <= currentStep ? 'text-indigo-600' : 'text-gray-500'">
                                            {{ stepTitles[step-1] }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-6 text-gray-900">
                        <!-- Step 1: Select Videos -->
                        <div v-if="currentStep === 1">
                            <h3 class="text-lg font-medium mb-4">Step 1: Select Videos</h3>
                            
                            <div>
                                <InputLabel for="videos" value="Video Files" />
                                
                                <div
                                    class="mt-2 flex justify-center rounded-md border-2 border-dashed px-6 pt-5 pb-6"
                                    :class="[
                                        isDragging ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300',
                                        form.errors.videos ? 'border-red-300' : ''
                                    ]"
                                    @dragover="onDragOver"
                                    @dragleave="onDragLeave"
                                    @drop="onDrop"
                                >
                                    <div class="space-y-1 text-center">
                                        <div v-if="form.videos.length === 0">
                                            <svg
                                                class="mx-auto h-12 w-12 text-gray-400"
                                                stroke="currentColor"
                                                fill="none"
                                                viewBox="0 0 48 48"
                                            >
                                                <path
                                                    d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02"
                                                    stroke-width="2"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                            <div class="flex text-sm text-gray-600 justify-center">
                                                <label
                                                    for="videos"
                                                    class="relative cursor-pointer rounded-md font-medium text-indigo-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-indigo-500 focus-within:ring-offset-2 hover:text-indigo-500"
                                                >
                                                    <span>Upload files</span>
                                                    <input
                                                        id="videos"
                                                        ref="fileInput"
                                                        name="videos"
                                                        type="file"
                                                        class="sr-only"
                                                        accept="video/*"
                                                        multiple
                                                        @change="handleFileInput"
                                                    />
                                                </label>
                                                <p class="pl-1">or drag and drop</p>
                                            </div>
                                            <p class="text-xs text-gray-500">
                                                MP4, MOV, or MPEG files. Multiple files allowed.
                                            </p>
                                        </div>
                                        
                                        <div v-else class="w-full">
                                            <div class="mb-4 text-center">
                                                <span class="text-sm font-medium text-gray-700">{{ form.videos.length }} videos selected</span>
                                                <span class="ml-2 text-xs text-gray-500">({{ formatFileSize(totalSize) }} total)</span>
                                            </div>
                                            
                                            <div class="grid grid-cols-2 gap-4 mb-4">
                                                <div 
                                                    v-for="(preview, index) in previews" 
                                                    :key="index"
                                                    class="border rounded p-2"
                                                >
                                                    <video 
                                                        :src="preview.url" 
                                                        controls 
                                                        class="w-full h-24 object-cover"
                                                    ></video>
                                                    
                                                    <div class="mt-2 text-xs text-gray-700 truncate" :title="preview.name">
                                                        {{ preview.name }}
                                                    </div>
                                                    
                                                    <div class="flex items-center justify-between mt-1">
                                                        <span class="text-xs text-gray-500">{{ formatFileSize(preview.size) }}</span>
                                                        <button 
                                                            type="button" 
                                                            class="text-xs text-red-600" 
                                                            @click="removeFile(index)"
                                                        >
                                                            Remove
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="flex justify-center">
                                                <label
                                                    for="add-more"
                                                    class="cursor-pointer rounded-md px-3 py-1 text-sm border border-gray-300 text-gray-700 hover:bg-gray-50"
                                                >
                                                    Add More Files
                                                    <input
                                                        id="add-more"
                                                        type="file"
                                                        class="sr-only"
                                                        accept="video/*"
                                                        multiple
                                                        @change="handleFileInput"
                                                    />
                                                </label>
                                                
                                                <button 
                                                    type="button" 
                                                    class="ml-3 px-3 py-1 text-sm border border-red-300 text-red-600 rounded-md hover:bg-red-50" 
                                                    @click="resetAll"
                                                >
                                                    Remove All
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <InputError :message="form.errors.videos" class="mt-2" />
                            </div>
                            
                            <div class="flex items-center justify-end mt-6">
                                <PrimaryButton
                                    :disabled="!canProceedToStep2"
                                    @click="nextStep"
                                >
                                    Continue
                                </PrimaryButton>
                            </div>
                        </div>
                        
                        <!-- Step 2: Configure Options -->
                        <div v-if="currentStep === 2">
                            <h3 class="text-lg font-medium mb-4">Step 2: Configure Options</h3>
                            
                            <!-- Course selection -->
                            <div class="mb-6">
                                <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-blue-700">
                                                Selected: <strong>{{ form.videos.length }} videos</strong> ({{ formatFileSize(totalSize) }} total)
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <InputLabel for="course_id" value="Assign to Course (Optional)" />
                                
                                <div class="mt-2">
                                    <select
                                        id="course_id"
                                        v-model="form.course_id"
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="">No course (select later)</option>
                                        <option v-for="course in courses" :key="course.id" :value="course.id">
                                            {{ course.name }}
                                        </option>
                                    </select>
                                </div>
                                
                                <div class="mt-4" v-if="form.course_id">
                                    <InputLabel for="lesson_number_start" value="Starting Lesson Number" />
                                    <input
                                        id="lesson_number_start"
                                        v-model="form.lesson_number_start"
                                        type="number"
                                        min="1"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    <p class="mt-1 text-xs text-gray-500">
                                        Multiple videos will be assigned sequential lesson numbers starting from this value.
                                    </p>
                                </div>
                                
                                <InputError :message="form.errors.course_id" class="mt-2" />
                            </div>
                            
                            <!-- Preset selection -->
                            <div class="mb-6">
                                <InputLabel for="preset_id" value="Transcription Preset" />
                                
                                <div class="mt-2">
                                    <select
                                        id="preset_id"
                                        v-model="form.preset_id"
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="">Use default preset</option>
                                        <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                            {{ preset.name }} ({{ preset.model }})
                                        </option>
                                    </select>
                                </div>
                                
                                <div v-if="form.preset_id" class="mt-2">
                                    <div class="bg-gray-50 p-3 rounded-md">
                                        <div class="text-sm text-gray-700">
                                            <span class="font-medium">Selected preset:</span>
                                            {{ presets.find(p => p.id == form.preset_id)?.name }}
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <span class="font-medium">Model:</span>
                                            {{ presets.find(p => p.id == form.preset_id)?.model }}
                                        </div>
                                        <div v-if="presets.find(p => p.id == form.preset_id)?.description" class="text-xs text-gray-500 mt-1">
                                            <span class="font-medium">Description:</span>
                                            {{ presets.find(p => p.id == form.preset_id)?.description }}
                                        </div>
                                    </div>
                                </div>
                                
                                <p class="mt-1 text-xs text-gray-500">
                                    Select a transcription preset to control how your video is transcribed.
                                </p>
                                
                                <InputError :message="form.errors.preset_id" class="mt-2" />
                            </div>
                            
                            <div class="flex items-center justify-between mt-6">
                                <button
                                    type="button"
                                    class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
                                    @click="prevStep"
                                >
                                    Back
                                </button>
                                
                                <PrimaryButton
                                    :disabled="!canProceedToStep3"
                                    @click="nextStep"
                                >
                                    Continue to Upload
                                </PrimaryButton>
                            </div>
                        </div>
                        
                        <!-- Step 3: Upload & Process -->
                        <div v-if="currentStep === 3">
                            <h3 class="text-lg font-medium mb-4">Step 3: Upload & Process</h3>
                            
                            <div v-if="!isUploading && uploadProgress === 0" class="mb-8">
                                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-yellow-700">
                                                You're about to upload {{ form.videos.length }} videos ({{ formatFileSize(totalSize) }}).
                                                {{ form.course_id ? 'They will be assigned to the selected course.' : 'They will not be assigned to any course.' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-gray-50 p-6 rounded-lg">
                                    <h4 class="text-base font-medium text-gray-900 mb-4">Upload Summary</h4>
                                    
                                    <ul class="space-y-3">
                                        <li class="flex justify-between">
                                            <span class="text-gray-600">Number of Videos:</span>
                                            <span class="font-medium">{{ form.videos.length }}</span>
                                        </li>
                                        <li class="flex justify-between">
                                            <span class="text-gray-600">Total Size:</span>
                                            <span class="font-medium">{{ formatFileSize(totalSize) }}</span>
                                        </li>
                                        <li class="flex justify-between">
                                            <span class="text-gray-600">Course Assignment:</span>
                                            <span class="font-medium">{{ form.course_id ? courses.find(c => c.id == form.course_id)?.name : 'None' }}</span>
                                        </li>
                                        <li v-if="form.course_id" class="flex justify-between">
                                            <span class="text-gray-600">Starting Lesson #:</span>
                                            <span class="font-medium">{{ form.lesson_number_start }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            
                            <div v-if="isUploading || uploadProgress > 0" class="mb-8">
                                <div class="mb-6">
                                    <div class="flex justify-between mb-2">
                                        <span class="text-sm font-medium text-gray-700">Upload Progress</span>
                                        <span class="text-sm font-medium text-gray-700">{{ Math.round(uploadProgress) }}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                                        <div 
                                            class="h-full rounded-full transition-all duration-300 ease-in-out"
                                            :class="uploadProgress === 100 ? 'bg-green-600' : 'bg-blue-600'"
                                            :style="{ width: uploadProgress + '%' }"
                                        ></div>
                                    </div>
                                    <p v-if="timeRemaining" class="text-xs text-gray-500 mt-1">{{ timeRemaining }}</p>
                                </div>
                                
                                <div class="bg-gray-50 p-6 rounded-lg" v-if="uploadProgress === 100">
                                    <div class="flex items-center mb-4">
                                        <div class="flex-shrink-0">
                                            <svg class="h-8 w-8 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <h4 class="text-lg font-medium text-gray-900">Upload Complete!</h4>
                                            <p class="text-sm text-gray-600 mt-1">
                                                Your videos have been uploaded and are now being processed. This may take a few minutes depending on video size.
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex space-x-4 mt-6">
                                        <Link 
                                            :href="route('videos.index')" 
                                            class="flex-1 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                        >
                                            View All Videos
                                        </Link>
                                        <button
                                            type="button"
                                            class="flex-1 inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                            @click="resetUpload"
                                        >
                                            Upload More Videos
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mt-6" v-if="isUploading && uploadProgress < 100">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-blue-700">
                                                Please keep this window open until the upload completes. Closing the window may interrupt the upload process.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between mt-6" v-if="!isUploading && uploadProgress === 0">
                                <button
                                    type="button"
                                    class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
                                    @click="prevStep"
                                >
                                    Back
                                </button>
                                
                                <PrimaryButton
                                    :disabled="form.videos.length === 0 || isUploading || form.processing"
                                    @click="submit"
                                >
                                    {{ form.videos.length > 1 ? 'Upload Videos' : 'Upload Video' }}
                                </PrimaryButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 