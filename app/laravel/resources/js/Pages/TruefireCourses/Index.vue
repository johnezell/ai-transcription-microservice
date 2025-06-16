<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { debounce } from 'lodash';
import axios from 'axios';


const props = defineProps({
    courses: Object,
    filters: Object
});

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const isLoading = ref(false);
let currentRequest = null;

const notifications = ref([]); // For toast notifications

// Batch selection functionality
const selectedCourses = ref(new Set());
const selectAll = ref(false);
const isBatchActionLoading = ref(false);

// Computed properties for batch selection
const allCoursesSelected = computed(() => {
    return props.courses.data && props.courses.data.length > 0 && 
           props.courses.data.every(course => selectedCourses.value.has(course.id));
});

const someCoursesSelected = computed(() => {
    return selectedCourses.value.size > 0 && !allCoursesSelected.value;
});

const selectedCourseCount = computed(() => {
    return selectedCourses.value.size;
});

const hasSelectedCourses = computed(() => {
    return selectedCourses.value.size > 0;
});

// Batch selection functions
const toggleSelectAll = () => {
    if (allCoursesSelected.value) {
        // Deselect all
        selectedCourses.value.clear();
        selectAll.value = false;
    } else {
        // Select all courses on current page
        selectedCourses.value.clear();
        if (props.courses.data) {
            props.courses.data.forEach(course => {
                selectedCourses.value.add(course.id);
            });
        }
        selectAll.value = true;
    }
};

const toggleCourseSelection = (courseId) => {
    if (selectedCourses.value.has(courseId)) {
        selectedCourses.value.delete(courseId);
    } else {
        selectedCourses.value.add(courseId);
    }
    
    // Update select all state
    selectAll.value = allCoursesSelected.value;
};

const clearSelection = () => {
    selectedCourses.value.clear();
    selectAll.value = false;
};

// Batch action functions
const performBatchRedo = async () => {
    if (selectedCourses.value.size === 0) {
        showNotification('No courses selected', 'warning');
        return;
    }

    const courseIds = Array.from(selectedCourses.value);
    const confirmMessage = `Are you sure you want to redo transcription for ${courseIds.length} selected course(s)? This will restart the entire transcription process for all segments in these courses.`;
    
    if (!confirm(confirmMessage)) {
        return;
    }

    isBatchActionLoading.value = true;
    
    try {
        showNotification(`Starting batch redo for ${courseIds.length} course(s)...`, 'info');
        
        // Process each course individually to avoid overwhelming the system
        let successCount = 0;
        let errorCount = 0;
        
        for (const courseId of courseIds) {
            try {
                const response = await axios.post(`/api/truefire-courses/${courseId}/redo-all-transcriptions`, {
                    use_intelligent_detection: true,
                    force_restart: true
                });
                
                if (response.data.success) {
                    successCount++;
                } else {
                    errorCount++;
                    console.error(`Failed to redo course ${courseId}:`, response.data.message);
                }
            } catch (error) {
                errorCount++;
                console.error(`Error redoing course ${courseId}:`, error);
            }
            
            // Small delay between requests to avoid overwhelming the server
            await new Promise(resolve => setTimeout(resolve, 500));
        }
        
        // Clear selection after successful batch operation
        clearSelection();
        
        // Show results
        if (successCount > 0 && errorCount === 0) {
            showNotification(`Successfully started batch redo for ${successCount} course(s)`, 'success');
        } else if (successCount > 0 && errorCount > 0) {
            showNotification(`Batch redo started for ${successCount} course(s), ${errorCount} failed`, 'warning');
        } else {
            showNotification(`Batch redo failed for all selected courses`, 'error');
        }
        
        // Refresh the page to show updated status
        setTimeout(() => {
            router.reload({ only: ['courses'] });
        }, 1000);
        
    } catch (error) {
        console.error('Batch redo error:', error);
        showNotification('Failed to perform batch redo operation', 'error');
    } finally {
        isBatchActionLoading.value = false;
    }
};

const performBatchAction = async (action) => {
    switch (action) {
        case 'redo':
            await performBatchRedo();
            break;
        default:
            showNotification(`Batch action "${action}" not implemented yet`, 'info');
    }
};

// Clear selection when navigating to different pages
watch(() => props.courses.current_page, () => {
    clearSelection();
});

