<script setup>
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, onMounted, onUnmounted, computed } from 'vue';

const props = defineProps({
    activeJobs: Array,
    failedJobs: Array,
    jobBatches: Array,
    queueStats: Object,
    jobTypeBreakdown: Object,
    recentActivity: Object,
    queueHealth: Object,
    processingTimes: Object,
});

const lastUpdated = ref(new Date());
const isRefreshing = ref(false);
const autoRefreshInterval = ref(null);
const expandedJobs = ref(new Set());
const expandedFailedJobs = ref(new Set());
const searchTerm = ref('');
const selectedJobType = ref('');
const selectedStatus = ref('');
const sortField = ref('created_at');
const sortDirection = ref('desc');

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
        jobs = jobs.filter(job => 
            job.job_class.toLowerCase().includes(searchTerm.value.toLowerCase()) ||
            job.queue.toLowerCase().includes(searchTerm.value.toLowerCase())
        );
    }
    
    if (selectedJobType.value) {
        jobs = jobs.filter(job => job.job_class === selectedJobType.value);
    }
    
    if (selectedStatus.value) {
        jobs = jobs.filter(job => job.status === selectedStatus.value);
    }
    
    // Sort jobs
    jobs.sort((a, b) => {
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

// Lifecycle hooks
onMounted(() => {
    startAutoRefresh();
});

onUnmounted(() => {
    stopAutoRefresh();
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
                    <button 
                        @click="refreshData"
                        :disabled="isRefreshing"
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                    >
                        <span v-if="isRefreshing">Refreshing...</span>
                        <span v-else>Refresh</span>
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                
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

                <!-- Filters and Search -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <input 
                                    v-model="searchTerm"
                                    type="text" 
                                    placeholder="Search jobs..."
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
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
                                    <option v-for="status in uniqueStatuses" :key="status" :value="status">{{ status }}</option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button 
                                    @click="searchTerm = ''; selectedJobType = ''; selectedStatus = ''"
                                    class="w-full px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700"
                                >
                                    Clear Filters
                                </button>
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
                                            Job Type
                                            <span v-if="sortField === 'job_class'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th @click="sortBy('queue')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Queue
                                            <span v-if="sortField === 'queue'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th @click="sortBy('status')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Status
                                            <span v-if="sortField === 'status'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th @click="sortBy('attempts')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Attempts
                                            <span v-if="sortField === 'attempts'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </th>
                                        <th @click="sortBy('wait_time')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                                            Wait Time
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
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                {{ job.job_class }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ job.queue }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 py-1 text-xs rounded-full" :class="getStatusColor(job.status)">
                                                    {{ job.status }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ job.attempts }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ formatDuration(job.wait_time) }}
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
                                                <div class="space-y-2">
                                                    <div><strong>Job ID:</strong> {{ job.id }}</div>
                                                    <div v-if="job.processing_time"><strong>Processing Time:</strong> {{ formatDuration(job.processing_time) }}</div>
                                                    <div v-if="job.payload_data && Object.keys(job.payload_data).length > 0">
                                                        <strong>Payload Data:</strong>
                                                        <pre class="mt-1 text-xs bg-gray-100 p-2 rounded">{{ JSON.stringify(job.payload_data, null, 2) }}</pre>
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
                                                <button class="text-green-600 hover:text-green-900">
                                                    Retry
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
    </AuthenticatedLayout>
</template>