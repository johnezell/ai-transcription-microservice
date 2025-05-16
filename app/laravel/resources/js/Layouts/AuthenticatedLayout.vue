<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
const showingImportSourcesDropdown = ref(false);

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

// Function to close dropdown when clicking outside
const closeDropdownOnOutsideClick = (event) => {
    // Only close if dropdown is open and click is outside the dropdown
    if (showingImportSourcesDropdown.value && !event.target.closest('.import-sources-dropdown')) {
        showingImportSourcesDropdown.value = false;
    }
};

// Add and remove event listener
onMounted(() => {
    window.addEventListener('click', closeDropdownOnOutsideClick);
});

onUnmounted(() => {
    window.removeEventListener('click', closeDropdownOnOutsideClick);
});
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav
                class="border-b border-gray-100 bg-white"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('videos.index')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('videos.index')"
                                    :active="route().current('videos.*')"
                                >
                                    Videos
                                </NavLink>
                                <NavLink
                                    :href="route('courses.index')"
                                    :active="route().current('courses.*')"
                                >
                                    Courses
                                </NavLink>
                                
                                <!-- Import Sources Dropdown -->
                                <div v-if="truefireIndexExists || channelsIndexExists" class="hidden sm:flex sm:items-center">
                                    <div class="relative import-sources-dropdown">
                                        <button 
                                            @click.stop="showingImportSourcesDropdown = !showingImportSourcesDropdown" 
                                            class="inline-flex items-center px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out border border-transparent rounded-md hover:text-gray-700 focus:outline-none"
                                            :class="{ 'text-indigo-600': (truefireIndexExists && route().current('truefire.*')) || (channelsIndexExists && route().current('channels.*')) }"
                                        >
                                            Import Sources
                                            <svg class="ml-2 -mr-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                            </svg>
                                        </button>

                                        <div 
                                            v-show="showingImportSourcesDropdown" 
                                            class="absolute z-50 mt-2 w-48 rounded-md shadow-lg origin-top-right right-0"
                                        >
                                            <div class="rounded-md ring-1 ring-black ring-opacity-5 py-1 bg-white">
                                                <Link 
                                                    v-if="truefireIndexExists"
                                                    :href="route('truefire.index')" 
                                                    class="block px-4 py-2 text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
                                                    :class="{ 'bg-gray-100': route().current('truefire.index') }"
                                                >
                                                    TrueFire Courses
                                                </Link>
                                                <Link 
                                                    v-if="channelsIndexExists"
                                                    :href="route('channels.index')" 
                                                    class="block px-4 py-2 text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
                                                    :class="{ 'bg-gray-100': route().current('channels.index') }"
                                                >
                                                    Channel Content
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <NavLink
                                    :href="route('enhancement-ideas.index')"
                                    :active="route().current('enhancement-ideas.*')"
                                >
                                    Enhancement Ideas
                                </NavLink>
                                <NavLink
                                    :href="route('admin.terminology.index')"
                                    :active="route().current('admin.terminology.*')"
                                >
                                    Terminology
                                </NavLink>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('videos.index')"
                            :active="route().current('videos.*')"
                        >
                            Videos
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('courses.index')"
                            :active="route().current('courses.*')"
                        >
                            Courses
                        </ResponsiveNavLink>
                        
                        <!-- Import Sources Section -->
                        <div v-if="truefireIndexExists || channelsIndexExists" class="pt-4 pb-1 border-t border-gray-200">
                            <div class="px-4">
                                <div class="font-medium text-base text-gray-800">Import Sources</div>
                            </div>
                            <div class="mt-3 space-y-1">
                                <ResponsiveNavLink
                                    v-if="truefireIndexExists"
                                    :href="route('truefire.index')"
                                    :active="route().current('truefire.index')"
                                >
                                    TrueFire Courses
                                </ResponsiveNavLink>
                                <ResponsiveNavLink
                                    v-if="channelsIndexExists"
                                    :href="route('channels.index')"
                                    :active="route().current('channels.index')"
                                >
                                    Channel Content
                                </ResponsiveNavLink>
                            </div>
                        </div>
                        
                        <ResponsiveNavLink
                            :href="route('enhancement-ideas.index')"
                            :active="route().current('enhancement-ideas.*')"
                        >
                            Enhancement Ideas
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('admin.terminology.index')"
                            :active="route().current('admin.terminology.*')"
                        >
                            Terminology
                        </ResponsiveNavLink>
                    </div>
                </div>
            </nav>

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
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
