<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  tabs: {
    type: Array,
    required: true,
    // Each tab should have: { id: string, label: string, icon?: string }
  },
  activeTab: {
    type: String,
    default: null,
  },
  modelValue: {
    type: String,
    default: null,
  },
  defaultTab: {
    type: String,
    default: null,
  },
  cardStyle: {
    type: Boolean,
    default: true,
  }
});

const emit = defineEmits(['tab-change', 'update:modelValue', 'update:activeTab']);

// Get the initial tab value, preferring modelValue (for v-model binding) over activeTab or defaultTab
const internalActiveTab = ref(props.modelValue || props.activeTab || props.defaultTab || (props.tabs.length > 0 ? props.tabs[0].id : null));

// Watch for changes to the activeTab or modelValue props
watch(() => props.activeTab, (newValue) => {
  if (newValue !== null && newValue !== internalActiveTab.value) {
    internalActiveTab.value = newValue;
  }
});

watch(() => props.modelValue, (newValue) => {
  if (newValue !== null && newValue !== internalActiveTab.value) {
    internalActiveTab.value = newValue;
  }
});

const setActiveTab = (tabId) => {
  internalActiveTab.value = tabId;
  // Emit all possible events for backward compatibility
  emit('tab-change', tabId);
  emit('update:modelValue', tabId);
  emit('update:activeTab', tabId);
};
</script>

<template>
  <div>
    <!-- Tab Navigation -->
    <div class="mb-4 border-b border-gray-200">
      <nav class="-mb-px flex space-x-4" aria-label="Tabs">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="setActiveTab(tab.id)"
          :class="[
            'py-3 px-4 text-sm font-medium border-b-2 transition-colors duration-200',
            internalActiveTab === tab.id
              ? 'border-indigo-500 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          ]"
          :aria-current="internalActiveTab === tab.id ? 'page' : undefined"
        >
          <div class="flex items-center">
            <span v-if="tab.icon" class="mr-2">
              <component :is="tab.icon" class="h-4 w-4" aria-hidden="true" />
            </span>
            {{ tab.label }}
          </div>
        </button>
      </nav>
    </div>

    <!-- Tab Content -->
    <div v-if="cardStyle" class="bg-white rounded-md shadow-sm p-4">
      <slot :activeTab="internalActiveTab"></slot>
    </div>
    <div v-else>
      <slot :activeTab="internalActiveTab"></slot>
    </div>
  </div>
</template> 