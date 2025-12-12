<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TiptapEditor from '@/Components/TiptapEditor.vue';

const props = defineProps({
    article: Object,
    brands: Object,
});

const article = ref(props.article);
const saving = ref(false);
const successMessage = ref(null);
const errorMessage = ref(null);
let pollInterval = null;

// Poll for generating articles
onMounted(() => {
    if (article.value.status === 'generating') {
        startPolling();
    }
});

onBeforeUnmount(() => {
    if (pollInterval) {
        clearInterval(pollInterval);
    }
});

const startPolling = () => {
    pollInterval = setInterval(async () => {
        try {
            const response = await fetch(`/api/articles/${article.value.id}`);
            const data = await response.json();
            
            if (data.status !== 'generating') {
                article.value = data;
                clearInterval(pollInterval);
                pollInterval = null;
                
                if (data.status === 'draft') {
                    successMessage.value = 'Article generated successfully!';
                    setTimeout(() => successMessage.value = null, 5000);
                }
            }
        } catch (e) {
            console.error('Polling error:', e);
        }
    }, 3000);
};

const saveArticle = async () => {
    saving.value = true;
    errorMessage.value = null;
    
    try {
        const response = await fetch(`/api/articles/${article.value.id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title: article.value.title,
                content: article.value.content,
                author: article.value.author,
                meta_description: article.value.meta_description,
                slug: article.value.slug,
                status: article.value.status,
            }),
        });
        
        if (!response.ok) {
            throw new Error('Failed to save article');
        }
        
        successMessage.value = 'Article saved successfully!';
        setTimeout(() => successMessage.value = null, 3000);
    } catch (e) {
        errorMessage.value = e.message;
    } finally {
        saving.value = false;
    }
};

const deleteArticle = async () => {
    if (!confirm('Are you sure you want to delete this article?')) return;
    
    try {
        const response = await fetch(`/api/articles/${article.value.id}`, {
            method: 'DELETE',
        });
        
        if (!response.ok) {
            throw new Error('Failed to delete article');
        }
        
        router.visit('/articles');
    } catch (e) {
        errorMessage.value = e.message;
    }
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
    <Head :title="article.title" />

    <AuthenticatedLayout>
        <div class="h-screen flex flex-col overflow-hidden">
            <!-- Top Navigation Bar -->
            <nav class="bg-white shadow-sm border-b border-gray-200 flex-shrink-0">
                <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-14">
                        <div class="flex items-center gap-4">
                            <Link
                                :href="route('articles.index', { brandId: article.brand_id })"
                                class="text-gray-600 hover:text-gray-900 text-sm"
                            >
                                ← Back
                            </Link>
                            <span class="text-sm text-gray-500">|</span>
                            <span class="text-sm font-medium text-gray-700 truncate max-w-md">
                                {{ article.title || 'Untitled' }}
                            </span>
                        </div>
                        <div class="flex gap-2 items-center">
                            <select
                                v-model="article.status"
                                :disabled="article.status === 'generating'"
                                class="text-xs px-2 py-1 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100"
                            >
                                <option value="generating" disabled>Generating</option>
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                                <option value="archived">Archived</option>
                            </select>
                            <button
                                @click="saveArticle"
                                :disabled="saving || article.status === 'generating'"
                                class="px-3 py-1.5 text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50"
                            >
                                {{ saving ? 'Saving...' : 'Save' }}
                            </button>
                            <button
                                @click="deleteArticle"
                                :disabled="article.status === 'generating'"
                                class="px-3 py-1.5 text-sm font-medium rounded-md text-red-700 bg-white border border-red-300 hover:bg-red-50 disabled:opacity-50"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Main Content Area -->
            <main class="flex-1 overflow-y-auto">
                <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                    <!-- Generating Banner -->
                    <div v-if="article.status === 'generating'" class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-4">
                        <div class="flex items-center">
                            <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600 mr-3"></div>
                            <div>
                                <p class="text-sm font-medium text-blue-900">Generating article...</p>
                                <p class="text-xs text-blue-700">This usually takes 1-2 minutes</p>
                            </div>
                        </div>
                    </div>

                    <!-- Success Message -->
                    <div v-if="successMessage" class="bg-green-50 border-l-4 border-green-500 p-4 mb-4">
                        <p class="text-sm text-green-800">{{ successMessage }}</p>
                    </div>

                    <!-- Error Message -->
                    <div v-if="errorMessage || article.error_message" class="bg-red-50 border-l-4 border-red-500 p-4 mb-4">
                        <p class="text-sm text-red-800">{{ errorMessage || article.error_message }}</p>
                    </div>

                    <!-- Article Editor Card -->
                    <div class="bg-white shadow-sm border border-gray-200 rounded-lg">
                        <!-- Title -->
                        <div class="p-6 border-b border-gray-200">
                            <input
                                v-model="article.title"
                                type="text"
                                :disabled="article.status === 'generating'"
                                class="w-full text-3xl font-bold border-0 focus:ring-0 p-0 placeholder-gray-300 disabled:bg-white"
                                placeholder="Untitled article"
                            />
                        </div>

                        <!-- Meta Fields -->
                        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 space-y-3">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Author</label>
                                    <input
                                        v-model="article.author"
                                        type="text"
                                        :disabled="article.status === 'generating'"
                                        class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                                        placeholder="Author name"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Slug</label>
                                    <input
                                        v-model="article.slug"
                                        type="text"
                                        :disabled="article.status === 'generating'"
                                        class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 font-mono"
                                        placeholder="url-friendly-slug"
                                    />
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">
                                    Meta Description
                                    <span class="text-gray-400 font-normal">(150-160 characters for SEO)</span>
                                </label>
                                <textarea
                                    v-model="article.meta_description"
                                    :disabled="article.status === 'generating'"
                                    rows="2"
                                    maxlength="160"
                                    class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                                    placeholder="Brief description for search engines..."
                                />
                                <div class="text-xs text-gray-500 mt-1 text-right">
                                    {{ article.meta_description?.length || 0 }} / 160
                                </div>
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="min-h-[500px]">
                            <div v-if="article.status === 'generating'" class="p-6 animate-pulse space-y-3">
                                <div class="h-4 bg-gray-200 rounded w-full"></div>
                                <div class="h-4 bg-gray-200 rounded w-11/12"></div>
                                <div class="h-4 bg-gray-200 rounded w-full"></div>
                                <div class="h-4 bg-gray-200 rounded w-10/12"></div>
                                <div class="h-4 bg-gray-200 rounded w-full"></div>
                            </div>
                            <div v-else class="h-[500px]">
                                <TiptapEditor
                                    v-model="article.content"
                                    :disabled="article.status === 'generating'"
                                    placeholder="Start writing your article..."
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Source Info -->
                    <div v-if="article.video || article.source_url" class="mt-4 p-4 bg-gray-50 rounded-lg">
                        <h4 class="text-sm font-semibold text-gray-700 mb-2">Source</h4>
                        <p v-if="article.video" class="text-sm text-gray-600">
                            Generated from video: {{ article.video.original_filename }}
                        </p>
                        <p v-if="article.source_url" class="text-sm text-gray-600">
                            Source URL: <a :href="article.source_url" target="_blank" class="text-blue-600 hover:underline">{{ article.source_url }}</a>
                        </p>
                    </div>
                </div>
            </main>
        </div>
    </AuthenticatedLayout>
</template>

<style>
.prose h2 { @apply text-2xl font-bold mt-6 mb-4; }
.prose h3 { @apply text-xl font-bold mt-4 mb-3; }
.prose p { @apply mb-4; }
.prose ul { @apply list-disc list-inside mb-4; }
.prose ol { @apply list-decimal list-inside mb-4; }
.prose blockquote { @apply border-l-4 border-gray-300 pl-4 italic my-4; }
.prose a { @apply text-blue-600 hover:underline; }
</style>

