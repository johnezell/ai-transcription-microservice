<script setup>
import { ref, defineEmits, defineProps } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    course: Object,
    unassignedVideos: Array,
    show: Boolean
});

const emit = defineEmits(['close']);

const form = useForm({
    video_id: '',
    lesson_number: '',
});

const isProcessing = ref(false);

const submit = () => {
    isProcessing.value = true;
    form.post(route('courses.videos.add', props.course.id), {
        preserveScroll: true,
        onSuccess: () => {
            isProcessing.value = false;
            form.reset();
            emit('close');
        },
        onError: () => {
            isProcessing.value = false;
        },
    });
};

// Calculate next lesson number based on existing videos
const nextLessonNumber = ref(() => {
    if (!props.course.videos || props.course.videos.length === 0) {
        return 1;
    }
    
    const maxLessonNumber = Math.max(...props.course.videos.map(video => video.lesson_number || 0));
    return maxLessonNumber + 1;
});

// Set default lesson number when modal opens
const setDefaultLessonNumber = () => {
    form.lesson_number = nextLessonNumber.value;
};

// Watch for changes in show prop
const watchShow = () => {
    if (props.show) {
        setDefaultLessonNumber();
    }
};

// Call watchShow whenever props.show changes
watchShow();
</script>

<template>
    <div v-if="show" class="fixed inset-0 overflow-y-auto z-50">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity">
                <div class="absolute inset-0 bg-gray-500 opacity-75" @click="emit('close')"></div>
            </div>
            
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>&#8203;
            
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900">
                                Add Video to Course
                            </h3>
                            
                            <div class="mt-4">
                                <form @submit.prevent="submit" class="space-y-4">
                                    <div v-if="unassignedVideos.length === 0" class="text-center py-4">
                                        <p class="text-gray-500">No unassigned videos available.</p>
                                        <p class="text-gray-500 text-sm mt-2">Upload new videos first.</p>
                                    </div>
                                    
                                    <div v-else>
                                        <div>
                                            <label for="video_id" class="block text-sm font-medium text-gray-700">Select Video</label>
                                            <select
                                                id="video_id"
                                                v-model="form.video_id"
                                                class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                                                required
                                            >
                                                <option value="" disabled>Select a video...</option>
                                                <option v-for="video in unassignedVideos" :key="video.id" :value="video.id">
                                                    {{ video.original_filename }}
                                                </option>
                                            </select>
                                            <div v-if="form.errors.video_id" class="text-red-500 text-sm mt-1">{{ form.errors.video_id }}</div>
                                        </div>
                                        
                                        <div class="mt-4">
                                            <label for="lesson_number" class="block text-sm font-medium text-gray-700">Lesson Number</label>
                                            <input
                                                id="lesson_number"
                                                v-model="form.lesson_number"
                                                type="number"
                                                min="1"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                required
                                            />
                                            <div v-if="form.errors.lesson_number" class="text-red-500 text-sm mt-1">{{ form.errors.lesson_number }}</div>
                                            <p class="text-xs text-gray-500 mt-1">This determines the order of lessons in the course.</p>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                                        <button
                                            type="submit"
                                            class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm"
                                            :disabled="isProcessing || unassignedVideos.length === 0"
                                        >
                                            <span v-if="isProcessing">Adding...</span>
                                            <span v-else>Add to Course</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm"
                                            @click="emit('close')"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template> 