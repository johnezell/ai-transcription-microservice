<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    courses: Array
});

const form = useForm({
    videos: [],
    course_id: '',
    lesson_number_start: 1
});

const fileInput = ref(null);
const previews = ref([]);
const isDragging = ref(false);
const uploadProgress = ref(0);
const isUploading = ref(false);

// Compute total file size
const totalSize = ref(0);
const updateTotalSize = () => {
    totalSize.value = form.videos.reduce((sum, file) => sum + file.size, 0);
};

const submit = () => {
    isUploading.value = true;
    
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
        },
        onSuccess: () => {
            form.reset();
            previews.value = [];
            isUploading.value = false;
            uploadProgress.value = 0;
            totalSize.value = 0;
        },
        onError: () => {
            isUploading.value = false;
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
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <form @submit.prevent="submit" class="max-w-2xl mx-auto">
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
                                            <div class="flex text-sm text-gray-600">
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
                            
                            <!-- Course selection -->
                            <div class="mt-6" v-if="courses && courses.length > 0">
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
                            
                            <div v-if="isUploading" class="mt-6">
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-blue-600 h-2.5 rounded-full" :style="{ width: uploadProgress + '%' }"></div>
                                </div>
                                <p class="text-sm text-gray-500 text-center mt-2">Uploading: {{ Math.round(uploadProgress) }}%</p>
                            </div>
                            
                            <div class="flex items-center justify-end mt-6">
                                <PrimaryButton
                                    class="ml-4"
                                    :disabled="form.videos.length === 0 || isUploading || form.processing"
                                >
                                    {{ form.videos.length > 1 ? 'Upload Videos' : 'Upload Video' }}
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 