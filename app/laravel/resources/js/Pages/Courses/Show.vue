<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, computed, watch } from 'vue';
import AddVideoModal from './Components/AddVideoModal.vue';
import DeleteCourseModal from './Components/DeleteCourseModal.vue';
import RemoveVideoModal from './Components/RemoveVideoModal.vue';
import draggable from 'vuedraggable';

const props = defineProps({
    course: Object,
    unassignedVideos: Array,
    segments: Array,
});

// Debug log to check course and videos data
console.log('Course data:', props.course);
console.log('Videos from course:', props.course.videos);
console.log('Unassigned videos:', props.unassignedVideos);
console.log('Segments data:', props.segments);

// Video ordering
const videos = ref(props.course.videos || []);
const dragEnabled = ref(true);
const orderChanged = ref(false);

// Modal state
const showAddVideoModal = ref(false);
const showDeleteModal = ref(false);
const showRemoveVideoModal = ref(false);
const selectedVideo = ref(null);

// Sort videos by lesson number
const sortedVideos = computed(() => {
    if (!videos.value || videos.value.length === 0) {
        return [];
    }
    return [...videos.value].sort((a, b) => (a.lesson_number || 0) - (b.lesson_number || 0));
});

// Create a draggable list of videos
const draggableVideos = ref(sortedVideos.value);

// Watch for changes in sortedVideos to update draggableVideos
watch(sortedVideos, (newVal) => {
    if (!orderChanged.value) {
        draggableVideos.value = [...newVal];
    }
});

// Track drag and drop order changes
const handleOrderChange = () => {
    orderChanged.value = true;
};

// Reorder videos (changing lesson numbers)
const saveOrder = () => {
    const videosToUpdate = draggableVideos.value.map((video, index) => ({
        id: video.id,
        lesson_number: index + 1,
    }));
    
    const form = useForm({
        videos: videosToUpdate,
    });
    
    form.put(route('courses.videos.order', props.course.id), {
        preserveScroll: true,
        onSuccess: () => {
            // Update the local state
            videosToUpdate.forEach((update) => {
                const video = videos.value.find(v => v.id === update.id);
                if (video) {
                    video.lesson_number = update.lesson_number;
                }
            });
            orderChanged.value = false;
        },
    });
};

// Show remove video modal
const confirmRemoveVideo = (video) => {
    selectedVideo.value = video;
    showRemoveVideoModal.value = true;
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

// Format duration for header stats
const formatDuration = (seconds) => {
    if (!seconds) return '0 min';
    
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    
    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }
    return `${minutes} min`;
};

// Get course stats
const videoCount = computed(() => videos.value.length || 0);
const totalDuration = computed(() => {
    return videos.value.reduce((total, video) => {
        return total + (video.audio_duration || 0);
    }, 0);
});

// Format status label colors
const getStatusClass = (status, isProcessing) => {
    if (isProcessing) return 'bg-yellow-100 text-yellow-800';
    switch(status) {
        case 'completed': return 'bg-green-100 text-green-800';
        case 'uploaded': return 'bg-blue-100 text-blue-800';
        case 'failed': return 'bg-red-100 text-red-800';
        default: return 'bg-gray-100 text-gray-800';
    }
};

