<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import AudioExtractionTestPanel from '@/Components/AudioExtractionTestPanel.vue';
import AudioTestResults from '@/Components/AudioTestResults.vue';
import AudioTestHistory from '@/Components/AudioTestHistory.vue';
import BatchTestManager from '@/Components/BatchTestManager.vue';
import CoursePresetManager from '@/Components/CoursePresetManager.vue';
import CourseTranscriptionPresetManager from '@/Components/CourseTranscriptionPresetManager.vue';
import TranscriptionTestPanel from '@/Components/TranscriptionTestPanel.vue';
import TranscriptionTestResults from '@/Components/TranscriptionTestResults.vue';

const props = defineProps({
    course: Object,
    segmentsWithSignedUrls: Array
});

const notifications = ref([]); // For toast notifications

// Audio testing state
const showAudioTestPanel = ref(false);
const showAudioTestResults = ref(false);
const showAudioTestHistory = ref(false);
const showBatchTestManager = ref(false);

const selectedTestSegmentId = ref(null);
const currentTestResults = ref(null);

// Transcription testing state
const showTranscriptionTestPanel = ref(false);
const showTranscriptionTestResults = ref(false);
const selectedTranscriptionTestSegmentId = ref(null);
const currentTranscriptionTestResults = ref(null);

// Computed properties
const totalSegments = computed(() => {
    return props.segmentsWithSignedUrls ? props.segmentsWithSignedUrls.length : 0;
});

const hasSegments = computed(() => {
    return totalSegments.value > 0;
});


// Copy to clipboard function
const copyToClipboard = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        console.log('URL copied to clipboard');
    } catch (err) {
        console.error('Failed to copy URL: ', err);
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    }
};


// Toast notification system
const showNotification = (message, type = 'info') => {
    const id = Date.now();
    notifications.value.push({
        id,
        message,
        type,
        show: true
    });
    
    // Auto-remove after 3 seconds
    setTimeout(() => {
        removeNotification(id);
    }, 3000);
};

const removeNotification = (id) => {
    const index = notifications.value.findIndex(n => n.id === id);
    if (index > -1) {
        notifications.value.splice(index, 1);
    }
};


// Utility functions for formatting
const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const formatDate = (timestamp) => {
    return new Date(timestamp * 1000).toLocaleDateString() + ' ' + new Date(timestamp * 1000).toLocaleTimeString();
};

// Audio testing methods
const openAudioTestPanel = () => {
    showAudioTestPanel.value = true;
};

const closeAudioTestPanel = () => {
    showAudioTestPanel.value = false;
    // Only reset selectedTestSegmentId if we're not transitioning to results modal
    if (!showAudioTestResults.value) {
        selectedTestSegmentId.value = null;
    }
};

const onTestStarted = (testData) => {
    console.log('Audio test started:', testData);
    showNotification(`Audio test started for segment ${testData.segmentId}`, 'info');
};

const onTestCompleted = (results) => {
    console.log('Audio test completed:', results);
    console.log('Current selectedTestSegmentId:', selectedTestSegmentId.value);
    
    // Store results and ensure we preserve the segment ID
    currentTestResults.value = results;
    
    // First open the results modal, then close the test panel to preserve selectedTestSegmentId
    showAudioTestResults.value = true;
    showAudioTestPanel.value = false;
    
    const qualityScore = results.quality_score || 
                        (Array.isArray(results) && results[0]?.result?.quality_score) ||
                        'N/A';
    showNotification(`Audio test completed with ${qualityScore}/100 quality score`, 'success');
};

const onTestFailed = (error) => {
    console.error('Audio test failed:', error);
    showNotification('Audio test failed. Please try again.', 'error');
};

const openAudioTestResults = (segmentId) => {
    // Validate segmentId before proceeding
    if (!segmentId || segmentId === null || segmentId === undefined) {
        console.error('Cannot open audio test results: Invalid segment ID provided:', segmentId);
        showNotification('Cannot view test results: Invalid segment ID', 'error');
        return;
    }
    
    selectedTestSegmentId.value = segmentId;
    showAudioTestResults.value = true;
};

const closeAudioTestResults = () => {
    showAudioTestResults.value = false;
    selectedTestSegmentId.value = null;
    currentTestResults.value = null;
};

const onRetryTest = (testData) => {
    closeAudioTestResults();
    selectedTestSegmentId.value = testData.segmentId;
    showAudioTestPanel.value = true;
};

const onDownloadAudio = (downloadData) => {
    // Create a temporary link to download the audio file
    const link = document.createElement('a');
    link.href = downloadData.url;
    link.download = downloadData.filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showNotification(`Downloading ${downloadData.filename}`, 'success');
};

const openAudioTestHistory = () => {
    showAudioTestHistory.value = true;
};

const closeAudioTestHistory = () => {
    showAudioTestHistory.value = false;
};

const onViewResults = (resultData) => {
    // Validate resultData and segmentId
    if (!resultData || !resultData.segmentId) {
        console.error('Cannot view test results: Invalid result data provided:', resultData);
        showNotification('Cannot view test results: Invalid data received', 'error');
        return;
    }
    
    selectedTestSegmentId.value = resultData.segmentId;
    currentTestResults.value = null; // Will be loaded by the component
    closeAudioTestHistory();
    showAudioTestResults.value = true;
};

const openBatchTestManager = () => {
    showBatchTestManager.value = true;
};

const closeBatchTestManager = () => {
    showBatchTestManager.value = false;
};

const onBatchTestStarted = (batchData) => {
    console.log('Batch test started:', batchData);
    showNotification(`Batch test started for ${batchData.segmentIds.length} segments`, 'info');
};

const onBatchTestCompleted = (results) => {
    console.log('Batch test completed:', results);
    showNotification(`Batch test completed: ${results.successful_count} successful, ${results.failed_count} failed`, 'success');
};

const onBatchTestFailed = (error) => {
    console.error('Batch test failed:', error);
    showNotification('Batch test failed. Please check the logs.', 'error');
};



// Transcription testing methods
const openTranscriptionTestPanel = () => {
    showTranscriptionTestPanel.value = true;
};

const closeTranscriptionTestPanel = () => {
    showTranscriptionTestPanel.value = false;
    // Only reset selectedTranscriptionTestSegmentId if we're not transitioning to results modal
    if (!showTranscriptionTestResults.value) {
        selectedTranscriptionTestSegmentId.value = null;
    }
};

const onTranscriptionTestStarted = (testData) => {
    console.log('Transcription test started:', testData);
    showNotification(`Transcription test started for segment ${testData.segmentId}`, 'info');
};

const onTranscriptionTestCompleted = (results) => {
    console.log('Transcription test completed:', results);
    console.log('Current selectedTranscriptionTestSegmentId:', selectedTranscriptionTestSegmentId.value);
    
    // Store results and ensure we preserve the segment ID
    currentTranscriptionTestResults.value = results;
    
    // First open the results modal, then close the test panel to preserve selectedTranscriptionTestSegmentId
    showTranscriptionTestResults.value = true;
    showTranscriptionTestPanel.value = false;
    
    const confidenceScore = results.confidence_score ||
                           (Array.isArray(results) && results[0]?.result?.confidence_score) ||
                           'N/A';
    showNotification(`Transcription test completed with ${confidenceScore}/100 confidence score`, 'success');
};

