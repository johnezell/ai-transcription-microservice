<script setup>
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { ref, onMounted, onUnmounted, computed } from 'vue';

const props = defineProps({
    activeJobs: Array,
    failedJobs: Array,
    jobBatches: Array,
    queueStats: Object,
    priorityQueueStats: Object,
    jobTypeBreakdown: Object,
    recentActivity: Object,
    queueHealth: Object,
    processingTimes: Object,
    segmentContext: Object,
    pipelineStatus: Object,
    workerStatus: Object,
});

const lastUpdated = ref(new Date());
const isRefreshing = ref(false);
const autoRefreshInterval = ref(null);
const expandedJobs = ref(new Set());
const expandedFailedJobs = ref(new Set());
const searchTerm = ref('');
const selectedJobType = ref('');
const selectedStatus = ref('');
const selectedPriority = ref('');
const sortField = ref('created_at');
const sortDirection = ref('desc');

// Job management state
const showPruneConfirmModal = ref(false);
const showClearFailedConfirmModal = ref(false);
const isPruningJobs = ref(false);
const isClearingFailedJobs = ref(false);
const notification = ref(null);

// Auto-refresh functionality
const startAutoRefresh = () => {
    autoRefreshInterval.value = setInterval(() => {
        refreshData();
    }, 30000); // 30 seconds
};

const stopAutoRefresh = () => {
    if (autoRefreshInterval.value) {
        clearInterval(autoRefreshInterval.value);
        autoRefreshInterval.value = null;
    }
};

const refreshData = () => {
    isRefreshing.value = true;
    router.reload({
        onFinish: () => {
            isRefreshing.value = false;
            lastUpdated.value = new Date();
        }
    });
};

// Computed properties for filtering and sorting
const filteredActiveJobs = computed(() => {
    let jobs = props.activeJobs || [];
    
    if (searchTerm.value) {
        const searchLower = searchTerm.value.toLowerCase();
        jobs = jobs.filter(job => 
            job.job_class.toLowerCase().includes(searchLower) ||
            job.queue.toLowerCase().includes(searchLower) ||
            (job.payload_data?.job_description && job.payload_data.job_description.toLowerCase().includes(searchLower)) ||
            (job.payload_data?.context && job.payload_data.context.toLowerCase().includes(searchLower)) ||
            (job.payload_data?.priority && job.payload_data.priority.toLowerCase().includes(searchLower)) ||
            (job.payload_data?.segment_id && job.payload_data.segment_id.toString().includes(searchLower)) ||
            (job.payload_data?.course_id && job.payload_data.course_id.toString().includes(searchLower)) ||
            (job.payload_data?.transcription_preset && job.payload_data.transcription_preset.toLowerCase().includes(searchLower))
        );
    }
    
    if (selectedJobType.value) {
        jobs = jobs.filter(job => job.job_class === selectedJobType.value);
    }
    
    if (selectedStatus.value) {
        jobs = jobs.filter(job => job.status === selectedStatus.value);
    }
    
    if (selectedPriority.value) {
        jobs = jobs.filter(job => {
            const jobPriority = job.payload_data?.priority || 'normal';
            return jobPriority === selectedPriority.value;
        });
    }
    
    // Sort jobs with priority consideration
    jobs.sort((a, b) => {
        // If sorting by priority, handle it specially
        if (sortField.value === 'priority') {
            const priorityOrder = { 'high': 3, 'normal': 2, 'low': 1 };
            const aPriority = priorityOrder[a.payload_data?.priority || 'normal'];
            const bPriority = priorityOrder[b.payload_data?.priority || 'normal'];
            const modifier = sortDirection.value === 'asc' ? 1 : -1;
            return (aPriority - bPriority) * modifier;
        }
        
        // Default sorting
        const aVal = a[sortField.value];
        const bVal = b[sortField.value];
        const modifier = sortDirection.value === 'asc' ? 1 : -1;
        
        if (aVal < bVal) return -1 * modifier;
        if (aVal > bVal) return 1 * modifier;
        return 0;
    });
    
    return jobs;
});

const filteredFailedJobs = computed(() => {
    let jobs = props.failedJobs || [];
    
    if (searchTerm.value) {
        jobs = jobs.filter(job => 
            job.job_class.toLowerCase().includes(searchTerm.value.toLowerCase()) ||
            job.queue.toLowerCase().includes(searchTerm.value.toLowerCase())
        );
    }
    
    return jobs;
});

// Utility functions
const formatDuration = (seconds) => {
    if (!seconds) return '0s';
    
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    
    if (hours > 0) return `${hours}h ${minutes}m ${secs}s`;
    if (minutes > 0) return `${minutes}m ${secs}s`;
    return `${secs}s`;
};

const formatTimeAgo = (timestamp) => {
    const now = new Date();
    const time = new Date(timestamp);
    const diffInSeconds = Math.floor((now - time) / 1000);
    
    if (diffInSeconds < 60) return `${diffInSeconds}s ago`;
    if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`;
    if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`;
    return `${Math.floor(diffInSeconds / 86400)}d ago`;
};

const getStatusColor = (status) => {
    const colors = {
        'queued': 'bg-blue-100 text-blue-800',
        'processing': 'bg-yellow-100 text-yellow-800',
        'delayed': 'bg-purple-100 text-purple-800',
        'completed': 'bg-green-100 text-green-800',
        'failed': 'bg-red-100 text-red-800',
        'cancelled': 'bg-gray-100 text-gray-800',
        'completed_with_failures': 'bg-orange-100 text-orange-800',
    };
    return colors[status] || 'bg-gray-100 text-gray-800';
};

const getBatchStatusColor = (status) => {
    return getStatusColor(status);
};

const getHealthScoreColor = (score) => {
    if (score >= 90) return 'text-green-600';
    if (score >= 70) return 'text-yellow-600';
    return 'text-red-600';
};

const toggleJobExpansion = (jobId) => {
    if (expandedJobs.value.has(jobId)) {
        expandedJobs.value.delete(jobId);
    } else {
        expandedJobs.value.add(jobId);
    }
};

const toggleFailedJobExpansion = (jobId) => {
    if (expandedFailedJobs.value.has(jobId)) {
        expandedFailedJobs.value.delete(jobId);
    } else {
        expandedFailedJobs.value.add(jobId);
    }
};

const sortBy = (field) => {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'desc';
    }
};

