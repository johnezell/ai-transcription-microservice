<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    currentBrand: String,
    brands: Object,
    videosWithTranscripts: Array,
});

const selectedVideoId = ref(null);
const transcriptText = ref('');
const youtubeUrl = ref('');
const truefireUrl = ref('');
const useWhisper = ref(true);
const videoFile = ref(null);
const videoFileName = ref('');
const uploadProgress = ref(0);
const processing = ref(false);
const processingType = ref(null);
const errorMessage = ref(null);
const dragActive = ref(false);

const currentBrandData = computed(() => props.brands[props.currentBrand] || props.brands.truefire);

// Generate from Thoth video transcript
const generateFromVideo = async () => {
    if (!selectedVideoId.value) return;
    
    processing.value = true;
    processingType.value = 'video';
    errorMessage.value = null;
    
    try {
        const response = await fetch('/api/articles/from-video', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                videoId: selectedVideoId.value,
                brandId: props.currentBrand,
                userName: 'Thoth User',
            }),
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || 'Failed to generate article');
        }
        
        router.visit(`/articles/${data.id}`);
    } catch (e) {
        errorMessage.value = e.message;
    } finally {
        processing.value = false;
        processingType.value = null;
    }
};

// Generate from raw transcript text
const generateFromTranscript = async () => {
    if (!transcriptText.value || transcriptText.value.length < 100) {
        errorMessage.value = 'Transcript must be at least 100 characters';
        return;
    }
    
    processing.value = true;
    processingType.value = 'transcript';
    errorMessage.value = null;
    
    try {
        const response = await fetch('/api/articles/from-transcript', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transcript: transcriptText.value,
                brandId: props.currentBrand,
                userName: 'Thoth User',
            }),
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || 'Failed to generate article');
        }
        
        router.visit(`/articles/${data.id}`);
    } catch (e) {
        errorMessage.value = e.message;
    } finally {
        processing.value = false;
        processingType.value = null;
    }
};

// Generate from YouTube URL
const generateFromYoutube = async () => {
    if (!youtubeUrl.value) {
        errorMessage.value = 'Please enter a YouTube URL';
        return;
    }
    
    if (!youtubeUrl.value.includes('youtube.com') && !youtubeUrl.value.includes('youtu.be')) {
        errorMessage.value = 'Please enter a valid YouTube URL';
        return;
    }
    
    processing.value = true;
    processingType.value = 'youtube';
    errorMessage.value = null;
    
    try {
        const response = await fetch('/api/articles/from-youtube', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                youtubeUrl: youtubeUrl.value,
                brandId: props.currentBrand,
                userName: 'Thoth User',
                useWhisper: useWhisper.value,
            }),
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || 'Failed to process YouTube video');
        }
        
        router.visit(`/articles/${data.id}`);
    } catch (e) {
        errorMessage.value = e.message;
    } finally {
        processing.value = false;
        processingType.value = null;
    }
};

// Generate from TrueFire URL or segment ID
// Accepts: "2228", "v2228", or full URL "https://truefire.com/.../v2228"
const generateFromTruefire = async () => {
    if (!truefireUrl.value) {
        errorMessage.value = 'Please enter a TrueFire URL or segment ID';
        return;
    }
    
    const input = truefireUrl.value.trim();
    let segmentId = null;
    let sourceUrl = null;
    
    // Check if it's just a number (segment ID)
    if (/^\d+$/.test(input)) {
        segmentId = input;
    }
    // Check if it's "v####" format
    else if (/^v\d+$/i.test(input)) {
        segmentId = input.substring(1);
    }
    // Check if it's a URL containing v####
    else if (input.includes('truefire.com') || input.includes('/v')) {
        const match = input.match(/\/v(\d+)(?:$|[?#/])/);
        if (match) {
            segmentId = match[1];
            sourceUrl = input;
        }
    }
    
    if (!segmentId) {
        errorMessage.value = 'Invalid input. Enter a segment ID (e.g., 2228) or TrueFire URL';
        return;
    }
    
    processing.value = true;
    processingType.value = 'truefire';
    errorMessage.value = null;
    
    try {
        const response = await fetch('/api/articles/from-truefire', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                segmentId: segmentId,
                sourceUrl: sourceUrl,
                brandId: props.currentBrand,
                userName: 'Thoth User',
            }),
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || 'Failed to process TrueFire video');
        }
        
        router.visit(`/articles/${data.id}`);
    } catch (e) {
        errorMessage.value = e.message;
    } finally {
        processing.value = false;
        processingType.value = null;
    }
};

// Handle video file selection
const handleFileSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        if (!file.type.startsWith('video/')) {
            errorMessage.value = 'Please select a video file';
            return;
        }
        if (file.size > 500 * 1024 * 1024) {
            errorMessage.value = 'Video file must be less than 500MB';
            return;
        }
        videoFile.value = file;
        videoFileName.value = file.name;
        errorMessage.value = null;
    }
};

