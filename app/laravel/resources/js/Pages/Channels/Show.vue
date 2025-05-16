<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    channel: Object
});

const importForm = useForm({});

function importSegment(segmentId) {
    importForm.post(route('channels.import.segment', segmentId));
}
</script>

<template>
    <Head :title="channel.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ channel.name }}
                </h2>
                <Link :href="route('channels.index')" class="text-sm text-blue-600 dark:text-blue-400">
                    &larr; Back to Channels
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="flex flex-col gap-6">
                            <div>
                                <h1 class="text-2xl font-bold mb-4 text-gray-900 dark:text-white">{{ channel.name }}</h1>
                                
                                <div class="flex flex-wrap gap-4 mb-4">
                                    <div v-if="channel.course" class="bg-blue-100 dark:bg-blue-900 px-3 py-1 rounded-full text-blue-800 dark:text-blue-200 text-sm">
                                        Course: {{ channel.course.title }}
                                    </div>
                                    <div class="bg-green-100 dark:bg-green-900 px-3 py-1 rounded-full text-green-800 dark:text-green-200 text-sm">
                                        Status: {{ channel.status }}
                                    </div>
                                </div>
                                
                                <p class="text-gray-700 dark:text-gray-300 mb-4">
                                    {{ channel.description }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold mb-4 text-gray-900 dark:text-white">Segments</h2>
                        
                        <div v-if="!channel.segments || channel.segments.length === 0" class="text-gray-600 dark:text-gray-400">
                            No segments available for this channel.
                        </div>
                        
                        <div v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                            <div v-for="segment in channel.segments" :key="segment.id" class="py-4 flex flex-col md:flex-row justify-between items-start md:items-center">
                                <div class="flex-grow mb-3 md:mb-0">
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                                        {{ segment.sequence || '?' }}. {{ segment.title }}
                                    </h3>
                                    <p class="text-gray-600 dark:text-gray-400 text-sm mt-1 line-clamp-2">
                                        {{ segment.description }}
                                    </p>
                                    <div class="flex gap-2 mt-2">
                                        <span class="text-sm text-gray-500 dark:text-gray-400" v-if="segment.duration">
                                            {{ segment.duration }} seconds
                                        </span>
                                        <span class="text-sm text-blue-600 dark:text-blue-400 break-all" v-if="segment.video">
                                            {{ segment.video }}
                                        </span>
                                    </div>
                                </div>
                                
                                <button 
                                    type="button" 
                                    @click="importSegment(segment.id)" 
                                    :disabled="importForm.processing"
                                    class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-500 active:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150"
                                >
                                    Import for Transcription
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 