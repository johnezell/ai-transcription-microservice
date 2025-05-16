<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    course: Object
});

const importForm = useForm({});

function importLesson(lessonId) {
    importForm.post(route('truefire.import.lesson', lessonId));
}
</script>

<template>
    <Head :title="course.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ course.title }}
                </h2>
                <Link :href="route('truefire.index')" class="text-sm text-blue-600 dark:text-blue-400">
                    &larr; Back to Courses
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="flex flex-col md:flex-row gap-6">
                            <div class="md:w-1/3">
                                <div class="bg-gray-200 dark:bg-gray-700 rounded-lg overflow-hidden h-48 md:h-auto">
                                    <img v-if="course.thumbnail_url" :src="course.thumbnail_url" alt="Course thumbnail" class="w-full h-full object-cover" />
                                    <div v-else class="w-full h-48 flex items-center justify-center">
                                        <span class="text-gray-500 dark:text-gray-400">No image</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="md:w-2/3">
                                <h1 class="text-2xl font-bold mb-4 text-gray-900 dark:text-white">{{ course.title }}</h1>
                                
                                <div class="flex flex-wrap gap-4 mb-4">
                                    <div class="bg-blue-100 dark:bg-blue-900 px-3 py-1 rounded-full text-blue-800 dark:text-blue-200 text-sm">
                                        Instructor: {{ course.instructor }}
                                    </div>
                                    <div class="bg-green-100 dark:bg-green-900 px-3 py-1 rounded-full text-green-800 dark:text-green-200 text-sm">
                                        {{ course.duration_minutes }} minutes
                                    </div>
                                    <div class="bg-purple-100 dark:bg-purple-900 px-3 py-1 rounded-full text-purple-800 dark:text-purple-200 text-sm">
                                        {{ course.difficulty_level }}
                                    </div>
                                    <div class="bg-yellow-100 dark:bg-yellow-900 px-3 py-1 rounded-full text-yellow-800 dark:text-yellow-200 text-sm">
                                        {{ course.category }}
                                    </div>
                                </div>
                                
                                <p class="text-gray-700 dark:text-gray-300 mb-4">
                                    {{ course.description }}
                                </p>
                                
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    <p>Published: {{ course.published_at }}</p>
                                    <p>{{ course.lessons ? course.lessons.length : 0 }} lessons</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold mb-4 text-gray-900 dark:text-white">Lessons</h2>
                        
                        <div v-if="!course.lessons || course.lessons.length === 0" class="text-gray-600 dark:text-gray-400">
                            No lessons available for this course.
                        </div>
                        
                        <div v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                            <div v-for="lesson in course.lessons" :key="lesson.id" class="py-4 flex flex-col md:flex-row justify-between items-start md:items-center">
                                <div class="flex-grow mb-3 md:mb-0">
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                                        {{ lesson.sequence_number }}. {{ lesson.title }}
                                    </h3>
                                    <p class="text-gray-600 dark:text-gray-400 text-sm mt-1 line-clamp-2">
                                        {{ lesson.description }}
                                    </p>
                                    <div class="flex gap-2 mt-2">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ lesson.duration_minutes }} minutes
                                        </span>
                                        <span v-if="lesson.is_free_preview" class="text-sm text-green-600 dark:text-green-400">
                                            Free Preview
                                        </span>
                                    </div>
                                </div>
                                
                                <button 
                                    type="button" 
                                    @click="importLesson(lesson.id)" 
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