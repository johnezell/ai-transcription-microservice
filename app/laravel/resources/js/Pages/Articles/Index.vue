<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    articles: Object,
    currentBrand: String,
    brands: Object,
});

const searchQuery = ref('');
const showBrandDropdown = ref(false);

const currentBrandData = computed(() => props.brands[props.currentBrand] || props.brands.truefire);

const filteredArticles = computed(() => {
    if (!searchQuery.value) return props.articles.data;
    
    const query = searchQuery.value.toLowerCase();
    return props.articles.data.filter(article => 
        article.title.toLowerCase().includes(query) ||
        article.created_by?.toLowerCase().includes(query) ||
        article.status.toLowerCase().includes(query)
    );
});

const switchBrand = (brandId) => {
    router.get(route('articles.index', { brandId }));
    showBrandDropdown.value = false;
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
};

const getStatusClass = (status) => {
    const classes = {
        generating: 'bg-blue-100 text-blue-800',
        draft: 'bg-yellow-100 text-yellow-800',
        published: 'bg-green-100 text-green-800',
        archived: 'bg-gray-100 text-gray-800',
        error: 'bg-red-100 text-red-800',
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
};
</script>

<template>
    <Head :title="`${currentBrandData.name} Article Generator`" />

    <div class="min-h-screen bg-gray-50">
        <!-- Navigation Header -->
        <nav class="bg-white shadow-sm border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900">
                            {{ currentBrandData.name }} Article Generator
                        </h1>

                        <!-- Brand Selector -->
                        <div class="relative">
                            <button
                                @click="showBrandDropdown = !showBrandDropdown"
                                class="flex items-center gap-2 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors text-sm font-medium text-gray-700 border border-gray-300"
                            >
                                <img
                                    v-if="currentBrandData.logo"
                                    :src="currentBrandData.logo"
                                    :alt="currentBrandData.name"
                                    class="h-5 object-contain"
                                />
                                <span v-else>{{ currentBrandData.name }}</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <!-- Brand Dropdown -->
                            <div
                                v-if="showBrandDropdown"
                                class="absolute left-0 mt-2 w-56 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-20"
                            >
                                <div class="px-3 py-2 border-b border-gray-100">
                                    <p class="text-xs text-gray-500 uppercase font-semibold">Switch Brand</p>
                                </div>
                                <button
                                    v-for="(brand, id) in brands"
                                    :key="id"
                                    @click="switchBrand(id)"
                                    class="w-full text-left px-4 py-3 hover:bg-gray-50 transition-colors flex items-center justify-between"
                                    :class="id === currentBrand ? 'bg-blue-50' : ''"
                                >
                                    <img
                                        v-if="brand.logo"
                                        :src="brand.logo"
                                        :alt="brand.name"
                                        class="h-6 object-contain"
                                    />
                                    <span v-else class="text-sm font-medium text-gray-900">{{ brand.name }}</span>
                                    <svg
                                        v-if="id === currentBrand"
                                        class="w-5 h-5 text-blue-600"
                                        fill="currentColor"
                                        viewBox="0 0 20 20"
                                    >
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <Link
                            :href="route('articles.settings', { brandId: currentBrand })"
                            class="text-sm text-gray-600 hover:text-gray-900"
                        >
                            Settings
                        </Link>
                        <Link
                            :href="route('articles.create', { brandId: currentBrand })"
                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                        >
                            Create Article
                        </Link>
                        <Link
                            :href="route('dashboard')"
                            class="text-sm text-gray-500 hover:text-gray-700"
                        >
                            ← Dashboard
                        </Link>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-3xl font-bold text-gray-900">All Articles</h2>
                        <p class="mt-2 text-sm text-gray-600">
                            Manage and view all your generated blog articles
                        </p>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="mt-4">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by title, creator, or status..."
                            class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                        />
                        <div v-if="searchQuery" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <button
                                @click="searchQuery = ''"
                                class="text-gray-400 hover:text-gray-600"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="filteredArticles.length === 0 && !searchQuery" class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No articles</h3>
                <p class="mt-1 text-sm text-gray-500">Get started by creating a new article.</p>
                <div class="mt-6">
                    <Link
                        :href="route('articles.create', { brandId: currentBrand })"
                        class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        Create New Article
                    </Link>
                </div>
            </div>

            <!-- No Search Results -->
            <div v-else-if="filteredArticles.length === 0 && searchQuery" class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No articles found</h3>
                <p class="mt-1 text-sm text-gray-500">Try adjusting your search query.</p>
                <div class="mt-6">
                    <button
                        @click="searchQuery = ''"
                        class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700"
                    >
                        Clear search
                    </button>
                </div>
            </div>

            <!-- Articles Grid -->
            <div v-else>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="article in filteredArticles"
                        :key="article.id"
                        :href="route('articles.show', article.id)"
                        class="bg-white rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow duration-200 cursor-pointer block"
                    >
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-2">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                    :class="getStatusClass(article.status)"
                                >
                                    {{ article.status }}
                                </span>
                                <span class="text-xs text-gray-500">
                                    {{ article.source_type }}
                                </span>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2 line-clamp-2">
                                {{ article.title }}
                            </h3>
                            <div class="flex items-center justify-between text-sm text-gray-500">
                                <span>{{ article.created_by || 'Anonymous' }}</span>
                                <span>{{ formatDate(article.created_at) }}</span>
                            </div>
                        </div>
                    </Link>
                </div>

                <!-- Pagination -->
                <div v-if="articles.last_page > 1" class="mt-8 flex justify-center gap-2">
                    <Link
                        v-if="articles.prev_page_url"
                        :href="articles.prev_page_url"
                        class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-sm"
                    >
                        Previous
                    </Link>
                    <span class="px-4 py-2 text-gray-600 text-sm">
                        Page {{ articles.current_page }} of {{ articles.last_page }}
                    </span>
                    <Link
                        v-if="articles.next_page_url"
                        :href="articles.next_page_url"
                        class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-sm"
                    >
                        Next
                    </Link>
                </div>

                <!-- Show total count -->
                <div v-if="articles.total" class="mt-6 text-center text-sm text-gray-500">
                    Showing {{ filteredArticles.length }} of {{ articles.total }} articles
                </div>
            </div>
        </main>
    </div>
</template>
