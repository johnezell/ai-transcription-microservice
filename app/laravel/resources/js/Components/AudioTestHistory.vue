<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import { debounce } from 'lodash';

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['close', 'view-results', 'retry-test', 'delete-test']);

// Component state
const isLoading = ref(false);
const testHistory = ref([]);
const pagination = ref({});
const error = ref(null);

// Filters
const filters = ref({
    search: '',
    courseId: '',
    qualityLevel: '',
    status: '',
    dateFrom: '',
    dateTo: '',
    sortBy: 'created_at',
    sortOrder: 'desc'
});

// Available filter options
const qualityLevels = ['fast', 'balanced', 'high', 'premium'];
const statusOptions = ['completed', 'failed', 'processing'];
const sortOptions = [
    { value: 'created_at', label: 'Date Created' },
    { value: 'quality_score', label: 'Quality Score' },
    { value: 'processing_time', label: 'Processing Time' },
    { value: 'course_id', label: 'Course ID' }
];

// Selection state
const selectedTests = ref(new Set());
const selectAll = ref(false);

// Computed properties
const filteredHistory = computed(() => {
    return testHistory.value.filter(test => {
        if (filters.value.search) {
            const searchTerm = filters.value.search.toLowerCase();
            if (!test.segment_id.toString().includes(searchTerm) && 
                !test.course_id.toString().includes(searchTerm)) {
                return false;
            }
        }
        
        if (filters.value.courseId && test.course_id.toString() !== filters.value.courseId) {
            return false;
        }
        
        if (filters.value.qualityLevel && test.quality_level !== filters.value.qualityLevel) {
            return false;
        }
        
        if (filters.value.status && test.status !== filters.value.status) {
            return false;
        }
        
        return true;
    });
});

const hasSelection = computed(() => selectedTests.value.size > 0);

const allTestsSelected = computed(() => {
    return filteredHistory.value.length > 0 && 
           filteredHistory.value.every(test => selectedTests.value.has(test.id));
});

// Methods
const loadTestHistory = async (page = 1) => {
    isLoading.value = true;
    error.value = null;
    
    try {
        // Map Vue component parameters to Laravel controller expected parameters
        const mappedFilters = {
            per_page: 15, // Default per page
            status: filters.value.status || undefined,
            quality_level: filters.value.qualityLevel || undefined,
            course_id: filters.value.courseId || undefined
        };
        
        // Remove undefined values
        Object.keys(mappedFilters).forEach(key => {
            if (mappedFilters[key] === undefined || mappedFilters[key] === '') {
                delete mappedFilters[key];
            }
        });
        
        const params = new URLSearchParams({
            page: page.toString(),
            ...mappedFilters
        });
        
        const response = await axios.get(`/audio-test-history?${params}`);
        
        // Handle Laravel pagination structure correctly
        if (response.data.success && response.data.data) {
            testHistory.value = response.data.data.data || [];
            pagination.value = {
                current_page: response.data.data.current_page,
                last_page: response.data.data.last_page,
                per_page: response.data.data.per_page,
                total: response.data.data.total,
                from: response.data.data.from,
                to: response.data.data.to
            };
        } else {
            testHistory.value = [];
            pagination.value = {};
        }
        // This was removed and handled above in the success check
    } catch (err) {
        console.error('Failed to load test history:', err);
        error.value = err.response?.data?.message || 'Failed to load test history';
    } finally {
        isLoading.value = false;
    }
};

const debouncedSearch = debounce(() => {
    loadTestHistory(1);
}, 500);

const clearFilters = () => {
    filters.value = {
        search: '',
        courseId: '',
        qualityLevel: '',
        status: '',
        dateFrom: '',
        dateTo: '',
        sortBy: 'created_at',
        sortOrder: 'desc'
    };
    loadTestHistory(1);
};

const goToPage = (page) => {
    if (page >= 1 && page <= pagination.value.last_page) {
        loadTestHistory(page);
    }
};

const toggleTestSelection = (testId) => {
    if (selectedTests.value.has(testId)) {
        selectedTests.value.delete(testId);
    } else {
        selectedTests.value.add(testId);
    }
};

const toggleSelectAll = () => {
    if (allTestsSelected.value) {
        selectedTests.value.clear();
    } else {
        filteredHistory.value.forEach(test => {
            selectedTests.value.add(test.id);
        });
    }
};

