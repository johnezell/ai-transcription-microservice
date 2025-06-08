<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import AudioExtractionTestPanel from '@/Components/AudioExtractionTestPanel.vue';
import AudioTestResults from '@/Components/AudioTestResults.vue';
import AudioTestHistory from '@/Components/AudioTestHistory.vue';
import BatchTestManager from '@/Components/BatchTestManager.vue';
import CoursePresetManager from '@/Components/CoursePresetManager.vue';
import CourseTranscriptionPresetManager from '@/Components/CourseTranscriptionPresetManager.vue';

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
const showCoursePresetManager = ref(false);
const showCourseTranscriptionPresetManager = ref(false);
const selectedTestSegmentId = ref(null);
const currentTestResults = ref(null);

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

// Course preset manager methods
const openCoursePresetManager = () => {
    showCoursePresetManager.value = true;
};

const closeCoursePresetManager = () => {
    showCoursePresetManager.value = false;
};

const onPresetUpdated = (presetData) => {
    console.log('Course preset updated:', presetData);
    showNotification(`Audio extraction preset updated to ${presetData.preset}`, 'success');
};

const onBatchStarted = (batchData) => {
    console.log('Course batch processing started:', batchData);
    showNotification(`Batch processing started for ${batchData.totalSegments} segments with ${batchData.preset} quality`, 'info');
};

const onBatchCompleted = (results) => {
    console.log('Course batch processing completed:', results);
    const duration = Math.round(results.duration / 1000 / 60);
    showNotification(`Batch processing completed: ${results.completedSegments} segments processed in ${duration} minutes`, 'success');
};

const onBatchFailed = (error) => {
    console.error('Course batch processing failed:', error);
    showNotification('Course batch processing failed. Please check the logs.', 'error');
};

// Course transcription preset manager methods
const openCourseTranscriptionPresetManager = () => {
    showCourseTranscriptionPresetManager.value = true;
};

const closeCourseTranscriptionPresetManager = () => {
    showCourseTranscriptionPresetManager.value = false;
};

const onTranscriptionPresetUpdated = (presetData) => {
    console.log('Course transcription preset updated:', presetData);
    showNotification(`Transcription preset updated to ${presetData.preset}`, 'success');
};
</script>

<template>
    <Head :title="`TrueFire Course: ${course.name || course.title || `Course #${course.id}`}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ course.name || course.title || `TrueFire Course #${course.id}` }}
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
                                <dd class="mt-1 text-sm text-gray-900">{{ course.id }}</dd>
                            </div>
                            
                            <div v-if="course.description">
                                <dt class="text-sm font-medium text-gray-500">Description</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ course.description }}</dd>
                            </div>
                            
                            <div v-if="course.created_at">
                                <dt class="text-sm font-medium text-gray-500">Created At</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ new Date(course.created_at).toLocaleString() }}</dd>
                            </div>
                        </dl>
                        
                    </div>
                </div>
