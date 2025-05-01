<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    video: Object,
});
</script>

<template>
    <Head :title="video.original_filename" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ video.original_filename }}</h2>
                <Link :href="route('videos.index')" class="px-4 py-2 bg-gray-100 rounded-md text-gray-700">
                    &larr; Back to Videos
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex flex-col md:flex-row md:space-x-6">
                            <div class="md:w-2/3">
                                <div class="bg-black rounded-lg overflow-hidden">
                                    <video 
                                        :src="video.url" 
                                        controls
                                        class="w-full max-h-[500px]"
                                    ></video>
                                </div>
                            </div>
                            
                            <div class="md:w-1/3 mt-6 md:mt-0">
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <h3 class="text-lg font-medium mb-4">Video Information</h3>
                                    
                                    <div class="space-y-3">
                                        <div>
                                            <span class="text-gray-500 text-sm">Status:</span>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full" 
                                                :class="{
                                                    'bg-green-100 text-green-800': video.status === 'processed',
                                                    'bg-yellow-100 text-yellow-800': video.status === 'processing',
                                                    'bg-blue-100 text-blue-800': video.status === 'uploaded',
                                                    'bg-red-100 text-red-800': video.status === 'failed',
                                                }">
                                                {{ video.status }}
                                            </span>
                                        </div>
                                        
                                        <div>
                                            <span class="text-gray-500 text-sm">File size:</span>
                                            <span class="ml-2">{{ formatFileSize(video.size_bytes) }}</span>
                                        </div>
                                        
                                        <div>
                                            <span class="text-gray-500 text-sm">Type:</span>
                                            <span class="ml-2">{{ video.mime_type }}</span>
                                        </div>
                                        
                                        <div>
                                            <span class="text-gray-500 text-sm">Uploaded:</span>
                                            <span class="ml-2">{{ new Date(video.created_at).toLocaleString() }}</span>
                                        </div>
                                        
                                        <div>
                                            <span class="text-gray-500 text-sm">S3 Key:</span>
                                            <span class="ml-2 text-xs font-mono bg-gray-100 p-1 rounded">{{ video.s3_key }}</span>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-6 space-y-3">
                                        <Link
                                            :href="route('videos.transcription.request', video.id)"
                                            method="post" 
                                            as="button"
                                            class="w-full inline-flex justify-center px-4 py-2 bg-green-600 text-white rounded-md"
                                        >
                                            Request Transcription
                                        </Link>
                                        
                                        <Link
                                            :href="route('videos.destroy', video.id)"
                                            method="delete"
                                            as="button"
                                            class="w-full inline-flex justify-center px-4 py-2 bg-red-600 text-white rounded-md"
                                            onclick="return confirm('Are you sure you want to delete this video?')"
                                        >
                                            Delete Video
                                        </Link>
                                    </div>
                                </div>
                                
                                <div class="bg-gray-50 rounded-lg p-4 mt-6" v-if="video.metadata">
                                    <h3 class="text-lg font-medium mb-2">Metadata</h3>
                                    <pre class="text-xs bg-gray-100 p-2 rounded overflow-auto max-h-40">{{ JSON.stringify(video.metadata, null, 2) }}</pre>
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