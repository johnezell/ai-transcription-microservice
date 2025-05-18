<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link, router } from '@inertiajs/vue3';
import SideNavigation from '@/Components/SideNavigation.vue';
import TopHeader from '@/Components/TopHeader.vue';

// This is the state that controls sidebar visibility
const showingSidebar = ref(false);

// Function to safely check if a route exists
const routeExists = (name) => {
    try {
        router.check(name);
        return true;
    } catch (e) {
        return false;
    }
};

// Route existence flags
const truefireIndexExists = routeExists('truefire.index');
const channelsIndexExists = routeExists('channels.index');

// Get current route for active state
const currentRoute = computed(() => route().current());

// Handle window resize and set initial sidebar state
const handleResize = () => {
    showingSidebar.value = window.innerWidth >= 768;
};

// Add and remove event listeners
onMounted(() => {
    handleResize(); // Set initial state
    window.addEventListener('resize', handleResize);
    
    // Log the initial state for debugging
    console.log('Initial sidebar state:', showingSidebar.value);
});

onUnmounted(() => {
    window.removeEventListener('resize', handleResize);
});

// Toggle sidebar visibility
const toggleSidebar = () => {
    showingSidebar.value = !showingSidebar.value;
    console.log('Toggled sidebar:', showingSidebar.value); // Debug log
};

// Close sidebar (mobile only)
const closeSidebar = () => {
    if (window.innerWidth < 768) {
        showingSidebar.value = false;
    }
};

// Handle search
const handleSearch = (query) => {
    // Placeholder for search functionality
    console.log('Search query:', query);
};
</script>

<template>
    <div>
        <!-- Side Navigation -->
        <SideNavigation 
            :isOpen="showingSidebar"
            :currentRoute="currentRoute"
            :user="$page.props.auth.user"
            :truefireIndexExists="truefireIndexExists"
            :channelsIndexExists="channelsIndexExists"
            @close="closeSidebar"
        />
        
        <!-- Top Header with toggle button -->
        <TopHeader 
            :user="$page.props.auth.user"
            @toggle-sidebar="toggleSidebar"
        />
        
        <div class="min-h-screen bg-gray-100">
            <!-- Main Content Area -->
            <main :class="['main-content', { 'sidebar-open': showingSidebar }]">
                <!-- Page Heading -->
                <header
                    class="bg-white shadow"
                    v-if="$slots.header"
                >
                    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        <slot name="header" />
                    </div>
                </header>

                <!-- Page Content -->
                <div class="page-content">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped>
.main-content {
    transition: margin-left 0.3s ease-in-out;
    margin-top: 4rem;
}

@media (min-width: 768px) {
    .main-content {
        margin-left: 250px;
    }
}

@media (min-width: 1024px) {
    .main-content {
        margin-left: 280px;
    }
}

.page-content {
    padding: 1.5rem;
}
</style>
