<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TranscriptionTimeline from '@/Components/TranscriptionTimeline.vue';
import EnhancedTranscriptViewer from '@/Components/EnhancedTranscriptViewer.vue';
import MusicTermsViewer from '@/Components/MusicTermsViewer.vue';
import TerminologyViewer from '@/Components/TerminologyViewer.vue';
import VideoSubtitleDisplay from '@/Components/VideoSubtitleDisplay.vue';

const props = defineProps({
    video: Object,
});

const activeTab = ref('transcript');

const videoData = ref(JSON.parse(JSON.stringify(props.video)));
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

function getStatusMessage() {
    if (videoData.value.status === 'processing') {
        return 'Processing your video and extracting audio';
    } else if (videoData.value.status === 'transcribing') {
        return 'Generating transcript from audio';
    } else if (videoData.value.status === 'processing_music_terms') {
        return 'Identifying terminology in transcript';
    } else if (videoData.value.is_processing) {
        return 'Processing your video';
    }
    return 'Processing';
}

const effectiveTranscriptText = computed(() => {
    if (transcriptData.value && typeof transcriptData.value.text === 'string') {
        console.log('[Show.vue DEBUG] Using text from fetched transcriptData.value.text');
        return transcriptData.value.text;
    }
    if (videoData.value.transcript_text) {
        console.log('[Show.vue DEBUG] Falling back to videoData.value.transcript_text');
        return videoData.value.transcript_text;
    }
    console.log('[Show.vue DEBUG] effectiveTranscriptText is currently null/undefined');
    return null; 
});

watch(() => videoData.value.transcript_json_url, (newUrl, oldUrl) => {
    if (newUrl && newUrl !== oldUrl) {
        console.log('[Show.vue DEBUG] transcript_json_url changed, fetching transcript data:', newUrl);
        fetchTranscriptData();
    } else if (!newUrl) {
        transcriptData.value = null;
    }
}, { immediate: true });

watch(
    () => ({
        transcriptJsonUrl: videoData.value.transcript_json_url,
        srtUrl: videoData.value.subtitles_url,
        transcriptText: videoData.value.transcript_text, 
        videoElementAvailable: !!videoElement.value,
        shouldRenderSynchronizedTranscript: !!videoData.value.transcript_json_url 
    }),
    (propsForSyncTranscript) => {
        console.log('[Show.vue DEBUG] Props for SynchronizedTranscript check:', propsForSyncTranscript);
        if (!propsForSyncTranscript.shouldRenderSynchronizedTranscript) {
            console.warn('[Show.vue DEBUG] SynchronizedTranscript will NOT render because videoData.transcript_json_url is falsy.');
        }
    },
    { deep: true, immediate: true }
);

const isNewVideo = computed(() => {
    if (!videoData.value.created_at) return false;
    const createdTime = new Date(videoData.value.created_at).getTime();
    const now = Date.now();
    return (now - createdTime) < 120000;
});

function startPolling() {
    const isNewlyUploaded = 
        videoData.value.status === 'uploaded' || 
        videoData.value.status === 'processing' || 
        videoData.value.is_processing || 
        videoData.value.status === 'transcribing' ||
        videoData.value.status === 'transcribed' ||
        videoData.value.status === 'processing_music_terms' ||
        isNewVideo.value;
    
    if (!isNewlyUploaded) {
        return;
    }
    
    pollingInterval.value = setInterval(fetchStatus, 15000);
}

