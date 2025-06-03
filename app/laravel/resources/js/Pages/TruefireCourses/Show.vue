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
const queueStatus = ref(null);
const downloadingSegments = ref(new Set()); // Track which segments are being downloaded
const downloadProgress = ref({
    current: 0,
    total: 0,
    successful: 0,
    failed: 0,
    skipped: 0,
    processing: 0,
    queued: 0,
    errors: []
});
const showConfirmDialog = ref(false);
const showProgressDialog = ref(false);
const showResultsDialog = ref(false);
const notifications = ref([]); // For toast notifications

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

// Load queue status to see segment processing states
const loadQueueStatus = async () => {
    try {
        const response = await axios.get(`/truefire-courses/${props.course.id}/queue-status`);
        queueStatus.value = response.data;
        console.log('Queue status loaded:', response.data);
    } catch (error) {
        console.error('Failed to load queue status:', error);
    }
};

// Load both statuses when component mounts
loadDownloadStatus();
loadQueueStatus();

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

// NEW: Download individual segment
const downloadSegment = async (segment) => {
    try {
        console.log('🔥 Downloading individual segment:', segment.id);
        
        // Add visual feedback immediately
        downloadingSegments.value.add(segment.id);
        
        // Check if already downloaded
        if (segment.is_downloaded) {
            if (!confirm(`Segment ${segment.id} is already downloaded. Download again?`)) {
                downloadingSegments.value.delete(segment.id);
                return;
            }
        }
        
        // Show immediate feedback
        showNotification(`Queuing download for segment ${segment.id}...`, 'info');
        
        // Make API call to trigger download for this specific segment
        const response = await axios.post(`/truefire-courses/${props.course.id}/download-segment/${segment.id}`);
        const result = response.data;
        
        console.log('✅ Segment download job queued:', result);
        
        // Update queue status immediately in the UI
        if (queueStatus.value && queueStatus.value.segments) {
            const segmentIndex = queueStatus.value.segments.findIndex(s => s.segment_id === segment.id);
            if (segmentIndex !== -1) {
                queueStatus.value.segments[segmentIndex].status = 'queued';
            }
            // Update status counts
            queueStatus.value.status_counts.queued = (queueStatus.value.status_counts.queued || 0) + 1;
            queueStatus.value.status_counts.not_started = Math.max(0, (queueStatus.value.status_counts.not_started || 0) - 1);
        }
        
        // Show success notification
        showNotification(`✅ Segment ${segment.id} queued for download!`, 'success');
        
        // Refresh status to get accurate queue state (but don't wait for it)
        setTimeout(() => {
            loadQueueStatus();
            downloadingSegments.value.delete(segment.id);
        }, 1000);
        
    } catch (error) {
        console.error('❌ Failed to queue segment download:', error);
        downloadingSegments.value.delete(segment.id);
        showNotification(`❌ Failed to queue segment ${segment.id}: ${error.response?.data?.message || error.message}`, 'error');
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
        skipped: 0,
        processing: 0,
        queued: 0,
        errors: []
    };

    try {
        console.log('🧪 Triggering test download job (1 file only)...');
        
        // Show immediate feedback
        showNotification('Queuing test download job (1 file)...', 'info');
        
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
            skipped: 0,
            processing: 0,
            queued: 0,
            errors: []
        };
        
        // Update UI for test download (just the first segment)
        if (queueStatus.value && queueStatus.value.segments && result.stats.queued_downloads > 0) {
            const firstNotStartedSegment = queueStatus.value.segments.find(s => s.status === 'not_started');
            if (firstNotStartedSegment) {
                firstNotStartedSegment.status = 'queued';
                queueStatus.value.status_counts.queued = (queueStatus.value.status_counts.queued || 0) + 1;
                queueStatus.value.status_counts.not_started = Math.max(0, (queueStatus.value.status_counts.not_started || 0) - 1);
                console.log(`Updated UI: Test segment ${firstNotStartedSegment.segment_id} marked as queued`);
            }
        }
        
        // Show success notification
        showNotification(`✅ Test download job queued successfully!`, 'success');
        
        // Start polling for real-time progress updates (shorter polling for test)
        console.log('🔄 Starting real-time progress polling for test download...');
        await pollDownloadProgress(true); // Pass true for test mode
        
    } catch (error) {
        console.error('❌ Failed to start test download job:', error);
        showNotification(`❌ Failed to queue test download: ${error.response?.data?.message || error.message}`, 'error');
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
        skipped: 0,
        processing: 0,
        queued: 0,
        errors: []
    };

    try {
        console.log('🚀 Triggering server-side download jobs...');
        
        // Show immediate feedback
        showNotification('Queuing download jobs for all segments...', 'info');
        
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
            skipped: 0,
            processing: 0,
            queued: 0,
            errors: []
        };
        
        // Immediately update UI to show segments as queued
        if (queueStatus.value && queueStatus.value.segments) {
            let queuedCount = 0;
            queueStatus.value.segments.forEach(segment => {
                // Only update segments that were actually queued (not already downloaded)
                if (segment.status === 'not_started') {
                    segment.status = 'queued';
                    queuedCount++;
                }
            });
            
            // Update status counts
            queueStatus.value.status_counts.queued = (queueStatus.value.status_counts.queued || 0) + queuedCount;
            queueStatus.value.status_counts.not_started = Math.max(0, (queueStatus.value.status_counts.not_started || 0) - queuedCount);
            
            console.log(`Updated UI: ${queuedCount} segments marked as queued`);
        }
        
        // Show success notification
        showNotification(`✅ ${result.stats.queued_downloads} download jobs queued successfully!`, 'success');
        
        // Start polling for real-time progress updates
        console.log('🔄 Starting real-time progress polling...');
        await pollDownloadProgress();
        
    } catch (error) {
        console.error('❌ Failed to start download jobs:', error);
        showNotification(`❌ Failed to queue download jobs: ${error.response?.data?.message || error.message}`, 'error');
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
    let lastTotalProcessed = 0;
    
    const poll = async () => {
        try {
            console.log('🔍 Polling download progress...');
            
            // Get current download status (files on disk)
            const statusResponse = await axios.get(`/truefire-courses/${props.course.id}/download-status`);
            const status = statusResponse.data;
            
            // Get queue status for real-time segment states
            await loadQueueStatus();
            
            // Get download stats from queue cache
            let queueStats = { success: 0, failed: 0, skipped: 0 };
            try {
                const statsResponse = await axios.get(`/truefire-courses/${props.course.id}/download-stats`);
                queueStats = statsResponse.data;
                console.log('📈 Queue stats from cache:', queueStats);
            } catch (statsError) {
                console.log('⚠️ Could not fetch queue stats:', statsError.message);
            }
            
            // Calculate total jobs processed
            const totalProcessed = queueStats.success + queueStats.failed + queueStats.skipped;
            const filesOnDisk = status.downloaded_segments;
            
            console.log('📊 Progress summary:', {
                files_on_disk: filesOnDisk,
                total_segments: status.total_segments,
                queue_processed: totalProcessed,
                queue_success: queueStats.success,
                queue_failed: queueStats.failed,
                queue_skipped: queueStats.skipped,
                completion_pct: Math.round((filesOnDisk / status.total_segments) * 100) + '%'
            });
            
            // Update progress with comprehensive data
            downloadProgress.value = {
                current: filesOnDisk, // Files actually downloaded to disk
                total: status.total_segments, // Total segments in course
                successful: queueStats.success, // Jobs that completed successfully
                failed: queueStats.failed, // Jobs that failed
                skipped: queueStats.skipped, // Jobs that were skipped (already downloaded)
                processing: queueStatus.value?.status_counts?.processing || 0, // Currently processing
                queued: queueStatus.value?.status_counts?.queued || 0, // Still queued
                errors: queueStats.failed > 0 ? [{ 
                    segment_id: 'QUEUE_ERRORS', 
                    error: `${queueStats.failed} download jobs failed. Check logs for details.` 
                }] : []
            };
            
            // Log progress for debugging
            console.log('📊 Updated progress:', {
                progress_current: downloadProgress.value.current,
                progress_total: downloadProgress.value.total,
                progress_successful: downloadProgress.value.successful,
                progress_failed: downloadProgress.value.failed,
                progress_queued: downloadProgress.value.queued,
                progress_processing: downloadProgress.value.processing
            });
            
            // Check progress
            const isComplete = filesOnDisk >= status.total_segments;
            const hasProgress = totalProcessed > lastTotalProcessed || filesOnDisk > lastTotalProcessed;
            
            if (!hasProgress) {
                consecutiveNoChange++;
                console.log(`⏳ No progress detected (${consecutiveNoChange} consecutive polls)`);
            } else {
                consecutiveNoChange = 0;
                lastTotalProcessed = Math.max(totalProcessed, filesOnDisk);
                console.log(`✅ Progress detected: ${totalProcessed} jobs processed, ${filesOnDisk} files on disk`);
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
                    error: `Download progress stalled - ${totalProcessed} jobs processed, ${filesOnDisk} files downloaded. Queue may be stuck or paused.`
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
                    error: `Download polling timeout - ${totalProcessed} jobs processed, ${filesOnDisk} files downloaded. Downloads may still be running in background.`
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
        skipped: 0,
        processing: 0,
        queued: 0,
        errors: []
    };
};

// Refresh download status
const refreshStatus = async () => {
    await loadDownloadStatus();
    await loadQueueStatus();
};

// Get queue status for a specific segment
const getSegmentQueueStatus = (segmentId) => {
    if (!queueStatus.value || !queueStatus.value.segments) {
        return 'unknown';
    }
    
    const segment = queueStatus.value.segments.find(s => s.segment_id === segmentId);
    return segment ? segment.status : 'unknown';
};

// Get status display info for a segment
const getStatusDisplay = (segmentId) => {
    const status = getSegmentQueueStatus(segmentId);
    
    const statusConfig = {
        'completed': {
            icon: '✅',
            text: 'Downloaded',
            class: 'text-green-600',
            bgClass: 'bg-green-50'
        },
        'processing': {
            icon: '🔄',
            text: 'Processing',
            class: 'text-blue-600',
            bgClass: 'bg-blue-50'
        },
        'queued': {
            icon: '⏳',
            text: 'Queued',
            class: 'text-yellow-600',
            bgClass: 'bg-yellow-50'
        },
        'not_started': {
            icon: '⏸️',
            text: 'Not Started',
            class: 'text-gray-600',
            bgClass: 'bg-gray-50'
        },
        'unknown': {
            icon: '❓',
            text: 'Unknown',
            class: 'text-gray-600',
            bgClass: 'bg-gray-50'
        }
    };
    
    return statusConfig[status] || statusConfig['unknown'];
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
                        {{ isDownloading ? 'Downloading...' : 'Download All' }}
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
                        
                        <!-- Queue Status Summary -->
                        <div v-if="queueStatus" class="mt-4 p-4 bg-green-50 rounded-lg">
                            <h4 class="text-sm font-medium text-green-900 mb-2">Queue Status ({{ queueStatus.queue_driver }} driver)</h4>
                            <div class="grid grid-cols-4 gap-4 text-sm">
                                <div class="text-center">
                                    <div class="text-2xl">✅</div>
                                    <div class="font-bold text-green-700">{{ queueStatus.status_counts?.completed || 0 }}</div>
                                    <div class="text-xs text-green-600">Completed</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl">🔄</div>
                                    <div class="font-bold text-blue-700">{{ queueStatus.status_counts?.processing || 0 }}</div>
                                    <div class="text-xs text-blue-600">Processing</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl">⏳</div>
                                    <div class="font-bold text-yellow-700">{{ queueStatus.status_counts?.queued || 0 }}</div>
                                    <div class="text-xs text-yellow-600">Queued</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl">⏸️</div>
                                    <div class="font-bold text-gray-700">{{ queueStatus.status_counts?.not_started || 0 }}</div>
                                    <div class="text-xs text-gray-600">Not Started</div>
                                </div>
                            </div>
                        </div>
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
                                    <tr v-for="segment in segmentsWithSignedUrls" :key="segment.id" class="hover:bg-gray-50" :class="getStatusDisplay(segment.id).bgClass">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <div class="flex items-center">
                                                <!-- Queue Status indicator icon -->
                                                <div class="mr-2" :class="getStatusDisplay(segment.id).class">
                                                    <span class="text-lg">{{ getStatusDisplay(segment.id).icon }}</span>
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
                                            <div class="flex items-center" :class="getStatusDisplay(segment.id).class">
                                                <span class="text-lg mr-2">{{ getStatusDisplay(segment.id).icon }}</span>
                                                <div>
                                                    <div class="font-medium">{{ getStatusDisplay(segment.id).text }}</div>
                                                    <div v-if="segment.is_downloaded && segment.file_size" class="text-xs text-gray-500">
                                                        {{ formatFileSize(segment.file_size) }}
                                                    </div>
                                                    <div v-if="segment.is_downloaded && segment.downloaded_at" class="text-xs text-gray-500">
                                                        {{ formatDate(segment.downloaded_at) }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <!-- Download Button -->
                                                <button
                                                    v-if="segment.signed_url"
                                                    @click="downloadSegment(segment)"
                                                    :disabled="downloadingSegments.has(segment.id)"
                                                    class="inline-flex items-center px-3 py-1 bg-green-50 text-green-700 rounded-md text-xs hover:bg-green-100 transition-all duration-200"
                                                    :class="{ 
                                                        'opacity-50 cursor-not-allowed': segment.is_downloaded && !downloadingSegments.has(segment.id),
                                                        'animate-pulse bg-yellow-50 text-yellow-700': downloadingSegments.has(segment.id)
                                                    }"
                                                >
                                                    <svg v-if="downloadingSegments.has(segment.id)" class="animate-spin w-3 h-3 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                    <svg v-else class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                    </svg>
                                                    {{ downloadingSegments.has(segment.id) ? 'Queuing...' : (segment.is_downloaded ? 'Re-download' : 'Download') }}
                                                </button>
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
                                        This will download all {{ downloadStatus ? downloadStatus.total_segments : totalSegments }} video files from this course to the server's local storage.
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
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                <h3 class="text-lg leading-6 font-medium text-gray-900">
                                    Downloading Course Videos
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        Server is downloading {{ downloadProgress.total || 0 }} files...
                                    </p>
                                    
                                    <!-- Progress Bar -->
                                    <div class="w-full bg-gray-200 rounded-full h-3 mt-3">
                                        <div
                                            class="bg-blue-600 h-3 rounded-full transition-all duration-300"
                                            :style="{ width: downloadProgress.total > 0 ? (downloadProgress.current / downloadProgress.total * 100) + '%' : '0%' }"
                                        ></div>
                                    </div>
                                    
                                    <!-- Detailed Progress Stats -->
                                    <div class="text-xs text-gray-500 mt-3 space-y-2">
                                        <!-- Main Progress -->
                                        <div class="text-center">
                                            <div class="font-medium text-sm text-gray-700">
                                                {{ downloadProgress.current || 0 }} / {{ downloadProgress.total || 0 }} files completed
                                            </div>
                                            <div class="text-blue-600">
                                                {{ downloadProgress.total > 0 ? Math.round((downloadProgress.current / downloadProgress.total) * 100) : 0 }}% complete
                                            </div>
                                        </div>
                                        
                                        <!-- Queue Status Grid -->
                                        <div class="grid grid-cols-4 gap-2 text-center bg-gray-50 rounded-lg p-2">
                                            <div class="text-green-600">
                                                <div class="font-bold">{{ downloadProgress.successful || 0 }}</div>
                                                <div class="text-xs">✅ Success</div>
                                            </div>
                                            <div class="text-blue-600">
                                                <div class="font-bold">{{ downloadProgress.processing || 0 }}</div>
                                                <div class="text-xs">🔄 Processing</div>
                                            </div>
                                            <div class="text-yellow-600">
                                                <div class="font-bold">{{ downloadProgress.queued || 0 }}</div>
                                                <div class="text-xs">⏳ Queued</div>
                                            </div>
                                            <div class="text-red-600" v-if="downloadProgress.failed > 0">
                                                <div class="font-bold">{{ downloadProgress.failed || 0 }}</div>
                                                <div class="text-xs">❌ Failed</div>
                                            </div>
                                            <div class="text-gray-600" v-else>
                                                <div class="font-bold">{{ downloadProgress.skipped || 0 }}</div>
                                                <div class="text-xs">⏭️ Skipped</div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-blue-600 text-center">
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
    </AuthenticatedLayout>
</template>