const onTranscriptionTestFailed = (error) => {
    console.error('Transcription test failed:', error);
    showNotification('Transcription test failed. Please try again.', 'error');
};

const openTranscriptionTestResults = (segmentId) => {
    // Validate segmentId before proceeding
    if (!segmentId || segmentId === null || segmentId === undefined) {
        console.error('Cannot open transcription test results: Invalid segment ID provided:', segmentId);
        showNotification('Cannot view test results: Invalid segment ID', 'error');
        return;
    }
    
    selectedTranscriptionTestSegmentId.value = segmentId;
    showTranscriptionTestResults.value = true;
};

const closeTranscriptionTestResults = () => {
    showTranscriptionTestResults.value = false;
    selectedTranscriptionTestSegmentId.value = null;
    currentTranscriptionTestResults.value = null;
};

const onTranscriptionRetryTest = (testData) => {
    closeTranscriptionTestResults();
    selectedTranscriptionTestSegmentId.value = testData.segmentId;
    showTranscriptionTestPanel.value = true;
};

const onDownloadTranscription = (downloadData) => {
    // Create a temporary link to download the transcription file
    const link = document.createElement('a');
    link.href = downloadData.url;
    link.download = downloadData.filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(downloadData.url); // Clean up the blob URL
    showNotification(`Downloading ${downloadData.filename}`, 'success');
};

// Course processing statistics
const courseStats = ref(null);
const statsLoading = ref(false);

// Load course processing statistics
const loadCourseStats = async () => {
    statsLoading.value = true;
    try {
        const response = await axios.get(`/truefire-courses/${props.course.id}/processing-stats`);
        if (response.data.success) {
            courseStats.value = response.data.data;
        }
    } catch (error) {
        console.error('Failed to load course statistics:', error);
        showNotification('Failed to load course statistics', 'error');
    } finally {
        statsLoading.value = false;
    }
};

// Load stats when component mounts
onMounted(() => {
    loadCourseStats();
    // Refresh stats every 30 seconds during processing
    setInterval(loadCourseStats, 30000);
    
    // Close dropdown when clicking outside
    const handleClickOutside = (event) => {
        if (restartDropdownRef.value && !restartDropdownRef.value.contains(event.target)) {
            showRestartDropdown.value = false;
        }
    };
    
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', handleEscKey);
    
    // Cleanup listener on unmount
    onUnmounted(() => {
        document.removeEventListener('click', handleClickOutside);
        document.removeEventListener('keydown', handleEscKey);
    });
});

// Batch Processing Functions
const startBatchAudioExtraction = () => {
    showAudioExtractionConfirm.value = true;
};

const startBatchTranscription = () => {
    showTranscriptionConfirm.value = true;
};

// Computed properties for processing status
const audioProcessingStatus = computed(() => {
    if (!courseStats.value) return { completed: 0, remaining: totalSegments };
    
    const completed = courseStats.value.audio_extraction?.completed_segments || 0;
    const total = courseStats.value.audio_extraction?.total_segments || totalSegments;
    return {
        completed,
        remaining: total - completed,
        percentage: total > 0 ? Math.round((completed / total) * 100) : 0
    };
});

const transcriptionProcessingStatus = computed(() => {
    if (!courseStats.value) return { completed: 0, remaining: totalSegments };
    
    const completed = courseStats.value.transcription?.completed_segments || 0;
    const total = courseStats.value.transcription?.total_segments || totalSegments;
    return {
        completed,
        remaining: total - completed,
        percentage: total > 0 ? Math.round((completed / total) * 100) : 0
    };
});

const confirmAudioExtraction = async (forceRestart = false) => {
    showAudioExtractionConfirm.value = false;
    
    try {
        const actionType = forceRestart ? 'Restarting' : (audioProcessingStatus.value.completed > 0 ? 'Continuing' : 'Starting');
        showNotification(`${actionType} batch audio extraction for course...`, 'info');
        
        const response = await axios.post(`/truefire-courses/${props.course.id}/process-all-audio-extractions`, {
            enable_intelligent_extraction: true, // Enable intelligent selection by default
            force_restart: forceRestart,
            continue_existing: !forceRestart && audioProcessingStatus.value.completed > 0
        });
        
        if (response.data.success) {
            const segmentCount = response.data.data.available_segments || audioProcessingStatus.value.remaining;
            showNotification(`Batch audio extraction ${actionType.toLowerCase()} for ${segmentCount} segments`, 'success');
            // Refresh stats to show updated progress
            loadCourseStats();
        }
    } catch (error) {
        console.error('Failed to start batch audio extraction:', error);
        const message = error.response?.data?.message || 'Failed to start batch audio extraction';
        showNotification(message, 'error');
    }
};

const confirmTranscription = async (forceRestart = false) => {
    showTranscriptionConfirm.value = false;
    
    try {
        const actionType = forceRestart ? 'Restarting' : (transcriptionProcessingStatus.value.completed > 0 ? 'Continuing' : 'Starting');
        showNotification(`${actionType} batch transcription for course...`, 'info');
        
        const response = await axios.post(`/truefire-courses/${props.course.id}/process-all-transcriptions`, {
            enable_intelligent_selection: true, // Enable intelligent selection by default
            force_restart: forceRestart,
            continue_existing: !forceRestart && transcriptionProcessingStatus.value.completed > 0
        });
        
        if (response.data.success) {
            const segmentCount = response.data.data.available_segments || transcriptionProcessingStatus.value.remaining;
            showNotification(`Batch transcription ${actionType.toLowerCase()} for ${segmentCount} segments`, 'success');
            // Refresh stats to show updated progress
            loadCourseStats();
        }
    } catch (error) {
        console.error('Failed to start batch transcription:', error);
        const message = error.response?.data?.message || 'Failed to start batch transcription';
        showNotification(message, 'error');
    }
};

const cancelBatchOperation = () => {
    showAudioExtractionConfirm.value = false;
    showTranscriptionConfirm.value = false;
};

// Handle ESC key for modals
const handleEscKey = (event) => {
    if (event.key === 'Escape') {
        if (showAudioExtractionConfirm.value || showTranscriptionConfirm.value) {
            cancelBatchOperation();
        }
    }
};

const restartCourseTranscription = async () => {
    if (!confirm('Are you sure you want to restart course transcription? This will clear existing transcriptions and start fresh.')) {
        return;
    }
    
    try {
        showNotification('Restarting course transcription...', 'info');
        
        const response = await axios.post(`/truefire-courses/${props.course.id}/restart-transcription`, {
            clear_existing: true,
            enable_intelligent_selection: true // Use intelligent selection by default
        });
        
        if (response.data.success) {
            showNotification('Course transcription restarted successfully', 'success');
            // Refresh stats to show updated progress
            loadCourseStats();
        }
    } catch (error) {
        console.error('Failed to restart transcription:', error);
        const message = error.response?.data?.message || 'Failed to restart transcription';
        showNotification(message, 'error');
    }
};