async function fetchStatus() {
    try {
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
            const wasProcessing = videoData.value.status === 'processing' || 
                                  videoData.value.status === 'transcribing' || 
                                  videoData.value.status === 'transcribed' ||
                                  videoData.value.status === 'processing_music_terms';
            const nowCompleted = data.video.status === 'completed';
            
            if (data.video.status !== videoData.value.status) {
                videoData.value.status = data.video.status;
                
                if (wasProcessing && nowCompleted) {
                    await fetchVideoDetails();
                    return;
                }
            }
            
            if (data.video) {
                videoData.value.error_message = data.video.error_message;
                videoData.value.is_processing = data.video.is_processing || 
                    ['processing', 'transcribing', 'transcribed', 'processing_music_terms'].includes(data.video.status);
                
                if (data.video.has_music_terms) {
                    videoData.value.has_music_terms = true;
                    videoData.value.music_terms_url = data.video.music_terms_url;
                    videoData.value.music_terms_count = data.video.music_terms_count;
                    videoData.value.music_terms_metadata = data.video.music_terms_metadata;
                }
                
                if (data.video.url) {
                    const oldBaseUrl = videoData.value.url ? videoData.value.url.split('?')[0] : null;
                    const newBaseUrl = data.video.url.split('?')[0];
                    if (!videoData.value.url || oldBaseUrl !== newBaseUrl) {
                        videoData.value.url = data.video.url;
                    }
                }

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
                    
                    if (!oldUrl || oldUrl !== data.video.transcript_json_url) {
                        fetchTranscriptData();
                    }
                }
            }
            
            if (data.transcript && data.transcript.transcript_json_url) {
                const oldUrl = videoData.value.transcript_json_url;
                videoData.value.transcript_json_url = data.transcript.transcript_json_url;
                
                if (!oldUrl || oldUrl !== data.transcript.transcript_json_url) {
                    fetchTranscriptData();
                }
            }
            
            if (data.video.has_audio && !videoData.value.audio_url) {
                fetchVideoDetails();
                return;
            }
            
            if (data.video.has_transcript && !videoData.value.transcript_text) {
                fetchVideoDetails();
                return;
            }
            
            timelineData.value = {
                status: data.status,
                progress_percentage: data.progress_percentage,
                timing: data.timing || {},
                error: data.video.error_message
            };
            
            if (data.video.status === 'completed' || data.video.status === 'failed') {
                stopPolling();
                
                fetchVideoDetails();
            }

            const oldJsonUrl = videoData.value.transcript_json_url;
        }
    } catch (error) {
        console.error('Error fetching status:', error);
        isLoading.value = false;
    }
}

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
            
            console.log('[Show.vue DEBUG - fetchVideoDetails] API response for video details:', newVideoDataFromApi);
            console.log('[Show.vue DEBUG - fetchVideoDetails] transcript_text from API:', newVideoDataFromApi.transcript_text ? `(length: ${newVideoDataFromApi.transcript_text.length}) '${newVideoDataFromApi.transcript_text.substring(0, 30)}...'` : 'MISSING or EMPTY');
            console.log('[Show.vue DEBUG - fetchVideoDetails] transcript_json_url from API:', newVideoDataFromApi.transcript_json_url || 'MISSING or EMPTY');

            Object.keys(newVideoDataFromApi).forEach(key => {
                if (key === 'url' || key === 'audio_url') {
                    const currentFullUrl = videoData.value[key];
                    const newFullUrl = newVideoDataFromApi[key];
                    if (newFullUrl) {
                        const oldBase = currentFullUrl ? currentFullUrl.split('?')[0] : null;
                        const newBase = newFullUrl.split('?')[0];
                        if (!currentFullUrl || oldBase !== newBase) {
                            videoData.value[key] = newFullUrl;
                        }
                    }
                } else {
                    videoData.value[key] = newVideoDataFromApi[key];
                }
            });

            if (videoData.value.transcript_json_url) {
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
    if (!manualTranscriptUrl.value) {
        alert('Please enter a transcript JSON URL first');
        return;
    }
    
    console.log('Setting manual transcript URL:', manualTranscriptUrl.value);
    videoData.value.transcript_json_url = manualTranscriptUrl.value;
}

async function fetchTranscriptData() {
    if (!videoData.value.transcript_json_url) {
        console.warn('[Show.vue DEBUG - fetchTranscriptData] Called without transcript_json_url.');
        transcriptData.value = null;
        return;
    }
    console.log(`[Show.vue DEBUG - fetchTranscriptData] Fetching from: ${videoData.value.transcript_json_url}`);
    try {
        const response = await fetch(videoData.value.transcript_json_url);
        if (!response.ok) {
            transcriptData.value = null;
            throw new Error(`Failed to fetch transcript data from ${videoData.value.transcript_json_url}. Status: ${response.status}`);
        }
        const jsonData = await response.json();
        console.log('[Show.vue DEBUG - fetchTranscriptData] Successfully fetched and parsed transcript.json');
        transcriptData.value = jsonData;
        calculateOverallConfidence(); 
    } catch (error) {
        console.error('[Show.vue DEBUG - fetchTranscriptData] Error:', error);
        transcriptData.value = null;
    }
}

