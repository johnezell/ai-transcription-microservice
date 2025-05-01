<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const form = useForm({
    video: null,
});

const fileInput = ref(null);
const previewUrl = ref(null);
const isDragging = ref(false);
const uploadProgress = ref(0);
const isUploading = ref(false);

const submit = () => {
    isUploading.value = true;
    form.post(route('videos.store'), {
        preserveScroll: true,
        onProgress: (progress) => {
            uploadProgress.value = progress.percentage;
        },
        onSuccess: () => {
            form.reset();
            previewUrl.value = null;
            isUploading.value = false;
            uploadProgress.value = 0;
        },
        onError: () => {
            isUploading.value = false;
        }
    });
};

const handleFileInput = (e) => {
    const file = e.target.files[0];
    if (file) {
        handleFile(file);
    }
};

const handleFile = (file) => {
    // Check if it's a video file
    if (file.type.match('video.*')) {
        form.video = file;
        
        // Create a preview
        previewUrl.value = URL.createObjectURL(file);
    } else {
        alert('Please select a video file.');
        resetFile();
    }
};

const onDrop = (e) => {
    e.preventDefault();
    isDragging.value = false;
    
    if (e.dataTransfer.files.length > 0) {
        handleFile(e.dataTransfer.files[0]);
    }
};

const onDragOver = (e) => {
    e.preventDefault();
    isDragging.value = true;
};

const onDragLeave = () => {
    isDragging.value = false;
};

const resetFile = () => {
    form.video = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
};
</script>

<template>
    <Head title="Upload Video" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Upload Video</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <form @submit.prevent="submit" class="max-w-2xl mx-auto">
                            <div>
                                <InputLabel for="video" value="Video File" />
                                
                                <div
                                    class="mt-2 flex justify-center rounded-md border-2 border-dashed px-6 pt-5 pb-6"
                                    :class="[
                                        isDragging ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300',
                                        form.errors.video ? 'border-red-300' : ''
                                    ]"
                                    @dragover="onDragOver"
                                    @dragleave="onDragLeave"
                                    @drop="onDrop"
                                >
                                    <div class="space-y-1 text-center">
                                        <div v-if="!form.video && !previewUrl">
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
                                            <div class="flex text-sm text-gray-600">
                                                <label
                                                    for="video"
                                                    class="relative cursor-pointer rounded-md font-medium text-indigo-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-indigo-500 focus-within:ring-offset-2 hover:text-indigo-500"
                                                >
                                                    <span>Upload a file</span>
                                                    <input
                                                        id="video"
                                                        ref="fileInput"
                                                        name="video"
                                                        type="file"
                                                        class="sr-only"
                                                        accept="video/*"
                                                        @change="handleFileInput"
                                                    />
                                                </label>
                                                <p class="pl-1">or drag and drop</p>
                                            </div>
                                            <p class="text-xs text-gray-500">
                                                MP4, MOV, or MPEG files
                                            </p>
                                        </div>
                                        
                                        <div v-else class="w-full">
                                            <video 
                                                v-if="previewUrl" 
                                                :src="previewUrl" 
                                                controls 
                                                class="mx-auto max-h-60"
                                            ></video>
                                            
                                            <div class="mt-3 flex items-center justify-between">
                                                <div class="text-sm text-gray-500">
                                                    {{ form.video ? form.video.name : '' }}
                                                    <span v-if="form.video">
                                                        ({{ formatFileSize(form.video.size) }})
                                                    </span>
                                                </div>
                                                <button 
                                                    type="button" 
                                                    class="text-sm text-red-600" 
                                                    @click="resetFile"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <InputError :message="form.errors.video" class="mt-2" />
                            </div>
                            
                            <div v-if="isUploading" class="mt-6">
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-blue-600 h-2.5 rounded-full" :style="{ width: uploadProgress + '%' }"></div>
                                </div>
                                <p class="text-sm text-gray-500 text-center mt-2">Uploading: {{ Math.round(uploadProgress) }}%</p>
                            </div>
                            
                            <div class="flex items-center justify-end mt-6">
                                <PrimaryButton
                                    class="ml-4"
                                    :disabled="!form.video || isUploading || form.processing"
                                >
                                    Upload
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script>
export default {
    methods: {
        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    }
}
</script> 