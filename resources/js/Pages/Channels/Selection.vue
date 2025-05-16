<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { channelsApi } from '@/api';

// State management
const courses = ref([]);
const loading = ref(true);
const error = ref(null);
const selectedSegments = ref({});
const expandedCourses = ref({});
const importStatus = ref({ 
  processing: false, 
  success: false, 
  message: '', 
  count: 0 
});

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

// Toggle course expansion
function toggleCourse(courseId) {
    expandedCourses.value[courseId] = !expandedCourses.value[courseId];
}

// Toggle segment selection
function toggleSegment(courseId, segmentId) {
    if (!selectedSegments.value[courseId]) {
        selectedSegments.value[courseId] = {};
    }
    selectedSegments.value[courseId][segmentId] = !selectedSegments.value[courseId][segmentId];
}

// Select/deselect all segments in a course
function toggleAllSegments(courseId) {
    const course = courses.value.find(c => c.id === courseId);
    if (!course || !course.segments) return;
    
    if (!selectedSegments.value[courseId]) {
        selectedSegments.value[courseId] = {};
    }
    
    // Check if all segments are already selected
    const allSelected = course.segments.every(segment => 
        selectedSegments.value[courseId][segment.id]
    );
    
    // Toggle accordingly
    course.segments.forEach(segment => {
        selectedSegments.value[courseId][segment.id] = !allSelected;
    });
}

// Check if a segment is selected
function isSegmentSelected(courseId, segmentId) {
    return selectedSegments.value[courseId] && selectedSegments.value[courseId][segmentId];
}

// Count selected segments
function getSelectedCount() {
    let count = 0;
    for (const courseId in selectedSegments.value) {
        for (const segmentId in selectedSegments.value[courseId]) {
            if (selectedSegments.value[courseId][segmentId]) {
                count++;
            }
        }
    }
    return count;
}

// Import selected segments
async function importSelectedSegments() {
    const segmentIds = [];
    for (const courseId in selectedSegments.value) {
        for (const segmentId in selectedSegments.value[courseId]) {
            if (selectedSegments.value[courseId][segmentId]) {
                segmentIds.push(parseInt(segmentId));
            }
        }
    }
    
    if (segmentIds.length === 0) {
        return;
    }
    
    try {
        importStatus.value.processing = true;
        const response = await channelsApi.importSegmentsBulk(segmentIds);
        importStatus.value.success = true;
        importStatus.value.message = response.data.message;
        importStatus.value.count = response.data.count;
        
        // Clear selections after successful import
        selectedSegments.value = {};
    } catch (err) {
        importStatus.value.success = false;
        importStatus.value.message = err.message || 'Failed to import segments';
        console.error(err);
    } finally {
        importStatus.value.processing = false;
    }
}
</script>

<template>
    <Head title="Select Channel Segments" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Select Channel Segments to Import
                </h2>
                <div v-if="getSelectedCount() > 0" class="flex items-center gap-4">
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        {{ getSelectedCount() }} segment{{ getSelectedCount() > 1 ? 's' : '' }} selected
                    </span>
                    <button 
                        @click="importSelectedSegments" 
                        :disabled="importStatus.processing"
                        class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-500 active:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150"
                    >
                        <svg v-if="importStatus.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Import Selected
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Import Status Alert -->
                <div v-if="importStatus.message" class="mb-4" :class="importStatus.success ? 'bg-green-100 border-green-400 text-green-700' : 'bg-red-100 border-red-400 text-red-700'" role="alert">
                    <div class="px-4 py-3 rounded relative border">
                        <span class="block sm:inline">{{ importStatus.message }}</span>
                        <button @click="importStatus.message = ''" class="absolute top-0 bottom-0 right-0 px-4 py-3">
                            <svg class="h-6 w-6" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
                        </button>
                    </div>
                </div>
                
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
                    
                    <!-- Course List -->
                    <div v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                        <div v-for="course in courses" :key="course.id" class="py-6">
                            <div @click="toggleCourse(course.id)" class="flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 bg-gray-200 dark:bg-gray-700 rounded overflow-hidden flex-shrink-0">
                                        <img v-if="course.thumbnail_url" :src="course.thumbnail_url" alt="Course thumbnail" class="w-full h-full object-cover" />
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">{{ course.title }}</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ course.segments ? course.segments.length : 0 }} segments • {{ course.instructor }}</p>
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
                            
                            <div v-if="expandedCourses[course.id] && course.segments && course.segments.length > 0" class="mt-4 pl-4">
                                <!-- Segment controls -->
                                <div class="flex justify-between items-center mb-2 border-b border-gray-100 dark:border-gray-700 pb-2">
                                    <button 
                                        @click.stop="toggleAllSegments(course.id)" 
                                        class="text-sm text-blue-600 hover:text-blue-500 dark:text-blue-400"
                                    >
                                        Select All Segments
                                    </button>
                                </div>
                                
                                <!-- Segment list -->
                                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <div v-for="segment in course.segments" :key="segment.id" class="py-3 flex items-center justify-between">
                                        <div class="flex items-center">
                                            <input 
                                                type="checkbox" 
                                                :id="`segment-${segment.id}`" 
                                                :checked="isSegmentSelected(course.id, segment.id)"
                                                @change="toggleSegment(course.id, segment.id)"
                                                class="mr-3 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            >
                                            <label :for="`segment-${segment.id}`" class="block">
                                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ segment.sequence_number }}. {{ segment.title }}</span>
                                                <span class="flex mt-1">
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ segment.duration_minutes }} minutes</span>
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
        </div>
    </AuthenticatedLayout>
</template> 