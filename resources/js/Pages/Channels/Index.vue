<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { channelsApi } from '@/api';

// State management
const courses = ref([]);
const loading = ref(true);
const error = ref(null);

// Load courses when component mounts
onMounted(async () => {
  try {
    loading.value = true;
    const response = await channelsApi.getCourses();
    courses.value = response.data;
  } catch (err) {
    error.value = err.message || 'Unable to load Channel courses';
    console.error(err);
  } finally {
    loading.value = false;
  }
});
</script>

<template>
    <Head title="Channel Courses" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Channel Courses
                </h2>
                <Link 
                    :href="route('channels.selection')" 
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                    Selection Mode
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                    
                    <!-- Loading State -->
                    <div v-if="loading" class="flex justify-center items-center py-8">
                        <svg class="animate-spin h-8 w-8 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                    
                    <!-- Error State -->
                    <div v-else-if="error" class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                        <span class="block sm:inline">{{ error }}</span>
                    </div>
                    
                    <!-- Empty State -->
                    <div v-else-if="courses && courses.length === 0" class="mb-4">
                        <p class="text-gray-600 dark:text-gray-400">No courses found.</p>
                    </div>
                    
                    <!-- Course Grid -->
                    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div v-for="course in courses" :key="course.id" class="flex flex-col bg-gray-50 dark:bg-gray-700 rounded-lg overflow-hidden shadow-md hover:shadow-lg transition-shadow duration-300">
                            <div class="h-48 bg-gray-200 dark:bg-gray-600 relative">
                                <img v-if="course.thumbnail_url" :src="course.thumbnail_url" alt="Course thumbnail" class="w-full h-full object-cover" />
                                <div v-else class="w-full h-full flex items-center justify-center bg-gray-300 dark:bg-gray-600">
                                    <span class="text-gray-500 dark:text-gray-400">No image</span>
                                </div>
                            </div>
                            
                            <div class="p-4 flex-grow">
                                <h3 class="text-lg font-semibold mb-2 text-gray-800 dark:text-white">{{ course.title }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-300 mb-2">
                                    Instructor: {{ course.instructor }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4 line-clamp-3">
                                    {{ course.description }}
                                </p>
                                <div class="flex justify-between items-center text-sm text-gray-500 dark:text-gray-400">
                                    <span>{{ course.segments ? course.segments.length : 0 }} segments</span>
                                    <span>{{ course.duration_minutes }} minutes</span>
                                </div>
                            </div>
                            
                            <div class="px-4 py-3 bg-gray-100 dark:bg-gray-600">
                                <Link :href="route('channels.show', course.id)" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 active:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                    View Course
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template> 