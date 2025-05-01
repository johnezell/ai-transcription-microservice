<template>
  <div>
    <h3 class="text-lg font-medium mb-4 flex items-center">
      <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
      </svg>
      Term Recognition ({{ totalTermCount }})
    </h3>

    <div v-if="isLoading" class="flex justify-center py-6">
      <svg class="animate-spin h-8 w-8 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
    </div>

    <div v-else-if="error" class="p-4 bg-red-50 text-red-800 rounded-md border border-red-200">
      <p class="font-medium">Error loading music terms</p>
      <p>{{ error }}</p>
    </div>

    <div v-else-if="!musicTermData || totalTermCount === 0" class="p-4 bg-gray-50 rounded-md text-gray-500">
      No music terminology was found in this transcription.
    </div>

    <div v-else class="space-y-4">
      <!-- Category Summary -->
      <div class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200">
        <h4 class="font-medium text-gray-700 mb-3">Categories</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div v-for="(count, category) in categoryStats" :key="category" class="flex items-center justify-between p-2 rounded-md" :class="getCategoryClass(category)">
            <span class="font-medium">{{ formatCategoryName(category) }}</span>
            <span class="bg-white px-2 py-1 rounded-full text-sm font-medium shadow-sm">{{ count }}</span>
          </div>
        </div>
      </div>

      <!-- Terms by Category -->
      <div v-for="(terms, category) in filteredTermsByCategory" :key="category" class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200">
        <h4 class="font-medium text-gray-700 mb-3 flex items-center">
          <span class="w-3 h-3 rounded-full mr-2" :class="getCategoryColorClass(category)"></span>
          {{ formatCategoryName(category) }}
        </h4>
        <div class="flex flex-wrap gap-2">
          <span v-for="term in terms" :key="term" class="inline-block px-3 py-1 rounded-full text-sm font-medium bg-white shadow-sm border border-gray-200">
            {{ term }}
          </span>
        </div>
      </div>

      <!-- Term Instances -->
      <div v-if="showInstances && musicTermData.term_instances && musicTermData.term_instances.length > 0" class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200">
        <div class="flex justify-between items-center mb-3">
          <h4 class="font-medium text-gray-700">Term Mentions ({{ musicTermData.term_instances.length }})</h4>
          <button @click="showInstances = false" class="text-sm text-gray-500 hover:text-gray-700">
            Hide
          </button>
        </div>
        <div class="space-y-2 max-h-60 overflow-y-auto">
          <div v-for="(instance, index) in musicTermData.term_instances" :key="index" class="p-2 bg-white rounded border border-gray-200">
            <div class="flex justify-between">
              <span class="font-medium" :class="getCategoryTextClass(instance.category)">{{ instance.term }}</span>
              <span class="text-xs text-gray-500">{{ formatCategoryName(instance.category) }}</span>
            </div>
            <p class="text-sm text-gray-600 mt-1">
              "...{{ instance.context }}..."
            </p>
          </div>
        </div>
      </div>
      
      <div v-else class="flex justify-center">
        <button @click="showInstances = true" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
          Show Term Mentions ({{ musicTermData.term_instances ? musicTermData.term_instances.length : 0 }})
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';

// Props
const props = defineProps({
  musicTermsUrl: String,
  musicTermsMetadata: Object,
  musicTermCount: Number
});

// Reactive state
const musicTermData = ref(null);
const isLoading = ref(false);
const error = ref(null);
const showInstances = ref(false);

// Computed properties
const totalTermCount = computed(() => {
  if (musicTermData.value && musicTermData.value.total_terms) {
    return musicTermData.value.total_terms;
  }
  return props.musicTermCount || 0;
});

const filteredTermsByCategory = computed(() => {
  if (!musicTermData.value || !musicTermData.value.terms_by_category) {
    return {};
  }
  
  // Filter out categories with empty arrays
  const filtered = {};
  Object.entries(musicTermData.value.terms_by_category).forEach(([category, terms]) => {
    if (terms.length > 0) {
      filtered[category] = terms;
    }
  });
  
  return filtered;
});

const categoryStats = computed(() => {
  if (props.musicTermsMetadata && props.musicTermsMetadata.categories) {
    return props.musicTermsMetadata.categories;
  }
  if (musicTermData.value && musicTermData.value.terms_by_category) {
    const stats = {};
    Object.entries(musicTermData.value.terms_by_category).forEach(([category, terms]) => {
      if (terms.length > 0) {
        stats[category] = terms.length;
      }
    });
    return stats;
  }
  return {};
});

// Methods
const fetchMusicTermData = async () => {
  if (!props.musicTermsUrl) return;
  
  isLoading.value = true;
  error.value = null;
  
  try {
    const response = await fetch(props.musicTermsUrl);
    
    if (!response.ok) {
      throw new Error(`Failed to fetch music terms data (Status: ${response.status})`);
    }
    
    musicTermData.value = await response.json();
  } catch (err) {
    console.error('Error fetching music terms data:', err);
    error.value = err.message || 'Failed to load music terms data';
  } finally {
    isLoading.value = false;
  }
};

const formatCategoryName = (category) => {
  return category
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

const getCategoryClass = (category) => {
  switch (category) {
    case 'guitar_techniques': return 'bg-blue-50 text-blue-800 border border-blue-200';
    case 'guitar_parts': return 'bg-green-50 text-green-800 border border-green-200';
    case 'music_theory': return 'bg-purple-50 text-purple-800 border border-purple-200';
    case 'music_equipment': return 'bg-orange-50 text-orange-800 border border-orange-200';
    case 'performance_techniques': return 'bg-pink-50 text-pink-800 border border-pink-200';
    case 'musical_genres': return 'bg-indigo-50 text-indigo-800 border border-indigo-200';
    case 'recording_terms': return 'bg-cyan-50 text-cyan-800 border border-cyan-200';
    default: return 'bg-gray-50 text-gray-800 border border-gray-200';
  }
};

const getCategoryColorClass = (category) => {
  switch (category) {
    case 'guitar_techniques': return 'bg-blue-500';
    case 'guitar_parts': return 'bg-green-500';
    case 'music_theory': return 'bg-purple-500';
    case 'music_equipment': return 'bg-orange-500';
    case 'performance_techniques': return 'bg-pink-500';
    case 'musical_genres': return 'bg-indigo-500';
    case 'recording_terms': return 'bg-cyan-500';
    default: return 'bg-gray-500';
  }
};

const getCategoryTextClass = (category) => {
  switch (category) {
    case 'guitar_techniques': return 'text-blue-700';
    case 'guitar_parts': return 'text-green-700';
    case 'music_theory': return 'text-purple-700';
    case 'music_equipment': return 'text-orange-700';
    case 'performance_techniques': return 'text-pink-700';
    case 'musical_genres': return 'text-indigo-700';
    case 'recording_terms': return 'text-cyan-700';
    default: return 'text-gray-700';
  }
};

// Watch for changes in URL to reload data
watch(() => props.musicTermsUrl, (newUrl) => {
  if (newUrl) {
    fetchMusicTermData();
  }
});

// Lifecycle hooks
onMounted(() => {
  if (props.musicTermsUrl) {
    fetchMusicTermData();
  }
});
</script> 