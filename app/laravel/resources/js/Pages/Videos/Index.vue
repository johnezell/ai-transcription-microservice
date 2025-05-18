<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import VideoCard from '@/Components/VideoCard.vue';
import { router } from '@inertiajs/vue3';

defineProps({
    videos: Array,
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
</script>

<template>
    <Head title="Videos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
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
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div v-if="videos.length === 0" class="text-center py-8">
                            <p class="text-gray-500">No videos uploaded yet.</p>
                            <Link :href="route('videos.create')" class="mt-4 inline-block px-4 py-2 bg-gray-800 text-white rounded-md">
                                Upload Your First Video
                            </Link>
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