<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount, computed, nextTick } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TranscriptionTimeline from '@/Components/TranscriptionTimeline.vue';
import SynchronizedTranscript from '@/Components/SynchronizedTranscript.vue';
import AdvancedSubtitles from '@/Components/AdvancedSubtitles.vue';
import MusicTermsViewer from '@/Components/MusicTermsViewer.vue';
import TerminologyViewer from '@/Components/TerminologyViewer.vue';

const props = defineProps({
    course: Object,
    segment: Object,
});

const segmentData = ref(props.segment);
const timelineData = ref({
    timing: {},
    progress_percentage: 0,
    status: props.segment.status
});
const pollingInterval = ref(null);
const videoError = ref(null);
const videoElement = ref(null);
const audioElement = ref(null);
const isLoading = ref(false);
const lastPolled = ref(Date.now());
const transcriptData = ref(null);
const overallConfidence = ref(null);
const processingTerminology = ref(false);
const showSynchronizedTranscript = ref(false); // Hidden by default

// Simple restart options
const showRestartConfirm = ref(false);

// Check if this is a newly started processing that needs monitoring
const isNewProcessing = computed(() => {
    // If created less than 2 minutes ago, treat as new
    if (!segmentData.value.updated_at) return false;
    const updatedTime = new Date(segmentData.value.updated_at).getTime();
    const now = Date.now();
    return (now - updatedTime) < 120000; // 2 minutes in milliseconds
});

function startPolling() {
    // Poll if the segment is being processed or newly updated
    const isProcessing = 
        segmentData.value.status === 'processing' || 
        segmentData.value.status === 'transcribing' || 
        segmentData.value.is_processing || 
        segmentData.value.status === 'audio_extracted' ||
        segmentData.value.status === 'transcribed' ||
        segmentData.value.status === 'processing_terminology' ||
        isNewProcessing.value;
    
    // Only poll if the segment is being processed or newly updated
    if (!isProcessing) {
        return;
    }
    
    // Poll every 3 seconds
    pollingInterval.value = setInterval(fetchStatus, 3000);
}

