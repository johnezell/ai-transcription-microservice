<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    course: Object,
    show: Boolean,
    videoCount: Number
});

const emit = defineEmits(['close']);

const isDeleting = ref(false);
const deleteVideos = ref(false);
const confirmText = ref('');

const form = useForm({});

const deleteCourse = () => {
    isDeleting.value = true;
    
    if (deleteVideos.value) {
        form.delete(route('courses.destroy-with-videos', props.course.id), {
            onSuccess: () => {
                isDeleting.value = false;
                emit('close');
            },
            onError: () => {
                isDeleting.value = false;
            }
        });
    } else {
        form.delete(route('courses.destroy', props.course.id), {
            onSuccess: () => {
                isDeleting.value = false;
                emit('close');
            },
            onError: () => {
                isDeleting.value = false;
            }
        });
    }
};

const isConfirmationValid = () => {
    return confirmText.value === props.course.name;
};
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
                        <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                            <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="text-lg leading-6 font-medium text-gray-900">
                                Delete Course
                            </h3>
                            <div class="mt-2">
                                <p class="text-sm text-gray-500">
                                    Are you sure you want to delete the course "{{ course.name }}"? This action cannot be undone.
                                </p>
                                
                                <div class="mt-4">
                                    <div class="flex items-center">
                                        <input 
                                            id="delete-videos" 
                                            type="checkbox" 
                                            v-model="deleteVideos"
                                            class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded"
                                        >
                                        <label for="delete-videos" class="ml-2 block text-sm text-gray-900">
                                            <span :class="deleteVideos ? 'font-bold text-red-600' : ''">Also delete all {{ videoCount }} videos in this course</span>
                                        </label>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">
                                        Warning: This will permanently remove all video files and their transcripts.
                                    </p>
                                </div>
                                
                                <div v-if="deleteVideos" class="mt-4 border border-red-200 bg-red-50 p-3 rounded-md">
                                    <p class="text-sm text-red-700 font-medium mb-2">
                                        This is a destructive action!
                                    </p>
                                    <p class="text-sm text-red-600">
                                        To confirm, please type the course name: <span class="font-bold">{{ course.name }}</span>
                                    </p>
                                    <input 
                                        type="text" 
                                        v-model="confirmText"
                                        class="mt-2 w-full border-red-300 focus:border-red-500 focus:ring-red-500 rounded-md shadow-sm text-sm"
                                        placeholder="Type course name to confirm"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button 
                        type="button" 
                        class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:ml-3 sm:w-auto sm:text-sm"
                        @click="deleteCourse"
                        :disabled="isDeleting || (deleteVideos && !isConfirmationValid())"
                    >
                        <span v-if="isDeleting">Deleting...</span>
                        <span v-else>{{ deleteVideos ? 'Delete Course and Videos' : 'Delete Course Only' }}</span>
                    </button>
                    <button 
                        type="button" 
                        class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                        @click="emit('close')"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</template> 