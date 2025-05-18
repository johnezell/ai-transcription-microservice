<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import VideoCard from '@/Components/VideoCard.vue';
import { router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    videos: Array,
    courses: Array,
    filters: Object,
    statusOptions: Array
});

// Filter state
const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const courseId = ref(props.filters.course_id || '');

// Watch for changes with debounce
let timeout;
watch(search, (value) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
        updateFilters();
    }, 500); // Debounce by 500ms
});

// Watch for status and course changes
watch([status, courseId], () => {
    updateFilters();
});

// Update filters
function updateFilters() {
    router.get(route('videos.index'), {
        search: search.value,
        status: status.value,
        course_id: courseId.value
    }, {
        preserveState: true,
        replace: true,
        preserveScroll: true
    });
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Handle view video
function handleView(video) {
    router.visit(route('videos.show', video.id));
}

// Handle delete video
function handleDelete(video) {
    router.delete(route('videos.destroy', video.id));
}

// Clear all filters
function clearFilters() {
    search.value = '';
    status.value = '';
    courseId.value = '';
    updateFilters();
}

// Get active filter count
const activeFilterCount = computed(() => {
    let count = 0;
    if (search.value) count++;
    if (status.value) count++;
    if (courseId.value) count++;
    return count;
});
</script>

<template>
    <Head title="Videos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between flex-col sm:flex-row gap-4">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Videos</h2>
                <div class="flex space-x-2">
                    <Link :href="route('courses.index')" class="px-4 py-2 bg-purple-600 text-white rounded-md">
                        Courses
                    </Link>
                    <Link :href="route('videos.create')" class="px-4 py-2 bg-gray-800 text-white rounded-md">
                        Upload New Video
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Search and filtering -->
                <div class="mb-6 bg-white p-4 rounded-lg shadow-sm">
                    <h3 class="text-lg font-medium text-gray-700 mb-3">Filter Videos</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Search input -->
                        <div class="relative">
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                            <div class="relative rounded-md shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                                <input 
                                    id="search"
                                    type="text" 
                                    class="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" 
                                    placeholder="Search videos..."
                                    v-model="search"
                                >
                                <div v-if="search" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <button @click="search = ''" class="text-gray-400 hover:text-gray-500">
                                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Status filter -->
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select
                                id="status"
                                v-model="status"
                                class="block w-full py-2 pl-3 pr-10 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500"
                            >
                                <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        
                        <!-- Course filter -->
                        <div>
                            <label for="course" class="block text-sm font-medium text-gray-700 mb-1">Course</label>
                            <select
                                id="course"
                                v-model="courseId"
                                class="block w-full py-2 pl-3 pr-10 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500"
                            >
                                <option value="">All Courses</option>
                                <option v-for="course in courses" :key="course.id" :value="course.id">
                                    {{ course.name }}
                                </option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Filter actions -->
                    <div class="mt-4 flex justify-end" v-if="activeFilterCount > 0">
                        <button @click="clearFilters" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Clear Filters ({{ activeFilterCount }})
                        </button>
                    </div>
                </div>

                <!-- Results count and filter summary -->
                <div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                    <span>{{ videos.length }} {{ videos.length === 1 ? 'video' : 'videos' }}</span>
                    
                    <template v-if="search || status || courseId">
                        <span class="mx-1">matching:</span>
                        
                        <div v-if="search" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            Search: "{{ search }}"
                        </div>
                        
                        <div v-if="status" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                            Status: {{ statusOptions.find(option => option.value === status)?.label }}
                        </div>
                        
                        <div v-if="courseId" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                            Course: {{ courses.find(course => course.id == courseId)?.name }}
                        </div>
                    </template>
                </div>
                
                <!-- Video cards -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div v-if="videos.length === 0" class="text-center py-8">
                            <p class="text-gray-500">No videos found matching your filters.</p>
                            <button @click="clearFilters" class="mt-4 inline-block px-4 py-2 bg-gray-200 text-gray-700 rounded-md">
                                Clear All Filters
                            </button>
                        </div>
                        
                        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <VideoCard 
                                v-for="video in videos" 
                                :key="video.id" 
                                :video="video"
                                @view="handleView"
                                @delete="handleDelete"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 