<!-- Channels Table -->
<div class="overflow-hidden bg-white shadow-sm sm:rounded-lg" v-if="course.channels && course.channels.length > 1">
                <!-- Channels Table -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            Course Channels
                        </h3>
                        
                        <div class="overflow-x-auto" v-if="course.channels && course.channels.length > 0">
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
                                    <tr v-for="channel in course.channels" :key="channel.id" class="hover:bg-gray-50">
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
                                <h3 class="text-lg font-medium text-gray-900">Audio Extraction Testing</h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    Test and analyze audio extraction quality for course segments
                                </p>
                            </div>
                            <div class="flex items-center space-x-3">
                                <button
                                    @click="openAudioTestHistory"
                                    class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    Test History
                                </button>
                                <button
                                    @click="openBatchTestManager"
                                    class="inline-flex items-center px-3 py-2 border border-purple-300 rounded-md text-sm font-medium text-purple-700 bg-purple-50 hover:bg-purple-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                    </svg>
                                    Batch Testing
                                </button>
                                <button
                                    @click="openCoursePresetManager"
                                    class="inline-flex items-center px-3 py-2 border border-green-300 rounded-md text-sm font-medium text-green-700 bg-green-50 hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    Audio Presets
                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        WAV
                                    </span>
                                </button>
                                <button
                                    @click="openCourseTranscriptionPresetManager"
                                    class="inline-flex items-center px-3 py-2 border border-indigo-300 rounded-md text-sm font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                                    </svg>
                                    Transcription Presets
                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                        Whisper
                                    </span>
                                </button>
                                <button
                                    @click="openAudioTestPanel"
                                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                    </svg>
                                    Start Audio Test
                                </button>
                            </div>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div class="bg-blue-50 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-blue-600">Available Segments</p>
                                        <p class="text-lg font-semibold text-blue-900">{{ totalSegments }}</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-green-50 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-green-600">Tests Completed</p>
                                        <p class="text-lg font-semibold text-green-900">-</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-yellow-50 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-yellow-600">Avg Quality Score</p>
                                        <p class="text-lg font-semibold text-yellow-900">-</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-purple-50 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-purple-600">Avg Processing Time</p>
                                        <p class="text-lg font-semibold text-purple-900">-</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Getting Started Guide -->
                        <div class="bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg p-6">
                            <h4 class="text-lg font-semibold text-indigo-900 mb-3">🚀 Getting Started with Audio Testing</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0 w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center text-white text-sm font-bold">1</div>
                                    <div>
                                        <h5 class="font-medium text-indigo-900">Start Individual Test</h5>
                                        <p class="text-sm text-indigo-700">Click "Start Audio Test" to test a single segment with custom quality settings.</p>
                                    </div>
                                </div>
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0 w-8 h-8 bg-purple-600 rounded-full flex items-center justify-center text-white text-sm font-bold">2</div>
                                    <div>
                                        <h5 class="font-medium text-purple-900">View Test History</h5>
                                        <p class="text-sm text-purple-700">Browse previous test results and compare quality metrics across segments.</p>
                                    </div>
                                </div>
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0 w-8 h-8 bg-amber-600 rounded-full flex items-center justify-center text-white text-sm font-bold">3</div>
                                    <div>
                                        <h5 class="font-medium text-amber-900">Batch Testing</h5>
                                        <p class="text-sm text-amber-700">Test multiple segments simultaneously for efficient quality analysis.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Segments with Signed URLs Table -->
                <div v-if="segmentsWithSignedUrls && segmentsWithSignedUrls.length > 0" class="overflow-hidden bg-white shadow-sm sm:rounded-lg mt-6">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-between items-center mb-6">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Course Segments with Signed URLs</h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    CloudFront signed URLs for testing segment access
                                </p>
                            </div>
                            <div class="flex items-center space-x-4">
                                <div class="text-sm text-gray-500">
                                    {{ segmentsWithSignedUrls.length }} segments loaded
                                </div>
                                <div class="text-xs bg-blue-50 text-blue-700 px-2 py-1 rounded">
                                    Testing Mode
                                </div>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Segment ID
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Channel
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Title
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Audio Testing
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="segment in segmentsWithSignedUrls" :key="segment.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ segment.id }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <div class="text-sm font-medium text-gray-900">{{ segment.channel_name }}</div>
                                            <div class="text-xs text-gray-500">ID: {{ segment.channel_id }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ segment.title }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <!-- Audio Test Button -->
                                                <button
                                                    @click="selectedTestSegmentId = segment.id; openAudioTestPanel()"
                                                    class="inline-flex items-center px-2 py-1 bg-indigo-50 text-indigo-700 rounded-md text-xs hover:bg-indigo-100 transition-all duration-200"
                                                >
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                                    </svg>
                                                    Test Audio
                                                </button>
                                                <!-- View Results Button (if results exist) -->
                                                <button
                                                    @click="openAudioTestResults(segment.id)"
                                                    class="inline-flex items-center px-2 py-1 bg-green-50 text-green-700 rounded-md text-xs hover:bg-green-100 transition-all duration-200"
                                                    title="View previous test results"
                                                >
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                                    </svg>
                                                    Results
                                                </button>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <!-- Test Link -->
                                                <a
                                                    v-if="segment.signed_url"
                                                    :href="segment.signed_url"
                                                    target="_blank"
                                                    class="inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 rounded-md text-xs hover:bg-blue-100"
                                                >
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                    </svg>
                                                    Test
                                                </a>
                                                <!-- Copy URL Button -->
                                                <button
                                                    v-if="segment.signed_url"
                                                    @click="copyToClipboard(segment.signed_url)"
                                                    class="inline-flex items-center px-3 py-1 bg-gray-50 text-gray-700 rounded-md text-xs hover:bg-gray-100"
                                                >
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                                    </svg>
                                                    Copy URL
                                                </button>
                                            </div>
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
            :course-id="course.id"
            :segments="segmentsWithSignedUrls || []"
            @close="closeAudioTestPanel"
            @test-started="onTestStarted"
            @test-completed="onTestCompleted"
            @test-failed="onTestFailed"
        />

        <AudioTestResults
            :show="showAudioTestResults"
            :course-id="course.id"
            :segment-id="selectedTestSegmentId"
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
            :course-id="course.id"
            :segments="segmentsWithSignedUrls || []"
            @close="closeBatchTestManager"
            @batch-started="onBatchTestStarted"
            @batch-completed="onBatchTestCompleted"
            @batch-failed="onBatchTestFailed"
        />

        <CoursePresetManager
            :show="showCoursePresetManager"
            :course-id="course.id"
            :course="course"
            @close="closeCoursePresetManager"
            @preset-updated="onPresetUpdated"
            @batch-started="onBatchStarted"
            @batch-completed="onBatchCompleted"
            @batch-failed="onBatchFailed"
        />

        <CourseTranscriptionPresetManager
            :show="showCourseTranscriptionPresetManager"
            :course-id="course.id"
            :course="course"
            @close="closeCourseTranscriptionPresetManager"
            @preset-updated="onTranscriptionPresetUpdated"
        />
    </AuthenticatedLayout>
</template>