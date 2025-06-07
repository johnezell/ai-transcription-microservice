<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { debounce } from 'lodash';
import axios from 'axios';
import AudioTestHistory from '@/Components/AudioTestHistory.vue';

const props = defineProps({
    courses: Object,
    filters: Object
});

const search = ref(props.filters.search || '');
const isLoading = ref(false);
let currentRequest = null;

// Bulk download state management
const isBulkDownloading = ref(false);
const bulkDownloadStatus = ref(null);
const bulkQueueStatus = ref(null);
const bulkDownloadProgress = ref({
    current: 0,
    total: 0,
    successful: 0,
    failed: 0,
    skipped: 0,
    processing: 0,
    queued: 0,
    errors: [],
    courseProgress: {} // Track progress per course
});
const showBulkConfirmDialog = ref(false);
const showBulkProgressDialog = ref(false);
const showBulkResultsDialog = ref(false);
const notifications = ref([]); // For toast notifications

// Audio testing state
const showAudioTestHistory = ref(false);

// Debounced search function to avoid excessive API calls
const debouncedSearch = debounce((searchTerm) => {
    // Cancel any existing request
    if (currentRequest) {
        currentRequest.cancel();
    }
    
    isLoading.value = true;
    currentRequest = router.get(route('truefire-courses.index'),
        { search: searchTerm },
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                isLoading.value = false;
                currentRequest = null;
            },
            onCancel: () => {
                isLoading.value = false;
                currentRequest = null;
            }
        }
    );
}, 500);

// Watch for search input changes
watch(search, (newValue) => {
    debouncedSearch(newValue);
});

// Handle pagination
const goToPage = (url) => {
    if (!url) return;
    
    // Cancel any existing search request when navigating
    if (currentRequest) {
        currentRequest.cancel();
        currentRequest = null;
    }
    
    isLoading.value = true;
    router.get(url, {}, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            isLoading.value = false;
        }
    });
};

// Clear search
const clearSearch = () => {
    search.value = '';
};

// Format runtime from seconds to human readable format
const formatRuntime = (seconds) => {
    if (!seconds || seconds === 0) return 'N/A';
    
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;
    
    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    } else if (minutes > 0) {
        return `${minutes}m ${remainingSeconds}s`;
    } else {
        return `${remainingSeconds}s`;
    }
};

// Computed properties for bulk download
const totalCourses = computed(() => {
    return props.courses?.total || 0;
});

const totalSegmentsEstimate = computed(() => {
    if (!props.courses?.data) return 0;
    return props.courses.data.reduce((sum, course) => sum + (course.segments_count || 0), 0);
});

// Bulk download functionality
const showBulkDownloadConfirmation = () => {
    if (!props.courses?.data || props.courses.data.length === 0) {
        showNotification('No courses available for bulk download.', 'error');
        return;
    }
    showBulkConfirmDialog.value = true;
};

const cancelBulkDownload = () => {
    showBulkConfirmDialog.value = false;
};

const confirmBulkDownload = () => {
    showBulkConfirmDialog.value = false;
    startBulkDownload();
};