const viewTestResults = (test) => {
    emit('view-results', {
        courseId: test.course_id,
        segmentId: test.segment_id,
        testId: test.id
    });
};

const retryTest = (test) => {
    emit('retry-test', {
        courseId: test.course_id,
        segmentId: test.segment_id,
        configuration: {
            qualityLevel: test.quality_level,
            testConfiguration: test.test_configuration
        }
    });
};

const deleteSelectedTests = async () => {
    if (!hasSelection.value) return;
    
    if (!confirm(`Are you sure you want to delete ${selectedTests.value.size} test(s)?`)) {
        return;
    }
    
    try {
        await axios.delete('/audio-test-history/bulk', {
            data: { test_ids: Array.from(selectedTests.value) }
        });
        
        selectedTests.value.clear();
        loadTestHistory(pagination.value.current_page);
    } catch (err) {
        console.error('Failed to delete tests:', err);
        alert('Failed to delete selected tests');
    }
};

const exportTestData = async () => {
    try {
        const params = new URLSearchParams(filters.value);
        const response = await axios.get(`/audio-test-history/export?${params}`, {
            responseType: 'blob'
        });
        
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `audio_test_history_${new Date().toISOString().split('T')[0]}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (err) {
        console.error('Failed to export test data:', err);
        alert('Failed to export test data');
    }
};

// Utility functions
const formatDate = (dateString) => {
    return new Date(dateString).toLocaleString();
};

const formatDuration = (seconds) => {
    if (!seconds) return 'N/A';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
};

const getStatusColor = (status) => {
    switch (status) {
        case 'completed': return 'text-green-600 bg-green-100';
        case 'failed': return 'text-red-600 bg-red-100';
        case 'processing': return 'text-blue-600 bg-blue-100';
        default: return 'text-gray-600 bg-gray-100';
    }
};

const getQualityColor = (score) => {
    if (score >= 90) return 'text-green-600';
    if (score >= 70) return 'text-yellow-600';
    if (score >= 50) return 'text-orange-600';
    return 'text-red-600';
};

// Watchers
watch(() => filters.value.search, debouncedSearch);
watch(() => [filters.value.courseId, filters.value.qualityLevel, filters.value.status], () => {
    loadTestHistory(1);
});

// Load data on mount
onMounted(() => {
    if (props.show) {
        loadTestHistory();
    }
});

watch(() => props.show, (newValue) => {
    if (newValue) {
        loadTestHistory();
    }
});
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        aria-labelledby="audio-test-history"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <!-- Background overlay -->
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            
            <!-- Center the modal -->
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            
            <!-- Modal panel -->
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-7xl sm:w-full">
                <!-- Header -->
                <div class="bg-gradient-to-r from-purple-500 to-pink-600 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-white">
                                    Audio Test History
                                </h3>
                                <p class="text-sm text-purple-100">
                                    Browse and manage historical audio extraction tests
                                </p>
                            </div>
                        </div>
                        <button
                            @click="$emit('close')"
                            class="text-purple-100 hover:text-white transition-colors duration-200"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-gray-50 px-6 py-4 border-b">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                        <!-- Search -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Search</label>
                            <input
                                v-model="filters.search"
                                type="text"
                                placeholder="Course ID, Segment ID..."
                                class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                        
                        <!-- Course ID -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Course ID</label>
                            <input
                                v-model="filters.courseId"
                                type="text"
                                placeholder="Filter by course..."
                                class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                        
                        <!-- Quality Level -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Quality Level</label>
                            <select
                                v-model="filters.qualityLevel"
                                class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            >
                                <option value="">All Levels</option>
                                <option v-for="level in qualityLevels" :key="level" :value="level">
                                    {{ level.charAt(0).toUpperCase() + level.slice(1) }}
                                </option>
                            </select>
                        </div>
                        
                        <!-- Status -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                            <select
                                v-model="filters.status"
                                class="block w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            >
                                <option value="">All Status</option>
                                <option v-for="status in statusOptions" :key="status" :value="status">
                                    {{ status.charAt(0).toUpperCase() + status.slice(1) }}
                                </option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <button
                                @click="clearFilters"
                                class="text-sm text-gray-600 hover:text-gray-800"
                            >
                                Clear Filters
                            </button>
                            <button
                                @click="exportTestData"
                                class="text-sm text-indigo-600 hover:text-indigo-800"
                            >
                                Export Data
                            </button>
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            <button
                                v-if="hasSelection"
                                @click="deleteSelectedTests"
                                class="inline-flex items-center px-3 py-1 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:bg-red-700 active:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
                            >
                                Delete Selected ({{ selectedTests.size }})
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div class="px-6 py-6">
                    <!-- Loading State -->
                    <div v-if="isLoading" class="flex items-center justify-center py-12">
                        <div class="text-center">
                            <svg class="animate-spin w-8 h-8 text-indigo-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <p class="text-gray-600">Loading test history...</p>
                        </div>
                    </div>

                    <!-- Error State -->
                    <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-6">
                        <div class="flex items-center space-x-3">
                            <svg class="w-6 h-6 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                            <div>
                                <h4 class="text-lg font-medium text-red-900">Failed to Load History</h4>
                                <p class="text-red-700">{{ error }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Test History Table -->
                    <div v-else-if="filteredHistory.length > 0" class="space-y-4">
                        <!-- Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <input
                                                type="checkbox"
                                                :checked="allTestsSelected"
                                                @change="toggleSelectAll"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Test Details
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Quality
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Performance
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr
                                        v-for="test in filteredHistory"
                                        :key="test.id"
                                        class="hover:bg-gray-50"
                                        :class="selectedTests.has(test.id) ? 'bg-indigo-50' : ''"
                                    >
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input
                                                type="checkbox"
                                                :checked="selectedTests.has(test.id)"
                                                @change="toggleTestSelection(test.id)"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                Course {{ test.course_id }} - Segment {{ test.segment_id }}
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                {{ test.quality_level }} quality
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div v-if="test.quality_score" class="text-sm font-medium" :class="getQualityColor(test.quality_score)">
                                                {{ test.quality_score }}/100
                                            </div>
                                            <div v-else class="text-sm text-gray-500">N/A</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <div v-if="test.processing_time_seconds">
                                                {{ formatDuration(test.processing_time_seconds) }}
                                            </div>
                                            <div v-else class="text-gray-500">N/A</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="getStatusColor(test.status)">
                                                {{ test.status }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ formatDate(test.created_at) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex items-center space-x-2">
                                                <button
                                                    @click="viewTestResults(test)"
                                                    class="text-indigo-600 hover:text-indigo-900"
                                                >
                                                    View
                                                </button>
                                                <button
                                                    @click="retryTest(test)"
                                                    class="text-green-600 hover:text-green-900"
                                                >
                                                    Retry
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6">
                            <div class="flex flex-1 justify-between sm:hidden">
                                <button
                                    @click="goToPage(pagination.current_page - 1)"
                                    :disabled="pagination.current_page <= 1"
                                    class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Previous
                                </button>
                                <button
                                    @click="goToPage(pagination.current_page + 1)"
                                    :disabled="pagination.current_page >= pagination.last_page"
                                    class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Next
                                </button>
                            </div>
                            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700">
                                        Showing
                                        <span class="font-medium">{{ pagination.from }}</span>
                                        to
                                        <span class="font-medium">{{ pagination.to }}</span>
                                        of
                                        <span class="font-medium">{{ pagination.total }}</span>
                                        results
                                    </p>
                                </div>
                                <div>
                                    <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                        <button
                                            @click="goToPage(pagination.current_page - 1)"
                                            :disabled="pagination.current_page <= 1"
                                            class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <span class="sr-only">Previous</span>
                                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                        
                                        <span class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300">
                                            {{ pagination.current_page }} / {{ pagination.last_page }}
                                        </span>
                                        
                                        <button
                                            @click="goToPage(pagination.current_page + 1)"
                                            :disabled="pagination.current_page >= pagination.last_page"
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

                    <!-- Empty State -->
                    <div v-else class="text-center py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">No Test History</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            No audio extraction tests found matching your criteria.
                        </p>
                    </div>
                </div>

                <!-- Footer -->
                <div class="bg-gray-50 px-6 py-4 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        <span v-if="pagination.total">
                            {{ pagination.total }} total test{{ pagination.total !== 1 ? 's' : '' }}
                        </span>
                        <span v-else>
                            No tests found
                        </span>
                    </div>
                    
                    <button
                        @click="$emit('close')"
                        class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>