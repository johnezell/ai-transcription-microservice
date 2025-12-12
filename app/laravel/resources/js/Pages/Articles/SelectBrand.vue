<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    brands: Object,
});
</script>

<template>
    <Head title="Select Brand - Article Generator" />

    <div class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 flex items-center justify-center p-4">
        <div class="max-w-5xl w-full">
            <!-- Header -->
            <div class="text-center mb-12">
                <h1 class="text-4xl font-bold text-gray-900 mb-3">Welcome!</h1>
                <p class="text-lg text-gray-600">Select a brand to start creating articles</p>
            </div>

            <!-- Brand Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Link
                    v-for="brand in brands"
                    :key="brand.id"
                    :href="route('articles.index', { brandId: brand.id })"
                    class="group relative bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 cursor-pointer border-2 border-transparent hover:border-blue-500 overflow-hidden"
                >
                    <!-- Background Pattern -->
                    <div class="absolute inset-0 opacity-5 group-hover:opacity-10 transition-opacity">
                        <div class="absolute inset-0" :style="{ backgroundColor: brand.primaryColor }"></div>
                    </div>

                    <!-- Content -->
                    <div class="relative p-8">
                        <!-- Logo Section -->
                        <div class="flex items-center justify-center h-24 mb-6">
                            <img
                                v-if="brand.logo"
                                :src="brand.logo"
                                :alt="brand.name"
                                class="max-h-full max-w-full object-contain filter group-hover:brightness-110 transition-all"
                            />
                            <div
                                v-else
                                class="text-4xl font-bold"
                                :style="{ color: brand.primaryColor }"
                            >
                                {{ brand.name }}
                            </div>
                        </div>

                        <!-- Brand Info -->
                        <div class="text-center">
                            <h3 class="text-2xl font-bold text-gray-900 mb-2">{{ brand.name }}</h3>
                            <p class="text-sm text-gray-600 mb-3">{{ brand.website }}</p>
                            <p class="text-gray-700">{{ brand.description }}</p>
                        </div>

                        <!-- Arrow Icon -->
                        <div class="absolute top-6 right-6 text-gray-400 group-hover:text-blue-500 group-hover:translate-x-1 transition-all">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </div>

                    <!-- Hover Effect Border -->
                    <div class="absolute inset-0 rounded-2xl ring-2 ring-blue-500 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none"></div>
                </Link>
            </div>

            <!-- Back to Dashboard Link -->
            <div class="text-center mt-8">
                <Link
                    :href="route('dashboard')"
                    class="text-gray-500 hover:text-gray-700 text-sm"
                >
                    ← Back to Dashboard
                </Link>
            </div>
        </div>
    </div>
</template>
