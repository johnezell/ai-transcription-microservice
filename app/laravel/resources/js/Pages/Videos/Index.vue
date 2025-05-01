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
                                        
                                        <!-- Features badges -->
                                        <div class="flex flex-wrap gap-2 mt-2">
                                            <span v-if="video.transcript_path" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-50 text-blue-700">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                                                </svg>
                                                Transcript
                                            </span>
                                            <span v-if="video.has_music_terms" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-indigo-50 text-indigo-700">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                                </svg>
                                                Music Terms ({{ video.music_terms_count || 0 }})
                                            </span>
                                        </div>
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