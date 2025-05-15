<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TranscriptionTimeline from '@/Components/TranscriptionTimeline.vue';
import SynchronizedTranscript from '@/Components/SynchronizedTranscript.vue';
import AdvancedSubtitles from '@/Components/AdvancedSubtitles.vue';
import MusicTermsViewer from '@/Components/MusicTermsViewer.vue';
import TerminologyViewer from '@/Components/TerminologyViewer.vue';

const props = defineProps({
    video: Object,
});

const videoData = ref(props.video);
const timelineData = ref({
    timing: {},
    progress_percentage: 0,
    status: props.video.status
});
const pollingInterval = ref(null);
const videoError = ref(null);
const videoElement = ref(null);
const isLoading = ref(false);
const lastPolled = ref(Date.now());
const manualTranscriptUrl = ref('');
const transcriptData = ref(null);
const overallConfidence = ref(null);
const processingMusicTerms = ref(false);
const processingTerminology = ref(false);

// Check if this is a newly uploaded video that needs monitoring
const isNewVideo = computed(() => {
    // If created less than 2 minutes ago, treat as new
    if (!videoData.value.created_at) return false;
    const createdTime = new Date(videoData.value.created_at).getTime();
    const now = Date.now();
    return (now - createdTime) < 120000; // 2 minutes in milliseconds
});

function startPolling() {
    // For newly uploaded videos, always poll initially since status may be changing quickly
    const isNewlyUploaded = 
        videoData.value.status === 'uploaded' || 
        videoData.value.status === 'processing' || 
        videoData.value.is_processing || 
        videoData.value.status === 'transcribing' ||
        videoData.value.status === 'transcribed' ||
        videoData.value.status === 'processing_music_terms' ||
        isNewVideo.value;
    
    // Only poll if the video is being processed or newly uploaded
    if (!isNewlyUploaded) {
        return;
    }
    
    // Poll every 3 seconds
    // pollingInterval.value = setInterval(fetchStatus, 3000);
    pollingInterval.value = setInterval(fetchStatus, 15000); // INCREASED POLLING INTERVAL FOR DEBUGGING
}

