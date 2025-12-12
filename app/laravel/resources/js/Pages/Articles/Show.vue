<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
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

const currentBrandData = computed(() => props.brands[article.value.brand_id] || props.brands.truefire);

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

// Processing step detection from title/content
const processingStep = computed(() => {
    const title = article.value.title || '';
    if (title.startsWith('Downloading:')) return { step: 1, label: 'Downloading video from TrueFire...', icon: '⬇️' };
    if (title.startsWith('Transcribing:') || title.includes('transcribed')) return { step: 2, label: 'Transcribing with Whisper...', icon: '🎤' };
    if (title.startsWith('Processing:')) return { step: 2, label: 'Processing video...', icon: '⚙️' };
    if (title.startsWith('Generating:')) return { step: 3, label: 'Generating article with AI...', icon: '✨' };
    return { step: 0, label: 'Initializing...', icon: '🔄' };
});

const isProcessing = computed(() => article.value.status === 'generating');

const startPolling = () => {
    pollInterval = setInterval(async () => {
        try {
            const response = await fetch(`/api/articles/${article.value.id}`);
            const data = await response.json();
            
            // Always update to show latest title/content during processing
            const wasGenerating = article.value.status === 'generating';
            article.value = data;
            
            if (wasGenerating && data.status !== 'generating') {
                clearInterval(pollInterval);
                pollInterval = null;
                
                if (data.status === 'draft') {
                    successMessage.value = 'Article generated successfully!';
                    setTimeout(() => successMessage.value = null, 5000);
                } else if (data.status === 'error') {
                    errorMessage.value = data.error_message || 'An error occurred during processing';
                }
            }
        } catch (e) {
            console.error('Polling error:', e);
        }
    }, 2000); // Poll every 2 seconds for more responsive updates
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
    if (!confirm('Are you sure you want to delete this article? This action cannot be undone.')) return;
    
    try {
        const response = await fetch(`/api/articles/${article.value.id}`, {
            method: 'DELETE',
        });
        
        if (!response.ok) {
            throw new Error('Failed to delete article');
        }
        
        router.visit(route('articles.index', { brandId: article.value.brand_id }));
    } catch (e) {
        errorMessage.value = e.message;
    }
};
</script>

