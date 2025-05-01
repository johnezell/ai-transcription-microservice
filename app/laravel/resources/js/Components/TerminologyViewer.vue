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
      <p class="font-medium">Error loading terminology</p>
      <p>{{ error }}</p>
    </div>

    <div v-else-if="!terminologyData || totalTermCount === 0" class="p-4 bg-gray-50 rounded-md text-gray-500">
      No terminology was found in this transcription.
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
      <div v-if="showInstances && terminologyData.term_instances && terminologyData.term_instances.length > 0" class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200">
        <div class="flex justify-between items-center mb-3">
          <h4 class="font-medium text-gray-700">Term Mentions ({{ terminologyData.term_instances.length }})</h4>
          <button @click="showInstances = false" class="text-sm text-gray-500 hover:text-gray-700">
            Hide
          </button>
        </div>
        <div class="space-y-2 max-h-60 overflow-y-auto">
          <div v-for="(instance, index) in terminologyData.term_instances" :key="index" class="p-2 bg-white rounded border border-gray-200">
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
          Show Term Mentions ({{ terminologyData.term_instances ? terminologyData.term_instances.length : 0 }})
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';

// Props
const props = defineProps({
  terminologyUrl: String,
  terminologyApiUrl: String,
  terminologyMetadata: Object,
  terminologyCount: Number,
  // For backward compatibility
  musicTermsUrl: String,
  musicTermsMetadata: Object,
  musicTermCount: Number
});

// Reactive state
const terminologyData = ref(null);
const isLoading = ref(false);
const error = ref(null);
const showInstances = ref(false);

// Computed properties
const totalTermCount = computed(() => {
  if (terminologyData.value && terminologyData.value.total_terms) {
    return terminologyData.value.total_terms;
  }
  return props.terminologyCount || props.musicTermCount || 0;
});

const filteredTermsByCategory = computed(() => {
  if (!terminologyData.value || !terminologyData.value.terms_by_category) {
    return {};
  }
  
  // Filter out categories with empty arrays
  const filtered = {};
  Object.entries(terminologyData.value.terms_by_category).forEach(([category, terms]) => {
    if (terms.length > 0) {
      filtered[category] = terms;
    }
  });
  
  return filtered;
});

const categoryStats = computed(() => {
  if (props.terminologyMetadata && props.terminologyMetadata.categories) {
    return props.terminologyMetadata.categories;
  } else if (props.musicTermsMetadata && props.musicTermsMetadata.categories) {
    return props.musicTermsMetadata.categories;
  }
  
  if (terminologyData.value && terminologyData.value.terms_by_category) {
    const stats = {};
    Object.entries(terminologyData.value.terms_by_category).forEach(([category, terms]) => {
      if (terms.length > 0) {
        stats[category] = terms.length;
      }
    });
    return stats;
  }
  return {};
});

// Methods
const fetchTerminologyData = async () => {
  // Prefer API URL if available, then fall back to file URLs
  const url = props.terminologyApiUrl || props.terminologyUrl || props.musicTermsUrl;
  if (!url) return;
  
  isLoading.value = true;
  error.value = null;
  
  try {
    const response = await fetch(url);
    
    if (!response.ok) {
      throw new Error(`Failed to fetch terminology data (Status: ${response.status})`);
    }
    
    terminologyData.value = await response.json();
  } catch (err) {
    console.error('Error fetching terminology data:', err);
    error.value = err.message || 'Failed to load terminology data';
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
  // Generic color assignment based on category name
  const colorMapping = {
    // Technology domain
    'programming_languages': 'bg-blue-50 text-blue-800 border border-blue-200',
    'frameworks': 'bg-green-50 text-green-800 border border-green-200',
    'databases': 'bg-purple-50 text-purple-800 border border-purple-200',
    'tools': 'bg-orange-50 text-orange-800 border border-orange-200',
    
    // Legacy music domain (for backward compatibility)
    'guitar_techniques': 'bg-blue-50 text-blue-800 border border-blue-200',
    'guitar_parts': 'bg-green-50 text-green-800 border border-green-200',
    'music_theory': 'bg-purple-50 text-purple-800 border border-purple-200',
    'music_equipment': 'bg-orange-50 text-orange-800 border border-orange-200',
    'performance_techniques': 'bg-pink-50 text-pink-800 border border-pink-200',
    'musical_genres': 'bg-indigo-50 text-indigo-800 border border-indigo-200',
    'recording_terms': 'bg-cyan-50 text-cyan-800 border border-cyan-200',
  };
  
  return colorMapping[category] || 'bg-gray-50 text-gray-800 border border-gray-200';
};

const getCategoryColorClass = (category) => {
  // Generic color mapping
  const colorMapping = {
    // Technology domain
    'programming_languages': 'bg-blue-500',
    'frameworks': 'bg-green-500',
    'databases': 'bg-purple-500',
    'tools': 'bg-orange-500',
    
    // Legacy music domain (for backward compatibility)
    'guitar_techniques': 'bg-blue-500',
    'guitar_parts': 'bg-green-500',
    'music_theory': 'bg-purple-500',
    'music_equipment': 'bg-orange-500',
    'performance_techniques': 'bg-pink-500',
    'musical_genres': 'bg-indigo-500',
    'recording_terms': 'bg-cyan-500',
  };
  
  return colorMapping[category] || 'bg-gray-500';
};

const getCategoryTextClass = (category) => {
  // Generic text color mapping
  const colorMapping = {
    // Technology domain
    'programming_languages': 'text-blue-700',
    'frameworks': 'text-green-700',
    'databases': 'text-purple-700',
    'tools': 'text-orange-700',
    
    // Legacy music domain (for backward compatibility)
    'guitar_techniques': 'text-blue-700',
    'guitar_parts': 'text-green-700',
    'music_theory': 'text-purple-700',
    'music_equipment': 'text-orange-700',
    'performance_techniques': 'text-pink-700',
    'musical_genres': 'text-indigo-700',
    'recording_terms': 'text-cyan-700',
  };
  
  return colorMapping[category] || 'text-gray-700';
};

// Watch for changes in URL to reload data
watch(() => props.terminologyApiUrl || props.terminologyUrl || props.musicTermsUrl, (newUrl) => {
  if (newUrl) {
    fetchTerminologyData();
  }
});

// Lifecycle hooks
onMounted(() => {
  if (props.terminologyUrl || props.musicTermsUrl) {
    fetchTerminologyData();
  }
});
</script> 