<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    course: Object,
    segmentsWithSignedUrls: Array
});

// Download state management
const isDownloading = ref(false);
const downloadStatus = ref(null);
const downloadProgress = ref({
    current: 0,
    total: 0,
    successful: 0,
    failed: 0,
    errors: []
});
const showConfirmDialog = ref(false);
const showProgressDialog = ref(false);
const showResultsDialog = ref(false);

// Computed properties
const totalSegments = computed(() => {
    return props.segmentsWithSignedUrls ? props.segmentsWithSignedUrls.length : 0;
});

const hasSegments = computed(() => {
    return totalSegments.value > 0;
});

const downloadedCount = computed(() => {
    return downloadStatus.value ? downloadStatus.value.downloaded_segments : 0;
});

const downloadProgressPercent = computed(() => {
    if (!downloadStatus.value || downloadStatus.value.total_segments === 0) return 0;
    return Math.round((downloadStatus.value.downloaded_segments / downloadStatus.value.total_segments) * 100);
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

// Load download status on component mount
const loadDownloadStatus = async () => {
    try {
        const response = await axios.get(`/truefire-courses/${props.course.id}/download-status`);
        downloadStatus.value = response.data;
        console.log('Download status loaded:', response.data);
    } catch (error) {
        console.error('Failed to load download status:', error);
    }
};

// Load status when component mounts
loadDownloadStatus();

// Download functionality
const showDownloadConfirmation = () => {
    if (!hasSegments.value) {
        alert('No segments available for download.');
        return;
    }
    showConfirmDialog.value = true;
};

const cancelDownload = () => {
    showConfirmDialog.value = false;
};

const confirmDownload = () => {
    showConfirmDialog.value = false;
    startDownload();
};

const startTestDownload = async () => {
    console.log('=== STARTING TEST DOWNLOAD (1 FILE) ===');
    console.log('Course ID:', props.course.id);
    
    isDownloading.value = true;
    showProgressDialog.value = true;
    
    // Reset progress
    downloadProgress.value = {
        current: 0,
        total: 0,
        successful: 0,
        failed: 0,
        errors: []
    };

    try {
        console.log('🧪 Triggering test download job (1 file only)...');
        
        // Make API call to trigger server-side test download (1 file only)
        const response = await axios.get(`/truefire-courses/${props.course.id}/download-all?test=1`);
        const result = response.data;
        
        console.log('✅ Test download job queued successfully:', result);
        console.log('📊 Initial stats:', result.stats);
        
        // Initialize progress with queued job info
        downloadProgress.value = {
            current: 0,
            total: result.stats.total_segments,
            successful: 0,
            failed: 0,
            errors: []
        };
        
        // Start polling for real-time progress updates (shorter polling for test)
        console.log('🔄 Starting real-time progress polling for test download...');
        await pollDownloadProgress(true); // Pass true for test mode
        
    } catch (error) {
        console.error('❌ Failed to start test download job:', error);
        downloadProgress.value.errors.push({
            segment_id: 'SERVER_ERROR',
            error: error.response?.data?.message || 'Failed to start test download job'
        });
        
        isDownloading.value = false;
        showProgressDialog.value = false;
        showResultsDialog.value = true;
    }
};

const startDownload = async () => {
    console.log('=== STARTING QUEUE-BASED DOWNLOAD ===');
    console.log('Course ID:', props.course.id);
    
    isDownloading.value = true;
    showProgressDialog.value = true;
    
    // Reset progress
    downloadProgress.value = {
        current: 0,
        total: 0,
        successful: 0,
        failed: 0,
        errors: []
    };

    try {
        console.log('🚀 Triggering server-side download jobs...');
        
        // Make API call to trigger server-side downloads
        const response = await axios.get(`/truefire-courses/${props.course.id}/download-all`);
        const result = response.data;
        
        console.log('✅ Download jobs queued successfully:', result);
        console.log('📊 Initial stats:', result.stats);
        
        // Initialize progress with queued job info
        downloadProgress.value = {
            current: 0,
            total: result.stats.total_segments,
            successful: 0,
            failed: 0,
            errors: []
        };
        
        // Start polling for real-time progress updates
        console.log('🔄 Starting real-time progress polling...');
        await pollDownloadProgress();
        
    } catch (error) {
        console.error('❌ Failed to start download jobs:', error);
        downloadProgress.value.errors.push({
            segment_id: 'SERVER_ERROR',
            error: error.response?.data?.message || 'Failed to start download jobs'
        });
        
        isDownloading.value = false;
        showProgressDialog.value = false;
        showResultsDialog.value = true;
    }
};

// NEW: Poll for real-time download progress
const pollDownloadProgress = async (isTestMode = false) => {
    console.log('📡 Starting progress polling...', isTestMode ? '(TEST MODE)' : '(FULL DOWNLOAD)');
    const maxPollingTime = isTestMode ? 5 * 60 * 1000 : 30 * 60 * 1000; // 5 minutes for test, 30 for full
    const pollInterval = isTestMode ? 1000 : 2000; // Poll every 1s for test, 2s for full
    const startTime = Date.now();
    
    let consecutiveNoChange = 0;
    let lastSuccessful = 0;
    
    const poll = async () => {
        try {
            console.log('🔍 Polling download progress...');
            
            // Get current download status
            const statusResponse = await axios.get(`/truefire-courses/${props.course.id}/download-status`);
            const status = statusResponse.data;
            
            console.log('📊 Current status:', {
                downloaded: status.downloaded_segments,
                total: status.total_segments,
                progress: Math.round((status.downloaded_segments / status.total_segments) * 100) + '%'
            });
            
            // Get download stats from cache (if available)
            let stats = { successful: 0, failed: 0, skipped: 0 };
            try {
                const statsResponse = await axios.get(`/truefire-courses/${props.course.id}/download-stats`);
                stats = statsResponse.data;
                console.log('📈 Queue stats:', stats);
            } catch (statsError) {
                console.log('⚠️ Could not fetch queue stats (this is normal):', statsError.message);
            }
            
            // Update progress
            const newSuccessful = status.downloaded_segments;
            downloadProgress.value = {
                current: newSuccessful,
                total: status.total_segments,
                successful: Math.max(stats.successful || 0, newSuccessful - lastSuccessful),
                failed: stats.failed || 0,
                errors: [] // We'll get errors from logs if needed
            };
            
            // Check if downloads are complete
            const isComplete = newSuccessful >= status.total_segments;
            const hasNoProgress = newSuccessful === lastSuccessful;
            
            if (hasNoProgress) {
                consecutiveNoChange++;
                console.log(`⏳ No progress detected (${consecutiveNoChange} consecutive polls)`);
            } else {
                consecutiveNoChange = 0;
                lastSuccessful = newSuccessful;
                console.log(`✅ Progress detected: ${newSuccessful}/${status.total_segments} files downloaded`);
            }
            
            // Stop polling conditions
            if (isComplete) {
                console.log('🎉 All downloads completed!');
                await loadDownloadStatus(); // Final status update
                isDownloading.value = false;
                showProgressDialog.value = false;
                showResultsDialog.value = true;
                return;
            }
            
            // Stop if no progress for too long (queue might be stuck)
            if (consecutiveNoChange >= 30) { // 30 polls = 60 seconds of no progress
                console.log('⚠️ No progress for 60 seconds, stopping polling');
                downloadProgress.value.errors.push({
                    segment_id: 'TIMEOUT',
                    error: 'Download progress stalled - queue may be stuck or paused'
                });
                isDownloading.value = false;
                showProgressDialog.value = false;
                showResultsDialog.value = true;
                return;
            }
            
            // Stop if polling for too long
            if (Date.now() - startTime > maxPollingTime) {
                console.log('⚠️ Maximum polling time reached');
                downloadProgress.value.errors.push({
                    segment_id: 'TIMEOUT',
                    error: 'Download polling timeout - downloads may still be running in background'
                });
                isDownloading.value = false;
                showProgressDialog.value = false;
                showResultsDialog.value = true;
                return;
            }
            
            // Continue polling
            setTimeout(poll, pollInterval);
            
        } catch (error) {
            console.error('❌ Error polling download progress:', error);
            consecutiveNoChange++;
            
            // If we get too many errors, stop polling
            if (consecutiveNoChange >= 10) {
                downloadProgress.value.errors.push({
                    segment_id: 'POLLING_ERROR',
                    error: 'Failed to poll download progress: ' + error.message
                });
                isDownloading.value = false;
                showProgressDialog.value = false;
                showResultsDialog.value = true;
                return;
            }
            
            // Continue polling despite error
            setTimeout(poll, pollInterval);
        }
    };
    
    // Start the polling loop
    poll();
};


const closeResultsDialog = () => {
    showResultsDialog.value = false;
    // Reset progress for next download
    downloadProgress.value = {
        current: 0,
        total: 0,
        successful: 0,
        failed: 0,
        errors: []
    };
};

// Refresh download status
const refreshStatus = async () => {
    await loadDownloadStatus();
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
                    <!-- Download Status Badge -->
                    <div v-if="downloadStatus" class="flex items-center space-x-2">
                        <div class="text-sm text-gray-600">
                            <span class="font-medium">{{ downloadedCount }}</span> / {{ downloadStatus.total_segments }} downloaded
                        </div>
                        <div class="w-20 bg-gray-200 rounded-full h-2">
                            <div class="bg-green-600 h-2 rounded-full transition-all duration-300" :style="{ width: downloadProgressPercent + '%' }"></div>
                        </div>
                        <button
                            @click="refreshStatus"
                            class="text-gray-500 hover:text-gray-700"
                            title="Refresh Status"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <button
                        @click="startTestDownload"
                        :disabled="!hasSegments || isDownloading"
                        class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700 focus:bg-green-700 active:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="{ 'opacity-50 cursor-not-allowed': !hasSegments || isDownloading }"
                    >
                        <svg v-if="isDownloading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                        </svg>
                        {{ isDownloading ? 'Testing...' : 'Test Download (1 File)' }}
                    </button>
                    
                    <button
                        @click="showDownloadConfirmation"
                        :disabled="!hasSegments || isDownloading"
                        class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="{ 'opacity-50 cursor-not-allowed': !hasSegments || isDownloading }"
                    >
                        <svg v-if="isDownloading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        {{ isDownloading ? 'Downloading...' : 'Download All (Server)' }}
                    </button>
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
                            
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Total Channels</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ course.channels ? course.channels.length : 0 }}</dd>
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
                        
                        <!-- Download Status Summary -->
                        <div v-if="downloadStatus" class="mt-6 p-4 bg-blue-50 rounded-lg">
                            <h4 class="text-sm font-medium text-blue-900 mb-2">Local Download Status</h4>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="text-blue-700">Storage Path:</span>
                                    <code class="block text-xs bg-blue-100 p-1 rounded mt-1">{{ downloadStatus.storage_path }}</code>
                                </div>
                                <div class="text-right">
                                    <div class="text-blue-700">Progress: <span class="font-bold">{{ downloadedCount }}/{{ downloadStatus.total_segments }}</span></div>
                                    <div class="text-xs text-blue-600 mt-1">{{ downloadProgressPercent }}% complete</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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
                                            Download Status
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="segment in segmentsWithSignedUrls" :key="segment.id" class="hover:bg-gray-50" :class="{ 'bg-green-50': segment.is_downloaded }">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <div class="flex items-center">
                                                <!-- Download indicator icon -->
                                                <div class="mr-2">
                                                    <svg v-if="segment.is_downloaded" class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                    </svg>
                                                    <svg v-else class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                    </svg>
                                                </div>
                                                {{ segment.id }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <div class="text-sm font-medium text-gray-900">{{ segment.channel_name }}</div>
                                            <div class="text-xs text-gray-500">ID: {{ segment.channel_id }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ segment.title }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <div v-if="segment.is_downloaded" class="flex items-center text-green-600">
                                                <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                </svg>
                                                <div>
                                                    <div class="font-medium">Downloaded</div>
                                                    <div class="text-xs text-gray-500" v-if="segment.file_size">
                                                        {{ formatFileSize(segment.file_size) }}
                                                    </div>
                                                    <div class="text-xs text-gray-500" v-if="segment.downloaded_at">
                                                        {{ formatDate(segment.downloaded_at) }}
                                                    </div>
                                                </div>
                                            </div>
                                            <div v-else class="flex items-center text-gray-400">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                                <span class="font-medium">Not downloaded</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
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

        <!-- Confirmation Dialog -->
        <div v-if="showConfirmDialog" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                    Download All Course Videos
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        This will download all {{ totalSegments }} video files from this course to the server's local storage.
                                        Files will be saved as "{segment_id}.mp4" in the course directory.
                                    </p>
                                    <p class="text-sm text-gray-500 mt-2">
                                        <strong>Server-side download:</strong> Files will be downloaded directly to the server using signed URLs,
                                        which is more cost-effective than repeated CloudFront access.
                                    </p>
                                    <div v-if="downloadStatus" class="mt-2 text-xs text-blue-600">
                                        {{ downloadedCount }} files already downloaded, {{ totalSegments - downloadedCount }} remaining
                                    </div>
                                    <p class="text-sm text-gray-500 mt-2">
                                        Are you sure you want to proceed?
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button @click="confirmDownload" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Download All
                        </button>
                        <button @click="cancelDownload" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress Dialog -->
        <div v-if="showProgressDialog" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="animate-spin h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-gray-900">
                                    Downloading Course Videos
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        Server is downloading {{ downloadProgress.total }} files...
                                    </p>
                                    <div class="w-full bg-gray-200 rounded-full h-2 mt-3">
                                        <div
                                            class="bg-blue-600 h-2 rounded-full transition-all duration-300"
                                            :style="{ width: downloadProgress.total > 0 ? (downloadProgress.current / downloadProgress.total * 100) + '%' : '0%' }"
                                        ></div>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-2 text-center">
                                        <div class="font-medium">
                                            {{ downloadProgress.current }} / {{ downloadProgress.total }} files completed
                                        </div>
                                        <div class="mt-1">
                                            ✅ {{ downloadProgress.successful }} successful
                                            <span v-if="downloadProgress.failed > 0" class="text-red-600 ml-2">
                                                ❌ {{ downloadProgress.failed }} failed
                                            </span>
                                        </div>
                                        <div class="text-blue-600 mt-1">
                                            Queue jobs running in background...
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Dialog -->
        <div v-if="showResultsDialog" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full sm:mx-0 sm:h-10 sm:w-10" :class="downloadProgress.failed === 0 ? 'bg-green-100' : 'bg-yellow-100'">
                                <svg v-if="downloadProgress.failed === 0" class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <svg v-else class="h-6 w-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                <h3 class="text-lg leading-6 font-medium text-gray-900">
                                    Download Complete
                                </h3>
                                <div class="mt-2">
                                    <div class="grid grid-cols-3 gap-4 mb-4">
                                        <div class="bg-green-50 p-3 rounded-lg">
                                            <div class="text-sm font-medium text-green-800">New Downloads</div>
                                            <div class="text-2xl font-bold text-green-600">{{ downloadProgress.successful }}</div>
                                        </div>
                                        <div class="bg-blue-50 p-3 rounded-lg">
                                            <div class="text-sm font-medium text-blue-800">Already Downloaded</div>
                                            <div class="text-2xl font-bold text-blue-600">{{ downloadedCount - downloadProgress.successful }}</div>
                                        </div>
                                        <div class="bg-red-50 p-3 rounded-lg" v-if="downloadProgress.failed > 0">
                                            <div class="text-sm font-medium text-red-800">Failed Downloads</div>
                                            <div class="text-2xl font-bold text-red-600">{{ downloadProgress.failed }}</div>
                                        </div>
                                    </div>
                                    
                                    <div v-if="downloadStatus" class="mb-4 p-3 bg-gray-50 rounded-lg">
                                        <div class="text-sm font-medium text-gray-700">Files stored in:</div>
                                        <code class="text-xs text-gray-600">{{ downloadStatus.storage_path }}</code>
                                    </div>
                                    
                                    <div v-if="downloadProgress.errors.length > 0" class="mt-4">
                                        <h4 class="text-sm font-medium text-gray-900 mb-2">Error Details:</h4>
                                        <div class="max-h-32 overflow-y-auto bg-gray-50 rounded-md p-3">
                                            <div v-for="error in downloadProgress.errors" :key="error.segment_id" class="text-xs text-red-600 mb-1">
                                                <strong>{{ error.segment_id }}:</strong> {{ error.error }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button @click="closeResultsDialog" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>