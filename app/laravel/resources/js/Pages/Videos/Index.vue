<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import VideoCard from '@/Components/VideoCard.vue';
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    videos: Array,
    filters: Object
});

// Search functionality
const search = ref(props.filters.search || '');

// Watch for changes to the search input with debounce
let timeout;
watch(search, (value) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
        router.get(route('videos.index', { search: value }), {}, {
            preserveState: true,
            replace: true,
            preserveScroll: true
        });
    }, 500); // Debounce by 500ms
});

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

// Clear search
function clearSearch() {
    search.value = '';
    router.get(route('videos.index'), {}, {
        preserveState: true,
        replace: true
    });
}
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
                <div class="mb-6">
                    <div class="flex flex-col md:flex-row gap-4">
                        <div class="relative flex-grow">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                            <input 
                                type="text" 
                                class="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" 
                                placeholder="Search videos..."
                                v-model="search"
                            >
                            <div v-if="search" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <button @click="clearSearch" class="text-gray-400 hover:text-gray-500">
                                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Filter options will go here in the future -->
                    </div>
                </div>

                <!-- Results count -->
                <div v-if="search" class="mb-4 text-sm text-gray-500">
                    Found {{ videos.length }} {{ videos.length === 1 ? 'result' : 'results' }} for "{{ search }}"
                </div>
                
                <!-- Video cards -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div v-if="videos.length === 0" class="text-center py-8">
                            <template v-if="search">
                                <p class="text-gray-500">No videos found matching "{{ search }}"</p>
                                <button @click="clearSearch" class="mt-4 inline-block px-4 py-2 bg-gray-200 text-gray-700 rounded-md">
                                    Clear Search
                                </button>
                            </template>
                            <template v-else>
                                <p class="text-gray-500">No videos uploaded yet.</p>
                                <Link :href="route('videos.create')" class="mt-4 inline-block px-4 py-2 bg-gray-800 text-white rounded-md">
                                    Upload Your First Video
                                </Link>
                            </template>
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