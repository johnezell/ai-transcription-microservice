<template>
  <div>
    <h3 class="text-lg font-medium mb-4 flex items-center">
      <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
      </svg>
      Term Recognition ({{ displayedTotalTermCount }})
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

    <!-- Category Summary - Show if categoryStats has keys, independent of full terminologyData loading for this part -->
    <div v-if="hasCategoryStats" class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200 mb-4">
      <h4 class="font-medium text-gray-700 mb-3">Categories</h4>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div v-for="(count, category) in categoryStats" :key="category" class="flex items-center justify-between p-2 rounded-md" :class="getCategoryClass(category)">
          <span class="font-medium">{{ formatCategoryName(category) }}</span>
          <span class="bg-white px-2 py-1 rounded-full text-sm font-medium shadow-sm">{{ count }}</span>
        </div>
      </div>
    </div>

    <!-- Detailed Term Data - Show only if terminologyData is loaded -->
    <div v-if="terminologyData && terminologyDataLoadedSuccessfully">
      <!-- Terms by Category -->
      <div v-for="(terms, category) in filteredTermsByCategory" :key="category" class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200 mb-4">
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
      
      <div v-else-if="terminologyData.term_instances && terminologyData.term_instances.length > 0" class="flex justify-center mt-4">
        <button @click="showInstances = true" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
          Show Term Mentions ({{ terminologyData.term_instances.length }})
        </button>
      </div>

       <div v-else-if="!filteredTermsByCategory || Object.keys(filteredTermsByCategory).length === 0" class="p-4 bg-gray-50 rounded-md text-gray-500 text-sm">
        Detailed term list not available or empty in the fetched data.
      </div>
    </div>

    <!-- Message if no categories from metadata AND no detailed data loaded/found -->
    <div v-else-if="!hasCategoryStats && !terminologyDataLoadedSuccessfully && !isLoading && !error" class="p-4 bg-gray-50 rounded-md text-gray-500">
      No terminology information available.
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
const terminologyDataLoadedSuccessfully = ref(false);

// Log incoming props
// console.log('[TerminologyViewer PROPS] terminologyMetadata:', props.terminologyMetadata);
// console.log('[TerminologyViewer PROPS] musicTermsMetadata:', props.musicTermsMetadata);

// Computed properties
const categoryStats = computed(() => {
  // console.log('[TerminologyViewer categoryStats] Evaluating. Props.terminologyMetadata:', props.terminologyMetadata);
  if (props.terminologyMetadata && props.terminologyMetadata.categories) {
    // console.log('[TerminologyViewer categoryStats] Using props.terminologyMetadata.categories:', props.terminologyMetadata.categories);
    return props.terminologyMetadata.categories;
  } else if (props.musicTermsMetadata && props.musicTermsMetadata.categories) {
    // console.log('[TerminologyViewer categoryStats] Using props.musicTermsMetadata.categories:', props.musicTermsMetadata.categories);
    return props.musicTermsMetadata.categories;
  }
  
  if (terminologyData.value && terminologyData.value.terms_by_category) {
    const stats = {};
    Object.entries(terminologyData.value.terms_by_category).forEach(([category, terms]) => {
      if (terms.length > 0) {
        stats[category] = terms.length;
      }
    });
    // console.log('[TerminologyViewer categoryStats] Calculating stats from terminologyData.terms_by_category:', stats);
    if (Object.keys(stats).length > 0) return stats;
  }
  // console.log('[TerminologyViewer categoryStats] Returning empty object.');
  return {};
});

const hasCategoryStats = computed(() => {
  return Object.keys(categoryStats.value).length > 0;
});

const displayedTotalTermCount = computed(() => {
  if (terminologyData.value && typeof terminologyData.value.total_terms === 'number') {
    return terminologyData.value.total_terms;
  }
  if (typeof props.terminologyCount === 'number') {
    return props.terminologyCount;
  }
  if (typeof props.musicTermCount === 'number') {
    return props.musicTermCount;
  }
  // If no explicit total count, sum from categoryStats as a last resort for display
  if (hasCategoryStats.value) {
    return Object.values(categoryStats.value).reduce((sum, count) => sum + count, 0);
  }
  return 0;
});

const filteredTermsByCategory = computed(() => {
  // console.log('[TerminologyViewer] filteredTermsByCategory: Evaluating. terminologyData.value:', terminologyData.value);

  if (!terminologyData.value || !Array.isArray(terminologyData.value.terms) || terminologyData.value.terms.length === 0) {
    // console.log('[TerminologyViewer] filteredTermsByCategory: No terminologyData.terms array found or it is empty. Returning {}.');
    return {};
  }

  const groupedByCategory = {};

  // Iterate over the flat 'terms' array from the API response
  terminologyData.value.terms.forEach(termObject => {
    if (termObject && termObject.category_slug && termObject.term) {
      const category = termObject.category_slug;
      if (!groupedByCategory[category]) {
        groupedByCategory[category] = [];
      }
      // Add the term string to the appropriate category array
      // Assuming the template expects an array of term strings for each category
      groupedByCategory[category].push(termObject.term); 
    }
  });

  // Filter out categories with empty arrays (though the above logic should prevent empty arrays if terms exist)
  const filtered = {};
  Object.entries(groupedByCategory).forEach(([category, terms]) => {
    if (terms.length > 0) {
      filtered[category] = terms;
    }
  });
  
  // console.log('[TerminologyViewer] filteredTermsByCategory: Result from processing flat terms list:', filtered);
  return filtered;
});

// Methods
const fetchTerminologyData = async () => {
  const url = props.terminologyApiUrl || props.terminologyUrl || props.musicTermsUrl;
  if (!url) {
    terminologyDataLoadedSuccessfully.value = false; // Explicitly set if no URL
    return;
  }
  
  isLoading.value = true;
  error.value = null;
  terminologyDataLoadedSuccessfully.value = false;
  
  try {
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`Failed to fetch terminology data (Status: ${response.status})`);
    }
    terminologyData.value = await response.json();
    terminologyDataLoadedSuccessfully.value = true; // Set on successful fetch and parse
    // console.log('[TerminologyViewer] Fetched terminologyData:', terminologyData.value);
  } catch (err) {
    console.error('Error fetching terminology data:', err);
    error.value = err.message || 'Failed to load terminology data';
    terminologyDataLoadedSuccessfully.value = false;
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
}, { immediate: true }); // Add immediate: true to run on mount if URL is already present

// onMounted is no longer strictly needed for initial fetch due to watch immediate:true
// onMounted(() => {
//   if (props.terminologyUrl || props.musicTermsUrl) {
//     fetchTerminologyData();
//   }
// });
</script> 