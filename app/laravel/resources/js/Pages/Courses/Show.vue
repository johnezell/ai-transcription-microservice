<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref } from 'vue';
import AddVideoModal from './Components/AddVideoModal.vue';

const props = defineProps({
    course: Object,
    unassignedVideos: Array,
});

// Video ordering
const videos = ref(props.course.videos || []);

// Modal state
const showAddVideoModal = ref(false);

// Sort videos by lesson number
const sortedVideos = ref(() => {
    return [...videos.value].sort((a, b) => a.lesson_number - b.lesson_number);
});

// Reorder videos (changing lesson numbers)
const reorderVideos = () => {
    const form = useForm({
        videos: sortedVideos.value.map((video, index) => ({
            id: video.id,
            lesson_number: index + 1,
        })),
    });
    
    form.put(route('courses.videos.order', props.course.id), {
        preserveScroll: true,
        onSuccess: () => {
            // Update the local state
            sortedVideos.value.forEach((video, index) => {
                video.lesson_number = index + 1;
            });
        },
    });
};

// Remove video from course
const removeVideo = (videoId) => {
    const form = useForm({
        video_id: videoId,
    });
    
    form.delete(route('courses.videos.remove', props.course.id), {
        preserveScroll: true,
    });
};

// Format date
const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString();
};
</script>

<template>
    <Head :title="course.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ course.name }}</h2>
                <div class="flex space-x-2">
                    <Link :href="route('courses.edit', course.id)" class="px-4 py-2 bg-gray-600 text-white rounded-md">
                        Edit Course
                    </Link>
                    <Link :href="route('courses.analysis', course.id)" class="px-4 py-2 bg-purple-600 text-white rounded-md">
                        Analysis
                    </Link>
                    <Link :href="route('courses.index')" class="px-4 py-2 bg-gray-800 text-white rounded-md">
                        Back to Courses
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Course Details Card -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6 text-gray-900">
                        <div class="flex flex-col md:flex-row md:justify-between">
                            <div class="md:w-2/3">
                                <h3 class="text-lg font-medium mb-4">Course Details</h3>
                                
                                <div class="space-y-2">
                                    <p v-if="course.subject_area" class="text-sm text-gray-600">
                                        <span class="font-medium">Subject Area:</span> {{ course.subject_area }}
                                    </p>
                                    <p v-if="course.description" class="text-sm text-gray-700">
                                        {{ course.description }}
                                    </p>
                                </div>
                            </div>
                            
                            <div class="md:w-1/3 mt-4 md:mt-0">
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <h4 class="text-sm font-medium text-gray-700 mb-2">Course Stats</h4>
                                    <div class="space-y-1">
                                        <p class="text-sm text-gray-600">
                                            <span class="font-medium">Created:</span> {{ formatDate(course.created_at) }}
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            <span class="font-medium">Videos:</span> {{ videos.length }}
                                        </p>
                                        <p v-if="course.total_duration" class="text-sm text-gray-600">
                                            <span class="font-medium">Total Duration:</span> {{ course.formatted_total_duration }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Videos Section -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-medium">Course Videos</h3>
                            <button 
                                @click="showAddVideoModal = true" 
                                class="px-4 py-2 bg-blue-600 text-white rounded-md text-sm hover:bg-blue-700"
                            >
                                Add Video
                            </button>
                        </div>
                        
                        <div v-if="videos.length === 0" class="text-center py-8">
                            <p class="text-gray-500">No videos added to this course yet.</p>
                            <button 
                                @click="showAddVideoModal = true" 
                                class="mt-4 inline-block px-4 py-2 bg-blue-600 text-white rounded-md"
                            >
                                Add First Video
                            </button>
                        </div>
                        
                        <div v-else>
                            <!-- Sortable video list -->
                            <div class="border rounded-lg overflow-hidden">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Lesson
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Video
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Duration
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Status
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="video in sortedVideos" :key="video.id">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ video.lesson_number }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <div class="flex items-center">
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900 truncate max-w-xs">
                                                            {{ video.original_filename }}
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            {{ formatDate(video.created_at) }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ video.formatted_duration || 'N/A' }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 py-1 text-xs rounded-full" 
                                                    :class="{
                                                        'bg-green-100 text-green-800': video.status === 'completed',
                                                        'bg-yellow-100 text-yellow-800': video.is_processing,
                                                        'bg-blue-100 text-blue-800': video.status === 'uploaded',
                                                        'bg-red-100 text-red-800': video.status === 'failed',
                                                    }">
                                                    {{ video.status }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex space-x-2">
                                                    <Link :href="route('videos.show', video.id)" class="text-blue-600 hover:text-blue-900">
                                                        View
                                                    </Link>
                                                    <button
                                                        @click="removeVideo(video.id)"
                                                        class="text-red-600 hover:text-red-900"
                                                        onclick="return confirm('Are you sure you want to remove this video from the course?')"
                                                    >
                                                        Remove
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Reorder button -->
                            <div class="mt-4 flex justify-end">
                                <button
                                    @click="reorderVideos"
                                    class="px-4 py-2 bg-gray-600 text-white rounded-md text-sm hover:bg-gray-700"
                                >
                                    Save Video Order
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Add Video Modal -->
        <AddVideoModal
            :course="course"
            :unassignedVideos="unassignedVideos"
            :show="showAddVideoModal"
            @close="showAddVideoModal = false"
        />
    </AuthenticatedLayout>
</template> 