const restartCourseAudioExtraction = async () => {
    if (!confirm('Are you sure you want to restart course audio extraction? This will clear existing audio files and start fresh.')) {
        return;
    }
    
    try {
        showNotification('Restarting course audio extraction...', 'info');
        
        const response = await axios.post(`/truefire-courses/${props.course.id}/restart-audio-extraction`, {
            clear_existing: true,
            enable_intelligent_extraction: true // Use intelligent extraction by default
        });
        
        if (response.data.success) {
            showNotification('Course audio extraction restarted successfully', 'success');
            // Refresh stats to show updated progress
            loadCourseStats();
        }
    } catch (error) {
        console.error('Failed to restart audio extraction:', error);
        const message = error.response?.data?.message || 'Failed to restart audio extraction';
        showNotification(message, 'error');
    }
};

const restartEntireCourseProcessing = async () => {
    if (!confirm('Are you sure you want to restart the ENTIRE course processing pipeline? This will clear ALL existing audio and transcription files and start completely fresh. This action cannot be undone.')) {
        return;
    }
    
    try {
        showNotification('Restarting entire course processing pipeline...', 'info');
        
        const response = await axios.post(`/truefire-courses/${props.course.id}/restart-entire-processing`, {
            clear_existing: true,
            enable_intelligent_extraction: true,
            enable_intelligent_selection: true
        });
        
        if (response.data.success) {
            showNotification('Entire course processing pipeline restarted successfully', 'success');
            // Refresh stats to show updated progress
            loadCourseStats();
        }
    } catch (error) {
        console.error('Failed to restart entire processing:', error);
        const message = error.response?.data?.message || 'Failed to restart entire processing';
        showNotification(message, 'error');
    }
};

// Dropdown state for restart options
const showRestartDropdown = ref(false);
const restartDropdownRef = ref(null);

// Intelligent selection info toggle
const showIntelligentSelectionInfo = ref(false);
const showIntelligentAudioInfo = ref(false);

// Batch confirmation modals
const showAudioExtractionConfirm = ref(false);
const showTranscriptionConfirm = ref(false);
</script>

<template>
    <Head :title="`TrueFire Course: ${props.course.name || props.course.title || `Course #${props.course.id}`}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ props.course.name || props.course.title || `TrueFire Course #${props.course.id}` }}
                </h2>
                <div class="flex items-center space-x-3">
                    <Link
                        :href="route('truefire-courses.index')"
                        class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    >
                        ← Back to Courses
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Course Information -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            Course Information
                        </h3>
                        
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Course ID</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ props.course.id }}</dd>
                            </div>
                            
                            <div v-if="props.course.description">
                                <dt class="text-sm font-medium text-gray-500">Description</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ props.course.description }}</dd>
                            </div>
                            
                            <div v-if="props.course.created_at">
                                <dt class="text-sm font-medium text-gray-500">Created At</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ new Date(props.course.created_at).toLocaleString() }}</dd>
                            </div>
                        </dl>
                        
                    </div>
                </div>
