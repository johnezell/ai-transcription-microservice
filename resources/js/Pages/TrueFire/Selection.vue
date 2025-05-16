<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    courses: Array,
    error: String
});

const selectedLessons = ref({});
const expandedCourses = ref({});

function toggleCourse(courseId) {
    expandedCourses.value[courseId] = !expandedCourses.value[courseId];
}

function toggleLesson(courseId, lessonId) {
    if (!selectedLessons.value[courseId]) {
        selectedLessons.value[courseId] = {};
    }
    selectedLessons.value[courseId][lessonId] = !selectedLessons.value[courseId][lessonId];
}

function isLessonSelected(courseId, lessonId) {
    return selectedLessons.value[courseId] && selectedLessons.value[courseId][lessonId];
}

function getSelectedCount() {
    let count = 0;
    for (const courseId in selectedLessons.value) {
        for (const lessonId in selectedLessons.value[courseId]) {
            if (selectedLessons.value[courseId][lessonId]) {
                count++;
            }
        }
    }
    return count;
}

const importForm = useForm({
    lessons: []
});

function importSelectedLessons() {
    const lessonIds = [];
    for (const courseId in selectedLessons.value) {
        for (const lessonId in selectedLessons.value[courseId]) {
            if (selectedLessons.value[courseId][lessonId]) {
                lessonIds.push(parseInt(lessonId));
            }
        }
    }
    
    importForm.lessons = lessonIds;
    importForm.post(route('truefire.import.lessons.bulk'));
}
</script>

<template>
    <Head title="Select TrueFire Lessons" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Select TrueFire Lessons to Import
                </h2>
                <div v-if="getSelectedCount() > 0" class="flex items-center gap-4">
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        {{ getSelectedCount() }} lesson{{ getSelectedCount() > 1 ? 's' : '' }} selected
                    </span>
                    <button 
                        @click="importSelectedLessons" 
                        :disabled="importForm.processing"
                        class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-500 active:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150"
                    >
                        Import Selected
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                    
                    <div v-if="error" class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                        <span class="block sm:inline">{{ error }}</span>
                    </div>
                    
                    <div v-if="courses && courses.length === 0" class="mb-4">
                        <p class="text-gray-600 dark:text-gray-400">No courses found.</p>
                    </div>
                    
                    <div v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                        <div v-for="course in courses" :key="course.id" class="py-6">
                            <div @click="toggleCourse(course.id)" class="flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 bg-gray-200 dark:bg-gray-700 rounded overflow-hidden flex-shrink-0">
                                        <img v-if="course.thumbnail_url" :src="course.thumbnail_url" alt="Course thumbnail" class="w-full h-full object-cover" />
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">{{ course.title }}</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ course.lessons ? course.lessons.length : 0 }} lessons • {{ course.instructor }}</p>
                                    </div>
                                </div>
                                <div class="text-gray-600 dark:text-gray-400">
                                    <svg v-if="!expandedCourses[course.id]" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                            
                            <div v-if="expandedCourses[course.id] && course.lessons && course.lessons.length > 0" class="mt-4 pl-4 divide-y divide-gray-100 dark:divide-gray-700">
                                <div v-for="lesson in course.lessons" :key="lesson.id" class="py-3 flex items-center justify-between">
                                    <div class="flex items-center">
                                        <input 
                                            type="checkbox" 
                                            :id="`lesson-${lesson.id}`" 
                                            :checked="isLessonSelected(course.id, lesson.id)"
                                            @change="toggleLesson(course.id, lesson.id)"
                                            class="mr-3 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        >
                                        <label :for="`lesson-${lesson.id}`" class="block">
                                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ lesson.sequence_number }}. {{ lesson.title }}</span>
                                            <span class="flex mt-1">
                                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ lesson.duration_minutes }} minutes</span>
                                                <span v-if="lesson.is_free_preview" class="ml-2 text-xs text-green-600 dark:text-green-400">Free Preview</span>
                                            </span>
                                        </label>
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