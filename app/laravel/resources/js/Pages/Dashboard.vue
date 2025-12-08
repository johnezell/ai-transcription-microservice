<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    stats: Object,
    jobQueue: Object,
    courses: Array,
    filters: Object,
    currentFilters: Object,
    pagination: Object,
    dataSource: String, // 'truefire' or 'mock'
});

// Local state
const selectedCourses = ref(new Set());
const isSelectAll = ref(false);
const isProcessing = ref(false);
const showFilters = ref(false);

// Filter state
const searchQuery = ref(props.currentFilters?.search || '');
const selectedGenre = ref(props.currentFilters?.genre || '');
const selectedLevel = ref(props.currentFilters?.level || '');
const selectedStatus = ref(props.currentFilters?.status || '');
const selectedInstructor = ref(props.currentFilters?.instructor || '');

// Computed
const selectedCount = computed(() => selectedCourses.value.size);
const selectedVideoCount = computed(() => {
    return props.courses
        .filter(c => selectedCourses.value.has(c.id))
        .reduce((sum, c) => sum + c.pending_count, 0);
});

// Methods
const toggleSelectAll = () => {
    if (isSelectAll.value) {
        selectedCourses.value.clear();
    } else {
        props.courses.forEach(c => selectedCourses.value.add(c.id));
    }
    isSelectAll.value = !isSelectAll.value;
};

const toggleCourseSelection = (courseId) => {
    if (selectedCourses.value.has(courseId)) {
        selectedCourses.value.delete(courseId);
    } else {
        selectedCourses.value.add(courseId);
    }
};