const startBulkDownload = async () => {
    console.log('=== STARTING BULK DOWNLOAD FOR ALL COURSES ===');
    
    isBulkDownloading.value = true;
    showBulkProgressDialog.value = true;
    
    // Reset progress
    bulkDownloadProgress.value = {
        current: 0,
        total: 0,
        successful: 0,
        failed: 0,
        skipped: 0,
        processing: 0,
        queued: 0,
        errors: [],
        courseProgress: {}
    };

    try {
        console.log('🚀 Triggering bulk download for all courses...');
        
        // Show immediate feedback
        showNotification('Starting bulk download for all courses...', 'info');
        
        // Make API call to trigger bulk download
        const response = await axios.get('/truefire-courses/download-all-courses');
        const result = response.data;
        
        console.log('✅ Bulk download initiated successfully:', result);
        console.log('📊 Initial stats:', result.stats);
        
        // Initialize progress with bulk download info
        bulkDownloadProgress.value = {
            current: 0,
            total: result.stats?.total_segments || totalSegmentsEstimate.value,
            successful: 0,
            failed: 0,
            skipped: 0,
            processing: 0,
            queued: result.stats?.queued_downloads || 0,
            errors: [],
            courseProgress: {}
        };
        
        // Show success notification
        showNotification(`✅ Bulk download started! Processing ${result.stats?.total_courses || totalCourses.value} courses with ${result.stats?.total_segments || totalSegmentsEstimate.value} segments.`, 'success');
        
        // Start polling for real-time progress updates
        console.log('🔄 Starting real-time bulk progress polling...');
        await pollBulkDownloadProgress();
        
    } catch (error) {
        console.error('❌ Failed to start bulk download:', error);
        showNotification(`❌ Failed to start bulk download: ${error.response?.data?.message || error.message}`, 'error');
        bulkDownloadProgress.value.errors.push({
            course_id: 'SERVER_ERROR',
            error: error.response?.data?.message || 'Failed to start bulk download'
        });
        
        isBulkDownloading.value = false;
        showBulkProgressDialog.value = false;
        showBulkResultsDialog.value = true;
    }
};