// Debounced search function to avoid excessive API calls
const debouncedSearch = debounce((searchTerm, status) => {
    // Cancel any existing request
    if (currentRequest) {
        currentRequest.cancel();
    }
    
    isLoading.value = true;
    currentRequest = router.get(route('truefire-courses.index'),
        { search: searchTerm, status: status },
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                isLoading.value = false;
                currentRequest = null;
            },
            onCancel: () => {
                isLoading.value = false;
                currentRequest = null;
            }
        }
    );
}, 500);

// Watch for search input changes
watch(search, (newValue) => {
    debouncedSearch(newValue, statusFilter.value);
});

// Watch for status filter changes
watch(statusFilter, (newValue) => {
    debouncedSearch(search.value, newValue);
});

// Handle pagination
const goToPage = (url) => {
    if (!url) return;
    
    // Cancel any existing search request when navigating
    if (currentRequest) {
        currentRequest.cancel();
        currentRequest = null;
    }
    
    isLoading.value = true;
    router.get(url, {}, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            isLoading.value = false;
        }
    });
};

// Clear search
const clearSearch = () => {
    search.value = '';
};

// Clear all filters
const clearAllFilters = () => {
    search.value = '';
    statusFilter.value = '';
};

// Helper function to get status color
const getStatusColor = (status) => {
    switch (status) {
        case 'completed':
            return 'bg-green-100 text-green-800 border-green-200';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'not_started':
            return 'bg-gray-100 text-gray-600 border-gray-200';
        default:
            return 'bg-gray-100 text-gray-600 border-gray-200';
    }
};

// Helper function to get status icon
const getStatusIcon = (status) => {
    switch (status) {
        case 'completed':
            return '✅';
        case 'in_progress':
            return '🔄';
        case 'not_started':
            return '⏳';
        default:
            return '❓';
    }
};

// Helper function to get progress bar color
const getProgressColor = (percentage) => {
    if (percentage === 100) return 'bg-green-500';
    if (percentage >= 75) return 'bg-blue-500';
    if (percentage >= 50) return 'bg-yellow-500';
    if (percentage >= 25) return 'bg-orange-500';
    return 'bg-red-500';
};

// Format runtime from seconds to human readable format
const formatRuntime = (seconds) => {
    if (!seconds || seconds === 0) return 'N/A';
    
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;
    
    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    } else if (minutes > 0) {
        return `${minutes}m ${remainingSeconds}s`;
    } else {
        return `${remainingSeconds}s`;
    }
};


// Toast notification system
const showNotification = (message, type = 'info') => {
    const id = Date.now();
    notifications.value.push({
        id,
        message,
        type,
        show: true
    });
    
    // Auto-remove after 5 seconds for bulk operations
    setTimeout(() => {
        removeNotification(id);
    }, 5000);
};

const removeNotification = (id) => {
    const index = notifications.value.findIndex(n => n.id === id);
    if (index > -1) {
        notifications.value.splice(index, 1);
    }
};



</script>