function calculateOverallConfidence() {
    if (!transcriptData.value || !transcriptData.value.segments) {
        return;
    }
    
    let totalWords = 0;
    let confidenceSum = 0;
    
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
    
    if (totalWords > 0) {
        overallConfidence.value = confidenceSum / totalWords;
    }
}

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
    fetchStatus();
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

        <div class="py-6">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div v-if="videoData.is_processing || videoData.status === 'processing' || videoData.status === 'transcribing'" 
                             class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-blue-500 mr-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                </svg>
                                <div>
                                    <p class="font-medium text-blue-700">Processing in progress</p>
                                    <p class="text-sm text-blue-600">{{ getStatusMessage() }}</p>
                                </div>
                            </div>
                            <div class="ml-4">
                                <span class="text-sm font-medium text-blue-700">{{ timelineData.progress_percentage }}%</span>
                            </div>
                        </div>

                        <div v-if="videoData.error_message" class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <div>
                                    <p class="font-medium text-red-800">Error</p>
                                    <p class="text-sm text-red-700">{{ videoData.error_message }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="mb-6">
                            <div class="bg-gray-900 rounded-lg overflow-hidden shadow-lg">
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
                            
                            <div v-if="videoData.transcript_json_url && videoElement" class="mt-2">
                                <VideoSubtitleDisplay
                                    :video-ref="videoElement"
                                    :transcript-json-url="videoData.transcript_json_url" 
                                    :srt-url="videoData.subtitles_url"
                                />
                            </div>
                        </div>

                        <div class="mb-6">
                            <div class="border-b border-gray-200">
                                <nav class="-mb-px flex space-x-8">
                                    <button 
                                        @click="activeTab = 'transcript'" 
                                        class="py-4 px-1 border-b-2 font-medium text-sm"
                                        :class="activeTab === 'transcript' 
                                            ? 'border-blue-500 text-blue-600' 
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                    >
                                        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        Transcript
                                    </button>
                                    <button 
                                        @click="activeTab = 'audio'" 
                                        class="py-4 px-1 border-b-2 font-medium text-sm"
                                        :class="activeTab === 'audio' 
                                            ? 'border-blue-500 text-blue-600' 
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                    >
                                        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                        </svg>
                                        Audio
                                    </button>
                                    <button 
                                        @click="activeTab = 'info'" 
                                        class="py-4 px-1 border-b-2 font-medium text-sm"
                                        :class="activeTab === 'info' 
                                            ? 'border-blue-500 text-blue-600' 
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                    >
                                        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Video Info
                                    </button>
                                    <button 
                                        v-if="videoData.has_terminology || videoData.has_music_terms"
                                        @click="activeTab = 'terminology'" 
                                        class="py-4 px-1 border-b-2 font-medium text-sm"
                                        :class="activeTab === 'terminology' 
                                            ? 'border-blue-500 text-blue-600' 
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                    >
                                        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                                        </svg>
                                        Terminology
                                    </button>
                                </nav>
                            </div>
                        </div>

                        <div class="tab-content">
                            <div v-if="activeTab === 'transcript'" class="transcript-tab">
                                <div v-if="videoData.transcript_json_url && videoElement">
                                    <EnhancedTranscriptViewer
                                        :video-ref="videoElement"
                                        :transcript-json-url="videoData.transcript_json_url" 
                                        :srt-url="videoData.subtitles_url" 
                                        :transcript-text="effectiveTranscriptText"
                                        :terminology="videoData.terminology || []"
                                    />
                                </div>
                                <div v-else class="text-center py-10 bg-gray-50 rounded-lg">
                                    <svg class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <h3 class="text-lg font-medium text-gray-900">No Transcript Available</h3>
                                    <p class="text-gray-500 mt-2">This video doesn't have a transcript yet or is still processing.</p>
                                </div>
                            </div>

                            <div v-if="activeTab === 'audio'" class="audio-tab">
                                <div v-if="videoData.audio_url" class="bg-gray-50 rounded-lg p-5 border border-gray-200">
                                    <h3 class="text-lg font-medium mb-4 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                        </svg>
                                        Extracted Audio
                                    </h3>
                                    <div class="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
                                        <audio controls class="w-full">
                                            <source :src="videoData.audio_url" type="audio/wav">
                                            Your browser does not support the audio element.
                                        </audio>
                                        <div class="mt-2 flex space-x-4 text-sm text-gray-600">
                                            <div v-if="videoData.formatted_duration" class="flex items-center">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                {{ videoData.formatted_duration }}
                                            </div>
                                            <div v-if="videoData.audio_size" class="flex items-center">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                                </svg>
                                                {{ formatFileSize(videoData.audio_size) }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-3 text-sm text-gray-500">
                                        The extracted audio is used for transcription and analysis.
                                    </div>
                                </div>
                                <div v-else class="text-center py-10 bg-gray-50 rounded-lg">
                                    <svg class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                    </svg>
                                    <h3 class="text-lg font-medium text-gray-900">No Audio Available</h3>
                                    <p class="text-gray-500 mt-2">Audio extraction is in progress or hasn't started yet.</p>
                                </div>
                            </div>

                            <div v-if="activeTab === 'info'" class="info-tab">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="bg-gray-50 rounded-lg p-5 border border-gray-200">
                                        <h3 class="text-lg font-medium mb-4 flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Video Information
                                        </h3>
                                        
                                        <div class="space-y-4 bg-white p-4 rounded-lg shadow-sm">
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

                                        <div class="mt-6 flex space-x-3">
                                            <Link
                                                :href="route('videos.destroy', videoData.id)"
                                                method="delete"
                                                as="button"
                                                class="inline-flex items-center justify-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md transition shadow-sm"
                                                onclick="return confirm('Are you sure you want to delete this video?')"
                                            >
                                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                                Delete Video
                                            </Link>
                                            <a 
                                                :href="videoData.url" 
                                                target="_blank" 
                                                class="inline-flex items-center justify-center px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-md transition shadow-sm"
                                            >
                                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                                </svg>
                                                Download Video
                                            </a>
                                        </div>
                                    </div>

                                    <div class="bg-gray-50 rounded-lg p-5 border border-gray-200">
                                        <h3 class="text-lg font-medium mb-4 flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                            Processing Timeline
                                        </h3>
                                        <div class="bg-white p-4 rounded-lg shadow-sm">
                                            <TranscriptionTimeline 
                                                :status="timelineData.status || videoData.status"
                                                :timing="timelineData.timing"
                                                :progress-percentage="timelineData.progress_percentage"
                                                :error="videoData.error_message"
                                                :media-duration="videoData.audio_duration"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div v-if="activeTab === 'terminology'" class="terminology-tab">
                                <div v-if="videoData.has_terminology || videoData.has_music_terms">
                                    <TerminologyViewer 
                                        :terminology-url="videoData.terminology_url || videoData.music_terms_url"
                                        :terminology-api-url="videoData.terminology_json_api_url"
                                        :terminology-metadata="videoData.terminology_metadata && videoData.terminology_metadata.category_summary ? { categories: videoData.terminology_metadata.category_summary } : (videoData.music_terms_metadata && videoData.music_terms_metadata.category_summary ? { categories: videoData.music_terms_metadata.category_summary } : null)"
                                        :terminology-count="videoData.terminology_count || videoData.music_terms_count"
                                    />
                                </div>
                                <div v-else-if="videoData.transcript_path && !videoData.has_terminology && !videoData.has_music_terms" class="bg-gray-50 rounded-lg p-5 border border-gray-200">
                                    <h3 class="text-lg font-medium mb-4 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
            if (confidence >= 0.8) return '#10b981';
            if (confidence >= 0.5) return '#f59e0b';
            return '#ef4444';
        }
    }
}
</script>

<style>
.tab-content {
    min-height: 400px;
}
</style> 