// Poll for real-time bulk download progress
const pollBulkDownloadProgress = async () => {
    console.log('📡 Starting bulk progress polling...');
    const maxPollingTime = 60 * 60 * 1000; // 60 minutes for bulk download
    const pollInterval = 3000; // Poll every 3 seconds for bulk
    const startTime = Date.now();
    
    let consecutiveNoChange = 0;
    let lastTotalProcessed = 0;
    
    const poll = async () => {
        try {
            console.log('🔍 Polling bulk download progress...');
            
            // Get overall bulk download status
            const statusResponse = await axios.get('/truefire-courses/bulk-download-status');
            const status = statusResponse.data;
            
            // Get queue status for real-time monitoring
            const queueResponse = await axios.get('/truefire-courses/bulk-queue-status');
            const queueStatus = queueResponse.data;
            
            // Get detailed stats
            let bulkStats = { success: 0, failed: 0, skipped: 0, processing: 0, queued: 0 };
            try {
                const statsResponse = await axios.get('/truefire-courses/bulk-download-stats');
                bulkStats = statsResponse.data;
                console.log('📈 Bulk stats from cache:', bulkStats);
            } catch (statsError) {
                console.log('⚠️ Could not fetch bulk stats:', statsError.message);
            }
            
            // Calculate total jobs processed
            const totalProcessed = bulkStats.success + bulkStats.failed + bulkStats.skipped;
            const totalSegments = status.total_segments || bulkDownloadProgress.value.total;
            
            console.log('📊 Bulk progress summary:', {
                total_segments: totalSegments,
                total_processed: totalProcessed,
                queue_success: bulkStats.success,
                queue_failed: bulkStats.failed,
                queue_skipped: bulkStats.skipped,
                queue_processing: bulkStats.processing,
                queue_queued: bulkStats.queued,
                completion_pct: totalSegments > 0 ? Math.round((totalProcessed / totalSegments) * 100) + '%' : '0%'
            });
            
            // Update progress with comprehensive data
            bulkDownloadProgress.value = {
                current: totalProcessed, // Total jobs processed
                total: totalSegments, // Total segments across all courses
                successful: bulkStats.success, // Jobs that completed successfully
                failed: bulkStats.failed, // Jobs that failed
                skipped: bulkStats.skipped, // Jobs that were skipped (already downloaded)
                processing: bulkStats.processing || 0, // Currently processing
                queued: bulkStats.queued || 0, // Still queued
                errors: bulkStats.failed > 0 ? [{
                    course_id: 'BULK_ERRORS',
                    error: `${bulkStats.failed} download jobs failed across all courses. Check logs for details.`
                }] : [],
                courseProgress: status.course_progress || {} // Per-course progress
            };
            
            // Check progress
            const isComplete = totalProcessed >= totalSegments && bulkStats.processing === 0 && bulkStats.queued === 0;
            const hasProgress = totalProcessed > lastTotalProcessed;
            
            if (!hasProgress) {
                consecutiveNoChange++;
                console.log(`⏳ No progress detected (${consecutiveNoChange} consecutive polls)`);
            } else {
                consecutiveNoChange = 0;
                lastTotalProcessed = totalProcessed;
                console.log(`✅ Progress detected: ${totalProcessed} jobs processed`);
            }
            
            // Stop polling conditions
            if (isComplete) {
                console.log('🎉 All bulk downloads completed!');
                isBulkDownloading.value = false;
                showBulkProgressDialog.value = false;
                showBulkResultsDialog.value = true;
                showNotification('🎉 Bulk download completed for all courses!', 'success');
                return;
            }
            
            // Stop if no progress for too long (queue might be stuck)
            if (consecutiveNoChange >= 40) { // 40 polls = 2 minutes of no progress
                console.log('⚠️ No progress for 2 minutes, stopping polling');
                bulkDownloadProgress.value.errors.push({
                    course_id: 'TIMEOUT',
                    error: `Bulk download progress stalled - ${totalProcessed} jobs processed. Queue may be stuck or paused.`
                });
                isBulkDownloading.value = false;
                showBulkProgressDialog.value = false;
                showBulkResultsDialog.value = true;
                return;
            }
            
            // Stop if polling for too long
            if (Date.now() - startTime > maxPollingTime) {
                console.log('⚠️ Maximum polling time reached');
                bulkDownloadProgress.value.errors.push({
                    course_id: 'TIMEOUT',
                    error: `Bulk download polling timeout - ${totalProcessed} jobs processed. Downloads may still be running in background.`
                });
                isBulkDownloading.value = false;
                showBulkProgressDialog.value = false;
                showBulkResultsDialog.value = true;
                return;
            }
            
            // Continue polling
            setTimeout(poll, pollInterval);
            
        } catch (error) {
            console.error('❌ Error polling bulk download progress:', error);
            consecutiveNoChange++;
            
            // If we get too many errors, stop polling
            if (consecutiveNoChange >= 10) {
                bulkDownloadProgress.value.errors.push({
                    course_id: 'POLLING_ERROR',
                    error: 'Failed to poll bulk download progress: ' + error.message
                });
                isBulkDownloading.value = false;
                showBulkProgressDialog.value = false;
                showBulkResultsDialog.value = true;
                return;
            }
            
            // Continue polling despite error
            setTimeout(poll, pollInterval);
        }
    };
    
    // Start the polling loop
    poll();
};

const closeBulkResultsDialog = () => {
    showBulkResultsDialog.value = false;
    // Reset progress for next download
    bulkDownloadProgress.value = {
        current: 0,
        total: 0,
        successful: 0,
        failed: 0,
        skipped: 0,
        processing: 0,
        queued: 0,
        errors: [],
        courseProgress: {}
    };
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
    
    // Auto-remove after 5 seconds for bulk operations
    setTimeout(() => {
        removeNotification(id);
    }, 5000);
};

const removeNotification = (id) => {
    const index = notifications.value.findIndex(n => n.id === id);
    if (index > -1) {
        notifications.value.splice(index, 1);
    }
};

// Utility functions
const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const estimateStorageSize = () => {
    // Rough estimate: 50MB per segment on average
    const avgSegmentSize = 50 * 1024 * 1024; // 50MB in bytes
    return formatFileSize(totalSegmentsEstimate.value * avgSegmentSize);
};

// Audio testing methods
const openAudioTestHistory = () => {
    showAudioTestHistory.value = true;
};

