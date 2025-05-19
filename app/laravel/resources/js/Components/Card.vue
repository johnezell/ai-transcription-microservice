<script setup>
import { ref } from 'vue';

const props = defineProps({
  title: {
    type: String,
    required: false
  },
  collapsible: {
    type: Boolean,
    default: false
  },
  defaultCollapsed: {
    type: Boolean,
    default: false
  },
  headerActions: {
    type: Boolean,
    default: false
  }
});

const collapsed = ref(props.defaultCollapsed);

const toggleCollapse = () => {
  collapsed.value = !collapsed.value;
};
</script>

<template>
  <div class="bg-white rounded-md shadow-sm mb-6 overflow-hidden">
    <div v-if="title || $slots.header || headerActions" 
         class="px-4 py-5 sm:px-6 border-b border-gray-200 flex justify-between items-center">
      <div class="flex-1">
        <h3 v-if="title" class="text-lg font-medium text-gray-900">{{ title }}</h3>
        <slot v-if="$slots.header" name="header"></slot>
      </div>
      
      <div v-if="headerActions || collapsible" class="flex items-center">
        <slot v-if="headerActions" name="actions"></slot>
        <button 
          v-if="collapsible" 
          @click="toggleCollapse"
          class="ml-2 text-gray-400 hover:text-gray-500 focus:outline-none"
          :aria-expanded="!collapsed"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform" :class="{ 'rotate-180': collapsed }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
      </div>
    </div>
    
    <div v-if="!collapsed || !collapsible" class="px-4 py-5 sm:p-6">
      <slot></slot>
    </div>
  </div>
</template> 