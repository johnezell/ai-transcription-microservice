<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    channels: Array,
    error: String
});
</script>

<template>
    <Head title="Channels" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Channels
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                    
                    <div v-if="error" class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                        <span class="block sm:inline">{{ error }}</span>
                    </div>
                    
                    <div v-if="channels && channels.length === 0" class="mb-4">
                        <p class="text-gray-600 dark:text-gray-400">No channels found.</p>
                    </div>
                    
                    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div v-for="channel in channels" :key="channel.id" class="flex flex-col bg-gray-50 dark:bg-gray-700 rounded-lg overflow-hidden shadow-md hover:shadow-lg transition-shadow duration-300">
                            <div class="p-4 flex-grow">
                                <h3 class="text-lg font-semibold mb-2 text-gray-800 dark:text-white">{{ channel.name }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-300 mb-2" v-if="channel.course">
                                    Course: {{ channel.course.title }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4 line-clamp-3">
                                    {{ channel.description }}
                                </p>
                                <div class="flex justify-between items-center text-sm text-gray-500 dark:text-gray-400">
                                    <span>{{ channel.segments ? channel.segments.length : 0 }} segments</span>
                                    <span>Status: {{ channel.status }}</span>
                                </div>
                            </div>
                            
                            <div class="px-4 py-3 bg-gray-100 dark:bg-gray-600">
                                <Link :href="route('channels.show', channel.id)" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 active:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                    View Channel
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 