<template>
  <header class="top-header">
    <!-- Left side: Menu toggle only (logo removed) -->
    <div class="left-section">
      <button 
        class="menu-toggle" 
        @click="toggleSidebar"
        aria-label="Toggle menu"
      >
        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      </button>
    </div>
    
    <!-- Empty center section (search removed) -->
    <div class="center-section"></div>
    
    <!-- Right side: Quick actions and user menu -->
    <div class="right-section">
      <div class="quick-actions">
        <Link :href="route('videos.create')" class="quick-action-btn" title="Upload Video">
          <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
          </svg>
        </Link>
      </div>
      
      <div class="user-menu">
        <button @click="showingUserMenu = !showingUserMenu" class="user-menu-btn">
          <span class="sr-only">Open user menu</span>
          <div class="user-avatar">
            {{ user?.name?.charAt(0) || 'U' }}
          </div>
        </button>
        
        <!-- User dropdown menu -->
        <div v-show="showingUserMenu" class="user-dropdown">
          <div class="user-dropdown-header">
            <div class="user-name">{{ user?.name }}</div>
            <div class="user-email">{{ user?.email }}</div>
          </div>
          
          <div class="user-dropdown-items">
            <Link :href="route('profile.edit')" class="user-dropdown-item">
              Profile
            </Link>
            <form @submit.prevent="logout" class="w-full">
              <button type="submit" class="user-dropdown-item text-left w-full">
                Log Out
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
  user: {
    type: Object,
    required: true
  }
});

const emit = defineEmits(['toggle-sidebar']);

// Toggle sidebar by emitting event to parent
function toggleSidebar() {
  console.log('TopHeader: Toggle sidebar button clicked'); // Debug log
  emit('toggle-sidebar');
}

const showingUserMenu = ref(false);

// Function to handle logout
function logout() {
  router.post(route('logout'));
}

// Close the user menu when clicking outside
const closeUserMenuOnOutsideClick = (event) => {
  if (showingUserMenu.value && !event.target.closest('.user-menu')) {
    showingUserMenu.value = false;
  }
};

// Add and remove event listener
onMounted(() => {
  window.addEventListener('click', closeUserMenuOnOutsideClick);
});

onUnmounted(() => {
  window.removeEventListener('click', closeUserMenuOnOutsideClick);
});
</script>

<style scoped>
.top-header {
  height: 4rem;
  background-color: white;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 1rem;
  position: fixed;
  top: 0;
  right: 0;
  left: 0;
  z-index: 90; /* Lower than sidebar's z-index */
}

@media (min-width: 768px) {
  .top-header {
    left: 250px;
  }
}

@media (min-width: 1024px) {
  .top-header {
    left: 280px;
  }
}

.left-section {
  display: flex;
  align-items: center;
}

.menu-toggle {
  color: #6b7280;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 0.5rem;
  border-radius: 0.375rem;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background-color 0.15s ease-in-out;
}

.menu-toggle:hover {
  background-color: #f3f4f6;
  color: #1f2937;
}

.center-section {
  flex: 1;
}

.right-section {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.quick-actions {
  display: flex;
  gap: 0.5rem;
}

.quick-action-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem;
  border-radius: 9999px;
  background-color: #f3f4f6;
  color: #4b5563;
  border: none;
  cursor: pointer;
  transition: all 0.15s ease-in-out;
}

.quick-action-btn:hover {
  background-color: #e5e7eb;
  color: #1f2937;
}

.user-menu {
  position: relative;
}

.user-menu-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  background: transparent;
  border: none;
  padding: 0;
}

.user-avatar {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 9999px;
  background-color: #4f46e5;
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 0.875rem;
}

.user-dropdown {
  position: absolute;
  right: 0;
  top: 100%;
  margin-top: 0.5rem;
  width: 15rem;
  background-color: white;
  border-radius: 0.375rem;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
  overflow: hidden;
  z-index: 50;
  border: 1px solid #e5e7eb;
}

.user-dropdown-header {
  padding: 1rem;
  border-bottom: 1px solid #e5e7eb;
}

.user-name {
  font-weight: 600;
  color: #1f2937;
  font-size: 0.875rem;
}

.user-email {
  color: #6b7280;
  font-size: 0.75rem;
  margin-top: 0.25rem;
}

.user-dropdown-items {
  padding: 0.5rem 0;
}

.user-dropdown-item {
  display: block;
  padding: 0.5rem 1rem;
  color: #4b5563;
  font-size: 0.875rem;
  transition: background-color 0.15s ease-in-out;
  text-decoration: none;
}

.user-dropdown-item:hover {
  background-color: #f3f4f6;
  color: #1f2937;
}

/* Hide menu toggle on desktop */
@media (min-width: 768px) {
  .menu-toggle {
    display: none;
  }
}
</style> 