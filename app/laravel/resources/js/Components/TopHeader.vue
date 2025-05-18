<template>
  <header class="top-header">
    <!-- Left side: Menu toggle -->
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
    
    <!-- Center section: Search -->
    <div class="center-section">
      <div class="search-container">
        <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input 
          type="text" 
          class="search-input" 
          placeholder="Search videos, courses..."
          v-model="searchQuery"
          @keyup.enter="performSearch"
        />
      </div>
    </div>
    
    <!-- Right side: Quick actions and user menu -->
    <div class="right-section">
      <div class="quick-actions">
        <Link :href="route('videos.create')" class="quick-action-btn primary" title="Upload Video">
          <svg class="w-5 h-5 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
          </svg>
          <span class="quick-action-text">Upload</span>
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
              <svg class="w-5 h-5 mr-2 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              Profile
            </Link>
            <Link :href="route('dashboard')" class="user-dropdown-item">
              <svg class="w-5 h-5 mr-2 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
              </svg>
              Dashboard
            </Link>
            <form @submit.prevent="logout" class="w-full">
              <button type="submit" class="user-dropdown-item text-left w-full">
                <svg class="w-5 h-5 mr-2 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
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

const emit = defineEmits(['toggle-sidebar', 'search']);

// Toggle sidebar by emitting event to parent
function toggleSidebar() {
  console.log('TopHeader: Toggle sidebar button clicked'); // Debug log
  emit('toggle-sidebar');
}

// Search functionality
const searchQuery = ref('');

function performSearch() {
  if (searchQuery.value.trim()) {
    console.log('Searching for:', searchQuery.value);
    emit('search', searchQuery.value);
    
    // Navigate to videos page with search query
    router.get(route('videos.index', { search: searchQuery.value }));
  }
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
    padding: 0 1.5rem;
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
  display: none;
  justify-content: center;
  padding: 0 1rem;
}

@media (min-width: 768px) {
  .center-section {
    display: flex;
  }
}

.search-container {
  position: relative;
  max-width: 28rem;
  width: 100%;
}

.search-icon {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  width: 1.25rem;
  height: 1.25rem;
  color: #9ca3af;
}

.search-input {
  width: 100%;
  padding: 0.5rem 1rem 0.5rem 2.5rem;
  border-radius: 9999px;
  border: 1px solid #e5e7eb;
  background-color: #f9fafb;
  font-size: 0.875rem;
  transition: all 0.15s ease-in-out;
}

.search-input:focus {
  outline: none;
  border-color: #4f46e5;
  background-color: white;
  box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
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
  border-radius: 0.375rem;
  background-color: #f3f4f6;
  color: #4b5563;
  border: none;
  cursor: pointer;
  transition: all 0.15s ease-in-out;
  text-decoration: none;
}

.quick-action-btn:hover {
  background-color: #e5e7eb;
  color: #1f2937;
}

.quick-action-btn.primary {
  background-color: #4f46e5;
  color: white;
  padding: 0.5rem 1rem;
}

.quick-action-btn.primary:hover {
  background-color: #4338ca;
  color: white;
}

.quick-action-text {
  display: none;
  margin-left: 0.25rem;
}

@media (min-width: 640px) {
  .quick-action-text {
    display: inline;
  }
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
  display: flex;
  align-items: center;
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