async function fetchStatus() {
    try {
        // Set loading state for first fetch
        if (Date.now() - lastPolled.value > 5000) {
            isLoading.value = true;
        }
        
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/status`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        
        const data = await response.json();
        
        lastPolled.value = Date.now();
        isLoading.value = false;
        
        if (data.success) {
            // Check if status changed from processing to completed
            const wasProcessing = segmentData.value.status === 'processing' || 
                                  segmentData.value.status === 'transcribing' || 
                                  segmentData.value.status === 'transcribed' ||
                                  segmentData.value.status === 'processing_terminology';
            const nowCompleted = data.segment.status === 'completed';
            
            // Update the segment data
            if (data.segment.status !== segmentData.value.status) {
                segmentData.value.status = data.segment.status;
                
                // If status changed from processing to completed, force a full refresh
                if (wasProcessing && nowCompleted) {
                    await fetchSegmentDetails();
                    return;
                }
                
                // If status changed to a new state, force refresh for newly created processing
                if (isNewProcessing.value && 
                    (data.segment.status === 'completed' || 
                     data.segment.has_audio || 
                     data.segment.has_transcript ||
                     data.segment.has_terminology)) {
                    window.location.reload();
                    return;
                }
            }
            
            // Copy all available properties from the response to our segment data
            if (data.segment) {
                // Copy standard properties
                segmentData.value.error_message = data.segment.error_message;
                segmentData.value.is_processing = data.segment.is_processing || 
                    ['processing', 'transcribing', 'transcribed', 'processing_terminology'].includes(data.segment.status);
                
                // Update terminology properties if they exist
                if (data.segment.has_terminology) {
                    segmentData.value.has_terminology = true;
                    segmentData.value.terminology_url = data.segment.terminology_url;
                    segmentData.value.terminology_count = data.segment.terminology_count;
                    segmentData.value.terminology_metadata = data.segment.terminology_metadata;
                }
                
                // Copy URLs if they exist
                if (data.segment.url) segmentData.value.url = data.segment.url;
                if (data.segment.audio_url) segmentData.value.audio_url = data.segment.audio_url;
                if (data.segment.transcript_url) segmentData.value.transcript_url = data.segment.transcript_url;
                if (data.segment.subtitles_url) segmentData.value.subtitles_url = data.segment.subtitles_url;
                if (data.segment.transcript_json_url) {
                    const oldUrl = segmentData.value.transcript_json_url;
                    segmentData.value.transcript_json_url = data.segment.transcript_json_url;
                    
                    // If transcript_json_url is new or changed, fetch the data
                    if (!oldUrl || oldUrl !== data.segment.transcript_json_url) {
                        fetchTranscriptData();
                    }
                }
            }
            
            // Update timeline data
            timelineData.value = {
                status: data.status,
                progress_percentage: data.progress_percentage,
                timing: data.timing || {},
                error: data.segment.error_message
            };
            
            // Stop polling once processing is complete
            if (data.segment.status === 'completed' || data.segment.status === 'failed') {
                stopPolling();
                
                // Fetch complete segment data instead of reloading
                fetchSegmentDetails();
            }
        }
    } catch (error) {
        console.error('Error fetching status:', error);
        isLoading.value = false;
    }
}

// New function to fetch full segment details
async function fetchSegmentDetails() {
    try {
        // Make sure we have an ID
        if (!segmentData.value.id) {
            console.error('Cannot fetch segment details: No segment ID available');
            return;
        }
        
        // Use relative URL to avoid CORS issues
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Update our local data with the full segment details
            Object.assign(segmentData.value, data.segment);
            
            // After updating the segment data, try to fetch the transcript JSON
            // if the transcript_json_url is now available
            if (segmentData.value.transcript_json_url) {
                await fetchTranscriptData();
            }
        } else {
            console.error('API returned error:', data.message || 'Unknown error');
        }
    } catch (error) {
        console.error('Error fetching segment details:', error);
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

function handleAudioError(event) {
    console.error('Audio error:', event.target.error);
}

// Generate audio URL from path
function getAudioUrl() {
    if (!segmentData.value.audio_path) return null;
    
    // Convert internal path to accessible URL
    // The path is like "/mnt/d_drive/truefire-courses/1/7959.wav"
    // We need to convert this to a public URL
    const pathParts = segmentData.value.audio_path.split('/');
    const filename = pathParts[pathParts.length - 1]; // "7959.wav"
    const courseId = pathParts[pathParts.length - 2]; // "1"
    
    // Return API endpoint for serving the audio file
    return `/api/truefire-courses/${courseId}/segments/${segmentData.value.id}/audio`;
}

// Add a function to fetch and process transcript JSON data
async function fetchTranscriptData() {
    if (!segmentData.value.transcript_json_api_url) {
        return;
    }
    
    try {
        const response = await fetch(segmentData.value.transcript_json_api_url);
        
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
    if (!segmentData.value || !segmentData.value.id) {
        console.error('No segment data available');
        return;
    }
    
    processingTerminology.value = true;
    
    try {
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/terminology`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            }
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Terminology recognition triggered successfully');
            // Start polling for segment status to show processing indicator
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

// Add transcription request method
async function requestTranscription() {
    try {
        const response = await fetch(`/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/transcription`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Transcription requested successfully');
            // Start polling for segment status
            startPolling();
        } else {
            console.error('Failed to request transcription:', data.message);
            alert('Failed to start transcription: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error requesting transcription:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
    }
}

// Add restart transcription method for failed segments
async function restartTranscription() {
    try {
        const response = await fetch(`/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/transcription/restart`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Transcription restart requested successfully');
            // Update status to processing and start polling
            segmentData.value.status = 'processing';
            segmentData.value.error_message = null;
            startPolling();
        } else {
            console.error('Failed to restart transcription:', data.message);
            alert('Failed to restart transcription: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error restarting transcription:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
    }
}

// Add abort processing method
async function abortProcessing() {
    if (!confirm('Are you sure you want to abort the current processing? This will stop all running jobs and reset the segment to ready status.')) {
        return;
    }
    
    try {
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/abort`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Processing aborted successfully');
            // Update status and refresh data
            segmentData.value.status = 'ready';
            segmentData.value.error_message = null;
            segmentData.value.progress_percentage = 0;
            // Stop polling and refresh status
            stopPolling();
            fetchStatus();
        } else {
            console.error('Failed to abort processing:', data.message);
            alert('Failed to abort processing: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error aborting processing:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
    }
}



// Simplified restart processing method using intelligent detection
async function restartProcessing() {
    if (!confirm('Are you sure you want to restart the entire processing? This will overwrite all existing audio, transcript, and terminology data for this segment using intelligent detection for optimal settings.')) {
        return;
    }
    
    try {
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/redo`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                force_reextraction: true,
                overwrite_existing: true,
                use_intelligent_detection: true
            })
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Processing restart started successfully with intelligent detection');
            // Update status to processing and start polling
            segmentData.value.status = 'processing';
            segmentData.value.error_message = null;
            segmentData.value.progress_percentage = 0;
            // Clear existing data
            segmentData.value.transcript_text = null;
            segmentData.value.transcript_json_url = null;
            segmentData.value.transcript_json_api_url = null;
            segmentData.value.has_terminology = false;
            segmentData.value.terminology_url = null;
            showRestartConfirm.value = false;
            startPolling();
        } else {
            console.error('Failed to start restart processing:', data.message);
            alert('Failed to start restart processing: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error starting restart processing:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
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
    
    // Set up video synchronization when video element is ready
    nextTick(() => {
        initializeVideoSync();
    });
});



onBeforeUnmount(() => {
    stopPolling();
});

// Function to initialize video synchronization
function initializeVideoSync() {
    // Retry video sync setup if video element isn't ready yet
    const checkVideoReady = () => {
        const videoEl = document.querySelector('video');
        if (videoEl) {
            // Set the ref manually and force component updates
            videoElement.value = videoEl;
            forceComponentRefresh();
            console.log('Video element found and set for synchronization');
        } else {
            // Retry after a short delay
            setTimeout(checkVideoReady, 100);
        }
    };
    
    checkVideoReady();
}

// Force refresh of transcript components
const componentKey = ref(Date.now());
function forceComponentRefresh() {
    componentKey.value = Date.now();
}
</script>

<template>
    <Head :title="`${segmentData.title || segmentData.name} - ${course.title || 'TrueFire Course'}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ segmentData.title || segmentData.name || `Segment #${segmentData.id}` }}
                </h2>
                <div class="flex items-center space-x-3">
                    <Link :href="route('truefire-courses.show', course.id)" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700 transition">
                        &larr; Back to Course
                    </Link>
                    <Link :href="route('truefire-courses.index')" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700 transition">
                        &larr; All Courses
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex flex-col md:flex-row md:space-x-6">
                            <div class="md:w-2/3">
                                <!-- Video player with better styling -->
                                <div class="bg-gray-900 rounded-lg overflow-hidden shadow-lg relative mb-4">
                                    <video 
                                        ref="videoElement"
                                        :src="segmentData.url" 
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
                                            <a :href="segmentData.url" target="_blank" class="text-blue-600 hover:underline">Download video</a>
                                        </div>
                                    </div>
                                </div>
                                

                                
                                <!-- Advanced Subtitles component with proper heading -->
                                <div v-if="segmentData.transcript_json_api_url" class="mt-6">
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
                                        :transcript-json-url="segmentData.transcript_json_url"
                                        :transcript-json-api-url="segmentData.transcript_json_api_url"
                                        :key="`advanced-${componentKey}`"
                                    />
                                    <div class="mt-2 text-xs text-gray-500 italic">
                                        🎯 Interactive transcript synchronized with video playback
                                    </div>
                                </div>
                                
                                <!-- Synchronized Transcript with Toggle -->
                                <div v-if="segmentData.transcript_text || segmentData.transcript_json_api_url" class="mt-6">
                                    <div class="flex items-center justify-between mb-3 border-b border-gray-200 pb-2">
                                        <h3 class="text-lg font-medium flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Synchronized Transcript
                                        </h3>
                                        <button 
                                            @click="showSynchronizedTranscript = !showSynchronizedTranscript" 
                                            class="flex items-center text-sm px-3 py-1 rounded-md transition"
                                            :class="showSynchronizedTranscript ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
                                        >
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="showSynchronizedTranscript ? 'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.529 6.529m4.243 4.243l4.242 4.242m0 0l3.35 3.35' : 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.543 7-1.275 4.057-5.065 7-9.543 7-4.477 0-8.268-2.943-9.542-7z'"></path>
                                            </svg>
                                            {{ showSynchronizedTranscript ? 'Hide' : 'Show' }}
                                        </button>
                                    </div>
                                    
                                    <!-- Synchronized Transcript (improved implementation) -->
                                    <div v-if="showSynchronizedTranscript" class="transition-all duration-300 ease-in-out">
                                        <SynchronizedTranscript
                                            :video-ref="videoElement"
                                            :srt-url="segmentData.subtitles_url"
                                            :transcript-json-url="segmentData.transcript_json_url || segmentData.transcript_json_api_url"
                                            :transcript-json-api-url="segmentData.transcript_json_api_url"
                                            :transcript-text="segmentData.transcript_text"
                                            :key="`sync-${componentKey}`"
                                        />
                                    </div>
                                </div>
                                
                                <!-- Terminology Viewer -->
                                <div v-if="segmentData.has_terminology" class="mt-8">
                                    <TerminologyViewer 
                                        :terminology-url="segmentData.terminology_url"
                                        :terminology-api-url="segmentData.terminology_json_api_url"
                                        :terminology-metadata="segmentData.terminology_metadata"
                                        :terminology-count="segmentData.terminology_count"
                                    />
                                </div>
                                
                                <!-- Simple Restart Processing for Completed Segments -->
                                <div v-if="segmentData.status === 'completed'" class="mt-8">
                                    <div class="bg-orange-50 rounded-lg p-5 shadow-sm border border-orange-200">
                                        <h3 class="text-lg font-medium mb-4 flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                            </svg>
                                            Restart Processing
                                        </h3>
                                        <p class="text-gray-600 mb-4">
                                            Re-run the entire processing pipeline using intelligent detection for optimal audio extraction and transcription settings. This will overwrite all existing results.
                                        </p>
                                        
                                        <!-- Intelligent Detection Info -->
                                        <div class="bg-blue-50 border border-blue-200 rounded-md p-3 mb-4">
                                            <div class="flex items-start">
                                                <svg class="w-5 h-5 mr-2 text-blue-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <div class="text-sm text-blue-800">
                                                    <div class="font-medium">Intelligent Detection Enabled</div>
                                                    <div>The system will automatically select optimal audio extraction and transcription settings based on content analysis, audio quality, and segment characteristics.</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Warning Message -->
                                        <div class="bg-orange-100 border border-orange-300 rounded-md p-3 mb-4">
                                            <div class="flex items-start">
                                                <svg class="w-5 h-5 mr-2 text-orange-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.734 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                                </svg>
                                                <div class="text-sm text-orange-800">
                                                    <div class="font-medium">Warning:</div>
                                                    <div>This action will permanently delete and replace all existing audio files, transcripts, and terminology data for this segment. This cannot be undone.</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Action Button -->
                                        <button 
                                            @click="restartProcessing" 
                                            class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-md transition shadow-sm flex items-center"
                                        >
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                            </svg>
                                            Restart Processing with Intelligent Detection
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Restart Transcription for Failed Segments - PRIORITY FOR FAILED STATUS -->
                                <div v-if="segmentData.status === 'failed'" class="mt-8">
                                    <div class="bg-red-50 rounded-lg p-5 shadow-sm border border-red-200">
                                        <h3 class="text-lg font-medium mb-4 flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Processing Failed
                                        </h3>
                                        <p class="text-gray-600 mb-4">
                                            The transcription process failed. You can restart it to try again.
                                        </p>
                                        <div v-if="segmentData.error_message" class="mb-4 p-3 bg-red-100 text-red-800 rounded-md border border-red-300">
                                            <div class="font-medium">Error Details:</div>
                                            <div class="text-sm mt-1">{{ segmentData.error_message }}</div>
                                        </div>
                                        <div class="flex space-x-3">
                                            <button 
                                                @click="restartTranscription" 
                                                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md transition shadow-sm"
                                            >
                                                Restart Transcription Process
                                            </button>
                                            <button 
                                                @click="abortProcessing" 
                                                class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-md transition shadow-sm"
                                            >
                                                Reset to Ready
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Transcription Trigger -->
                                <div v-else-if="!segmentData.transcript_path && segmentData.status === 'ready'" class="mt-8">
                                    <div class="bg-blue-50 rounded-lg p-5 shadow-sm border border-blue-200">
                                        <h3 class="text-lg font-medium mb-4 flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                                            </svg>
                                            Start Transcription
                                        </h3>
                                        <p class="text-gray-600 mb-4">
                                            Extract audio and generate transcript for this TrueFire course segment.
                                        </p>
                                        <button 
                                            @click="requestTranscription" 
                                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md transition shadow-sm"
                                        >
                                            Start Audio Extraction & Transcription
                                        </button>
                                    </div>
                                </div>

                                <!-- Terminology Recognition Trigger - DISABLED -->
                                <div v-else-if="segmentData.transcript_path && !segmentData.has_terminology && segmentData.status === 'completed'" class="mt-8">
                                    <div class="bg-gray-50 rounded-lg p-5 shadow-sm border border-gray-200">
                                        <h3 class="text-lg font-medium mb-4 flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                            </svg>
                                            Term Recognition (Disabled)
                                        </h3>
                                        <p class="text-gray-600 mb-4">
                                            Terminology recognition is currently disabled. The transcript is complete.
                                        </p>
                                        <button 
                                            disabled 
                                            class="px-4 py-2 bg-gray-400 text-white rounded-md cursor-not-allowed"
                                        >
                                            Terminology Recognition Disabled
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
                                        Segment Information
                                    </h3>
                                    
                                    <div class="space-y-4">
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Course</div>
                                            <div class="font-medium">{{ course.title || `Course #${course.id}` }}</div>
                                        </div>

                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Segment ID</div>
                                            <div class="font-medium">{{ segmentData.id }}</div>
                                        </div>

                                        <div v-if="segmentData.name">
                                            <div class="text-gray-500 text-sm mb-1">Name</div>
                                            <div class="font-medium">{{ segmentData.name }}</div>
                                        </div>

                                        <div v-if="segmentData.description">
                                            <div class="text-gray-500 text-sm mb-1">Description</div>
                                            <div class="font-medium">{{ segmentData.description }}</div>
                                        </div>

                                        <div v-if="segmentData.runtime">
                                            <div class="text-gray-500 text-sm mb-1">Runtime</div>
                                            <div class="font-medium">{{ segmentData.runtime }} seconds</div>
                                        </div>
                                        
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Status</div>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" 
                                                :class="{
                                                    'bg-green-100 text-green-800': segmentData.status === 'completed',
                                                    'bg-blue-100 text-blue-800': segmentData.status === 'processing',
                                                    'bg-purple-100 text-purple-800': segmentData.status === 'transcribing',
                                                    'bg-indigo-100 text-indigo-800': segmentData.status === 'transcribed',
                                                    'bg-orange-100 text-orange-800': segmentData.status === 'processing_terminology',
                                                    'bg-yellow-100 text-yellow-800': segmentData.status === 'audio_extracted',
                                                    'bg-gray-100 text-gray-800': segmentData.status === 'ready',
                                                    'bg-red-100 text-red-800': segmentData.status === 'failed',
                                                }">
                                                <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                    v-if="segmentData.status === 'completed'">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                <svg class="w-3.5 h-3.5 mr-1.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                    v-else-if="segmentData.is_processing">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                </svg>
                                                {{ segmentData.status }}
                                            </span>
                                        </div>
                                        
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Updated</div>
                                            <div class="font-medium">{{ new Date(segmentData.updated_at).toLocaleString() }}</div>
                                        </div>
                                        
                                        <!-- Add the overall confidence display in the Segment Information section -->
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
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-lg font-medium">Processing Timeline</h3>
                                        
                                        <!-- Abort button for processing segments -->
                                        <button 
                                            v-if="segmentData.is_processing && segmentData.status !== 'ready' && segmentData.status !== 'completed'"
                                            @click="abortProcessing" 
                                            class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-sm rounded-md transition shadow-sm"
                                            title="Stop processing and reset to ready state"
                                        >
                                            Abort
                                        </button>
                                    </div>
                                    
                                    <TranscriptionTimeline 
                                        :status="timelineData.status || segmentData.status"
                                        :timing="timelineData.timing"
                                        :progress-percentage="timelineData.progress_percentage"
                                        :error="segmentData.error_message"
                                        :media-duration="segmentData.audio_duration"
                                    />
                                </div>
                                
                                <!-- Error message -->
                                <div v-if="segmentData.error_message" class="mt-4 p-3 bg-red-50 text-red-800 rounded-md border border-red-200">
                                    <div class="font-medium flex items-center">
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Error
                                    </div>
                                    <div class="mt-1">{{ segmentData.error_message }}</div>
                                </div>
                                
                                <!-- Audio Player (if audio extraction is complete) -->
                                <div v-if="segmentData.audio_url" class="mt-8">
                                    <h3 class="text-lg font-medium mb-4 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                        </svg>
                                        Extracted Audio
                                    </h3>
                                    <div class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200">
                                        <audio controls class="w-full">
                                            <source :src="segmentData.audio_url" type="audio/wav">
                                            Your browser does not support the audio element.
                                        </audio>
                                        <div class="mt-2 flex space-x-4 text-sm text-gray-600">
                                            <div v-if="segmentData.formatted_duration" class="flex items-center">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                {{ segmentData.formatted_duration }}
                                            </div>
                                            <div v-if="segmentData.audio_size" class="flex items-center">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                                </svg>
                                                {{ formatFileSize(segmentData.audio_size) }}
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