// Handle drag and drop
const handleDrop = (event) => {
    dragActive.value = false;
    const file = event.dataTransfer.files[0];
    if (file && file.type.startsWith('video/')) {
        if (file.size > 500 * 1024 * 1024) {
            errorMessage.value = 'Video file must be less than 500MB';
            return;
        }
        videoFile.value = file;
        videoFileName.value = file.name;
        errorMessage.value = null;
    }
};

const clearFile = () => {
    videoFile.value = null;
    videoFileName.value = '';
};

// Generate from uploaded video file
const generateFromUpload = async () => {
    if (!videoFile.value) {
        errorMessage.value = 'Please select a video file';
        return;
    }
    
    processing.value = true;
    processingType.value = 'upload';
    errorMessage.value = null;
    uploadProgress.value = 0;
    
    try {
        const formData = new FormData();
        formData.append('video', videoFile.value);
        formData.append('brandId', props.currentBrand);
        formData.append('userName', 'Thoth User');
        formData.append('generateArticle', 'true');
        
        const xhr = new XMLHttpRequest();
        
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                uploadProgress.value = Math.round((e.loaded / e.total) * 100);
            }
        });
        
        const response = await new Promise((resolve, reject) => {
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(JSON.parse(xhr.responseText));
                } else {
                    try {
                        const error = JSON.parse(xhr.responseText);
                        reject(new Error(error.error || 'Upload failed'));
                    } catch {
                        reject(new Error('Upload failed'));
                    }
                }
            };
            xhr.onerror = () => reject(new Error('Network error'));
            xhr.open('POST', '/api/articles/from-upload');
            xhr.send(formData);
        });
        
        router.visit(`/articles/${response.id}`);
    } catch (e) {
        errorMessage.value = e.message;
    } finally {
        processing.value = false;
        processingType.value = null;
        uploadProgress.value = 0;
    }
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};
</script>