// Job management functions
const pruneAllJobs = async () => {
    isPruningJobs.value = true;
    showPruneConfirmModal.value = false;
    
    try {
        const response = await fetch('/jobs/prune-all', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(`Successfully pruned ${data.pruned_count} completed jobs`, 'success');
            refreshData();
        } else {
            showNotification(data.message || 'Failed to prune jobs', 'error');
        }
    } catch (error) {
        console.error('Error pruning jobs:', error);
        showNotification('An error occurred while pruning jobs', 'error');
    } finally {
        isPruningJobs.value = false;
    }
};

const clearFailedJobs = async () => {
    isClearingFailedJobs.value = true;
    showClearFailedConfirmModal.value = false;
    
    try {
        const response = await fetch('/jobs/clear-failed', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(`Successfully cleared ${data.cleared_count} failed jobs`, 'success');
            refreshData();
        } else {
            showNotification(data.message || 'Failed to clear failed jobs', 'error');
        }
    } catch (error) {
        console.error('Error clearing failed jobs:', error);
        showNotification('An error occurred while clearing failed jobs', 'error');
    } finally {
        isClearingFailedJobs.value = false;
    }
};

const retryFailedJob = async (jobId) => {
    try {
        const response = await fetch(`/jobs/retry/${jobId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        
        const data = await response.json();
        
        if (data.success) {
            const context = data.data?.context ? ` (${data.data.context})` : '';
            const queueInfo = data.data?.retry_queue ? ` to ${data.data.retry_queue} queue` : '';
            showNotification(
                `Job retried successfully with HIGH priority${queueInfo}${context}`, 
                'success'
            );
            refreshData();
        } else {
            showNotification(data.message || 'Failed to retry job', 'error');
        }
    } catch (error) {
        console.error('Error retrying job:', error);
        showNotification('An error occurred while retrying the job', 'error');
    }
};

const showNotification = (message, type = 'info') => {
    notification.value = { message, type };
    setTimeout(() => {
        notification.value = null;
    }, 5000);
};

const dismissNotification = () => {
    notification.value = null;
};

// Quick filter functions
const showHighPriorityOnly = () => {
    selectedPriority.value = 'high';
    searchTerm.value = '';
    selectedJobType.value = '';
    selectedStatus.value = '';
};

const showProcessingJobs = () => {
    selectedStatus.value = 'processing';
    selectedPriority.value = '';
    searchTerm.value = '';
    selectedJobType.value = '';
};

const showTranscriptionJobs = () => {
    searchTerm.value = 'transcription';
    selectedPriority.value = '';
    selectedJobType.value = '';
    selectedStatus.value = '';
};

const clearAllFilters = () => {
    searchTerm.value = '';
    selectedJobType.value = '';
    selectedStatus.value = '';
    selectedPriority.value = '';
};

// Computed properties for button states
const hasFailedJobs = computed(() => {
    return props.failedJobs && props.failedJobs.length > 0;
});

const hasCompletedJobs = computed(() => {
    return props.queueStats && props.queueStats.total_active_jobs > 0;
});

// Keyboard shortcuts
const handleKeyDown = (event) => {
    // Only trigger if not typing in an input
    if (event.target.tagName === 'INPUT' || event.target.tagName === 'SELECT') return;
    
    switch(event.key.toLowerCase()) {
        case 'h':
            event.preventDefault();
            showHighPriorityOnly();
            break;
        case 'c':
            event.preventDefault();
            clearAllFilters();
            break;
        case 'p':
            event.preventDefault();
            showProcessingJobs();
            break;
        case 't':
            event.preventDefault();
            showTranscriptionJobs();
            break;
    }
};

// Lifecycle hooks
onMounted(() => {
    startAutoRefresh();
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
    stopAutoRefresh();
    
    // Remove keyboard shortcuts
    document.removeEventListener('keydown', handleKeyDown);
});

// Get unique job types for filter
const uniqueJobTypes = computed(() => {
    const types = new Set();
    props.activeJobs?.forEach(job => types.add(job.job_class));
    props.failedJobs?.forEach(job => types.add(job.job_class));
    return Array.from(types).sort();
});

const uniqueStatuses = computed(() => {
    const statuses = new Set();
    props.activeJobs?.forEach(job => statuses.add(job.status));
    return Array.from(statuses).sort();
});

const uniquePriorities = computed(() => {
    const priorities = new Set();
    props.activeJobs?.forEach(job => {
        const priority = job.payload_data?.priority || 'normal';
        priorities.add(priority);
    });
    return Array.from(priorities).sort((a, b) => {
        const order = { 'high': 1, 'normal': 2, 'low': 3 };
        return order[a] - order[b];
    });
});
</script>

<template>
    <Head title="Jobs Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Jobs Dashboard</h2>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-500">
                        Last updated: {{ lastUpdated.toLocaleTimeString() }}
                    </div>
                    
                    <!-- Job Management Buttons -->
                    <div class="flex items-center space-x-2">
                        <SecondaryButton
                            @click="showPruneConfirmModal = true"
                            :disabled="isPruningJobs || !hasCompletedJobs"
                            class="text-xs"
                        >
                            <span v-if="isPruningJobs">Pruning...</span>
                            <span v-else>Prune All Jobs</span>
                        </SecondaryButton>
                        
                        <DangerButton
                            @click="showClearFailedConfirmModal = true"
                            :disabled="isClearingFailedJobs || !hasFailedJobs"
                            class="text-xs"
                        >
                            <span v-if="isClearingFailedJobs">Clearing...</span>
                            <span v-else>Clear Failed Jobs</span>
                        </DangerButton>
                    </div>
                    
                    <button
                        @click="refreshData"
                        :disabled="isRefreshing"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50"
                    >
                        <span v-if="isRefreshing">Refreshing...</span>
                        <span v-else>Refresh</span>
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                
                <!-- Notification -->
                <div v-if="notification" class="fixed top-4 right-4 z-50 max-w-sm">
                    <div
                        class="rounded-md p-4 shadow-lg"
                        :class="{
                            'bg-green-50 border border-green-200': notification.type === 'success',
                            'bg-red-50 border border-red-200': notification.type === 'error',
                            'bg-blue-50 border border-blue-200': notification.type === 'info'
                        }"
                    >
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <!-- Success Icon -->
                                <svg v-if="notification.type === 'success'" class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <!-- Error Icon -->
                                <svg v-else-if="notification.type === 'error'" class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                                <!-- Info Icon -->
                                <svg v-else class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p
                                    class="text-sm font-medium"
                                    :class="{
                                        'text-green-800': notification.type === 'success',
                                        'text-red-800': notification.type === 'error',
                                        'text-blue-800': notification.type === 'info'
                                    }"
                                >
                                    {{ notification.message }}
                                </p>
                            </div>
                            <div class="ml-auto pl-3">
                                <div class="-mx-1.5 -my-1.5">
                                    <button
                                        @click="dismissNotification"
                                        class="inline-flex rounded-md p-1.5 focus:outline-none focus:ring-2 focus:ring-offset-2"
                                        :class="{
                                            'text-green-500 hover:bg-green-100 focus:ring-green-600': notification.type === 'success',
                                            'text-red-500 hover:bg-red-100 focus:ring-red-600': notification.type === 'error',
                                            'text-blue-500 hover:bg-blue-100 focus:ring-blue-600': notification.type === 'info'
                                        }"
                                    >
                                        <span class="sr-only">Dismiss</span>
                                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Queue Statistics Dashboard -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Total Active Jobs</p>
                                <p class="text-2xl font-semibold text-gray-900">{{ queueStats?.total_active_jobs || 0 }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Processing</p>
                                <p class="text-2xl font-semibold text-gray-900">{{ queueStats?.processing_jobs || 0 }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Failed Jobs</p>
                                <p class="text-2xl font-semibold text-gray-900">{{ queueStats?.total_failed_jobs || 0 }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Success Rate</p>
                                <p class="text-2xl font-semibold text-gray-900">{{ queueStats?.success_rate || 0 }}%</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Queue Health Indicators -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Queue Health</h3>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div class="text-center">
                                <div class="text-3xl font-bold" :class="getHealthScoreColor(queueHealth?.health_score || 0)">
                                    {{ queueHealth?.health_score || 0 }}
                                </div>
                                <div class="text-sm text-gray-500">Health Score</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-semibold text-orange-600">
                                    {{ queueHealth?.stuck_jobs || 0 }}
                                </div>
                                <div class="text-sm text-gray-500">Stuck Jobs</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-semibold text-purple-600">
                                    {{ queueHealth?.long_running_jobs || 0 }}
                                </div>
                                <div class="text-sm text-gray-500">Long Running</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-semibold text-red-600">
                                    {{ queueHealth?.high_failure_queues?.length || 0 }}
                                </div>
                                <div class="text-sm text-gray-500">Problem Queues</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Worker Status -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg" v-if="workerStatus">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <svg class="w-6 h-6 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                            </svg>
                            Worker Status
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                            <!-- Worker Health -->
                            <div class="text-center p-4 rounded-lg"
                                 :class="{
                                     'bg-green-50 border border-green-200': workerStatus.worker_health === 'good',
                                     'bg-yellow-50 border border-yellow-200': workerStatus.worker_health === 'warning',
                                     'bg-red-50 border border-red-200': workerStatus.worker_health === 'critical'
                                 }">
                                <div class="text-2xl font-bold"
                                     :class="{
                                         'text-green-600': workerStatus.worker_health === 'good',
                                         'text-yellow-600': workerStatus.worker_health === 'warning',
                                         'text-red-600': workerStatus.worker_health === 'critical'
                                     }">
                                    {{ workerStatus.worker_health?.toUpperCase() || 'UNKNOWN' }}
                                </div>
                                <div class="text-sm text-gray-700">Worker Health</div>
                            </div>

                            <!-- Recent Activity -->
                            <div class="text-center p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="text-2xl font-bold text-blue-600">{{ workerStatus.recent_processing_activity || 0 }}</div>
                                <div class="text-sm text-blue-700">Jobs Processed (5min)</div>
                            </div>

                            <!-- Stuck Jobs -->
                            <div class="text-center p-4 bg-orange-50 border border-orange-200 rounded-lg">
                                <div class="text-2xl font-bold text-orange-600">{{ workerStatus.stuck_jobs || 0 }}</div>
                                <div class="text-sm text-orange-700">Stuck Jobs</div>
                            </div>

                            <!-- Health Score -->
                            <div class="text-center p-4 bg-gray-50 border border-gray-200 rounded-lg">
                                <div class="text-lg font-bold"
                                     :class="{
                                         'text-green-600': workerStatus.health_score >= 80,
                                         'text-yellow-600': workerStatus.health_score >= 60,
                                         'text-red-600': workerStatus.health_score < 60
                                     }">
                                    {{ workerStatus.health_score || 0 }}/100
                                </div>
                                <div class="text-sm text-gray-700">Health Score</div>
                                <div class="text-xs text-gray-500 mt-1">
                                    Based on processing activity
                                </div>
                            </div>
                        </div>

                        <!-- Worker Status Description -->
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="text-sm text-blue-800">
                                <span class="font-medium">📊 Status Method:</span> {{ workerStatus.monitoring_method || 'Job processing activity monitoring' }}
                            </div>
                            <div class="text-sm text-blue-700 mt-1">
                                {{ workerStatus.status_description || 'Monitoring workers based on actual job processing activity rather than supervisor socket monitoring.' }}
                            </div>
                            <div class="text-xs text-blue-600 mt-2">
                                Last checked: {{ new Date(workerStatus.last_checked).toLocaleTimeString() }}
                            </div>
                        </div>

                        <!-- Worker Troubleshooting -->
                        <div v-if="workerStatus.worker_health !== 'good'" class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <h4 class="font-medium text-yellow-800 mb-2 flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                </svg>
                                Worker Issues Detected
                            </h4>
                            <div class="text-sm text-yellow-700 space-y-1">
                                <div v-if="!workerStatus.supervisor_running">• Supervisor is not running - workers may not be processing jobs</div>
                                <div v-if="workerStatus.stuck_jobs > 0">• {{ workerStatus.stuck_jobs }} jobs are stuck (created over 1 hour ago)</div>
                                <div v-if="!workerStatus.workers_likely_active">• No recent processing activity detected in the last 5 minutes</div>
                                <div v-if="workerStatus.artisan_responsive === false">• Laravel artisan commands are not responding</div>
                                <div class="mt-2 font-medium">💡 Try: <code class="px-1 py-0.5 bg-yellow-100 rounded text-xs">docker restart laravel-app</code></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Priority Queue Status -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg" v-if="priorityQueueStats && Object.keys(priorityQueueStats).length > 0">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Priority Queue Status</h3>
                        <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="text-sm text-blue-800">
                                <span class="font-medium">✨ New System:</span> Single Queue + Job Priority (simplified from 6 to 2 queues)
                            </div>
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Audio Extraction Queue -->
                            <div>
                                <h4 class="text-md font-medium text-gray-700 mb-3 flex items-center">
                                    <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                    </svg>
                                    Audio Extraction Queue
                                </h4>
                                <div class="space-y-3">
                                    <!-- Main Queue Overview -->
                                    <div v-if="priorityQueueStats['audio-extraction']" class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-sm font-medium text-blue-900">audio-extraction (main queue)</span>
                                            <span class="text-xs px-2 py-1 bg-blue-100 text-blue-800 rounded">Priority-Aware</span>
                                        </div>
                                        <div class="grid grid-cols-3 gap-4 text-center">
                                            <div>
                                                <div class="text-lg font-bold text-gray-900">{{ priorityQueueStats['audio-extraction'].total_jobs || 0 }}</div>
                                                <div class="text-xs text-gray-600">Total</div>
                                            </div>
                                            <div>
                                                <div class="text-lg font-bold text-yellow-600">{{ priorityQueueStats['audio-extraction'].processing_jobs || 0 }}</div>
                                                <div class="text-xs text-gray-600">Processing</div>
                                            </div>
                                            <div>
                                                <div class="text-lg font-bold text-blue-600">{{ priorityQueueStats['audio-extraction'].pending_jobs || 0 }}</div>
                                                <div class="text-xs text-gray-600">Pending</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Priority Breakdown -->
                                    <div class="text-xs font-medium text-gray-600 mb-2">Priority Breakdown:</div>
                                    <div class="space-y-2">
                                        <!-- High Priority -->
                                        <div v-if="priorityQueueStats['audio-extraction-high']" 
                                             class="flex justify-between items-center p-2 bg-red-50 border border-red-200 rounded">
                                            <div class="flex items-center">
                                                <span class="text-xs px-2 py-1 bg-red-100 text-red-800 rounded font-medium">HIGH</span>
                                                <span class="ml-2 text-sm">⚡ Priority ≥5</span>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <div class="text-center">
                                                    <div class="text-sm font-bold text-gray-900">{{ priorityQueueStats['audio-extraction-high'].pending_jobs || 0 }}</div>
                                                    <div class="text-xs text-gray-500">Pending</div>
                                                </div>
                                                <div class="text-center">
                                                    <div class="text-sm font-bold text-yellow-600">{{ priorityQueueStats['audio-extraction-high'].processing_jobs || 0 }}</div>
                                                    <div class="text-xs text-gray-500">Processing</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Normal Priority -->
                                        <div v-if="priorityQueueStats['audio-extraction-normal']" 
                                             class="flex justify-between items-center p-2 bg-blue-50 border border-blue-200 rounded">
                                            <div class="flex items-center">
                                                <span class="text-xs px-2 py-1 bg-blue-100 text-blue-800 rounded font-medium">NORMAL</span>
                                                <span class="ml-2 text-sm">🔵 Priority 0-4</span>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <div class="text-center">
                                                    <div class="text-sm font-bold text-gray-900">{{ priorityQueueStats['audio-extraction-normal'].pending_jobs || 0 }}</div>
                                                    <div class="text-xs text-gray-500">Pending</div>
                                                </div>
                                                <div class="text-center">
                                                    <div class="text-sm font-bold text-yellow-600">{{ priorityQueueStats['audio-extraction-normal'].processing_jobs || 0 }}</div>
                                                    <div class="text-xs text-gray-500">Processing</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Transcription Queue -->
                            <div>
                                <h4 class="text-md font-medium text-gray-700 mb-3 flex items-center">
                                    <svg class="w-5 h-5 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                                    </svg>
                                    Transcription Queue
                                </h4>
                                <div class="space-y-3">
                                    <!-- Main Queue Overview -->
                                    <div v-if="priorityQueueStats['transcription']" class="p-3 bg-purple-50 border border-purple-200 rounded-lg">
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-sm font-medium text-purple-900">transcription (main queue)</span>
                                            <span class="text-xs px-2 py-1 bg-purple-100 text-purple-800 rounded">Priority-Aware</span>
                                        </div>
                                        <div class="grid grid-cols-3 gap-4 text-center">
                                            <div>
                                                <div class="text-lg font-bold text-gray-900">{{ priorityQueueStats['transcription'].total_jobs || 0 }}</div>
                                                <div class="text-xs text-gray-600">Total</div>
                                            </div>
                                            <div>
                                                <div class="text-lg font-bold text-yellow-600">{{ priorityQueueStats['transcription'].processing_jobs || 0 }}</div>
                                                <div class="text-xs text-gray-600">Processing</div>
                                            </div>
                                            <div>
                                                <div class="text-lg font-bold text-purple-600">{{ priorityQueueStats['transcription'].pending_jobs || 0 }}</div>
                                                <div class="text-xs text-gray-600">Pending</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Priority Breakdown -->
                                    <div class="text-xs font-medium text-gray-600 mb-2">Priority Breakdown:</div>
                                    <div class="space-y-2">
                                        <!-- High Priority -->
                                        <div v-if="priorityQueueStats['transcription-high']" 
                                             class="flex justify-between items-center p-2 bg-red-50 border border-red-200 rounded">
                                            <div class="flex items-center">
                                                <span class="text-xs px-2 py-1 bg-red-100 text-red-800 rounded font-medium">HIGH</span>
                                                <span class="ml-2 text-sm">⚡ Priority ≥5</span>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <div class="text-center">
                                                    <div class="text-sm font-bold text-gray-900">{{ priorityQueueStats['transcription-high'].pending_jobs || 0 }}</div>
                                                    <div class="text-xs text-gray-500">Pending</div>
                                                </div>
                                                <div class="text-center">
                                                    <div class="text-sm font-bold text-yellow-600">{{ priorityQueueStats['transcription-high'].processing_jobs || 0 }}</div>
                                                    <div class="text-xs text-gray-500">Processing</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Normal Priority -->
                                        <div v-if="priorityQueueStats['transcription-normal']" 
                                             class="flex justify-between items-center p-2 bg-purple-50 border border-purple-200 rounded">
                                            <div class="flex items-center">
                                                <span class="text-xs px-2 py-1 bg-purple-100 text-purple-800 rounded font-medium">NORMAL</span>
                                                <span class="ml-2 text-sm">🔵 Priority 0-4</span>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <div class="text-center">
                                                    <div class="text-sm font-bold text-gray-900">{{ priorityQueueStats['transcription-normal'].pending_jobs || 0 }}</div>
                                                    <div class="text-xs text-gray-500">Pending</div>
                                                </div>
                                                <div class="text-center">
                                                    <div class="text-sm font-bold text-yellow-600">{{ priorityQueueStats['transcription-normal'].processing_jobs || 0 }}</div>
                                                    <div class="text-xs text-gray-500">Processing</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pipeline Status & Bottlenecks -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg" v-if="pipelineStatus">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Pipeline Status</h3>
                        
                        <!-- Pipeline Efficiency -->
                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-700">Pipeline Efficiency</span>
                                <span class="text-sm font-bold" :class="{
                                    'text-green-600': pipelineStatus.pipeline_efficiency >= 80,
                                    'text-yellow-600': pipelineStatus.pipeline_efficiency >= 60,
                                    'text-red-600': pipelineStatus.pipeline_efficiency < 60
                                }">{{ pipelineStatus.pipeline_efficiency }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3">
                                <div class="h-3 rounded-full transition-all" 
                                     :class="{
                                         'bg-green-500': pipelineStatus.pipeline_efficiency >= 80,
                                         'bg-yellow-500': pipelineStatus.pipeline_efficiency >= 60,
                                         'bg-red-500': pipelineStatus.pipeline_efficiency < 60
                                     }"
                                     :style="{ width: pipelineStatus.pipeline_efficiency + '%' }"></div>
                            </div>
                        </div>

                        <!-- Bottlenecks Alert -->
                        <div v-if="pipelineStatus.bottlenecks && pipelineStatus.bottlenecks.length > 0" 
                             class="mb-6 p-4 bg-orange-50 border border-orange-200 rounded-lg">
                            <h4 class="font-medium text-orange-800 mb-2 flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                </svg>
                                Pipeline Bottlenecks Detected
                            </h4>
                            <div class="space-y-2">
                                <div v-for="bottleneck in pipelineStatus.bottlenecks" :key="bottleneck.type" 
                                     class="text-sm text-orange-700">
                                    • {{ bottleneck.description }}
                                    <span v-if="bottleneck.count" class="font-medium">({{ bottleneck.count }} jobs)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Pipeline Flow -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="p-4 bg-blue-50 rounded-lg">
                                <h4 class="font-medium text-blue-800 mb-3">Audio Extraction</h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-sm text-blue-700">Total Jobs:</span>
                                        <span class="font-medium">{{ pipelineStatus.audio_extraction?.total_jobs || 0 }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-sm text-blue-700">Processing:</span>
                                        <span class="font-medium text-yellow-600">{{ pipelineStatus.audio_extraction?.processing_jobs || 0 }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-sm text-blue-700">Pending:</span>
                                        <span class="font-medium">{{ pipelineStatus.audio_extraction?.pending_jobs || 0 }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="p-4 bg-purple-50 rounded-lg">
                                <h4 class="font-medium text-purple-800 mb-3">Transcription</h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-sm text-purple-700">Total Jobs:</span>
                                        <span class="font-medium">{{ pipelineStatus.transcription?.total_jobs || 0 }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-sm text-purple-700">Processing:</span>
                                        <span class="font-medium text-yellow-600">{{ pipelineStatus.transcription?.processing_jobs || 0 }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-sm text-purple-700">Pending:</span>
                                        <span class="font-medium">{{ pipelineStatus.transcription?.pending_jobs || 0 }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Segment Processing Context -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg" v-if="segmentContext">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">TrueFire Segment Processing</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                            <div class="text-center p-4 bg-blue-50 rounded-lg">
                                <div class="text-2xl font-bold text-blue-600">{{ segmentContext.processing_segments?.length || 0 }}</div>
                                <div class="text-sm text-blue-700">Currently Processing</div>
                            </div>
                            <div class="text-center p-4 bg-green-50 rounded-lg">
                                <div class="text-2xl font-bold text-green-600">{{ segmentContext.recent_completed_count || 0 }}</div>
                                <div class="text-sm text-green-700">Completed (24h)</div>
                            </div>
                            <div class="text-center p-4 bg-red-50 rounded-lg">
                                <div class="text-2xl font-bold text-red-600">{{ segmentContext.failed_segments?.length || 0 }}</div>
                                <div class="text-sm text-red-700">Failed Segments</div>
                            </div>
                            <div class="text-center p-4 bg-gray-50 rounded-lg">
                                <div class="text-2xl font-bold text-gray-600">{{ segmentContext.total_segments_in_system || 0 }}</div>
                                <div class="text-sm text-gray-700">Total in System</div>
                            </div>
                        </div>

                        <!-- Currently Processing Segments -->
                        <div v-if="segmentContext.processing_segments && segmentContext.processing_segments.length > 0" class="mb-6">
                            <h4 class="font-medium text-gray-700 mb-3">Currently Processing Segments</h4>
                            <div class="bg-gray-50 rounded-lg p-4">
                                <div class="space-y-2">
                                    <div v-for="segment in segmentContext.processing_segments.slice(0, 5)" :key="segment.segment_id" 
                                         class="flex justify-between items-center p-2 bg-white rounded border">
                                        <div class="flex items-center space-x-3">
                                            <span class="text-xs px-2 py-1 rounded font-medium"
                                                  :class="{
                                                      'bg-red-100 text-red-800': segment.priority === 'high',
                                                      'bg-blue-100 text-blue-800': segment.priority === 'normal',
                                                      'bg-gray-100 text-gray-800': segment.priority === 'low'
                                                  }">
                                                {{ segment.priority?.toUpperCase() || 'NORMAL' }}
                                            </span>
                                            <span class="text-sm">Course {{ segment.course_id }}, Segment {{ segment.segment_id }}</span>
                                            <span class="text-xs px-2 py-1 rounded bg-yellow-100 text-yellow-800">{{ segment.status }}</span>
                                        </div>
                                        <div class="text-sm font-medium">{{ segment.progress_percentage || 0 }}%</div>
                                    </div>
                                    <div v-if="segmentContext.processing_segments.length > 5" class="text-xs text-gray-500 text-center">
                                        ... and {{ segmentContext.processing_segments.length - 5 }} more segments
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Failed Segments -->
                        <div v-if="segmentContext.failed_segments && segmentContext.failed_segments.length > 0">
                            <h4 class="font-medium text-gray-700 mb-3">Failed Segments</h4>
                            <div class="bg-red-50 rounded-lg p-4">
                                <div class="space-y-2">
                                    <div v-for="segment in segmentContext.failed_segments.slice(0, 3)" :key="segment.segment_id" 
                                         class="flex justify-between items-start p-2 bg-white rounded border border-red-200">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-2 mb-1">
                                                <span class="text-sm font-medium">Course {{ segment.course_id }}, Segment {{ segment.segment_id }}</span>
                                                <span v-if="segment.priority" class="text-xs px-2 py-1 rounded bg-red-100 text-red-800">
                                                    {{ segment.priority.toUpperCase() }}
                                                </span>
                                            </div>
                                            <div class="text-xs text-red-600">{{ segment.error_message || 'Unknown error' }}</div>
                                        </div>
                                    </div>
                                    <div v-if="segmentContext.failed_segments.length > 3" class="text-xs text-red-600 text-center">
                                        ... and {{ segmentContext.failed_segments.length - 3 }} more failed segments
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Job Type Breakdown Chart -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Job Type Distribution</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="text-md font-medium text-gray-700 mb-2">Active Jobs</h4>
                                <div class="space-y-2">
                                    <div v-for="(count, type) in jobTypeBreakdown?.active_jobs" :key="type" class="flex justify-between items-center">
                                        <span class="text-sm text-gray-600">{{ type }}</span>
                                        <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-sm">{{ count }}</span>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <h4 class="text-md font-medium text-gray-700 mb-2">Failed Jobs</h4>
                                <div class="space-y-2">
                                    <div v-for="(count, type) in jobTypeBreakdown?.failed_jobs" :key="type" class="flex justify-between items-center">
                                        <span class="text-sm text-gray-600">{{ type }}</span>
                                        <span class="px-2 py-1 bg-red-100 text-red-800 rounded text-sm">{{ count }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Timeline -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Recent Activity (Last 24 Hours)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-blue-50 p-4 rounded-lg">
                                <h4 class="font-medium text-blue-900 mb-2">Jobs Created</h4>
                                <div class="text-2xl font-bold text-blue-600">{{ recentActivity?.total_jobs_last_24h || 0 }}</div>
                            </div>
                            <div class="bg-red-50 p-4 rounded-lg">
                                <h4 class="font-medium text-red-900 mb-2">Jobs Failed</h4>
                                <div class="text-2xl font-bold text-red-600">{{ recentActivity?.total_failed_last_24h || 0 }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Job Batches Section -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg" v-if="jobBatches?.length > 0">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Job Batches</h3>
                        <div class="space-y-4">
                            <div v-for="batch in jobBatches" :key="batch.id" class="border rounded-lg p-4">
                                <div class="flex justify-between items-center mb-2">
                                    <h4 class="font-medium text-gray-900">{{ batch.name || `Batch ${batch.id}` }}</h4>
                                    <span class="px-2 py-1 text-xs rounded-full" :class="getBatchStatusColor(batch.status)">
                                        {{ batch.status }}
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2 mb-2">
                                    <div class="bg-blue-600 h-2 rounded-full" :style="`width: ${batch.progress_percentage}%`"></div>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600">
                                    <span>{{ batch.completed_jobs }}/{{ batch.total_jobs }} completed</span>
                                    <span>{{ batch.progress_percentage }}%</span>
                                </div>
                                <div v-if="batch.failed_jobs > 0" class="text-sm text-red-600 mt-1">
                                    {{ batch.failed_jobs }} failed
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Filters and Search -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Search & Filter Jobs</h3>
                        
                        <!-- Quick Filter Buttons -->
                        <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                            <div class="flex justify-between items-start mb-3">
                                <div class="text-sm font-medium text-gray-700">Quick Filters:</div>
                                <details class="text-xs text-gray-500">
                                    <summary class="cursor-pointer hover:text-gray-700">⌨️ Keyboard shortcuts</summary>
                                    <div class="mt-2 p-2 bg-white rounded border border-gray-200 text-xs">
                                        <div class="space-y-1">
                                            <div><kbd class="px-1 py-0.5 bg-gray-100 rounded text-xs">H</kbd> - High Priority Only</div>
                                            <div><kbd class="px-1 py-0.5 bg-gray-100 rounded text-xs">P</kbd> - Processing Jobs</div>
                                            <div><kbd class="px-1 py-0.5 bg-gray-100 rounded text-xs">T</kbd> - Transcription Jobs</div>
                                            <div><kbd class="px-1 py-0.5 bg-gray-100 rounded text-xs">C</kbd> - Clear All Filters</div>
                                        </div>
                                    </div>
                                </details>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button 
                                    @click="showHighPriorityOnly"
                                    class="inline-flex items-center px-3 py-2 border border-red-300 text-sm leading-4 font-medium rounded-md text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition"
                                    title="Keyboard shortcut: H"
                                >
                                    ⚡ High Priority Only
                                </button>
                                <button 
                                    @click="showProcessingJobs"
                                    class="inline-flex items-center px-3 py-2 border border-yellow-300 text-sm leading-4 font-medium rounded-md text-yellow-700 bg-yellow-50 hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition"
                                    title="Keyboard shortcut: P"
                                >
                                    🔄 Processing Jobs
                                </button>
                                <button 
                                    @click="showTranscriptionJobs"
                                    class="inline-flex items-center px-3 py-2 border border-purple-300 text-sm leading-4 font-medium rounded-md text-purple-700 bg-purple-50 hover:bg-purple-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition"
                                    title="Keyboard shortcut: T"
                                >
                                    🎤 Transcription Jobs
                                </button>
                                <button 
                                    @click="clearAllFilters"
                                    class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition"
                                    title="Keyboard shortcut: C"
                                >
                                    🗑️ Clear All
                                </button>
                            </div>
                        </div>
                        
                        <!-- Search Section -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                            <div class="relative">
                                <input 
                                    v-model="searchTerm"
                                    type="text" 
                                    placeholder="Search by job type, queue, description, segment ID, course ID, priority, preset..."
                                    class="w-full pl-10 pr-4 py-2 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                Search includes: job descriptions, segment/course IDs, priority levels, presets, and queue names
                            </div>
                        </div>
                        
                        <!-- Filter Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                                <select 
                                    v-model="selectedPriority"
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option value="">All Priorities</option>
                                    <option v-for="priority in uniquePriorities" :key="priority" :value="priority">
                                        {{ priority.charAt(0).toUpperCase() + priority.slice(1) }}
                                        <span v-if="priority === 'high'">⚡</span>
                                        <span v-if="priority === 'low'">🔻</span>
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Job Type</label>
                                <select 
                                    v-model="selectedJobType"
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option value="">All Types</option>
                                    <option v-for="type in uniqueJobTypes" :key="type" :value="type">{{ type }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select 
                                    v-model="selectedStatus"
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option value="">All Statuses</option>
                                    <option v-for="status in uniqueStatuses" :key="status" :value="status">
                                        {{ status.charAt(0).toUpperCase() + status.slice(1) }}
                                    </option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button 
                                    @click="clearAllFilters"
                                    class="w-full px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 transition"
                                >
                                    Clear All Filters
                                </button>
                            </div>
                            <div class="flex items-end">
                                <button 
                                    @click="showHighPriorityOnly"
                                    class="w-full px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition text-sm"
                                    title="Show only high priority jobs"
                                >
                                    ⚡ High Priority Only
                                </button>
                            </div>
                        </div>
                        
                        <!-- Filter Summary -->
                        <div v-if="searchTerm || selectedJobType || selectedStatus || selectedPriority" class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="text-sm text-blue-800">
                                <span class="font-medium">Active filters:</span>
                                <span v-if="searchTerm" class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    Search: "{{ searchTerm }}"
                                </span>
                                <span v-if="selectedPriority" class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                      :class="{
                                          'bg-red-100 text-red-800': selectedPriority === 'high',
                                          'bg-blue-100 text-blue-800': selectedPriority === 'normal',
                                          'bg-gray-100 text-gray-800': selectedPriority === 'low'
                                      }">
                                    Priority: {{ selectedPriority.charAt(0).toUpperCase() + selectedPriority.slice(1) }}
                                </span>
                                <span v-if="selectedJobType" class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Type: {{ selectedJobType }}
                                </span>
                                <span v-if="selectedStatus" class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                    Status: {{ selectedStatus }}
                                </span>
                                <span class="ml-2 text-blue-600 font-medium">
                                    ({{ filteredActiveJobs.length }} job{{ filteredActiveJobs.length !== 1 ? 's' : '' }} found)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Jobs Table -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Active Jobs</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th @click="sortBy('job_class')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Job Description
                                            <span v-if="sortField === 'job_class'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th @click="sortBy('queue')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Queue
                                            <span v-if="sortField === 'queue'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th @click="sortBy('priority')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Priority
                                            <span v-if="sortField === 'priority'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th @click="sortBy('status')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Status
                                            <span v-if="sortField === 'status'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th @click="sortBy('wait_time')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Timing
                                            <span v-if="sortField === 'wait_time'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th @click="sortBy('created_at')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Created
                                            <span v-if="sortField === 'created_at'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template v-for="job in filteredActiveJobs" :key="job.id">
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 text-sm">
                                                <div class="flex flex-col">
                                                    <div class="font-medium text-gray-900">
                                                        {{ job.payload_data?.job_description || job.job_class }}
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        {{ job.job_class }}
                                                        <span v-if="job.payload_data?.estimated_duration" class="ml-2">
                                                            (~{{ job.payload_data.estimated_duration }})
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-sm">
                                                <div class="flex flex-col">
                                                    <div class="font-medium">{{ job.queue }}</div>
                                                    <div v-if="job.payload_data?.context" class="text-xs text-gray-500 mt-1">
                                                        {{ job.payload_data.context }}
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span v-if="job.payload_data?.priority" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                                      :class="{
                                                          'bg-red-100 text-red-800': job.payload_data.priority === 'high',
                                                          'bg-blue-100 text-blue-800': job.payload_data.priority === 'normal',
                                                          'bg-gray-100 text-gray-800': job.payload_data.priority === 'low'
                                                      }">
                                                    <span v-if="job.payload_data.priority === 'high'" class="mr-1">⚡</span>
                                                    <span v-if="job.payload_data.priority === 'low'" class="mr-1">🔻</span>
                                                    {{ job.payload_data.priority.toUpperCase() }}
                                                </span>
                                                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                                    NORMAL
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex flex-col items-start">
                                                    <span class="px-2 py-1 text-xs rounded-full" :class="getStatusColor(job.status)">
                                                        {{ job.status }}
                                                    </span>
                                                    <span class="text-xs text-gray-500 mt-1">
                                                        {{ job.attempts }} attempts
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-sm">
                                                <div class="flex flex-col">
                                                    <div class="text-gray-900">
                                                        <span class="font-medium">{{ formatDuration(job.wait_time) }}</span>
                                                        <span class="text-xs text-gray-500 ml-1">wait</span>
                                                    </div>
                                                    <div v-if="job.processing_time" class="text-xs text-gray-500">
                                                        {{ formatDuration(job.processing_time) }} processing
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ formatTimeAgo(job.created_at) }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <button 
                                                    @click="toggleJobExpansion(job.id)"
                                                    class="text-indigo-600 hover:text-indigo-900"
                                                >
                                                    {{ expandedJobs.has(job.id) ? 'Hide' : 'Details' }}
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="expandedJobs.has(job.id)" class="bg-gray-50">
                                            <td colspan="7" class="px-6 py-4">
                                                <div class="space-y-4">
                                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                        <div>
                                                            <h5 class="font-medium text-gray-700 mb-2">Job Information</h5>
                                                            <div class="space-y-1 text-sm">
                                                                <div><strong>Job ID:</strong> {{ job.id }}</div>
                                                                <div><strong>Queue:</strong> {{ job.queue }}</div>
                                                                <div><strong>Attempts:</strong> {{ job.attempts }}</div>
                                                                <div><strong>Status:</strong> {{ job.status }}</div>
                                                                <div v-if="job.payload_data?.priority">
                                                                    <strong>Priority:</strong> {{ job.payload_data.priority.toUpperCase() }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <div>
                                                            <h5 class="font-medium text-gray-700 mb-2">Timing Information</h5>
                                                            <div class="space-y-1 text-sm">
                                                                <div><strong>Created:</strong> {{ new Date(job.created_at).toLocaleString() }}</div>
                                                                <div><strong>Available:</strong> {{ new Date(job.available_at).toLocaleString() }}</div>
                                                                <div v-if="job.reserved_at">
                                                                    <strong>Reserved:</strong> {{ new Date(job.reserved_at).toLocaleString() }}
                                                                </div>
                                                                <div><strong>Wait Time:</strong> {{ formatDuration(job.wait_time) }}</div>
                                                                <div v-if="job.processing_time">
                                                                    <strong>Processing Time:</strong> {{ formatDuration(job.processing_time) }}
                                                                </div>
                                                                <div v-if="job.payload_data?.estimated_duration">
                                                                    <strong>Estimated Duration:</strong> {{ job.payload_data.estimated_duration }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <div>
                                                            <h5 class="font-medium text-gray-700 mb-2">Context Information</h5>
                                                            <div class="space-y-1 text-sm">
                                                                <div v-if="job.payload_data?.segment_id">
                                                                    <strong>Segment:</strong> {{ job.payload_data.segment_id }}
                                                                </div>
                                                                <div v-if="job.payload_data?.course_id">
                                                                    <strong>Course:</strong> {{ job.payload_data.course_id }}
                                                                </div>
                                                                <div v-if="job.payload_data?.transcription_preset">
                                                                    <strong>Preset:</strong> {{ job.payload_data.transcription_preset }}
                                                                </div>
                                                                <div v-if="job.payload_data?.use_intelligent_detection">
                                                                    <strong>Intelligent Detection:</strong> Yes
                                                                </div>
                                                                <div v-if="job.payload_data?.force_reextraction">
                                                                    <strong>Force Re-extraction:</strong> Yes
                                                                </div>
                                                                <div v-if="job.payload_data?.batch_id">
                                                                    <strong>Batch:</strong> {{ job.payload_data.batch_id }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div v-if="job.payload_data && Object.keys(job.payload_data).length > 0">
                                                        <details class="mt-4">
                                                            <summary class="font-medium text-gray-700 cursor-pointer">Raw Payload Data</summary>
                                                            <pre class="mt-2 text-xs bg-gray-100 p-3 rounded overflow-x-auto">{{ JSON.stringify(job.payload_data, null, 2) }}</pre>
                                                        </details>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <div v-if="filteredActiveJobs.length === 0" class="text-center py-8 text-gray-500">
                                No active jobs found.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Failed Jobs Table -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg" v-if="failedJobs?.length > 0">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Failed Jobs</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Type</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Queue</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Failed At</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Error</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template v-for="job in filteredFailedJobs" :key="job.id">
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                {{ job.job_class }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ job.queue }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ formatTimeAgo(job.failed_at) }}
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                                                {{ job.exception?.message || 'Unknown error' }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <button
                                                    @click="toggleFailedJobExpansion(job.id)"
                                                    class="text-indigo-600 hover:text-indigo-900 mr-3"
                                                >
                                                    {{ expandedFailedJobs.has(job.id) ? 'Hide' : 'Details' }}
                                                </button>
                                                <button 
                                                    @click="retryFailedJob(job.id)"
                                                    class="text-green-600 hover:text-green-900 inline-flex items-center"
                                                    title="Retry this job with high priority"
                                                >
                                                    ⚡ Retry
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="expandedFailedJobs.has(job.id)" class="bg-red-50">
                                            <td colspan="5" class="px-6 py-4">
                                                <div class="space-y-2">
                                                    <div><strong>Job UUID:</strong> {{ job.uuid }}</div>
                                                    <div><strong>Connection:</strong> {{ job.connection }}</div>
                                                    <div v-if="job.payload_data && Object.keys(job.payload_data).length > 0">
                                                        <strong>Payload Data:</strong>
                                                        <pre class="mt-1 text-xs bg-gray-100 p-2 rounded">{{ JSON.stringify(job.payload_data, null, 2) }}</pre>
                                                    </div>
                                                    <div>
                                                        <strong>Full Exception:</strong>
                                                        <pre class="mt-1 text-xs bg-red-100 p-2 rounded max-h-40 overflow-y-auto">{{ job.exception?.full_trace || 'No trace available' }}</pre>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <div v-if="filteredFailedJobs.length === 0" class="text-center py-8 text-gray-500">
                                No failed jobs found.
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        
        <!-- Prune All Jobs Confirmation Modal -->
        <Modal :show="showPruneConfirmModal" @close="showPruneConfirmModal = false">
            <div class="p-6">
                <div class="flex items-center">
                    <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-yellow-100 sm:mx-0 sm:h-10 sm:w-10">
                        <svg class="h-6 w-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                        <h3 class="text-base font-semibold leading-6 text-gray-900">
                            Prune All Completed Jobs
                        </h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-500">
                                This will permanently remove all completed jobs from the queue. This action cannot be undone.
                                Are you sure you want to continue?
                            </p>
                        </div>
                    </div>
                </div>
                <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                    <SecondaryButton
                        @click="pruneAllJobs"
                        class="w-full justify-center sm:ml-3 sm:w-auto"
                    >
                        Yes, Prune Jobs
                    </SecondaryButton>
                    <SecondaryButton
                        @click="showPruneConfirmModal = false"
                        class="mt-3 w-full justify-center sm:mt-0 sm:w-auto"
                    >
                        Cancel
                    </SecondaryButton>
                </div>
            </div>
        </Modal>
        
        <!-- Clear Failed Jobs Confirmation Modal -->
        <Modal :show="showClearFailedConfirmModal" @close="showClearFailedConfirmModal = false">
            <div class="p-6">
                <div class="flex items-center">
                    <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                        <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                        <h3 class="text-base font-semibold leading-6 text-gray-900">
                            Clear All Failed Jobs
                        </h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-500">
                                This will permanently remove all failed jobs from the queue. You will lose the ability to retry these jobs.
                                Are you sure you want to continue?
                            </p>
                            <p class="text-sm text-red-600 mt-1 font-medium">
                                {{ failedJobs?.length || 0 }} failed jobs will be deleted.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                    <DangerButton
                        @click="clearFailedJobs"
                        class="w-full justify-center sm:ml-3 sm:w-auto"
                    >
                        Yes, Clear Failed Jobs
                    </DangerButton>
                    <SecondaryButton
                        @click="showClearFailedConfirmModal = false"
                        class="mt-3 w-full justify-center sm:mt-0 sm:w-auto"
                    >
                        Cancel
                    </SecondaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>