async function fetchStatus() {
    try {
        // Set loading state for first fetch
        if (Date.now() - lastPolled.value > 5000) {
            isLoading.value = true;
        }
        
        const response = await fetch(`/api/videos/${videoData.value.id}/status`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        
        const data = await response.json();
        
        lastPolled.value = Date.now();
        isLoading.value = false;
        
        if (data.success) {
            // Check if status changed from processing/transcribing to completed
            const wasProcessing = videoData.value.status === 'processing' || 
                                  videoData.value.status === 'transcribing' || 
                                  videoData.value.status === 'transcribed' ||
                                  videoData.value.status === 'processing_music_terms';
            const nowCompleted = data.video.status === 'completed';
            
            // Update the video data
            if (data.video.status !== videoData.value.status) {
                videoData.value.status = data.video.status;
                
                // If status changed from processing to completed, force a full refresh
                if (wasProcessing && nowCompleted) {
                    await fetchVideoDetails();
                    return;
                }
                
                // If status changed to a new state, force refresh for newly created videos
                // This ensures we get all updated UI elements
                // if (isNewVideo.value && 
                //     (data.video.status === 'completed' || 
                //      data.video.has_audio || 
                //      data.video.has_transcript ||
                //      data.video.has_music_terms)) {
                //     // window.location.reload(); // COMMENTED OUT FOR DEBUGGING
                //     console.log('[Show.vue DEBUG] Would have reloaded, but commented out. Fetching details instead.');
                //     await fetchVideoDetails(); // Fetch details instead of full reload
                //     return;
                // }
            }
            
            // Copy all available properties from the response to our video data
            if (data.video) {
                // Copy standard properties
                videoData.value.error_message = data.video.error_message;
                videoData.value.is_processing = data.video.is_processing || 
                    ['processing', 'transcribing', 'transcribed', 'processing_music_terms'].includes(data.video.status);
                
                // Update music terms properties if they exist
                if (data.video.has_music_terms) {
                    videoData.value.has_music_terms = true;
                    videoData.value.music_terms_url = data.video.music_terms_url;
                    videoData.value.music_terms_count = data.video.music_terms_count;
                    videoData.value.music_terms_metadata = data.video.music_terms_metadata;
                }
                
                // Copy URLs if they exist
                if (data.video.url) {
                    const oldUrlString = videoData.value.url || 'null_or_empty';
                    const newUrlString = data.video.url;
                    const oldBaseUrl = videoData.value.url ? videoData.value.url.split('?')[0] : null;
                    const newBaseUrl = data.video.url.split('?')[0];

                    console.log('[Show.vue DEBUG] Comparing video URLs:', { 
                        currentVideoDataUrl: oldUrlString,
                        newUrlFromApi: newUrlString,
                        oldBaseUrl: oldBaseUrl, 
                        newBaseUrl: newBaseUrl,
                        isDifferent: oldBaseUrl !== newBaseUrl,
                        isCurrentMissing: !videoData.value.url
                    });

                    if (!videoData.value.url || oldBaseUrl !== newBaseUrl) {
                        console.log('[Show.vue DEBUG] Updating videoData.url because it was missing or base changed. New URL:', data.video.url);
                        videoData.value.url = data.video.url;
                    } else {
                        console.log('[Show.vue DEBUG] videoData.url base is the same, NOT updating to prevent restart.');
                    }
                }

                // Similarly for audio_url
                if (data.video.audio_url) {
                    const oldBaseAudioUrl = videoData.value.audio_url ? videoData.value.audio_url.split('?')[0] : null;
                    const newBaseAudioUrl = data.video.audio_url.split('?')[0];
                    if (!videoData.value.audio_url || oldBaseAudioUrl !== newBaseAudioUrl) {
                        videoData.value.audio_url = data.video.audio_url;
                    }
                }

                if (data.video.transcript_url) videoData.value.transcript_url = data.video.transcript_url;
                if (data.video.subtitles_url) videoData.value.subtitles_url = data.video.subtitles_url;
                if (data.video.transcript_json_url) {
                    const oldUrl = videoData.value.transcript_json_url;
                    videoData.value.transcript_json_url = data.video.transcript_json_url;
                    
                    // If transcript_json_url is new or changed, fetch the data
                    if (!oldUrl || oldUrl !== data.video.transcript_json_url) {
                        fetchTranscriptData();
                    }
                }
            }
            
            // Also check if transcript info is in the separate transcript property
            if (data.transcript && data.transcript.transcript_json_url) {
                const oldUrl = videoData.value.transcript_json_url;
                videoData.value.transcript_json_url = data.transcript.transcript_json_url;
                
                // If transcript_json_url is new or changed, fetch the data
                if (!oldUrl || oldUrl !== data.transcript.transcript_json_url) {
                    fetchTranscriptData();
                }
            }
            
            // Instead of reloading the page, update the local data
            if (data.video.has_audio && !videoData.value.audio_url) {
                // Fetch complete video data using the API
                fetchVideoDetails();
                return;
            }
            
            if (data.video.has_transcript && !videoData.value.transcript_text) {
                // Fetch complete video data using the API
                fetchVideoDetails();
                return;
            }
            
            // Update timeline data
            timelineData.value = {
                status: data.status,
                progress_percentage: data.progress_percentage,
                timing: data.timing || {},
                error: data.video.error_message
            };
            
            // Stop polling once processing is complete
            if (data.video.status === 'completed' || data.video.status === 'failed') {
                stopPolling();
                
                // Fetch complete video data instead of reloading
                fetchVideoDetails();
            }
        }
    } catch (error) {
        console.error('Error fetching status:', error);
        isLoading.value = false;
    }
}

// New function to fetch full video details
async function fetchVideoDetails() {
    console.log('[Show.vue DEBUG] Attempting to call fetchVideoDetails() for video ID:', videoData.value.id);
    try {
        if (!videoData.value.id) {
            console.error('Cannot fetch video details: No video ID available');
            return;
        }
        
        const response = await fetch(`/api/videos/${videoData.value.id}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success && data.video) {
            const newVideoDataFromApi = data.video;
            
            // *** ADDED LOGGING FOR TRANSCRIPT_TEXT ***
            console.log('[Show.vue DEBUG - fetchVideoDetails] API response for video details:', newVideoDataFromApi);
            console.log('[Show.vue DEBUG - fetchVideoDetails] transcript_text from API:', newVideoDataFromApi.transcript_text ? `'${newVideoDataFromApi.transcript_text.substring(0, 50)}...'` : 'MISSING or EMPTY');
            console.log('[Show.vue DEBUG - fetchVideoDetails] transcript_json_url from API:', newVideoDataFromApi.transcript_json_url || 'MISSING or EMPTY');

            // Selectively update videoData.value to avoid unnecessary video restarts
            Object.keys(newVideoDataFromApi).forEach(key => {
                if (key === 'url' || key === 'audio_url') {
                    const currentFullUrl = videoData.value[key];
                    const newFullUrl = newVideoDataFromApi[key];

                    // *** NEW DETAILED LOGGING FOR fetchVideoDetails ***
                    console.log(`[Show.vue DEBUG - fetchVideoDetails - ${key}] Comparing:`, {
                        currentKnownUrl: currentFullUrl || 'null_or_empty',
                        newUrlFromApi: newFullUrl || 'null_or_empty'
                    });

                    if (newFullUrl) { // Only proceed if the new URL exists
                        const oldBase = currentFullUrl ? currentFullUrl.split('?')[0] : null;
                        const newBase = newFullUrl.split('?')[0];

                        console.log(`[Show.vue DEBUG - fetchVideoDetails - ${key}] Base URLs:`, {
                            oldBase: oldBase,
                            newBase: newBase,
                            isDifferent: oldBase !== newBase,
                            isCurrentMissing: !currentFullUrl
                        });

                        if (!currentFullUrl || oldBase !== newBase) {
                            console.log(`[Show.vue DEBUG from fetchVideoDetails] Updating videoData.${key} because it was missing or base changed. New URL:`, newFullUrl);
                            videoData.value[key] = newFullUrl;
                        } else {
                            console.log(`[Show.vue DEBUG from fetchVideoDetails] videoData.${key} base is the same, NOT updating.`);
                        }
                    } else {
                        console.log(`[Show.vue DEBUG - fetchVideoDetails - ${key}] New URL from API is null/empty, not updating.`);
                    }
                } else {
                    // For all other keys, update directly
                    videoData.value[key] = newVideoDataFromApi[key];
                }
            });

            if (videoData.value.transcript_json_url) { // Check after potential update from API
                await fetchTranscriptData();
            }
        } else {
            console.error('API returned error or no video data in fetchVideoDetails:', data.message || 'Unknown error');
        }
    } catch (error) {
        console.error('Error fetching video details:', error);
        console.log('[Show.vue DEBUG] fetchVideoDetails() FAILED for video ID:', videoData.value.id, 'Error:', error);
    }
}

function stopPolling() {
    if (pollingInterval.value) {
        clearInterval(pollingInterval.value);
        pollingInterval.value = null;
    }
}

function handleVideoError(event) {
    videoError.value = event.target.error ? event.target.error.message : 'An error occurred while loading the video.';
}

function applyManualTranscriptUrl() {
    // Use the manually entered URL to override the transcript_json_url
    if (!manualTranscriptUrl.value) {
        alert('Please enter a transcript JSON URL first');
        return;
    }
    
    console.log('Setting manual transcript URL:', manualTranscriptUrl.value);
    videoData.value.transcript_json_url = manualTranscriptUrl.value;
}

// Add a function to fetch and process transcript JSON data
async function fetchTranscriptData() {
    if (!videoData.value.transcript_json_url) {
        return;
    }
    
    try {
        let url = videoData.value.transcript_json_url;
        
        // If it's an absolute URL, convert to a relative path
        if (url.startsWith('http')) {
            try {
                const parsedUrl = new URL(url);
                const pathMatch = parsedUrl.pathname.match(/\/storage\/(.+)/);
                if (pathMatch && pathMatch[1]) {
                    url = `/storage/${pathMatch[1]}`;
                }
            } catch (e) {
                // Handle URL parsing errors
            }
        }
        
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error('Failed to fetch transcript data');
        }
        
        transcriptData.value = await response.json();
        calculateOverallConfidence();
    } catch (error) {
        console.error('Error fetching transcript data:', error);
    }
}

// Calculate overall confidence from transcript data
function calculateOverallConfidence() {
    if (!transcriptData.value || !transcriptData.value.segments) {
        return;
    }
    
    let totalWords = 0;
    let confidenceSum = 0;
    
    // Go through all segments and words to sum up confidence values
    transcriptData.value.segments.forEach(segment => {
        if (Array.isArray(segment.words)) {
            segment.words.forEach(word => {
                if (word.probability !== undefined) {
                    confidenceSum += parseFloat(word.probability);
                    totalWords++;
                }
            });
        }
    });
    
    // Calculate average confidence if we have words
    if (totalWords > 0) {
        overallConfidence.value = confidenceSum / totalWords;
    }
}

// Add the terminology recognition trigger method
async function triggerTerminologyRecognition() {
    if (!videoData.value || !videoData.value.id) {
        console.error('No video data available');
        return;
    }
    
    processingTerminology.value = true;
    
    try {
        const response = await fetch(`/api/videos/${videoData.value.id}/terminology`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            }
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Terminology recognition triggered successfully');
            // Start polling for video status to show processing indicator
            startPolling();
        } else {
            console.error('Failed to trigger terminology recognition:', data.message);
            alert('Failed to start terminology recognition: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error triggering terminology recognition:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
    } finally {
        processingTerminology.value = false;
    }
}

onMounted(() => {
    // Get initial status immediately
    fetchStatus();
    
    // Fetch transcript data if available
    fetchTranscriptData();
    
    // Then start polling after a short delay to ensure backend has time to update
    setTimeout(() => {
        startPolling();
    }, 1000);
});

onBeforeUnmount(() => {
    stopPolling();
});
</script>

<template>
    <Head :title="videoData.original_filename" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ videoData.original_filename }}</h2>
                <Link :href="route('videos.index')" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700 transition">
                    &larr; Back to Videos
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex flex-col md:flex-row md:space-x-6">
                            <div class="md:w-2/3">
                                <!-- Video player with better styling -->
                                <div class="bg-gray-900 rounded-lg overflow-hidden shadow-lg relative">
                                    <video 
                                        ref="videoElement"
                                        :src="videoData.url" 
                                        controls
                                        class="w-full max-h-[500px]"
                                        preload="metadata"
                                        @error="handleVideoError"
                                        poster="/images/video-placeholder.svg"
                                        type="video/mp4"
                                    ></video>
                                    
                                    <div v-if="videoError" class="p-4 bg-red-50 text-red-800 text-sm">
                                        <div class="font-medium">Error loading video:</div>
                                        {{ videoError }}
                                        <div class="mt-2">
                                            <a :href="videoData.url" target="_blank" class="text-blue-600 hover:underline">Download video</a>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Advanced Subtitles component with proper heading -->
                                <div v-if="videoData.transcript_json_url && videoElement" class="mt-6">
                                    <div class="flex items-center justify-between mb-3 border-b border-gray-200 pb-2">
                                        <h3 class="text-lg font-medium flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                                            </svg>
                                            Interactive Transcript
                                        </h3>
                                    </div>
                                    <AdvancedSubtitles
                                        :video-ref="videoElement"
                                        :transcript-json-url="videoData.transcript_json_url"
                                        :transcript-json-api-url="videoData.transcript_json_api_url"
                                    />
                                </div>
                                
                                <!-- Full Transcript Text -->
                                <div v-if="videoData.transcript_text" class="mt-6">
                                    <div class="flex items-center justify-between mb-3 border-b border-gray-200 pb-2">
                                    </div>
                                    
                                    <!-- Synchronized Transcript (improved implementation) -->
                                    <SynchronizedTranscript
                                        :video-ref="videoElement"
                                        :srt-url="videoData.subtitles_url"
                                        :transcript-json-url="videoData.transcript_json_url"
                                        :transcript-text="videoData.transcript_text"
                                    />
                                </div>
                                
                                <!-- Terminology Viewer (renamed from MusicTermsViewer) -->
                                <div v-if="videoData.has_terminology || videoData.has_music_terms" class="mt-8">
                                    <TerminologyViewer 
                                        :terminology-url="videoData.terminology_url || videoData.music_terms_url"
                                        :terminology-api-url="videoData.terminology_json_api_url"
                                        :terminology-metadata="videoData.terminology_metadata && videoData.terminology_metadata.category_summary ? { categories: videoData.terminology_metadata.category_summary } : (videoData.music_terms_metadata && videoData.music_terms_metadata.category_summary ? { categories: videoData.music_terms_metadata.category_summary } : null)"
                                        :terminology-count="videoData.terminology_count || videoData.music_terms_count"
                                    />
                                </div>
                                
                                <!-- Terminology Recognition Trigger (renamed from MusicTermsRecognition) -->
                                <div v-else-if="videoData.transcript_path && !videoData.has_terminology && !videoData.has_music_terms" class="mt-8">
                                    <div class="bg-gray-50 rounded-lg p-5 shadow-sm border border-gray-200">
                                        <h3 class="text-lg font-medium mb-4 flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                            </svg>
                                            Term Recognition
                                        </h3>
                                        <p class="text-gray-600 mb-4">
                                            Identify terminology in the transcript to help analyze the content.
                                        </p>
                                        <button 
                                            @click="triggerTerminologyRecognition" 
                                            class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md transition shadow-sm"
                                            :disabled="processingTerminology"
                                        >
                                            <span v-if="processingTerminology" class="flex items-center">
                                                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                Processing...
                                            </span>
                                            <span v-else>Identify Terminology</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="md:w-1/3 mt-6 md:mt-0">
                                <div class="bg-gray-50 rounded-lg p-5 shadow-sm border border-gray-200">
                                    <h3 class="text-lg font-medium mb-4 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Video Information
                                    </h3>
                                    
                                    <div class="space-y-4">
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Original Filename</div>
                                            <div class="font-medium">{{ videoData.original_filename }}</div>
                                        </div>
                                        
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Status</div>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" 
                                                :class="{
                                                    'bg-green-100 text-green-800': videoData.status === 'completed',
                                                    'bg-blue-100 text-blue-800': videoData.status === 'processing',
                                                    'bg-purple-100 text-purple-800': videoData.status === 'transcribing',
                                                    'bg-indigo-100 text-indigo-800': videoData.status === 'transcribed',
                                                    'bg-orange-100 text-orange-800': videoData.status === 'processing_music_terms',
                                                    'bg-yellow-100 text-yellow-800': videoData.status === 'uploaded',
                                                    'bg-red-100 text-red-800': videoData.status === 'failed',
                                                }">
                                                <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                    v-if="videoData.status === 'completed'">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                <svg class="w-3.5 h-3.5 mr-1.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                    v-else-if="videoData.is_processing">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                </svg>
                                                {{ videoData.status }}
                                            </span>
                                        </div>
                                        
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">File Size</div>
                                            <div class="font-medium">{{ formatFileSize(videoData.size_bytes) }}</div>
                                        </div>
                                        
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">File Type</div>
                                            <div class="font-medium">{{ videoData.mime_type }}</div>
                                        </div>
                                        
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Uploaded</div>
                                            <div class="font-medium">{{ new Date(videoData.created_at).toLocaleString() }}</div>
                                        </div>
                                        
                                        <!-- Add the overall confidence display in the Video Information section -->
                                        <div v-if="overallConfidence !== null">
                                            <div class="text-gray-500 text-sm mb-1">Transcript Confidence</div>
                                            <div class="flex items-center gap-2">
                                                <div class="w-full h-3 bg-gray-300 rounded-full overflow-hidden">
                                                    <div 
                                                        class="h-full" 
                                                        :style="{
                                                            width: `${(overallConfidence * 100).toFixed(0)}%`,
                                                            backgroundColor: getConfidenceColor(overallConfidence)
                                                        }"
                                                    ></div>
                                                </div>
                                                <span class="font-medium whitespace-nowrap">{{ (overallConfidence * 100).toFixed(0) }}%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Timeline component (moved to sidebar) -->
                                <div class="mt-6 bg-gray-50 rounded-lg p-5 shadow-sm border border-gray-200">
                                    <TranscriptionTimeline 
                                        :status="timelineData.status || videoData.status"
                                        :timing="timelineData.timing"
                                        :progress-percentage="timelineData.progress_percentage"
                                        :error="videoData.error_message"
                                        :media-duration="videoData.audio_duration"
                                    />
                                </div>
                                
                                <!-- Actions -->
                                <div class="mt-6 flex space-x-3">
                                    <Link
                                        :href="route('videos.destroy', videoData.id)"
                                        method="delete"
                                        as="button"
                                        class="inline-flex items-center justify-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md transition shadow-sm"
                                        onclick="return confirm('Are you sure you want to delete this video?')"
                                    >
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                        Delete Video
                                    </Link>
                                </div>
                                
                                <!-- Error message -->
                                <div v-if="videoData.error_message" class="mt-4 p-3 bg-red-50 text-red-800 rounded-md border border-red-200">
                                    <div class="font-medium flex items-center">
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Error
                                    </div>
                                    <div class="mt-1">{{ videoData.error_message }}</div>
                                </div>
                                
                                <!-- Audio Player (if audio extraction is complete) -->
                                <div v-if="videoData.audio_url" class="mt-8">
                                    <h3 class="text-lg font-medium mb-4 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                        </svg>
                                        Extracted Audio
                                    </h3>
                                    <div class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200">
                                        <audio controls class="w-full">
                                            <source :src="videoData.audio_url" type="audio/wav">
                                            Your browser does not support the audio element.
                                        </audio>
                                        <div class="mt-2 flex space-x-4 text-sm text-gray-600">
                                            <div v-if="videoData.formatted_duration" class="flex items-center">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                {{ videoData.formatted_duration }}
                                            </div>
                                            <div v-if="videoData.audio_size" class="flex items-center">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                                </svg>
                                                {{ formatFileSize(videoData.audio_size) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
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
        },
        getConfidenceColor(confidence) {
            if (confidence >= 0.8) return '#10b981'; // green-500
            if (confidence >= 0.5) return '#f59e0b'; // yellow-500
            return '#ef4444'; // red-500
        }
    }
}
</script> 