<template>
    <Head :title="article.title || 'Article'" />

    <div class="h-screen flex flex-col bg-gray-100 overflow-hidden">
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
                        <span class="text-sm font-medium text-gray-700 truncate max-w-md">{{ article.title || 'Untitled' }}</span>
                    </div>
                    <div class="flex gap-2 items-center">
                        <!-- Brand Badge -->
                        <div class="flex items-center gap-2 px-2 py-1 bg-gray-100 rounded text-xs text-gray-600">
                            <img
                                v-if="currentBrandData.logo"
                                :src="currentBrandData.logo"
                                :alt="currentBrandData.name"
                                class="h-4 object-contain"
                            />
                            <span v-else>{{ currentBrandData.name }}</span>
                        </div>
                        
                        <select
                            v-model="article.status"
                            :disabled="article.status === 'generating'"
                            class="text-xs px-2 py-1 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                        >
                            <option value="generating" disabled>Generating</option>
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                            <option value="archived">Archived</option>
                        </select>
                        <button
                            @click="saveArticle"
                            :disabled="saving || article.status === 'generating'"
                            class="px-3 py-1.5 text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ saving ? 'Saving...' : 'Save' }}
                        </button>
                        <button
                            @click="deleteArticle"
                            :disabled="article.status === 'generating'"
                            class="px-3 py-1.5 text-sm font-medium rounded-md text-red-700 bg-white border border-red-300 hover:bg-red-50 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content Area - Scrollable -->
        <main class="flex-1 overflow-y-auto">
            <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <!-- Processing Status Banner -->
                <div v-if="isProcessing" class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-6 mb-6">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0">
                            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-blue-900 flex items-center gap-2">
                                <span>{{ processingStep.icon }}</span>
                                <span>{{ processingStep.label }}</span>
                            </h3>
                            
                            <!-- Progress Steps -->
                            <div class="mt-4 flex items-center gap-2">
                                <div class="flex items-center">
                                    <div :class="[
                                        'w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium',
                                        processingStep.step >= 1 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-500'
                                    ]">1</div>
                                    <span class="ml-2 text-sm" :class="processingStep.step >= 1 ? 'text-blue-700 font-medium' : 'text-gray-500'">Download</span>
                                </div>
                                <div class="flex-1 h-1 bg-gray-200 mx-2">
                                    <div :class="['h-1 bg-blue-600 transition-all', processingStep.step >= 2 ? 'w-full' : 'w-0']"></div>
                                </div>
                                <div class="flex items-center">
                                    <div :class="[
                                        'w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium',
                                        processingStep.step >= 2 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-500'
                                    ]">2</div>
                                    <span class="ml-2 text-sm" :class="processingStep.step >= 2 ? 'text-blue-700 font-medium' : 'text-gray-500'">Transcribe</span>
                                </div>
                                <div class="flex-1 h-1 bg-gray-200 mx-2">
                                    <div :class="['h-1 bg-blue-600 transition-all', processingStep.step >= 3 ? 'w-full' : 'w-0']"></div>
                                </div>
                                <div class="flex items-center">
                                    <div :class="[
                                        'w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium',
                                        processingStep.step >= 3 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-500'
                                    ]">3</div>
                                    <span class="ml-2 text-sm" :class="processingStep.step >= 3 ? 'text-blue-700 font-medium' : 'text-gray-500'">Complete</span>
                                </div>
                            </div>
                            
                            <p class="mt-3 text-sm text-blue-600">
                                Page will update automatically when processing completes...
                            </p>
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

                <!-- Article Content -->
                <div class="flex justify-center">
                    <div class="flex-1 max-w-5xl mx-auto">
                        <!-- Article Editor Card -->
                        <div class="bg-white shadow-sm border border-gray-200 rounded-lg">
                            <!-- Title -->
                            <div class="p-8 pb-4 border-b border-gray-200">
                                <input
                                    v-model="article.title"
                                    type="text"
                                    :disabled="article.status === 'generating'"
                                    class="w-full text-4xl font-bold border-0 focus:ring-0 p-0 placeholder-gray-300 disabled:bg-white disabled:cursor-not-allowed"
                                    placeholder="Untitled article"
                                />
                            </div>

                            <!-- Meta Fields -->
                            <div class="px-8 py-4 bg-gray-50 border-b border-gray-200 space-y-3">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">
                                            Author
                                        </label>
                                        <input
                                            v-model="article.author"
                                            type="text"
                                            :disabled="article.status === 'generating'"
                                            class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100"
                                            placeholder="Author name"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">
                                            Slug
                                            <span class="text-gray-400 font-normal">(URL-friendly)</span>
                                        </label>
                                        <input
                                            v-model="article.slug"
                                            type="text"
                                            :disabled="article.status === 'generating'"
                                            class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 font-mono"
                                            placeholder="e.g., guitar-lesson-blues"
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
                                        :maxlength="160"
                                        class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100"
                                        placeholder="Brief description of the article for search engines..."
                                    />
                                    <div class="text-xs text-gray-500 mt-1 text-right">
                                        {{ article.meta_description?.length || 0 }} / 160
                                    </div>
                                </div>
                            </div>

                            <!-- Content Editor -->
                            <div style="height: 600px;">
                                <div v-if="article.status === 'generating'" class="p-8 text-gray-400">
                                    <div class="animate-pulse space-y-3">
                                        <div class="h-4 bg-gray-200 rounded w-full"></div>
                                        <div class="h-4 bg-gray-200 rounded w-11/12"></div>
                                        <div class="h-4 bg-gray-200 rounded w-full"></div>
                                        <div class="h-4 bg-gray-200 rounded w-10/12"></div>
                                        <div class="h-4 bg-gray-200 rounded w-full"></div>
                                    </div>
                                </div>
                                <div v-else-if="article.content !== null" class="article-content h-full">
                                    <TiptapEditor
                                        v-model="article.content"
                                        :disabled="article.status === 'generating'"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Source Info -->
                        <div v-if="article.video || article.source_url" class="mt-4 p-4 bg-white rounded-lg border border-gray-200">
                            <h4 class="text-sm font-semibold text-gray-700 mb-2">Source</h4>
                            <p v-if="article.video" class="text-sm text-gray-600">
                                Generated from video: {{ article.video.original_filename }}
                            </p>
                            <p v-if="article.source_url" class="text-sm text-gray-600">
                                Source URL: 
                                <a :href="article.source_url" target="_blank" class="text-blue-600 hover:underline">
                                    {{ article.source_url }}
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