const closeAudioTestHistory = () => {
    showAudioTestHistory.value = false;
};

const onViewResults = (resultData) => {
    // Navigate to the specific course page to view results
    router.visit(route('truefire-courses.show', resultData.courseId));
};

const onRetryTest = (testData) => {
    // Navigate to the specific course page to retry test
    router.visit(route('truefire-courses.show', testData.courseId));
};
</script>

<template>
    <Head title="TrueFire Courses" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    TrueFire Courses
                </h2>
                <button
                    @click="openAudioTestHistory"
                    class="inline-flex items-center px-4 py-2 bg-purple-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-purple-700 focus:bg-purple-700 active:bg-purple-800 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Audio Test History
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <!-- Bulk Download Section -->
                        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-6 mb-6">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold text-blue-900 mb-2">
                                        🚀 Bulk Download All Courses
                                    </h3>
                                    <p class="text-sm text-blue-700 mb-3">
                                        Download all {{ totalCourses }} courses with approximately {{ totalSegmentsEstimate }} segments (~{{ estimateStorageSize() }} estimated storage).
                                    </p>
                                    <div class="flex items-center space-x-4 text-xs text-blue-600">
                                        <div class="flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                            Server-side processing
                                        </div>
                                        <div class="flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Queue-based downloads
                                        </div>
                                        <div class="flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Real-time progress tracking
                                        </div>
                                    </div>
                                </div>
                                <div class="ml-6">
                                    <button
                                        @click="showBulkDownloadConfirmation"
                                        :disabled="!totalCourses || isBulkDownloading"
                                        class="inline-flex items-center px-6 py-3 bg-blue-600 border border-transparent rounded-lg font-semibold text-sm text-white uppercase tracking-wider hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg"
                                        :class="{ 'opacity-50 cursor-not-allowed': !totalCourses || isBulkDownloading }"
                                    >
                                        <svg v-if="isBulkDownloading" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <svg v-else class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M7 13l3 3 7-7"></path>
                                        </svg>
                                        {{ isBulkDownloading ? 'Processing...' : 'Download All Courses' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mb-6">
                            <div class="flex justify-between items-center mb-4">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">
                                        Available TrueFire Courses
                                    </h3>
                                    <p class="mt-1 text-sm text-gray-600">
                                        Browse and explore TrueFire courses with their segment counts.
                                    </p>
                                </div>
                                <div class="text-sm text-gray-500" v-if="courses.data">
                                    Showing {{ courses.from || 0 }} to {{ courses.to || 0 }} of {{ courses.total || 0 }} courses
                                </div>
                            </div>
                            
                            <!-- Search Input -->
                            <div class="relative mb-4">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                                <input
                                    v-model="search"
                                    type="text"
                                    placeholder="Search by course ID or title..."
                                    class="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                    :disabled="isLoading"
                                />
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <button
                                        v-if="search"
                                        @click="clearSearch"
                                        class="text-gray-400 hover:text-gray-600"
                                        :disabled="isLoading"
                                    >
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                    <div v-if="isLoading" class="ml-2">
                                        <svg class="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            ID
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Course Name
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Segments
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Audio Preset
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Duration
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Created At
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="course in courses.data" :key="course.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ course.id }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ course.name || course.title || `Course #${course.id}` }}
                                            </div>
                                            <div class="text-sm text-gray-500" v-if="course.description">
                                                {{ course.description }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                {{ course.segments_count || 0 }} segments
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span
                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                                :class="{
                                                    'bg-green-100 text-green-800': course.audio_extraction_preset === 'fast',
                                                    'bg-blue-100 text-blue-800': course.audio_extraction_preset === 'balanced',
                                                    'bg-purple-100 text-purple-800': course.audio_extraction_preset === 'high',
                                                    'bg-amber-100 text-amber-800': course.audio_extraction_preset === 'premium',
                                                    'bg-gray-100 text-gray-800': !course.audio_extraction_preset
                                                }"
                                            >
                                                {{ course.audio_extraction_preset || 'Not Set' }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                {{ formatRuntime(course.segments_sum_runtime) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ course.release_date ? new Date(course.release_date).toLocaleDateString() : 'N/A' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <Link 
                                                :href="route('truefire-courses.show', course.id)"
                                                class="text-indigo-600 hover:text-indigo-900"
                                            >
                                                View Details
                                            </Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            <div v-if="!courses.data || courses.data.length === 0" class="text-center py-12">
                                <div class="text-gray-500">
                                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900">
                                        {{ search ? 'No courses match your search' : 'No courses found' }}
                                    </h3>
                                    <p class="mt-1 text-sm text-gray-500">
                                        {{ search ? 'Try adjusting your search terms.' : 'There are no TrueFire courses available at the moment.' }}
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Pagination Controls -->
                            <div v-if="courses.data && courses.data.length > 0" class="mt-6 flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6">
                                <div class="flex flex-1 justify-between sm:hidden">
                                    <button
                                        @click="goToPage(courses.prev_page_url)"
                                        :disabled="!courses.prev_page_url || isLoading"
                                        class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Previous
                                    </button>
                                    <button
                                        @click="goToPage(courses.next_page_url)"
                                        :disabled="!courses.next_page_url || isLoading"
                                        class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Next
                                    </button>
                                </div>
                                <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-sm text-gray-700">
                                            Showing
                                            <span class="font-medium">{{ courses.from }}</span>
                                            to
                                            <span class="font-medium">{{ courses.to }}</span>
                                            of
                                            <span class="font-medium">{{ courses.total }}</span>
                                            results
                                        </p>
                                    </div>
                                    <div>
                                        <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                            <!-- Previous Button -->
                                            <button
                                                @click="goToPage(courses.prev_page_url)"
                                                :disabled="!courses.prev_page_url || isLoading"
                                                class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                <span class="sr-only">Previous</span>
                                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                            
                                            <!-- Page Numbers -->
                                            <template v-for="link in courses.links" :key="link.label">
                                                <button
                                                    v-if="link.url && !link.label.includes('Previous') && !link.label.includes('Next')"
                                                    @click="goToPage(link.url)"
                                                    :disabled="isLoading"
                                                    :class="[
                                                        link.active
                                                            ? 'relative z-10 inline-flex items-center bg-indigo-600 px-4 py-2 text-sm font-semibold text-white focus:z-20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600'
                                                            : 'relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0',
                                                        isLoading ? 'opacity-50 cursor-not-allowed' : ''
                                                    ]"
                                                    v-html="link.label"
                                                ></button>
                                                <span
                                                    v-else-if="!link.url && !link.label.includes('Previous') && !link.label.includes('Next')"
                                                    class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 focus:outline-offset-0"
                                                    v-html="link.label"
                                                ></span>
                                            </template>
                                            
                                            <!-- Next Button -->
                                            <button
                                                @click="goToPage(courses.next_page_url)"
                                                :disabled="!courses.next_page_url || isLoading"
                                                class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                <span class="sr-only">Next</span>
                                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </nav>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Download Confirmation Dialog -->
        <div v-if="showBulkConfirmDialog" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M7 13l3 3 7-7" />
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                    Download All TrueFire Courses
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        This will start a bulk download process for all {{ totalCourses }} courses containing approximately {{ totalSegmentsEstimate }} video segments.
                                    </p>
                                    <div class="mt-3 p-3 bg-blue-50 rounded-lg">
                                        <h4 class="text-sm font-medium text-blue-900 mb-2">📊 Download Summary:</h4>
                                        <ul class="text-xs text-blue-700 space-y-1">
                                            <li>• <strong>Total Courses:</strong> {{ totalCourses }}</li>
                                            <li>• <strong>Total Segments:</strong> {{ totalSegmentsEstimate }}</li>
                                            <li>• <strong>Estimated Storage:</strong> {{ estimateStorageSize() }}</li>
                                            <li>• <strong>Processing:</strong> Server-side with queue system</li>
                                        </ul>
                                    </div>
                                    <p class="text-sm text-gray-500 mt-3">
                                        <strong>Note:</strong> This process will run in the background using the queue system.
                                        You can monitor progress in real-time and continue using the application.
                                    </p>
                                    <p class="text-sm text-gray-500 mt-2">
                                        Are you sure you want to proceed with the bulk download?
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button @click="confirmBulkDownload" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Start Bulk Download
                        </button>
                        <button @click="cancelBulkDownload" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Download Progress Dialog -->
        <div v-if="showBulkProgressDialog" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
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
                                    Bulk Download in Progress
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        Processing {{ bulkDownloadProgress.total || 0 }} segments across all courses...
                                    </p>
                                    
                                    <!-- Overall Progress Bar -->
                                    <div class="w-full bg-gray-200 rounded-full h-4 mt-4">
                                        <div
                                            class="bg-blue-600 h-4 rounded-full transition-all duration-300"
                                            :style="{ width: bulkDownloadProgress.total > 0 ? (bulkDownloadProgress.current / bulkDownloadProgress.total * 100) + '%' : '0%' }"
                                        ></div>
                                    </div>
                                    
                                    <!-- Progress Stats -->
                                    <div class="text-xs text-gray-500 mt-3 space-y-3">
                                        <!-- Main Progress -->
                                        <div class="text-center">
                                            <div class="font-medium text-sm text-gray-700">
                                                {{ bulkDownloadProgress.current || 0 }} / {{ bulkDownloadProgress.total || 0 }} segments processed
                                            </div>
                                            <div class="text-blue-600">
                                                {{ bulkDownloadProgress.total > 0 ? Math.round((bulkDownloadProgress.current / bulkDownloadProgress.total) * 100) : 0 }}% complete
                                            </div>
                                        </div>
                                        
                                        <!-- Queue Status Grid -->
                                        <div class="grid grid-cols-4 gap-3 text-center bg-gray-50 rounded-lg p-3">
                                            <div class="text-green-600">
                                                <div class="font-bold text-lg">{{ bulkDownloadProgress.successful || 0 }}</div>
                                                <div class="text-xs">✅ Successful</div>
                                            </div>
                                            <div class="text-blue-600">
                                                <div class="font-bold text-lg">{{ bulkDownloadProgress.processing || 0 }}</div>
                                                <div class="text-xs">🔄 Processing</div>
                                            </div>
                                            <div class="text-yellow-600">
                                                <div class="font-bold text-lg">{{ bulkDownloadProgress.queued || 0 }}</div>
                                                <div class="text-xs">⏳ Queued</div>
                                            </div>
                                            <div class="text-red-600" v-if="bulkDownloadProgress.failed > 0">
                                                <div class="font-bold text-lg">{{ bulkDownloadProgress.failed || 0 }}</div>
                                                <div class="text-xs">❌ Failed</div>
                                            </div>
                                            <div class="text-gray-600" v-else>
                                                <div class="font-bold text-lg">{{ bulkDownloadProgress.skipped || 0 }}</div>
                                                <div class="text-xs">⏭️ Skipped</div>
                                            </div>
                                        </div>
                                        
                                        <!-- Course Progress Summary -->
                                        <div v-if="Object.keys(bulkDownloadProgress.courseProgress).length > 0" class="mt-4">
                                            <h4 class="text-sm font-medium text-gray-700 mb-2">Per-Course Progress:</h4>
                                            <div class="max-h-32 overflow-y-auto bg-gray-50 rounded-md p-2 space-y-1">
                                                <div
                                                    v-for="(progress, courseId) in bulkDownloadProgress.courseProgress"
                                                    :key="courseId"
                                                    class="flex justify-between items-center text-xs"
                                                >
                                                    <span class="text-gray-600">Course {{ courseId }}:</span>
                                                    <span class="font-medium" :class="progress.completed === progress.total ? 'text-green-600' : 'text-blue-600'">
                                                        {{ progress.completed }}/{{ progress.total }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-blue-600 text-center font-medium">
                                            🔄 Queue processing in background...
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Download Results Dialog -->
        <div v-if="showBulkResultsDialog" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full sm:mx-0 sm:h-10 sm:w-10" :class="bulkDownloadProgress.failed === 0 ? 'bg-green-100' : 'bg-yellow-100'">
                                <svg v-if="bulkDownloadProgress.failed === 0" class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <svg v-else class="h-6 w-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                <h3 class="text-lg leading-6 font-medium text-gray-900">
                                    Bulk Download {{ bulkDownloadProgress.failed === 0 ? 'Complete' : 'Finished with Issues' }}
                                </h3>
                                <div class="mt-2">
                                    <div class="grid grid-cols-2 gap-4 mb-4">
                                        <div class="bg-green-50 p-3 rounded-lg">
                                            <div class="text-sm font-medium text-green-800">Successfully Downloaded</div>
                                            <div class="text-2xl font-bold text-green-600">{{ bulkDownloadProgress.successful }}</div>
                                        </div>
                                        <div class="bg-blue-50 p-3 rounded-lg">
                                            <div class="text-sm font-medium text-blue-800">Already Downloaded</div>
                                            <div class="text-2xl font-bold text-blue-600">{{ bulkDownloadProgress.skipped }}</div>
                                        </div>
                                        <div class="bg-red-50 p-3 rounded-lg" v-if="bulkDownloadProgress.failed > 0">
                                            <div class="text-sm font-medium text-red-800">Failed Downloads</div>
                                            <div class="text-2xl font-bold text-red-600">{{ bulkDownloadProgress.failed }}</div>
                                        </div>
                                        <div class="bg-gray-50 p-3 rounded-lg">
                                            <div class="text-sm font-medium text-gray-800">Total Processed</div>
                                            <div class="text-2xl font-bold text-gray-600">{{ bulkDownloadProgress.current }}</div>
                                        </div>
                                    </div>
                                    
                                    <!-- Course Progress Summary -->
                                    <div v-if="Object.keys(bulkDownloadProgress.courseProgress).length > 0" class="mb-4 p-3 bg-gray-50 rounded-lg">
                                        <div class="text-sm font-medium text-gray-700 mb-2">Course Completion Summary:</div>
                                        <div class="max-h-32 overflow-y-auto space-y-1">
                                            <div
                                                v-for="(progress, courseId) in bulkDownloadProgress.courseProgress"
                                                :key="courseId"
                                                class="flex justify-between items-center text-xs"
                                            >
                                                <span class="text-gray-600">Course {{ courseId }}:</span>
                                                <span class="font-medium" :class="progress.completed === progress.total ? 'text-green-600' : 'text-yellow-600'">
                                                    {{ progress.completed }}/{{ progress.total }} segments
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div v-if="bulkDownloadProgress.errors.length > 0" class="mt-4">
                                        <h4 class="text-sm font-medium text-gray-900 mb-2">Error Details:</h4>
                                        <div class="max-h-32 overflow-y-auto bg-gray-50 rounded-md p-3">
                                            <div v-for="error in bulkDownloadProgress.errors" :key="error.course_id" class="text-xs text-red-600 mb-1">
                                                <strong>{{ error.course_id }}:</strong> {{ error.error }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button @click="closeBulkResultsDialog" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
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

        <!-- Audio Test History Component -->
        <AudioTestHistory
            :show="showAudioTestHistory"
            @close="closeAudioTestHistory"
            @view-results="onViewResults"
            @retry-test="onRetryTest"
        />
    </AuthenticatedLayout>
</template>