<template>
    <Head title="Create Article" />

    <div class="min-h-screen bg-gray-50">
        <!-- Navigation Header -->
        <nav class="bg-white shadow-sm border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="route('articles.index', { brandId: currentBrand })"
                            class="text-gray-600 hover:text-gray-900"
                        >
                            ← Back to Articles
                        </Link>
                        <h1 class="text-2xl font-bold text-gray-900">Create New Article</h1>
                    </div>

                    <!-- Brand Display -->
                    <div class="flex items-center gap-3 px-3 py-1.5 bg-gray-100 rounded-lg">
                        <img
                            v-if="currentBrandData.logo"
                            :src="currentBrandData.logo"
                            :alt="currentBrandData.name"
                            class="h-5 object-contain"
                        />
                        <span class="text-sm font-medium text-gray-700">{{ currentBrandData.name }}</span>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
                <div class="space-y-6">
                    <!-- TrueFire URL Option -->
                    <div class="border-2 border-dashed border-orange-300 rounded-lg p-6 hover:border-orange-500 transition-colors bg-orange-50">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">
                            <span class="text-orange-600">🔥</span> Import from TrueFire
                        </h3>
                        <p class="text-sm text-gray-600 mb-4">
                            Enter a segment ID or paste a TrueFire lesson URL to transcribe the video.
                        </p>
                        <div class="space-y-4">
                            <input
                                v-model="truefireUrl"
                                type="text"
                                placeholder="2228 or https://truefire.com/.../v2228"
                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-orange-500 focus:border-orange-500"
                                :disabled="processing"
                            />
                            
                            <button
                                @click="generateFromTruefire"
                                :disabled="!truefireUrl || processing"
                                class="w-full inline-flex justify-center items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span v-if="processing && processingType === 'truefire'">
                                    Processing TrueFire Video...
                                </span>
                                <span v-else>
                                    Generate Article from TrueFire
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- YouTube URL Option -->
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 hover:border-blue-500 transition-colors">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Import from YouTube</h3>
                        <div class="space-y-4">
                            <input
                                v-model="youtubeUrl"
                                type="text"
                                placeholder="https://www.youtube.com/watch?v=..."
                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
                                :disabled="processing"
                            />
                            
                            <div class="flex items-center gap-4">
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        v-model="useWhisper"
                                        :disabled="processing"
                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    />
                                    <span>Use Whisper transcription</span>
                                    <span class="text-xs text-gray-500">(better quality, industry-optimized)</span>
                                </label>
                            </div>
                            
                            <button
                                @click="generateFromYoutube"
                                :disabled="!youtubeUrl || processing"
                                class="w-full inline-flex justify-center items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span v-if="processing && processingType === 'youtube'">
                                    Processing...
                                </span>
                                <span v-else>
                                    Generate Article from YouTube
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Video Upload Option -->
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 hover:border-blue-500 transition-colors">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Upload Video File</h3>
                        <div class="space-y-4">
                            <div
                                class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center"
                                @drop.prevent="handleDrop"
                                @dragover.prevent
                                @dragenter.prevent="dragActive = true"
                                @dragleave.prevent="dragActive = false"
                                :class="{ 'border-blue-500 bg-blue-50': dragActive }"
                            >
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="mt-4 flex text-sm text-gray-600 justify-center">
                                    <label class="relative cursor-pointer rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none">
                                        <span>Upload a file</span>
                                        <input
                                            type="file"
                                            class="sr-only"
                                            accept="video/*"
                                            @change="handleFileSelect"
                                            :disabled="processing"
                                        />
                                    </label>
                                    <p class="pl-1">or drag and drop</p>
                                </div>
                                <p class="text-xs text-gray-500 mt-2">MP4, MOV up to 500MB</p>
                            </div>

                            <div v-if="videoFileName" class="flex items-center justify-between bg-gray-50 p-4 rounded-lg">
                                <div class="flex items-center">
                                    <svg class="h-8 w-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    <span class="ml-2 text-sm text-gray-900">{{ videoFileName }}</span>
                                </div>
                                <button
                                    @click="clearFile"
                                    class="text-red-600 hover:text-red-800"
                                    :disabled="processing"
                                >
                                    Remove
                                </button>
                            </div>

                            <!-- Upload Progress Bar -->
                            <div v-if="processingType === 'upload' && uploadProgress > 0 && uploadProgress < 100" class="w-full bg-blue-200 rounded-full h-2.5">
                                <div
                                    class="bg-blue-600 h-2.5 rounded-full transition-all duration-300"
                                    :style="{ width: uploadProgress + '%' }"
                                ></div>
                                <p class="text-xs text-blue-700 mt-1 text-right">{{ uploadProgress }}%</p>
                            </div>

                            <button
                                @click="generateFromUpload"
                                :disabled="!videoFile || processing"
                                class="w-full inline-flex justify-center items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span v-if="processing && processingType === 'upload'">
                                    Uploading and Processing...
                                </span>
                                <span v-else>
                                    Generate Article from Video
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- From Existing Thoth Video -->
                    <div v-if="videosWithTranscripts && videosWithTranscripts.length > 0" class="border-2 border-dashed border-gray-300 rounded-lg p-6 hover:border-blue-500 transition-colors">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Use Existing Thoth Video</h3>
                        <p class="text-sm text-gray-600 mb-4">
                            Select a video that has already been transcribed in Thoth.
                        </p>
                        <div class="space-y-4">
                            <select
                                v-model="selectedVideoId"
                                :disabled="processing"
                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
                            >
                                <option :value="null">Select a video...</option>
                                <option v-for="video in videosWithTranscripts" :key="video.id" :value="video.id">
                                    {{ video.original_filename }} ({{ formatDate(video.created_at) }})
                                </option>
                            </select>
                            
                            <button
                                @click="generateFromVideo"
                                :disabled="!selectedVideoId || processing"
                                class="w-full inline-flex justify-center items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span v-if="processing && processingType === 'video'">
                                    Generating...
                                </span>
                                <span v-else>
                                    Generate Article from Video
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Raw Transcript Option -->
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 hover:border-blue-500 transition-colors">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Paste Raw Transcript</h3>
                        <p class="text-sm text-gray-600 mb-4">
                            Have a transcript ready? Paste it below to generate an article.
                        </p>
                        <div class="space-y-4">
                            <textarea
                                v-model="transcriptText"
                                :disabled="processing"
                                rows="8"
                                placeholder="Paste your transcript here (minimum 100 characters)..."
                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
                            ></textarea>
                            
                            <div class="text-sm text-gray-500 text-right">
                                {{ transcriptText.length }} characters
                            </div>
                            
                            <button
                                @click="generateFromTranscript"
                                :disabled="transcriptText.length < 100 || processing"
                                class="w-full inline-flex justify-center items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span v-if="processing && processingType === 'transcript'">
                                    Generating...
                                </span>
                                <span v-else>
                                    Generate Article from Transcript
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Processing Status -->
                <div v-if="processing" class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <div class="flex-shrink-0">
                            <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-blue-800">
                                {{ processingType === 'upload' && uploadProgress < 100 ? 'Uploading video...' : 'Processing your content' }}
                            </h3>
                            <p class="mt-1 text-sm text-blue-700">
                                <span v-if="processingType === 'upload' && uploadProgress < 100">
                                    Upload in progress...
                                </span>
                                <span v-else>
                                    This may take a minute or two. We're extracting the transcript and generating your article...
                                </span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Error Display -->
                <div v-if="errorMessage" class="mt-8 bg-red-50 border border-red-200 rounded-lg p-6">
                    <div class="flex">
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-red-800">Error</h3>
                            <p class="mt-1 text-sm text-red-700">{{ errorMessage }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