// Copy to clipboard function
const copyToClipboard = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        // You could add a toast notification here if you have one
        console.log('URL copied to clipboard');
    } catch (err) {
        console.error('Failed to copy URL: ', err);
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    }
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
                    <button 
                        @click="showDeleteModal = true" 
                        class="px-4 py-2 bg-red-600 text-white rounded-md"
                    >
                        Delete Course
                    </button>
                    <Link :href="route('courses.index')" class="px-4 py-2 bg-gray-800 text-white rounded-md">
                        Back to Courses
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Course Header with Stats -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="flex flex-col md:flex-row md:items-center justify-between">
                            <div class="md:w-2/3">
                                <h1 class="text-2xl font-bold text-gray-900">{{ course.name }}</h1>
                                <p v-if="course.subject_area" class="mt-1 text-sm text-indigo-600 font-medium">
                                    {{ course.subject_area }}
                                </p>
                                <p v-if="course.description" class="mt-2 text-gray-700">
                                    {{ course.description }}
                                </p>
                            </div>
                            
                            <div class="md:w-1/3 mt-4 md:mt-0 flex flex-wrap gap-4">
                                <div class="flex-1 bg-blue-50 rounded-lg p-4 text-center">
                                    <span class="text-lg font-bold text-blue-700">{{ videoCount }}</span>
                                    <p class="text-sm text-blue-600">Videos</p>
                                </div>
                                <div class="flex-1 bg-green-50 rounded-lg p-4 text-center">
                                    <span class="text-lg font-bold text-green-700">{{ formatDuration(totalDuration) }}</span>
                                    <p class="text-sm text-green-600">Total Duration</p>
                                </div>
                                <div class="flex-1 bg-purple-50 rounded-lg p-4 text-center">
                                    <span class="text-lg font-bold text-purple-700">{{ formatDate(course.created_at) }}</span>
                                    <p class="text-sm text-purple-600">Created</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Videos Section with Drag & Drop -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <div>
                                <h3 class="text-lg font-medium">Course Lessons</h3>
                                <p class="text-sm text-gray-500 mt-1" v-if="orderChanged">
                                    Order changed. Click "Save Order" to update.
                                </p>
                            </div>
                            <div class="flex space-x-2">
                                <button 
                                    v-if="orderChanged"
                                    @click="saveOrder" 
                                    class="px-4 py-2 bg-green-600 text-white rounded-md text-sm hover:bg-green-700"
                                >
                                    Save Order
                                </button>
                                <button 
                                    @click="showAddVideoModal = true" 
                                    class="px-4 py-2 bg-blue-600 text-white rounded-md text-sm hover:bg-blue-700"
                                >
                                    Add Video
                                </button>
                            </div>
                        </div>
                        
                        <div v-if="videos.length === 0" class="text-center py-8">
                            <div class="mx-auto w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                                <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <p class="text-gray-500 text-lg">No videos added to this course yet</p>
                            <p class="text-gray-400 text-sm mt-1 mb-4">Add videos to start building your course</p>
                            <button 
                                @click="showAddVideoModal = true" 
                                class="mt-2 inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                            >
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Add First Video
                            </button>
                        </div>
                        
                        <div v-else>
                            <!-- Draggable video list -->
                            <draggable 
                                v-model="draggableVideos" 
                                item-key="id"
                                :disabled="!dragEnabled"
                                ghost-class="bg-gray-100"
                                chosen-class="opacity-50"
                                handle=".drag-handle"
                                @change="handleOrderChange"
                                class="space-y-2"
                            >
                                <template #item="{ element: video, index }">
                                    <div class="border rounded-lg overflow-hidden bg-white hover:bg-gray-50 transition-colors">
                                        <div class="p-4">
                                            <div class="flex items-center">
                                                <!-- Drag handle -->
                                                <div class="drag-handle cursor-move px-2 text-gray-400 hover:text-gray-700 flex items-center justify-center">
                                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path>
                                                    </svg>
                                                </div>
                                                
                                                <!-- Lesson number and title -->
                                                <div class="ml-2 flex-1">
                                                    <div class="flex items-center">
                                                        <span class="inline-flex items-center justify-center bg-gray-200 text-gray-800 text-sm font-medium w-8 h-8 rounded-full mr-3">
                                                            {{ index + 1 }}
                                                        </span>
                                                        <span class="font-medium text-gray-900">{{ video.original_filename }}</span>
                                                        <span 
                                                            class="ml-3 px-2 py-1 text-xs rounded-full" 
                                                            :class="getStatusClass(video.status, video.is_processing)"
                                                        >
                                                            {{ video.status }}
                                                        </span>
                                                    </div>
                                                    <div class="mt-1 flex items-center text-sm text-gray-500">
                                                        <span v-if="video.formatted_duration" class="mr-3">
                                                            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                            </svg>
                                                            {{ video.formatted_duration }}
                                                        </span>
                                                        <span class="mr-3">
                                                            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                            </svg>
                                                            {{ formatDate(video.created_at) }}
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                <!-- Features badges -->
                                                <div class="hidden md:flex items-center space-x-2 mr-4">
                                                    <span v-if="video.transcript_path" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                        </svg>
                                                        Transcript
                                                    </span>
                                                    <span v-if="video.has_terminology" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                                        </svg>
                                                        {{ video.terminology_count || 0 }} Terms
                                                    </span>
                                                </div>
                                                
                                                <!-- Actions -->
                                                <div class="ml-auto flex items-center space-x-2">
                                                    <Link :href="route('videos.show', video.id)" class="inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 rounded-md text-sm hover:bg-blue-100">
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                        </svg>
                                                        View
                                                    </Link>
                                                    <button
                                                        @click="confirmRemoveVideo(video)"
                                                        class="inline-flex items-center px-3 py-1 bg-red-50 text-red-700 rounded-md text-sm hover:bg-red-100"
                                                    >
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                        Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </draggable>
                            
                            <!-- Instructions for drag and drop -->
                            <div class="mt-4 text-center text-gray-500 text-sm">
                                <p>Drag and drop videos to reorder them in the course</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Segments Section -->
                <div v-if="segments && segments.length > 0" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-6">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <div>
                                <h3 class="text-lg font-medium">TrueFire Segments Testing</h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    Sample segments with signed CloudFront URLs for testing
                                </p>
                            </div>
                            <div class="flex items-center space-x-4">
                                <div class="text-sm text-gray-500">
                                    {{ segments.length }} segments loaded
                                </div>
                                <div class="text-xs bg-blue-50 text-blue-700 px-2 py-1 rounded">
                                    Testing Mode
                                </div>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Segment ID
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Title
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Video File
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Signed URL
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="segment in segments" :key="segment.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ segment.id }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ segment.title }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <code class="bg-gray-100 px-2 py-1 rounded text-xs">
                                                {{ segment.video }}
                                            </code>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <div v-if="segment.signed_url" class="flex items-center">
                                                <div class="truncate max-w-xs mr-2">
                                                    <code class="bg-green-50 text-green-700 px-2 py-1 rounded text-xs">
                                                        {{ segment.signed_url.substring(0, 50) }}...
                                                    </code>
                                                </div>
                                                <button
                                                    @click="copyToClipboard(segment.signed_url)"
                                                    class="text-blue-600 hover:text-blue-800 text-xs"
                                                    title="Copy URL"
                                                >
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                            <div v-else class="text-red-500 text-xs">
                                                {{ segment.error || 'No signed URL available' }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a
                                                    v-if="segment.signed_url"
                                                    :href="segment.signed_url"
                                                    target="_blank"
                                                    class="inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 rounded-md text-xs hover:bg-blue-100"
                                                >
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                    </svg>
                                                    Test
                                                </a>
                                                <button
                                                    v-if="segment.signed_url"
                                                    @click="copyToClipboard(segment.signed_url)"
                                                    class="inline-flex items-center px-3 py-1 bg-gray-50 text-gray-700 rounded-md text-xs hover:bg-gray-100"
                                                >
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                                    </svg>
                                                    Copy
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
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
        
        <!-- Delete Course Modal -->
        <DeleteCourseModal
            :course="course"
            :videoCount="videoCount"
            :show="showDeleteModal"
            @close="showDeleteModal = false"
        />
        
        <!-- Remove Video Modal -->
        <RemoveVideoModal
            :course="course"
            :video="selectedVideo"
            :show="showRemoveVideoModal"
            @close="showRemoveVideoModal = false"
        />
    </AuthenticatedLayout>
</template> 