<template>
    <Head title="TrueFire Courses" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                TrueFire Courses
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">

                        <div class="mb-6">
                            <div class="flex justify-between items-center mb-4">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">
                                        Available TrueFire Courses
                                    </h3>
                                    <p class="mt-1 text-sm text-gray-600">
                                        Browse and explore TrueFire courses with their segment counts.
                                    </p>
                                </div>
                                <div class="text-sm text-gray-500" v-if="courses.data">
                                    Showing {{ courses.from || 0 }} to {{ courses.to || 0 }} of {{ courses.total || 0 }} courses
                                </div>
                            </div>
                            
                            <!-- Search and Filter Controls -->
                            <div class="mb-4 space-y-4">
                                <!-- Search Input -->
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="search"
                                        type="text"
                                        placeholder="Search by course ID or title..."
                                        class="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        :disabled="isLoading"
                                    />
                                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                        <button
                                            v-if="search"
                                            @click="clearSearch"
                                            class="text-gray-400 hover:text-gray-600"
                                            :disabled="isLoading"
                                        >
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                        <div v-if="isLoading" class="ml-2">
                                            <svg class="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <!-- Filter Controls -->
                                <div class="flex flex-wrap items-center gap-4">
                                    <!-- Status Filter -->
                                    <div class="flex items-center space-x-2">
                                        <label for="status-filter" class="text-sm font-medium text-gray-700">Status:</label>
                                        <select
                                            id="status-filter"
                                            v-model="statusFilter"
                                            class="border border-gray-300 rounded-md px-3 py-1 text-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                                            :disabled="isLoading"
                                        >
                                            <option value="">All Courses</option>
                                            <option value="completed">✅ Completed</option>
                                            <option value="in_progress">🔄 In Progress</option>
                                            <option value="not_started">⏳ Not Started</option>
                                        </select>
                                    </div>

                                    <!-- Clear Filters Button -->
                                    <button
                                        v-if="search || statusFilter"
                                        @click="clearAllFilters"
                                        class="inline-flex items-center px-3 py-1 border border-gray-300 rounded-md text-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                        :disabled="isLoading"
                                    >
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        Clear Filters
                                    </button>

                                    <!-- Active Filter Indicators -->
                                    <div class="flex items-center space-x-2">
                                        <span v-if="search" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            Search: "{{ search }}"
                                        </span>
                                        <span v-if="statusFilter" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                            Status: {{ statusFilter.replace('_', ' ') }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Batch Actions Bar -->
                            <div v-if="hasSelectedCourses" class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm font-medium text-blue-900">
                                            {{ selectedCourseCount }} course(s) selected
                                        </span>
                                        <button
                                            @click="clearSelection"
                                            class="text-sm text-blue-600 hover:text-blue-800 underline"
                                        >
                                            Clear Selection
                                        </button>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2">
                                        <!-- Batch Redo Button -->
                                        <button
                                            @click="performBatchAction('redo')"
                                            :disabled="isBatchActionLoading"
                                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <svg v-if="isBatchActionLoading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <svg v-else class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                            </svg>
                                            {{ isBatchActionLoading ? 'Processing...' : 'Redo Selected' }}
                                        </button>
                                        
                                        <!-- Additional batch actions can be added here -->
                                        <div class="relative">
                                            <button
                                                type="button"
                                                class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                                disabled
                                            >
                                                More Actions
                                                <svg class="-mr-1 ml-2 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <!-- Select All Checkbox -->
                                        <th scope="col" class="px-6 py-3 text-left">
                                            <div class="flex items-center">
                                                <input
                                                    type="checkbox"
                                                    :checked="allCoursesSelected"
                                                    :indeterminate="someCoursesSelected"
                                                    @change="toggleSelectAll"
                                                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                                    :disabled="!courses.data || courses.data.length === 0"
                                                />
                                                <span class="sr-only">Select all courses</span>
                                            </div>
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            ID
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Course Name
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Progress
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Segments
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Duration
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Created At
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="course in courses.data" :key="course.id" 
                                        class="hover:bg-gray-50"
                                        :class="{ 'bg-blue-50': selectedCourses.has(course.id) }">
                                        <!-- Course Selection Checkbox -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <input
                                                    type="checkbox"
                                                    :checked="selectedCourses.has(course.id)"
                                                    @change="toggleCourseSelection(course.id)"
                                                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                                />
                                                <span class="sr-only">Select course {{ course.id }}</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ course.id }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ course.name || course.title || `Course #${course.id}` }}
                                            </div>
                                            <div class="text-sm text-gray-500" v-if="course.description">
                                                {{ course.description }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div v-if="course.progress" class="space-y-2">
                                                <!-- Status Badge -->
                                                <div class="flex items-center space-x-2">
                                                    <span 
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border"
                                                        :class="getStatusColor(course.progress.status)"
                                                    >
                                                        <span class="mr-1">{{ getStatusIcon(course.progress.status) }}</span>
                                                        {{ course.progress.status.replace('_', ' ') }}
                                                    </span>
                                                    <span class="text-xs font-medium text-gray-900">
                                                        {{ course.progress.completion_percentage }}%
                                                    </span>
                                                </div>
                                                
                                                <!-- Progress Bar -->
                                                <div class="w-full bg-gray-200 rounded-full h-2">
                                                    <div 
                                                        class="h-2 rounded-full transition-all duration-300"
                                                        :class="getProgressColor(course.progress.completion_percentage)"
                                                        :style="{ width: course.progress.completion_percentage + '%' }"
                                                    ></div>
                                                </div>
                                                
                                                <!-- Progress Details -->
                                                <div class="text-xs text-gray-500">
                                                    {{ course.progress.completed_segments }}/{{ course.progress.total_segments }} completed
                                                    <span v-if="course.progress.in_progress_segments > 0" class="text-blue-600">
                                                        ({{ course.progress.in_progress_segments }} processing)
                                                    </span>
                                                    <span v-if="course.progress.failed_segments > 0" class="text-red-600">
                                                        ({{ course.progress.failed_segments }} failed)
                                                    </span>
                                                </div>
                                            </div>
                                            <div v-else class="text-xs text-gray-400">
                                                No progress data
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                {{ course.segments_count || 0 }} segments
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                {{ formatRuntime(course.segments_sum_runtime) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ course.release_date ? new Date(course.release_date).toLocaleDateString() : 'N/A' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <Link 
                                                :href="route('truefire-courses.show', course.id)"
                                                class="text-indigo-600 hover:text-indigo-900"
                                            >
                                                View Details
                                            </Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            <div v-if="!courses.data || courses.data.length === 0" class="text-center py-12">
                                <div class="text-gray-500">
                                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900">
                                        {{ search ? 'No courses match your search' : 'No courses found' }}
                                    </h3>
                                    <p class="mt-1 text-sm text-gray-500">
                                        {{ search ? 'Try adjusting your search terms.' : 'There are no TrueFire courses available at the moment.' }}
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Pagination Controls -->
                            <div v-if="courses.data && courses.data.length > 0" class="mt-6 flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6">
                                <div class="flex flex-1 justify-between sm:hidden">
                                    <button
                                        @click="goToPage(courses.prev_page_url)"
                                        :disabled="!courses.prev_page_url || isLoading"
                                        class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Previous
                                    </button>
                                    <button
                                        @click="goToPage(courses.next_page_url)"
                                        :disabled="!courses.next_page_url || isLoading"
                                        class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Next
                                    </button>
                                </div>
                                <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-sm text-gray-700">
                                            Showing
                                            <span class="font-medium">{{ courses.from }}</span>
                                            to
                                            <span class="font-medium">{{ courses.to }}</span>
                                            of
                                            <span class="font-medium">{{ courses.total }}</span>
                                            results
                                        </p>
                                    </div>
                                    <div>
                                        <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                            <!-- Previous Button -->
                                            <button
                                                @click="goToPage(courses.prev_page_url)"
                                                :disabled="!courses.prev_page_url || isLoading"
                                                class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                <span class="sr-only">Previous</span>
                                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                            
                                            <!-- Page Numbers -->
                                            <template v-for="link in courses.links" :key="link.label">
                                                <button
                                                    v-if="link.url && !link.label.includes('Previous') && !link.label.includes('Next')"
                                                    @click="goToPage(link.url)"
                                                    :disabled="isLoading"
                                                    :class="[
                                                        link.active
                                                            ? 'relative z-10 inline-flex items-center bg-indigo-600 px-4 py-2 text-sm font-semibold text-white focus:z-20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600'
                                                            : 'relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0',
                                                        isLoading ? 'opacity-50 cursor-not-allowed' : ''
                                                    ]"
                                                    v-html="link.label"
                                                ></button>
                                                <span
                                                    v-else-if="!link.url && !link.label.includes('Previous') && !link.label.includes('Next')"
                                                    class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 focus:outline-offset-0"
                                                    v-html="link.label"
                                                ></span>
                                            </template>
                                            
                                            <!-- Next Button -->
                                            <button
                                                @click="goToPage(courses.next_page_url)"
                                                :disabled="!courses.next_page_url || isLoading"
                                                class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                <span class="sr-only">Next</span>
                                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </nav>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Toast Notifications -->
        <div class="fixed top-4 right-4 z-50 space-y-2">
            <div
                v-for="notification in notifications"
                :key="notification.id"
                class="transform transition-all duration-300 ease-in-out"
                :class="{
                    'translate-x-0 opacity-100': notification.show,
                    'translate-x-full opacity-0': !notification.show
                }"
            >
                <div
                    class="bg-white rounded-lg shadow-lg border-l-4 p-4 max-w-sm"
                    :class="{
                        'border-blue-500': notification.type === 'info',
                        'border-green-500': notification.type === 'success',
                        'border-red-500': notification.type === 'error',
                        'border-yellow-500': notification.type === 'warning'
                    }"
                >
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg v-if="notification.type === 'success'" class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <svg v-else-if="notification.type === 'error'" class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            <svg v-else-if="notification.type === 'warning'" class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <svg v-else class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="ml-3 flex-1">
                            <p class="text-sm font-medium text-gray-900">
                                {{ notification.message }}
                            </p>
                        </div>
                        <div class="ml-4 flex-shrink-0">
                            <button
                                @click="removeNotification(notification.id)"
                                class="inline-flex text-gray-400 hover:text-gray-600 focus:outline-none focus:text-gray-600"
                            >
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>


    </AuthenticatedLayout>
</template>