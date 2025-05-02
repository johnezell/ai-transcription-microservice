<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    courses: Array,
});
</script>

<template>
    <Head title="Courses" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Courses</h2>
                <Link :href="route('courses.create')" class="px-4 py-2 bg-gray-800 text-white rounded-md">
                    Create New Course
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div v-if="courses.length === 0" class="text-center py-8">
                            <p class="text-gray-500">No courses created yet.</p>
                            <Link :href="route('courses.create')" class="mt-4 inline-block px-4 py-2 bg-gray-800 text-white rounded-md">
                                Create Your First Course
                            </Link>
                        </div>
                        
                        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div v-for="course in courses" :key="course.id" class="border rounded-lg overflow-hidden bg-gray-50">
                                <div class="p-4">
                                    <div class="flex flex-col">
                                        <h3 class="text-lg font-medium mb-2">{{ course.name }}</h3>
                                        <p v-if="course.subject_area" class="text-sm text-gray-600 mb-2">
                                            <span class="font-medium">Subject:</span> {{ course.subject_area }}
                                        </p>
                                        <p class="text-sm text-gray-500 mb-2 line-clamp-2" v-if="course.description">
                                            {{ course.description }}
                                        </p>
                                        <div class="text-sm text-gray-600 mt-2">
                                            <p><span class="font-medium">Videos:</span> {{ course.videos_count || 0 }}</p>
                                            <p class="mt-1">
                                                <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full">
                                                    Created {{ new Date(course.created_at).toLocaleDateString() }}
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex mt-4 space-x-2">
                                        <Link :href="route('courses.show', course.id)" class="px-3 py-1 bg-blue-600 text-white rounded-md text-sm">
                                            View
                                        </Link>
                                        <Link :href="route('courses.edit', course.id)" class="px-3 py-1 bg-gray-600 text-white rounded-md text-sm">
                                            Edit
                                        </Link>
                                        <Link :href="route('courses.destroy', course.id)" method="delete" as="button" class="px-3 py-1 bg-red-600 text-white rounded-md text-sm" 
                                            onclick="return confirm('Are you sure you want to delete this course? Videos will be preserved.')">
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