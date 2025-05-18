<template>
  <aside :class="['sidebar', { 'open': isOpen }]">
    <!-- Close button for mobile -->
    <button class="close-btn" @click="$emit('close')" aria-label="Close menu">
      <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
    
    <!-- Logo for sidebar -->
    <div class="sidebar-logo">
      <Link :href="route('videos.index')">
        <ApplicationLogo class="block h-9 w-auto fill-current text-gray-800" />
      </Link>
    </div>
    
    <!-- Navigation sections -->
    <nav class="sidebar-nav">
      <NavSection title="Main">
        <NavItem 
          icon="dashboard"
          label="Dashboard"
          :href="route('dashboard')"
          :active="currentRoute === 'dashboard'"
        />
      </NavSection>
      
      <NavSection title="Content">
        <NavItem 
          icon="video"
          label="Videos"
          :href="route('videos.index')"
          :active="currentRoute.startsWith('videos')"
        />
        <NavItem 
          icon="courses"
          label="Courses"
          :href="route('courses.index')"
          :active="currentRoute.startsWith('courses')"
        />
      </NavSection>
      
      <NavSection v-if="truefireIndexExists || channelsIndexExists" title="Import">
        <NavItem 
          v-if="truefireIndexExists"
          icon="truefire"
          label="TrueFire Courses"
          :href="route('truefire.index')"
          :active="currentRoute.startsWith('truefire')"
        />
        <NavItem 
          v-if="channelsIndexExists"
          icon="channels"
          label="Channel Content"
          :href="route('channels.index')"
          :active="currentRoute.startsWith('channels')"
        />
      </NavSection>
      
      <NavSection title="Administration">
        <NavItem 
          icon="terminology"
          label="Terminology"
          :href="route('admin.terminology.index')"
          :active="currentRoute.startsWith('admin.terminology')"
        />
        <NavItem 
          icon="ideas"
          label="Enhancement Ideas"
          :href="route('enhancement-ideas.index')"
          :active="currentRoute.startsWith('enhancement-ideas')"
        />
      </NavSection>
    </nav>
  </aside>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import NavSection from '@/Components/NavSection.vue';
import NavItem from '@/Components/NavItem.vue';

defineProps({
  isOpen: {
    type: Boolean,
    default: false
  },
  currentRoute: {
    type: String,
    required: true
  },
  user: {
    type: Object,
    required: true
  },
  truefireIndexExists: {
    type: Boolean,
    default: false
  },
  channelsIndexExists: {
    type: Boolean,
    default: false
  }
});

defineEmits(['close']);
</script>

<style scoped>
.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  height: 100vh;
  width: 250px;
  background-color: white;
  border-right: 1px solid #e5e7eb;
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
  z-index: 100;
  transform: translateX(-100%);
  transition: transform 0.3s ease-in-out;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

.sidebar.open {
  transform: translateX(0);
  box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
}

.close-btn {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  color: #6b7280;
  background: transparent;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.25rem;
  border-radius: 0.25rem;
  transition: background-color 0.15s ease-in-out;
}

.close-btn:hover {
  background-color: #f3f4f6;
  color: #1f2937;
}

.sidebar-logo {
  padding: 1rem;
  display: flex;
  justify-content: center;
  align-items: center;
  border-bottom: 1px solid #e5e7eb;
}

.sidebar-nav {
  flex: 1;
  padding: 1rem 0;
}

/* When screen is at least 768px wide (tablet and above) */
@media (min-width: 768px) {
  .sidebar {
    transform: translateX(0); /* Always show sidebar */
  }
  
  .close-btn {
    display: none; /* Hide the close button on desktop */
  }
}

/* When screen is at least 1024px wide (desktop) */
@media (min-width: 1024px) {
  .sidebar {
    width: 280px; /* Slightly wider sidebar on desktop */
  }
}
</style> 