const applyFilters = () => {
    router.get(route('dashboard'), {
        search: searchQuery.value || undefined,
        genre: selectedGenre.value || undefined,
        level: selectedLevel.value || undefined,
        status: selectedStatus.value || undefined,
        instructor: selectedInstructor.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

const clearFilters = () => {
    searchQuery.value = '';
    selectedGenre.value = '';
    selectedLevel.value = '';
    selectedStatus.value = '';
    selectedInstructor.value = '';
    applyFilters();
};

const startBulkTranscription = async () => {
    if (selectedCourses.value.size === 0) return;
    
    isProcessing.value = true;
    
    try {
        const response = await fetch(route('dashboard.bulk-transcribe'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            body: JSON.stringify({
                course_ids: Array.from(selectedCourses.value),
            }),
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message);
            selectedCourses.value.clear();
            isSelectAll.value = false;
            router.reload();
        }
    } catch (error) {
        console.error('Error starting transcription:', error);
        alert('Failed to start transcription. Please try again.');
    } finally {
        isProcessing.value = false;
    }
};

const goToPage = (page) => {
    router.get(route('dashboard'), {
        ...props.currentFilters,
        page,
    }, {
        preserveState: true,
    });
};

const getStatusColor = (status) => {
    const colors = {
        completed: 'bg-emerald-100 text-emerald-800 border-emerald-200',
        processing: 'bg-amber-100 text-amber-800 border-amber-200',
        partial: 'bg-sky-100 text-sky-800 border-sky-200',
        pending: 'bg-slate-100 text-slate-600 border-slate-200',
    };
    return colors[status] || colors.pending;
};

const getProgressBarColor = (progress) => {
    if (progress === 100) return 'bg-emerald-500';
    if (progress >= 75) return 'bg-sky-500';
    if (progress >= 50) return 'bg-amber-500';
    if (progress > 0) return 'bg-orange-500';
    return 'bg-slate-300';
};

// Debounced search
let searchTimeout;
watch(searchQuery, (newValue) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 500);
});
</script>

<template>
    <Head title="Transcription Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900">Transcription Dashboard</h2>
                    <div class="flex items-center gap-2 mt-1">
                        <p class="text-sm text-slate-500">TrueFire Course Transcription Management</p>
                        <span 
                            v-if="dataSource"
                            :class="dataSource === 'truefire' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                            class="px-2 py-0.5 text-xs font-medium rounded-full"
                        >
                            {{ dataSource === 'truefire' ? '🔗 Live Database' : '📋 Mock Data' }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        @click="router.reload()"
                        class="px-4 py-2 text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors"
                    >
                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">
                
                <!-- Stats Overview -->
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
                    <!-- Total Courses -->
                    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-indigo-100 rounded-lg">
                                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-slate-900">{{ stats.total_courses.toLocaleString() }}</p>
                                <p class="text-xs text-slate-500 uppercase tracking-wide">Courses</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Videos -->
                    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-violet-100 rounded-lg">
                                <svg class="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-slate-900">{{ stats.total_videos.toLocaleString() }}</p>
                                <p class="text-xs text-slate-500 uppercase tracking-wide">Videos</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Transcribed -->
                    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-emerald-100 rounded-lg">
                                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-emerald-600">{{ stats.transcribed_videos.toLocaleString() }}</p>
                                <p class="text-xs text-slate-500 uppercase tracking-wide">Transcribed</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Processing -->
                    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-amber-100 rounded-lg">
                                <svg class="w-5 h-5 text-amber-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-amber-600">{{ stats.processing_videos.toLocaleString() }}</p>
                                <p class="text-xs text-slate-500 uppercase tracking-wide">Processing</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pending -->
                    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-slate-100 rounded-lg">
                                <svg class="w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-slate-600">{{ stats.pending_videos.toLocaleString() }}</p>
                                <p class="text-xs text-slate-500 uppercase tracking-wide">Pending</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Progress -->
                    <div class="bg-gradient-to-br from-indigo-500 to-violet-600 rounded-xl shadow-sm p-5 text-white">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-white/20 rounded-lg">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold">{{ stats.completion_percentage }}%</p>
                                <p class="text-xs text-white/80 uppercase tracking-wide">Complete</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Job Queue Status -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-8">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        Job Queue Status
                    </h3>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-6">
                        <div class="text-center p-4 bg-slate-50 rounded-lg">
                            <p class="text-3xl font-bold text-amber-600">{{ jobQueue.active_jobs }}</p>
                            <p class="text-sm text-slate-500">Active Jobs</p>
                        </div>
                        <div class="text-center p-4 bg-slate-50 rounded-lg">
                            <p class="text-3xl font-bold text-slate-700">{{ jobQueue.queued_jobs }}</p>
                            <p class="text-sm text-slate-500">In Queue</p>
                        </div>
                        <div class="text-center p-4 bg-slate-50 rounded-lg">
                            <p class="text-3xl font-bold text-emerald-600">{{ jobQueue.completed_today }}</p>
                            <p class="text-sm text-slate-500">Today</p>
                        </div>
                        <div class="text-center p-4 bg-slate-50 rounded-lg">
                            <p class="text-3xl font-bold text-red-500">{{ jobQueue.failed_today }}</p>
                            <p class="text-sm text-slate-500">Failed</p>
                        </div>
                        <div class="text-center p-4 bg-slate-50 rounded-lg">
                            <p class="text-3xl font-bold text-indigo-600">{{ jobQueue.avg_processing_time }}s</p>
                            <p class="text-sm text-slate-500">Avg Time</p>
                        </div>
                    </div>
                </div>
                
                <!-- Course Browser -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
                    <!-- Header & Filters -->
                    <div class="p-6 border-b border-slate-100">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">Course Browser</h3>
                                <p class="text-sm text-slate-500">
                                    Showing {{ courses.length }} of {{ pagination.total.toLocaleString() }} courses
                                </p>
                            </div>
                            
                            <!-- Search & Actions -->
                            <div class="flex flex-col sm:flex-row gap-3">
                                <div class="relative">
                                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search courses..."
                                        class="pl-10 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 w-full sm:w-64"
                                    />
                                </div>
                                
                                <button
                                    @click="showFilters = !showFilters"
                                    class="px-4 py-2 text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 transition-colors text-sm font-medium inline-flex items-center gap-2"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                    </svg>
                                    Filters
                                </button>
                                
                                <button
                                    v-if="selectedCount > 0"
                                    @click="startBulkTranscription"
                                    :disabled="isProcessing"
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors text-sm font-medium inline-flex items-center gap-2 disabled:opacity-50"
                                >
                                    <svg v-if="!isProcessing" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <svg v-else class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Transcribe {{ selectedCount }} courses ({{ selectedVideoCount }} videos)
                                </button>
                            </div>
                        </div>
                        
                        <!-- Filter Panel -->
                        <div v-if="showFilters" class="mt-4 pt-4 border-t border-slate-100">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Genre</label>
                                    <select v-model="selectedGenre" @change="applyFilters" class="w-full border border-slate-200 rounded-lg text-sm py-2 px-3">
                                        <option value="">All Genres</option>
                                        <option v-for="genre in filters.genres" :key="genre" :value="genre">{{ genre }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Level</label>
                                    <select v-model="selectedLevel" @change="applyFilters" class="w-full border border-slate-200 rounded-lg text-sm py-2 px-3">
                                        <option value="">All Levels</option>
                                        <option v-for="level in filters.levels" :key="level" :value="level">{{ level }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Status</label>
                                    <select v-model="selectedStatus" @change="applyFilters" class="w-full border border-slate-200 rounded-lg text-sm py-2 px-3">
                                        <option value="">All Statuses</option>
                                        <option v-for="status in filters.statuses" :key="status" :value="status">{{ status.charAt(0).toUpperCase() + status.slice(1) }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Instructor</label>
                                    <select v-model="selectedInstructor" @change="applyFilters" class="w-full border border-slate-200 rounded-lg text-sm py-2 px-3">
                                        <option value="">All Instructors</option>
                                        <option v-for="instructor in filters.instructors" :key="instructor" :value="instructor">{{ instructor }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button @click="clearFilters" class="text-sm text-indigo-600 hover:text-indigo-800">
                                    Clear all filters
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left">
                                        <input
                                            type="checkbox"
                                            :checked="isSelectAll"
                                            @change="toggleSelectAll"
                                            class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Course</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Instructor</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Genre / Level</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Videos</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Progress</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr 
                                    v-for="course in courses" 
                                    :key="course.id"
                                    class="hover:bg-slate-50 transition-colors"
                                    :class="{ 'bg-indigo-50/50': selectedCourses.has(course.id) }"
                                >
                                    <td class="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            :checked="selectedCourses.has(course.id)"
                                            @change="toggleCourseSelection(course.id)"
                                            class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-slate-900">{{ course.name }}</div>
                                        <div class="text-xs text-slate-500">{{ course.truefire_id }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-slate-600">{{ course.instructor }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-700 mr-1">
                                            {{ course.genre }}
                                        </span>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                                            {{ course.level }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="text-sm font-medium text-slate-900">{{ course.video_count }}</div>
                                        <div class="text-xs text-slate-500">
                                            {{ course.transcribed_count }} done
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="w-32">
                                            <div class="flex items-center gap-2">
                                                <div class="flex-1 bg-slate-200 rounded-full h-2 overflow-hidden">
                                                    <div 
                                                        :class="getProgressBarColor(course.progress)"
                                                        class="h-full transition-all duration-300"
                                                        :style="{ width: course.progress + '%' }"
                                                    ></div>
                                                </div>
                                                <span class="text-xs font-medium text-slate-600 w-9 text-right">{{ course.progress }}%</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span 
                                            :class="getStatusColor(course.status)"
                                            class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border"
                                        >
                                            {{ course.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between">
                        <div class="text-sm text-slate-500">
                            Page {{ pagination.current_page }} of {{ pagination.last_page }}
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="goToPage(pagination.current_page - 1)"
                                :disabled="pagination.current_page === 1"
                                class="px-3 py-1.5 text-sm border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                Previous
                            </button>
                            <button
                                @click="goToPage(pagination.current_page + 1)"
                                :disabled="pagination.current_page === pagination.last_page"
                                class="px-3 py-1.5 text-sm border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