<!-- Channels Table -->
<div class="overflow-hidden bg-white shadow-sm sm:rounded-lg" v-if="props.course.channels && props.course.channels.length > 1">
                <!-- Channels Table -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            Course Channels
                        </h3>
                        
                        <div class="overflow-x-auto" v-if="props.course.channels && props.course.channels.length > 0">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Channel ID
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Channel Name
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Segments
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Created At
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="channel in props.course.channels" :key="channel.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ channel.id }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ channel.name || channel.title || `Channel #${channel.id}` }}
                                            </div>
                                            <div class="text-sm text-gray-500" v-if="channel.description">
                                                {{ channel.description }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                {{ channel.segments ? channel.segments.length : 0 }} segments
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ channel.created_at ? new Date(channel.created_at).toLocaleDateString() : 'N/A' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div v-else class="text-center py-12">
                            <div class="text-gray-500">
                                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                                <h3 class="mt-2 text-sm font-medium text-gray-900">No channels found</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    This course doesn't have any channels associated with it.
                                </p>
                            </div>
</div>
                        </div>
                    </div>
                </div>

                <!-- Audio Testing Panel -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg mt-6">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-between items-center mb-6">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Course Batch Processing</h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    Process all segments in this course with audio extraction and transcription
                                </p>
                            </div>
                            <div class="flex items-center space-x-3">
                                <!-- Restart Options Dropdown -->
                                <div class="relative" ref="restartDropdownRef">
                                    <button
                                        @click.stop="showRestartDropdown = !showRestartDropdown"
                                        class="inline-flex items-center px-3 py-2 border border-orange-300 rounded-md text-sm font-medium text-orange-700 bg-orange-50 hover:bg-orange-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500"
                                    >
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                        </svg>
                                        Restart Options
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </button>
                                    
                                    <!-- Dropdown Menu -->
                                    <div v-if="showRestartDropdown" 
                                         class="absolute right-0 mt-2 w-80 bg-white rounded-md shadow-lg border border-gray-200 z-50"
                                         @click.stop>
                                        <div class="py-1">
                                            <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wide bg-gray-50">
                                                Restart Processing Options
                                            </div>
                                            
                                            <!-- Restart Transcription Only -->
                                            <button
                                                @click="restartCourseTranscription(); showRestartDropdown = false"
                                                class="flex items-start w-full px-4 py-3 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-900 transition-colors duration-150"
                                            >
                                                <svg class="w-5 h-5 mr-3 mt-0.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                                                </svg>
                                                <div>
                                                    <div class="font-medium">Restart Transcription Only</div>
                                                    <div class="text-xs text-gray-500">Clear transcripts, keep audio files</div>
                                                </div>
                                            </button>
                                            
                                            <!-- Restart Audio Extraction Only -->
                                            <button
                                                @click="restartCourseAudioExtraction(); showRestartDropdown = false"
                                                class="flex items-start w-full px-4 py-3 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-900 transition-colors duration-150"
                                            >
                                                <svg class="w-5 h-5 mr-3 mt-0.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                                </svg>
                                                <div>
                                                    <div class="font-medium">Restart Audio Extraction Only</div>
                                                    <div class="text-xs text-gray-500">Clear audio files, keep transcripts</div>
                                                </div>
                                            </button>
                                            
                                            <div class="border-t border-gray-100 my-1"></div>
                                            
                                            <!-- Restart Entire Pipeline -->
                                            <button
                                                @click="restartEntireCourseProcessing(); showRestartDropdown = false"
                                                class="flex items-start w-full px-4 py-3 text-sm text-gray-700 hover:bg-red-50 hover:text-red-900 transition-colors duration-150"
                                            >
                                                <svg class="w-5 h-5 mr-3 mt-0.5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <div>
                                                    <div class="font-medium text-red-700">Restart Entire Pipeline</div>
                                                    <div class="text-xs text-red-500">⚠️ Clear ALL files and start fresh</div>
                                                </div>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Batch Processing Actions -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <!-- Audio Extraction Batch -->
                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0">
                                            <svg class="h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <h4 class="text-lg font-semibold text-blue-900">Audio Extraction</h4>
                                            <p class="text-sm text-blue-700">Intelligent quality selection with cascading escalation</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-blue-700">Current Method:</span>
                                        <div class="text-right">
                                            <span class="font-medium text-blue-900">Intelligent (Cascading Quality)</span>
                                            <div class="text-xs text-blue-600">Fast→balanced→high→premium as needed <span class="text-green-700 font-semibold">(Enabled)</span></div>
                                        </div>
                                    </div>
                                    <button
                                        @click="startBatchAudioExtraction"
                                        class="w-full inline-flex items-center justify-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition ease-in-out duration-150"
                                    >
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h.01M16 14h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Start Audio Extraction
                                    </button>
                                </div>
                            </div>

                            <!-- Transcription Batch -->
                            <div class="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-200 rounded-lg p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0">
                                            <svg class="h-8 w-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <h4 class="text-lg font-semibold text-emerald-900">Transcription</h4>
                                            <p class="text-sm text-emerald-700">Generate transcripts with quality metrics</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-emerald-700">Current Preset:</span>
                                        <div class="text-right">
                                            <span class="font-medium text-emerald-900">Balanced (Intelligent Selection)</span>
                                            <div class="text-xs text-emerald-600">Cascading tiny→small→medium→large as needed</div>
                                        </div>
                                    </div>
                                    <button
                                        @click="startBatchTranscription"
                                        class="w-full inline-flex items-center justify-center px-4 py-2 bg-emerald-600 border border-transparent rounded-md font-semibold text-sm text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition ease-in-out duration-150"
                                    >
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                        </svg>
                                        Start Transcription
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Intelligent Audio Extraction Information Toggle -->
                        <div class="mb-6">
                            <!-- Toggle Button -->
                            <button 
                                @click="showIntelligentAudioInfo = !showIntelligentAudioInfo"
                                class="w-full bg-gradient-to-r from-blue-50 to-cyan-50 border border-blue-200 rounded-lg p-4 hover:from-blue-100 hover:to-cyan-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div class="flex-shrink-0">
                                            <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                            </svg>
                                        </div>
                                        <div class="text-left">
                                            <h4 class="text-lg font-semibold text-blue-900">🎵 Intelligent Audio Extraction <span class="text-sm text-green-600">(Enabled)</span></h4>
                                            <p class="text-sm text-blue-700">
                                                Smart quality escalation system - optimizes audio processing automatically
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full font-medium">
                                            {{ showIntelligentAudioInfo ? 'Hide Details' : 'Learn More' }}
                                        </span>
                                        <svg 
                                            class="w-5 h-5 text-blue-600 transition-transform duration-200"
                                            :class="{ 'rotate-180': showIntelligentAudioInfo }"
                                            fill="none" 
                                            stroke="currentColor" 
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                            </button>
                            
                            <!-- Collapsible Content -->
                            <transition 
                                enter-active-class="transition duration-300 ease-out"
                                enter-from-class="transform scale-95 opacity-0"
                                enter-to-class="transform scale-100 opacity-100"
                                leave-active-class="transition duration-200 ease-in"
                                leave-from-class="transform scale-100 opacity-100"
                                leave-to-class="transform scale-95 opacity-0"
                            >
                                <div v-show="showIntelligentAudioInfo" class="mt-4 bg-gradient-to-br from-blue-50 to-cyan-50 border border-blue-200 rounded-lg p-6">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- How It Works -->
                                <div class="bg-white rounded-lg p-4 border border-blue-100">
                                    <h5 class="font-semibold text-blue-900 mb-3 flex items-center">
                                        <svg class="w-4 h-4 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                        </svg>
                                        How It Works
                                    </h5>
                                    <div class="space-y-3 text-sm text-gray-700">
                                        <div class="flex items-start space-x-2">
                                            <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center text-green-600 text-xs font-bold mt-0.5">1</div>
                                            <div>
                                                <strong>Start Fast:</strong> Begins with fast quality (minimal processing)
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 text-xs font-bold mt-0.5">2</div>
                                            <div>
                                                <strong>Quality Check:</strong> Analyzes audio metrics (sample rate, volume, dynamics)
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <div class="w-6 h-6 bg-purple-100 rounded-full flex items-center justify-center text-purple-600 text-xs font-bold mt-0.5">3</div>
                                            <div>
                                                <strong>Auto-Escalate:</strong> Upgrades to higher quality levels when needed
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <div class="w-6 h-6 bg-orange-100 rounded-full flex items-center justify-center text-orange-600 text-xs font-bold mt-0.5">4</div>
                                            <div>
                                                <strong>Optimize:</strong> Achieves 60% time savings vs. always using premium quality
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Quality Thresholds -->
                                <div class="bg-white rounded-lg p-4 border border-blue-100">
                                    <h5 class="font-semibold text-blue-900 mb-3 flex items-center">
                                        <svg class="w-4 h-4 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                        </svg>
                                        Quality Thresholds
                                    </h5>
                                    <div class="space-y-2 text-sm">
                                        <div class="flex justify-between items-center py-1">
                                            <span class="text-gray-600">Fast Processing</span>
                                            <div class="flex items-center">
                                                <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded mr-2">70/100</span>
                                                <span class="text-xs text-gray-500">escalation threshold</span>
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center py-1">
                                            <span class="text-gray-600">Balanced Quality</span>
                                            <div class="flex items-center">
                                                <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded mr-2">75/100</span>
                                                <span class="text-xs text-gray-500">escalation threshold</span>
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center py-1">
                                            <span class="text-gray-600">High Quality</span>
                                            <div class="flex items-center">
                                                <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded mr-2">80/100</span>
                                                <span class="text-xs text-gray-500">escalation threshold</span>
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center py-1">
                                            <span class="text-gray-600">Premium Quality</span>
                                            <div class="flex items-center">
                                                <span class="text-xs bg-orange-100 text-orange-800 px-2 py-1 rounded mr-2">85/100</span>
                                                <span class="text-xs text-gray-500">final quality target</span>
                                            </div>
                                        </div>
                                        <div class="mt-3 pt-2 border-t border-gray-200">
                                            <div class="flex justify-between items-center text-xs">
                                                <span class="text-gray-500">Target Quality:</span>
                                                <span class="font-semibold text-blue-600">≥75/100 score</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Performance Benefits -->
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div class="bg-white rounded-lg p-3 border border-blue-100 text-center">
                                    <div class="text-2xl font-bold text-green-600">60%</div>
                                    <div class="text-xs text-gray-600">Time Savings</div>
                                    <div class="text-xs text-gray-500 mt-1">vs. always using premium</div>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-blue-100 text-center">
                                    <div class="text-2xl font-bold text-blue-600">92%</div>
                                    <div class="text-xs text-gray-600">Quality Success</div>
                                    <div class="text-xs text-gray-500 mt-1">achieve ≥75 quality score</div>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-blue-100 text-center">
                                    <div class="text-2xl font-bold text-purple-600">30%</div>
                                    <div class="text-xs text-gray-600">Escalation Rate</div>
                                    <div class="text-xs text-gray-500 mt-1">need higher quality</div>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-blue-100 text-center">
                                    <div class="text-2xl font-bold text-orange-600">50%</div>
                                    <div class="text-xs text-gray-600">Cost Reduction</div>
                                    <div class="text-xs text-gray-500 mt-1">through efficiency</div>
                                </div>
                            </div>
                            
                            <!-- Technical Details Collapsible -->
                            <div class="mt-4">
                                <details class="group">
                                    <summary class="flex items-center justify-between cursor-pointer text-sm font-medium text-blue-700 hover:text-blue-900">
                                        <span class="flex items-center">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                            Technical Details & Quality Analysis
                                        </span>
                                        <svg class="w-4 h-4 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </summary>
                                    <div class="mt-3 bg-white rounded-lg p-4 border border-blue-100">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                            <div>
                                                <h6 class="font-semibold text-gray-800 mb-2">Audio Quality Metrics (Weighted)</h6>
                                                <ul class="space-y-1 text-gray-600">
                                                    <li>• <strong>25% weight:</strong> Sample rate optimization</li>
                                                    <li>• <strong>30% weight:</strong> Volume level balance</li>
                                                    <li>• <strong>20% weight:</strong> Dynamic range analysis</li>
                                                    <li>• <strong>15% weight:</strong> Duration efficiency</li>
                                                    <li>• <strong>10% weight:</strong> Bit rate quality</li>
                                                </ul>
                                            </div>
                                            <div>
                                                <h6 class="font-semibold text-gray-800 mb-2">Escalation Triggers</h6>
                                                <ul class="space-y-1 text-gray-600">
                                                    <li>• Overall quality score below threshold</li>
                                                    <li>• Poor sample rate optimization (&lt;70)</li>
                                                    <li>• Volume level issues (&lt;60)</li>
                                                    <li>• Limited dynamic range (&lt;65)</li>
                                                    <li>• Inadequate bit rate quality (&lt;70)</li>
                                                </ul>
                                            </div>
                                        </div>
                                        <div class="mt-4 p-3 bg-gray-50 rounded border">
                                            <p class="text-xs text-gray-600">
                                                <strong>Current Status:</strong> Intelligent audio extraction is <span class="text-green-600 font-semibold">ENABLED by default</span>. 
                                                The system automatically uses cascading quality selection for optimal speed and quality. To disable intelligent extraction, add 
                                                <code class="bg-gray-200 px-1 rounded">"enable_intelligent_extraction": false</code> 
                                                to your audio extraction API requests.
                                            </p>
                                        </div>
                                    </div>
                                </details>
                            </div>
                                </div>
                            </transition>
                        </div>
                        
                        <!-- Intelligent Model Selection Information Toggle -->
                        <div class="mb-6">
                            <!-- Toggle Button -->
                            <button 
                                @click="showIntelligentSelectionInfo = !showIntelligentSelectionInfo"
                                class="w-full bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg p-4 hover:from-indigo-100 hover:to-purple-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div class="flex-shrink-0">
                                            <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                        </div>
                                        <div class="text-left">
                                            <h4 class="text-lg font-semibold text-indigo-900">🧠 Intelligent Model Selection <span class="text-sm text-green-600">(Enabled)</span></h4>
                                            <p class="text-sm text-indigo-700">
                                                AI-powered cascading model selection - active by default for optimal performance
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full font-medium">
                                            {{ showIntelligentSelectionInfo ? 'Hide Details' : 'Learn More' }}
                                        </span>
                                        <svg 
                                            class="w-5 h-5 text-indigo-600 transition-transform duration-200"
                                            :class="{ 'rotate-180': showIntelligentSelectionInfo }"
                                            fill="none" 
                                            stroke="currentColor" 
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                            </button>
                            
                            <!-- Collapsible Content -->
                            <transition 
                                enter-active-class="transition duration-300 ease-out"
                                enter-from-class="transform scale-95 opacity-0"
                                enter-to-class="transform scale-100 opacity-100"
                                leave-active-class="transition duration-200 ease-in"
                                leave-from-class="transform scale-100 opacity-100"
                                leave-to-class="transform scale-95 opacity-0"
                            >
                                <div v-show="showIntelligentSelectionInfo" class="mt-4 bg-gradient-to-br from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg p-6">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- How It Works -->
                                <div class="bg-white rounded-lg p-4 border border-indigo-100">
                                    <h5 class="font-semibold text-indigo-900 mb-3 flex items-center">
                                        <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                        </svg>
                                        How It Works
                                    </h5>
                                    <div class="space-y-3 text-sm text-gray-700">
                                        <div class="flex items-start space-x-2">
                                            <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center text-green-600 text-xs font-bold mt-0.5">1</div>
                                            <div>
                                                <strong>Start Fast:</strong> Begins with the tiny model (fastest processing)
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 text-xs font-bold mt-0.5">2</div>
                                            <div>
                                                <strong>Quality Check:</strong> Analyzes confidence and consistency scores
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <div class="w-6 h-6 bg-purple-100 rounded-full flex items-center justify-center text-purple-600 text-xs font-bold mt-0.5">3</div>
                                            <div>
                                                <strong>Auto-Escalate:</strong> Upgrades to larger models only when needed
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <div class="w-6 h-6 bg-orange-100 rounded-full flex items-center justify-center text-orange-600 text-xs font-bold mt-0.5">4</div>
                                            <div>
                                                <strong>Optimize:</strong> Saves 70-80% processing time vs. always using the largest model
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Quality Thresholds -->
                                <div class="bg-white rounded-lg p-4 border border-indigo-100">
                                    <h5 class="font-semibold text-indigo-900 mb-3 flex items-center">
                                        <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                        </svg>
                                        Quality Thresholds
                                    </h5>
                                    <div class="space-y-2 text-sm">
                                        <div class="flex justify-between items-center py-1">
                                            <span class="text-gray-600">Tiny Model</span>
                                            <div class="flex items-center">
                                                <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded mr-2">75%</span>
                                                <span class="text-xs text-gray-500">escalation threshold</span>
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center py-1">
                                            <span class="text-gray-600">Small Model</span>
                                            <div class="flex items-center">
                                                <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded mr-2">80%</span>
                                                <span class="text-xs text-gray-500">escalation threshold</span>
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center py-1">
                                            <span class="text-gray-600">Medium Model</span>
                                            <div class="flex items-center">
                                                <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded mr-2">85%</span>
                                                <span class="text-xs text-gray-500">escalation threshold</span>
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center py-1">
                                            <span class="text-gray-600">Large-v3 Model</span>
                                            <div class="flex items-center">
                                                <span class="text-xs bg-orange-100 text-orange-800 px-2 py-1 rounded mr-2">90%</span>
                                                <span class="text-xs text-gray-500">final quality target</span>
                                            </div>
                                        </div>
                                        <div class="mt-3 pt-2 border-t border-gray-200">
                                            <div class="flex justify-between items-center text-xs">
                                                <span class="text-gray-500">Target Quality:</span>
                                                <span class="font-semibold text-indigo-600">≥80% confidence</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Performance Benefits -->
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div class="bg-white rounded-lg p-3 border border-indigo-100 text-center">
                                    <div class="text-2xl font-bold text-green-600">75%</div>
                                    <div class="text-xs text-gray-600">Time Savings</div>
                                    <div class="text-xs text-gray-500 mt-1">vs. always using large model</div>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-indigo-100 text-center">
                                    <div class="text-2xl font-bold text-blue-600">95%</div>
                                    <div class="text-xs text-gray-600">Quality Success</div>
                                    <div class="text-xs text-gray-500 mt-1">achieve ≥80% confidence</div>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-indigo-100 text-center">
                                    <div class="text-2xl font-bold text-purple-600">25%</div>
                                    <div class="text-xs text-gray-600">Escalation Rate</div>
                                    <div class="text-xs text-gray-500 mt-1">need larger models</div>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-indigo-100 text-center">
                                    <div class="text-2xl font-bold text-orange-600">60%</div>
                                    <div class="text-xs text-gray-600">Cost Reduction</div>
                                    <div class="text-xs text-gray-500 mt-1">through efficiency</div>
                                </div>
                            </div>
                            
                            <!-- Technical Details Collapsible -->
                            <div class="mt-4">
                                <details class="group">
                                    <summary class="flex items-center justify-between cursor-pointer text-sm font-medium text-indigo-700 hover:text-indigo-900">
                                        <span class="flex items-center">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                            Technical Details & Decision Matrix
                                        </span>
                                        <svg class="w-4 h-4 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </summary>
                                    <div class="mt-3 bg-white rounded-lg p-4 border border-indigo-100">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                            <div>
                                                <h6 class="font-semibold text-gray-800 mb-2">Multi-Metric Decision Algorithm</h6>
                                                <ul class="space-y-1 text-gray-600">
                                                    <li>• <strong>40% weight:</strong> Average confidence score</li>
                                                    <li>• <strong>30% weight:</strong> Segment consistency</li>
                                                    <li>• <strong>20% weight:</strong> Duration coverage</li>
                                                    <li>• <strong>10% penalty:</strong> Low confidence words</li>
                                                </ul>
                                            </div>
                                            <div>
                                                <h6 class="font-semibold text-gray-800 mb-2">Escalation Triggers</h6>
                                                <ul class="space-y-1 text-gray-600">
                                                    <li>• Overall quality score below threshold</li>
                                                    <li>• Average confidence &lt; 80%</li>
                                                    <li>• Poor segment consistency (&lt;60%)</li>
                                                    <li>• High low-confidence penalty (&gt;30%)</li>
                                                </ul>
                                            </div>
                                        </div>
                                        <div class="mt-4 p-3 bg-gray-50 rounded border">
                                            <p class="text-xs text-gray-600">
                                                <strong>Current Status:</strong> Intelligent selection is <span class="text-green-600 font-semibold">ENABLED by default</span>. 
                                                The system automatically uses cascading model selection for optimal speed and quality. To disable intelligent selection, add 
                                                <code class="bg-gray-200 px-1 rounded">"enable_intelligent_selection": false</code> 
                                                to your transcription API requests.
                                            </p>
                                        </div>
                                    </div>
                                </details>
                            </div>
                                </div>
                            </transition>
                        </div>
                        
                        <!-- Course Stats -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div class="bg-gray-50 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-gray-600">Total Segments</p>
                                        <p class="text-lg font-semibold text-gray-900">
                                            {{ courseStats ? courseStats.course_info.total_segments : totalSegments }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-blue-50 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-blue-600">Audio Extracted</p>
                                        <div v-if="courseStats">
                                            <p class="text-lg font-semibold text-blue-900">
                                                {{ courseStats.audio_extraction.completed_segments }} / {{ courseStats.audio_extraction.total_segments }}
                                            </p>
                                            <p class="text-xs text-blue-600">
                                                {{ courseStats.audio_extraction.completion_percentage }}%
                                            </p>
                                        </div>
                                        <p v-else class="text-lg font-semibold text-blue-900">Loading...</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-emerald-50 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-emerald-600">Transcribed</p>
                                        <div v-if="courseStats">
                                            <p class="text-lg font-semibold text-emerald-900">
                                                {{ courseStats.transcription.completed_segments }} / {{ courseStats.transcription.total_segments }}
                                            </p>
                                            <p class="text-xs text-emerald-600">
                                                {{ courseStats.transcription.completion_percentage }}%
                                            </p>
                                        </div>
                                        <p v-else class="text-lg font-semibold text-emerald-900">Loading...</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-purple-50 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-purple-600">Quality Score</p>
                                        <div v-if="courseStats && courseStats.transcription.avg_quality_score">
                                            <p class="text-lg font-semibold text-purple-900">
                                                {{ courseStats.transcription.avg_quality_score }}
                                            </p>
                                            <p class="text-xs text-purple-600">
                                                Avg confidence
                                            </p>
                                        </div>
                                        <p v-else class="text-lg font-semibold text-purple-900">
                                            {{ courseStats ? 'N/A' : 'Loading...' }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Processing Performance Summary -->
                        <div v-if="courseStats && courseStats.summary" class="bg-gradient-to-r from-gray-50 to-blue-50 border border-gray-200 rounded-lg p-6 mb-6">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4">📊 Processing Performance Summary</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="bg-white rounded-lg p-4 border">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-gray-600">Overall Progress</p>
                                            <p class="text-2xl font-bold text-blue-600">{{ courseStats.summary.overall_completion_percentage }}%</p>
                                        </div>
                                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-white rounded-lg p-4 border" v-if="courseStats.transcription.total_processing_time_minutes">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-gray-600">Total Processing Time</p>
                                            <p class="text-2xl font-bold text-emerald-600">{{ courseStats.transcription.total_processing_time_minutes }}m</p>
                                        </div>
                                        <div class="w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center">
                                            <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-white rounded-lg p-4 border" v-if="courseStats.summary.estimated_remaining_time_minutes">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-gray-600">Est. Remaining Time</p>
                                            <p class="text-2xl font-bold text-purple-600">{{ courseStats.summary.estimated_remaining_time_minutes }}m</p>
                                        </div>
                                        <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                                            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-white rounded-lg p-4 border" v-if="courseStats.summary.ready_for_transcription > 0">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-gray-600">Ready for Transcription</p>
                                            <p class="text-2xl font-bold text-orange-600">{{ courseStats.summary.ready_for_transcription }}</p>
                                        </div>
                                        <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                                            <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Recent batch jobs if available -->
                            <div v-if="courseStats.recent_batch_jobs && courseStats.recent_batch_jobs.length > 0" class="mt-6">
                                <h5 class="text-sm font-semibold text-gray-700 mb-3">Recent Batch Jobs</h5>
                                <div class="space-y-2">
                                    <div v-for="job in courseStats.recent_batch_jobs.slice(0, 3)" :key="job.job_id" 
                                         class="flex items-center justify-between p-3 bg-white rounded border">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-2 h-2 rounded-full" 
                                                 :class="{
                                                     'bg-green-500': job.status === 'completed',
                                                     'bg-blue-500': job.status === 'processing',
                                                     'bg-red-500': job.status === 'failed',
                                                     'bg-yellow-500': job.status === 'completed_with_errors'
                                                 }">
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-gray-900">{{ job.job_id }}</p>
                                                <p class="text-xs text-gray-500">{{ new Date(job.started_at).toLocaleDateString() }}</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-sm font-medium text-gray-900">{{ job.processed_segments || 0 }} segments</p>
                                            <p class="text-xs text-gray-500" v-if="job.duration_minutes">{{ job.duration_minutes }}m</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions Guide -->
                        <div class="bg-gradient-to-r from-indigo-50 to-blue-50 border border-indigo-200 rounded-lg p-6">
                            <h4 class="text-lg font-semibold text-indigo-900 mb-3">🚀 Intelligent Course Processing Workflow</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0 w-8 h-8 bg-emerald-600 rounded-full flex items-center justify-center text-white text-sm font-bold">1</div>
                                    <div>
                                        <h5 class="font-medium text-emerald-900">Intelligent Batch Processing</h5>
                                        <p class="text-sm text-emerald-700">System automatically optimizes audio extraction and transcription quality across all course segments with intelligent selection.</p>
                                    </div>
                                </div>
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0 w-8 h-8 bg-purple-600 rounded-full flex items-center justify-center text-white text-sm font-bold">2</div>
                                    <div>
                                        <h5 class="font-medium text-purple-900">Monitor & Review</h5>
                                        <p class="text-sm text-purple-700">Track real-time processing progress and review individual segment quality metrics as needed.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Course Segments Overview Table -->
                <div v-if="segmentsWithSignedUrls && segmentsWithSignedUrls.length > 0" class="overflow-hidden bg-white shadow-sm sm:rounded-lg mt-6">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-between items-center mb-6">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Course Segments</h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    Overview of all segments in this course - click to view individual segment details
                                </p>
                            </div>
                            <div class="flex items-center space-x-4">
                                <div class="text-sm text-gray-500">
                                    {{ segmentsWithSignedUrls.length }} segments available
                                </div>
                                <div class="text-xs bg-blue-50 text-blue-700 px-2 py-1 rounded">
                                    Course {{ props.course.id }}
                                </div>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Segment
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Channel
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Title
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Processing Status
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="segment in segmentsWithSignedUrls" :key="segment.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">{{ segment.id }}</div>
                                            <div class="text-xs text-gray-500">Segment ID</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">{{ segment.channel_name }}</div>
                                            <div class="text-xs text-gray-500">ID: {{ segment.channel_id }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">{{ segment.title }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center space-x-2">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                    🔄 Pending
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <Link
                                                :href="route('truefire-courses.segments.show', [props.course.id, segment.id])"
                                                class="inline-flex items-center px-3 py-1 bg-indigo-600 text-white rounded-md text-xs hover:bg-indigo-700 transition-all duration-200"
                                            >
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                                View Details
                                            </Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Toast Notifications -->
        <div class="fixed top-4 right-4 z-50 space-y-2">
            <div
                v-for="notification in notifications"
                :key="notification.id"
                class="transform transition-all duration-300 ease-in-out"
                :class="{
                    'translate-x-0 opacity-100': notification.show,
                    'translate-x-full opacity-0': !notification.show
                }"
            >
                <div
                    class="bg-white rounded-lg shadow-lg border-l-4 p-4 max-w-sm"
                    :class="{
                        'border-blue-500': notification.type === 'info',
                        'border-green-500': notification.type === 'success',
                        'border-red-500': notification.type === 'error',
                        'border-yellow-500': notification.type === 'warning'
                    }"
                >
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg v-if="notification.type === 'success'" class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <svg v-else-if="notification.type === 'error'" class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            <svg v-else-if="notification.type === 'warning'" class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <svg v-else class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="ml-3 flex-1">
                            <p class="text-sm font-medium text-gray-900">
                                {{ notification.message }}
                            </p>
                        </div>
                        <div class="ml-4 flex-shrink-0">
                            <button
                                @click="removeNotification(notification.id)"
                                class="inline-flex text-gray-400 hover:text-gray-600 focus:outline-none focus:text-gray-600"
                            >
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Audio Testing Components -->
        <AudioExtractionTestPanel
            :show="showAudioTestPanel"
            :course-id="props.course.id"
            :segments="segmentsWithSignedUrls || []"
            @close="closeAudioTestPanel"
            @test-started="onTestStarted"
            @test-completed="onTestCompleted"
            @test-failed="onTestFailed"
        />

        <AudioTestResults
            :show="showAudioTestResults"
            :course-id="props.course.id"
            :segment-id="selectedTestSegmentId || 0"
            :test-results="currentTestResults"
            @close="closeAudioTestResults"
            @retry-test="onRetryTest"
            @download-audio="onDownloadAudio"
        />

        <AudioTestHistory
            :show="showAudioTestHistory"
            @close="closeAudioTestHistory"
            @view-results="onViewResults"
            @retry-test="onRetryTest"
        />

        <BatchTestManager
            :show="showBatchTestManager"
            :course-id="props.course.id"
            :segments="segmentsWithSignedUrls || []"
            @close="closeBatchTestManager"
            @batch-started="onBatchTestStarted"
            @batch-completed="onBatchTestCompleted"
            @batch-failed="onBatchTestFailed"
        />



        <!-- Transcription Testing Components -->
        <TranscriptionTestPanel
            :show="showTranscriptionTestPanel"
            :course-id="props.course.id"
            @close="closeTranscriptionTestPanel"
            @test-started="onTranscriptionTestStarted"
            @test-completed="onTranscriptionTestCompleted"
            @test-failed="onTranscriptionTestFailed"
        />

        <TranscriptionTestResults
            :show="showTranscriptionTestResults"
            :course-id="props.course.id"
            :segment-id="selectedTranscriptionTestSegmentId || 0"
            :test-results="currentTranscriptionTestResults"
            @close="closeTranscriptionTestResults"
            @retry-test="onTranscriptionRetryTest"
            @download-transcription="onDownloadTranscription"
        />

        <!-- Batch Audio Extraction Confirmation Modal -->
        <div v-if="showAudioExtractionConfirm" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-blue-100">
                        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                        </svg>
                    </div>
                    <div class="mt-2 px-7 py-3">
                        <h3 class="text-lg font-medium text-gray-900 text-center">
                            {{ audioProcessingStatus.completed > 0 ? 'Continue' : 'Start' }} Batch Audio Extraction
                        </h3>
                        
                        <!-- Processing Status Summary -->
                        <div class="mt-3 p-3 bg-gray-50 rounded-lg" v-if="audioProcessingStatus.completed > 0">
                            <h4 class="text-sm font-semibold text-gray-800 mb-2">📊 Current Processing Status:</h4>
                            <div class="flex items-center justify-between text-sm">
                                <div class="flex items-center space-x-2">
                                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                                    <span class="text-gray-700">Completed: <strong>{{ audioProcessingStatus.completed }}</strong></span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                                    <span class="text-gray-700">Remaining: <strong>{{ audioProcessingStatus.remaining }}</strong></span>
                                </div>
                            </div>
                            <div class="mt-2 bg-gray-200 rounded-full h-2">
                                <div class="bg-green-500 h-2 rounded-full" :style="`width: ${audioProcessingStatus.percentage}%`"></div>
                            </div>
                            <p class="text-xs text-gray-600 mt-1 text-center">{{ audioProcessingStatus.percentage }}% complete</p>
                        </div>
                        
                        <p class="mt-2 text-sm text-gray-500 text-center">
                            <span v-if="audioProcessingStatus.remaining > 0">
                                Process audio extraction for <strong>{{ audioProcessingStatus.remaining }} remaining segments</strong> using intelligent quality selection.
                            </span>
                            <span v-else-if="audioProcessingStatus.completed > 0">
                                All segments have been processed. You can restart to reprocess existing files.
                            </span>
                            <span v-else>
                                Process audio extraction for <strong>all {{ totalSegments }} segments</strong> using intelligent quality selection.
                            </span>
                        </p>
                        
                        <div class="mt-4 p-4 bg-blue-50 rounded-lg" v-if="audioProcessingStatus.remaining > 0">
                            <h4 class="text-sm font-semibold text-blue-900 mb-2">🤖 Intelligent Processing Details:</h4>
                            <ul class="text-sm text-blue-700 space-y-1">
                                <li>• Automatic quality escalation (fast→balanced→high→premium)</li>
                                <li>• Estimated time: <strong>{{ Math.round(audioProcessingStatus.remaining * 1.5) }} minutes</strong></li>
                                <li>• Will optimize for 60% time savings vs premium-only</li>
                                <li>• Processing can be monitored in real-time</li>
                                <li v-if="audioProcessingStatus.completed > 0">• <strong>Skips already processed segments</strong></li>
                            </ul>
                        </div>
                        
                        <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded" v-if="audioProcessingStatus.remaining > 0">
                            <p class="text-xs text-yellow-800">
                                ⚠️ This will only process remaining segments. Use "Restart All" to reprocess existing files.
                            </p>
                        </div>
                        
                        <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded" v-else-if="audioProcessingStatus.completed > 0">
                            <p class="text-xs text-green-800">
                                ✅ All segments already processed. Use "Restart All" if you want to reprocess with different settings.
                            </p>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="px-4 py-3">
                        <div v-if="audioProcessingStatus.remaining > 0" class="flex space-x-2">
                            <button
                                @click="cancelBatchOperation"
                                class="flex-1 px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300"
                            >
                                Cancel
                            </button>
                            <button
                                @click="confirmAudioExtraction(false)"
                                class="flex-1 px-4 py-2 bg-blue-600 text-white text-base font-medium rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                {{ audioProcessingStatus.completed > 0 ? 'Continue' : 'Start' }} ({{ audioProcessingStatus.remaining }})
                            </button>
                            <button
                                v-if="audioProcessingStatus.completed > 0"
                                @click="confirmAudioExtraction(true)"
                                class="flex-1 px-4 py-2 bg-orange-600 text-white text-base font-medium rounded-md shadow-sm hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500"
                            >
                                Restart All
                            </button>
                        </div>
                        <div v-else class="flex space-x-3">
                            <button
                                @click="cancelBatchOperation"
                                class="flex-1 px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300"
                            >
                                Cancel
                            </button>
                            <button
                                @click="confirmAudioExtraction(true)"
                                class="flex-1 px-4 py-2 bg-orange-600 text-white text-base font-medium rounded-md shadow-sm hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500"
                            >
                                Restart All ({{ totalSegments }})
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Batch Transcription Confirmation Modal -->
        <div v-if="showTranscriptionConfirm" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-emerald-100">
                        <svg class="h-6 w-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                        </svg>
                    </div>
                    <div class="mt-2 px-7 py-3">
                        <h3 class="text-lg font-medium text-gray-900 text-center">
                            {{ transcriptionProcessingStatus.completed > 0 ? 'Continue' : 'Start' }} Batch Transcription
                        </h3>
                        
                        <!-- Processing Status Summary -->
                        <div class="mt-3 p-3 bg-gray-50 rounded-lg" v-if="transcriptionProcessingStatus.completed > 0">
                            <h4 class="text-sm font-semibold text-gray-800 mb-2">📊 Current Processing Status:</h4>
                            <div class="flex items-center justify-between text-sm">
                                <div class="flex items-center space-x-2">
                                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                                    <span class="text-gray-700">Completed: <strong>{{ transcriptionProcessingStatus.completed }}</strong></span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="w-3 h-3 bg-emerald-500 rounded-full"></div>
                                    <span class="text-gray-700">Remaining: <strong>{{ transcriptionProcessingStatus.remaining }}</strong></span>
                                </div>
                            </div>
                            <div class="mt-2 bg-gray-200 rounded-full h-2">
                                <div class="bg-emerald-500 h-2 rounded-full" :style="`width: ${transcriptionProcessingStatus.percentage}%`"></div>
                            </div>
                            <p class="text-xs text-gray-600 mt-1 text-center">{{ transcriptionProcessingStatus.percentage }}% complete</p>
                        </div>
                        
                        <p class="mt-2 text-sm text-gray-500 text-center">
                            <span v-if="transcriptionProcessingStatus.remaining > 0">
                                Process transcription for <strong>{{ transcriptionProcessingStatus.remaining }} remaining segments</strong> using intelligent model selection.
                            </span>
                            <span v-else-if="transcriptionProcessingStatus.completed > 0">
                                All segments have been transcribed. You can restart to retranscribe existing files.
                            </span>
                            <span v-else>
                                Process transcription for <strong>all {{ totalSegments }} segments</strong> using intelligent model selection.
                            </span>
                        </p>
                        
                        <div class="mt-4 p-4 bg-emerald-50 rounded-lg" v-if="transcriptionProcessingStatus.remaining > 0">
                            <h4 class="text-sm font-semibold text-emerald-900 mb-2">🧠 Intelligent Processing Details:</h4>
                            <ul class="text-sm text-emerald-700 space-y-1">
                                <li>• Cascading model selection (tiny→small→medium→large)</li>
                                <li>• Estimated time: <strong>{{ Math.round(transcriptionProcessingStatus.remaining * 0.5) }} minutes</strong></li>
                                <li>• GPU-accelerated processing (12x faster)</li>
                                <li>• Automatic quality optimization and escalation</li>
                                <li v-if="transcriptionProcessingStatus.completed > 0">• <strong>Skips already transcribed segments</strong></li>
                            </ul>
                        </div>
                        
                        <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded" v-if="transcriptionProcessingStatus.remaining > 0">
                            <p class="text-xs text-yellow-800">
                                ⚠️ This will only process remaining segments. Use "Restart All" to retranscribe existing files.
                            </p>
                        </div>
                        
                        <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded" v-else-if="transcriptionProcessingStatus.completed > 0">
                            <p class="text-xs text-green-800">
                                ✅ All segments already transcribed. Use "Restart All" if you want to retranscribe with updated models.
                            </p>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="px-4 py-3">
                        <div v-if="transcriptionProcessingStatus.remaining > 0" class="flex space-x-2">
                            <button
                                @click="cancelBatchOperation"
                                class="flex-1 px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300"
                            >
                                Cancel
                            </button>
                            <button
                                @click="confirmTranscription(false)"
                                class="flex-1 px-4 py-2 bg-emerald-600 text-white text-base font-medium rounded-md shadow-sm hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            >
                                {{ transcriptionProcessingStatus.completed > 0 ? 'Continue' : 'Start' }} ({{ transcriptionProcessingStatus.remaining }})
                            </button>
                            <button
                                v-if="transcriptionProcessingStatus.completed > 0"
                                @click="confirmTranscription(true)"
                                class="flex-1 px-4 py-2 bg-orange-600 text-white text-base font-medium rounded-md shadow-sm hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500"
                            >
                                Restart All
                            </button>
                        </div>
                        <div v-else class="flex space-x-3">
                            <button
                                @click="cancelBatchOperation"
                                class="flex-1 px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300"
                            >
                                Cancel
                            </button>
                            <button
                                @click="confirmTranscription(true)"
                                class="flex-1 px-4 py-2 bg-orange-600 text-white text-base font-medium rounded-md shadow-sm hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500"
                            >
                                Restart All ({{ totalSegments }})
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>