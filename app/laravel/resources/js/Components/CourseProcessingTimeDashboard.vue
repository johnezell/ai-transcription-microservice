<template>
    <div class="space-y-6">
        <!-- Course Processing Time Overview -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">Course Processing Time Analysis</h3>
                    <p class="text-sm text-gray-600 mt-1">Real-time monitoring and historical analysis of course processing performance</p>
                </div>
                <div class="flex items-center space-x-3">
                    <select v-model="selectedTimeRange" @change="refreshData" class="text-sm border-gray-300 rounded-md">
                        <option value="24h">Last 24 Hours</option>
                        <option value="7d">Last 7 Days</option>
                        <option value="30d">Last 30 Days</option>
                        <option value="90d">Last 90 Days</option>
                    </select>
                    <button @click="refreshData" class="px-3 py-1 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>

            <!-- Real-time Active Processing -->
            <div v-if="activeProcessing.length > 0" class="mb-6 bg-blue-50 rounded-lg p-4 border border-blue-200">
                <h4 class="font-medium text-blue-800 mb-3 flex items-center">
                    <div class="w-3 h-3 bg-blue-500 rounded-full animate-pulse mr-2"></div>
                    Active Processing ({{ activeProcessing.length }} courses)
                </h4>
                <div class="space-y-3">
                    <div v-for="processing in activeProcessing" :key="processing.course_id" 
                         class="bg-white rounded-lg p-3 border border-blue-200">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <div class="flex items-center">
                                    <div class="font-medium text-gray-900">{{ processing.course_title }}</div>
                                    <span class="ml-2 px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded-full">
                                        {{ processing.status }}
                                    </span>
                                </div>
                                <div class="text-sm text-gray-600 mt-1">
                                    {{ processing.completed_segments }}/{{ processing.total_segments }} segments complete
                                    • {{ formatElapsedTime(processing.elapsed_seconds) }} elapsed
                                </div>
                                <div class="mt-2">
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" 
                                             :style="{ width: processing.progress_percentage + '%' }"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="ml-4 text-right">
                                <div class="text-lg font-bold text-blue-600">{{ processing.progress_percentage }}%</div>
                                <div class="text-xs text-gray-500">{{ processing.processing_type }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Processing Time Metrics -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-4 border border-blue-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-blue-800">Average Course Time</p>
                            <p class="text-2xl font-bold text-blue-900">{{ formatDuration(timingStats.avg_course_processing_time) }}</p>
                            <p class="text-xs text-blue-600 mt-1">Per complete course</p>
                        </div>
                        <div class="w-12 h-12 bg-blue-200 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-r from-green-50 to-green-100 rounded-lg p-4 border border-green-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-green-800">Fastest Course</p>
                            <p class="text-2xl font-bold text-green-900">{{ formatDuration(timingStats.fastest_course_time) }}</p>
                            <p class="text-xs text-green-600 mt-1">{{ timingStats.fastest_course_segments }} segments</p>
                        </div>
                        <div class="w-12 h-12 bg-green-200 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg p-4 border border-orange-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-orange-800">Total Processing</p>
                            <p class="text-2xl font-bold text-orange-900">{{ formatDuration(timingStats.total_processing_time) }}</p>
                            <p class="text-xs text-orange-600 mt-1">{{ timingStats.total_courses }} courses</p>
                        </div>
                        <div class="w-12 h-12 bg-orange-200 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-orange-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-4 border border-purple-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-purple-800">Efficiency Rate</p>
                            <p class="text-2xl font-bold text-purple-900">{{ timingStats.efficiency_ratio }}x</p>
                            <p class="text-xs text-purple-600 mt-1">vs real-time</p>
                        </div>
                        <div class="w-12 h-12 bg-purple-200 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-purple-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Course Processing History -->
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="font-medium text-gray-900">Recent Course Processing History</h4>
                    <span class="text-sm text-gray-500">Last {{ recentCourses.length }} completed courses</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Course</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Segments</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Processing Time</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg per Segment</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Efficiency</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completed</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="course in recentCourses" :key="course.course_id" class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm">
                                    <div class="font-medium text-gray-900">{{ course.course_title }}</div>
                                    <div class="text-xs text-gray-500">ID: {{ course.course_id }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900">{{ course.segment_count }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <div class="font-medium text-gray-900">{{ formatDuration(course.total_processing_time) }}</div>
                                    <div class="text-xs text-gray-500">{{ course.processing_type }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900">{{ formatDuration(course.avg_segment_time) }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="px-2 py-1 text-xs rounded-full" :class="getEfficiencyBadgeClass(course.efficiency_ratio)">
                                        {{ course.efficiency_ratio }}x
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ formatRelativeTime(course.completed_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';

const props = defineProps({
    courseId: Number,
    autoRefresh: {
        type: Boolean,
        default: true
    },
    refreshInterval: {
        type: Number,
        default: 30000
    }
});

const emit = defineEmits(['course-selected', 'processing-update']);

// Reactive data
const isLoading = ref(false);
const selectedTimeRange = ref('7d');
const activeProcessing = ref([]);
const timingStats = ref({
    avg_course_processing_time: 0,
    fastest_course_time: 0,
    total_processing_time: 0,
    total_courses: 0,
    efficiency_ratio: 0,
    fastest_course_segments: 0
});
const recentCourses = ref([]);
const refreshIntervalId = ref(null);

// Methods
async function refreshData() {
    if (isLoading.value) return;
    
    isLoading.value = true;
    try {
        await Promise.all([
            fetchActiveProcessing(),
            fetchTimingStats(),
            fetchRecentCourses()
        ]);
    } catch (error) {
        console.error('Error refreshing course timing data:', error);
    } finally {
        isLoading.value = false;
    }
}

async function fetchActiveProcessing() {
    // Mock data for now - replace with actual API calls
    activeProcessing.value = [];
}

async function fetchTimingStats() {
    // Mock data for now - replace with actual API calls
    timingStats.value = {
        avg_course_processing_time: 3600,
        fastest_course_time: 1800,
        total_processing_time: 36000,
        total_courses: 10,
        efficiency_ratio: 1.5,
        fastest_course_segments: 25
    };
}

async function fetchRecentCourses() {
    // Mock data for now - replace with actual API calls
    recentCourses.value = [];
}

function formatDuration(seconds) {
    if (!seconds || seconds === 0) return '0s';
    
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    
    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    } else if (minutes > 0) {
        return `${minutes}m ${secs}s`;
    } else {
        return `${secs}s`;
    }
}

function formatElapsedTime(seconds) {
    if (!seconds) return '0s';
    return formatDuration(seconds);
}

function formatRelativeTime(timestamp) {
    if (!timestamp) return 'Unknown';
    
    const now = new Date();
    const time = new Date(timestamp);
    const diffMs = now - time;
    const diffMinutes = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMinutes < 60) {
        return `${diffMinutes}m ago`;
    } else if (diffHours < 24) {
        return `${diffHours}h ago`;
    } else if (diffDays < 7) {
        return `${diffDays}d ago`;
    } else {
        return time.toLocaleDateString();
    }
}

function getEfficiencyBadgeClass(ratio) {
    if (ratio >= 2) return 'bg-green-100 text-green-800';
    if (ratio >= 1) return 'bg-yellow-100 text-yellow-800';
    return 'bg-red-100 text-red-800';
}

function startAutoRefresh() {
    if (props.autoRefresh) {
        refreshIntervalId.value = setInterval(refreshData, props.refreshInterval);
    }
}

function stopAutoRefresh() {
    if (refreshIntervalId.value) {
        clearInterval(refreshIntervalId.value);
        refreshIntervalId.value = null;
    }
}

// Lifecycle
onMounted(() => {
    refreshData();
    startAutoRefresh();
});

onBeforeUnmount(() => {
    stopAutoRefresh();
});
</script> 