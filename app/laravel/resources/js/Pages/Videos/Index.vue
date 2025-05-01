<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    videos: Array,
});
</script>

<template>
    <Head title="Videos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Videos</h2>
                <Link :href="route('videos.create')" class="px-4 py-2 bg-gray-800 text-white rounded-md">
                    Upload New Video
                </Link>
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
                            <div v-for="video in videos" :key="video.id" class="border rounded-lg overflow-hidden bg-gray-50">
                                <div class="p-4">
                                    <div class="flex items-center justify-between">
                                        <h3 class="text-lg font-medium truncate" :title="video.original_filename">
                                            {{ video.original_filename }}
                                        </h3>
                                        <span class="px-2 py-1 text-xs rounded-full" 
                                            :class="{
                                                'bg-green-100 text-green-800': video.status === 'processed',
                                                'bg-yellow-100 text-yellow-800': video.status === 'processing',
                                                'bg-blue-100 text-blue-800': video.status === 'uploaded',
                                                'bg-red-100 text-red-800': video.status === 'failed',
                                            }">
                                            {{ video.status }}
                                        </span>
                                    </div>
                                    
                                    <div class="text-sm text-gray-500 mt-2">
                                        <p>{{ formatFileSize(video.size_bytes) }}</p>
                                        <p>{{ new Date(video.created_at).toLocaleString() }}</p>
                                    </div>
                                    
                                    <div class="flex mt-4 space-x-2">
                                        <Link :href="route('videos.show', video.id)" class="px-3 py-1 bg-blue-600 text-white rounded-md text-sm">
                                            View
                                        </Link>
                                        <Link :href="route('videos.transcription.request', video.id)" method="post" as="button" class="px-3 py-1 bg-green-600 text-white rounded-md text-sm">
                                            Transcribe
                                        </Link>
                                        <Link :href="route('videos.destroy', video.id)" method="delete" as="button" class="px-3 py-1 bg-red-600 text-white rounded-md text-sm" 
                                            onclick="return confirm('Are you sure you want to delete this video?')">
                                            Delete
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script>
export default {
    methods: {
        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    }
}
</script> 