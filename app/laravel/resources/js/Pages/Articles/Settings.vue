<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    currentBrand: String,
    brands: Object,
    settings: Object,
    availableModels: Object,
});

const llmModel = ref(props.settings.llm_model);
const systemPrompt = ref(props.settings.system_prompt);
const saving = ref(false);
const successMessage = ref(null);
const errorMessage = ref(null);
const expandedBrands = ref({});

const currentBrandData = computed(() => props.brands[props.currentBrand] || props.brands.truefire);

const DEFAULT_PROMPTS = {
    truefire: 'You are an expert music educator creating detailed, professional blog content for intermediate to advanced musicians. Transform the provided video transcript into a comprehensive, well-structured blog article that maintains educational value while being engaging and insightful. Focus on technical accuracy, practical applications, and clear explanations for guitar players.',
    artistworks: 'You are an expert music instructor creating inspiring and educational blog content for musicians of all skill levels. Transform the provided video transcript into a comprehensive article that balances professional instruction with accessibility. Emphasize personal growth, mastery, and transformative learning. Focus on structured lessons, technique development, and the journey from beginner to advanced musicianship across various instruments and genres.',
    blayze: 'You are a professional coaching expert creating actionable, results-oriented content for athletes and skill-learners. Transform the provided video transcript into an engaging article that builds confidence and drives improvement. Use an approachable yet professional tone, emphasizing personalized learning, video analysis insights, and practical tips. Focus on breaking down technique, identifying strengths and weaknesses, and providing clear action steps for measurable progress.',
    faderpro: 'You are a professional music production expert creating practical, studio-focused content for electronic music producers and DJs. Transform the provided video transcript into a detailed, hands-on article that reveals real-world production techniques. Emphasize workflow optimization, DAW-specific tips, mixing/mastering fundamentals, and genre-specific production approaches. Use an aspirational yet accessible tone, focusing on actionable techniques that producers can immediately apply in their own studios.',
};

const switchBrand = (brandId) => {
    router.get(route('articles.settings', { brandId }));
};

const saveSettings = async () => {
    saving.value = true;
    errorMessage.value = null;
    
    try {
        const response = await fetch('/api/article-settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                brandId: props.currentBrand,
                llm_model: llmModel.value,
                system_prompt: systemPrompt.value,
            }),
        });
        
        if (!response.ok) {
            throw new Error('Failed to save settings');
        }
        
        successMessage.value = 'Settings saved successfully!';
        setTimeout(() => successMessage.value = null, 3000);
    } catch (e) {
        errorMessage.value = e.message;
    } finally {
        saving.value = false;
    }
};

const resetToDefault = () => {
    if (confirm(`Reset to default prompt for ${currentBrandData.value.name}?`)) {
        systemPrompt.value = DEFAULT_PROMPTS[props.currentBrand] || DEFAULT_PROMPTS.truefire;
    }
};

const toggleBrandExpanded = (brandId) => {
    expandedBrands.value[brandId] = !expandedBrands.value[brandId];
};
</script>

<template>
    <Head title="Article Settings" />

    <div class="min-h-screen bg-gray-50">
        <!-- Navigation Header -->
        <nav class="bg-white shadow-sm border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900">
                            {{ currentBrandData.name }} Article Settings
                        </h1>
                    </div>
                    <div class="flex items-center gap-4">
                        <Link
                            :href="route('articles.create', { brandId: currentBrand })"
                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700"
                        >
                            Create Article
                        </Link>
                        <Link
                            :href="route('articles.index', { brandId: currentBrand })"
                            class="text-sm text-gray-600 hover:text-gray-900"
                        >
                            ← Back to Articles
                        </Link>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Back link bar -->
        <div class="bg-white border-b border-gray-200">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-3">
                <Link
                    :href="route('articles.index', { brandId: currentBrand })"
                    class="inline-flex items-center text-sm text-gray-600 hover:text-gray-900"
                >
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                    Back to Articles
                </Link>
            </div>
        </div>

        <!-- Main Content -->
        <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="space-y-6">
                <!-- LLM Model Selection -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">LLM Model</h2>
                    <p class="text-sm text-gray-600 mb-4">
                        Select the Claude model to use for article generation
                    </p>

                    <select
                        v-model="llmModel"
                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
                    >
                        <option v-for="(label, modelId) in availableModels" :key="modelId" :value="modelId">
                            {{ label }}
                        </option>
                    </select>
                    <p class="mt-2 text-sm text-gray-500">
                        Claude 3.5 Sonnet v2 is recommended for best quality. Haiku is faster but may produce lower quality output.
                    </p>
                </div>

                <!-- Brand-Specific Prompts -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-2">Brand-Specific Prompts</h2>
                    <p class="text-sm text-gray-600 mb-6">
                        Customize the AI system prompt for each brand. These prompts guide the tone, style, and structure of generated content.
                    </p>

                    <!-- Brand Tabs -->
                    <div class="flex border-b border-gray-200 mb-6">
                        <button
                            v-for="(brand, id) in brands"
                            :key="id"
                            @click="switchBrand(id)"
                            class="px-6 py-3 text-sm font-medium border-b-2 -mb-px transition-colors flex items-center gap-2"
                            :class="id === currentBrand 
                                ? 'border-blue-500 text-blue-600' 
                                : 'border-transparent text-gray-500 hover:text-gray-700'"
                        >
                            <img
                                v-if="brand.logo"
                                :src="brand.logo"
                                :alt="brand.name"
                                class="h-5 object-contain"
                            />
                            <span v-else>{{ brand.name }}</span>
                        </button>
                    </div>

                    <!-- Current Brand Info -->
                    <div class="p-4 bg-gray-50 rounded-lg mb-4">
                        <div class="flex items-center gap-4">
                            <img
                                v-if="currentBrandData.logo"
                                :src="currentBrandData.logo"
                                :alt="currentBrandData.name"
                                class="h-10 object-contain"
                            />
                            <div>
                                <h3 class="font-semibold text-gray-900">{{ currentBrandData.name }}</h3>
                                <p class="text-sm text-gray-600">{{ currentBrandData.website }}</p>
                                <p class="text-sm text-gray-500 mt-1">{{ currentBrandData.description }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- System Prompt for Current Brand -->
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label class="block text-sm font-semibold text-gray-700">
                                System Prompt for {{ currentBrandData.name }}
                            </label>
                            <button
                                @click="resetToDefault"
                                class="text-sm text-blue-600 hover:text-blue-700"
                            >
                                Restore default
                            </button>
                        </div>
                        <textarea
                            v-model="systemPrompt"
                            rows="8"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                            placeholder="Enter system prompt..."
                        ></textarea>
                    </div>

                    <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-blue-900 mb-2">Tips for a good prompt:</h3>
                        <ul class="text-sm text-blue-800 space-y-1 list-disc list-inside">
                            <li>Define the target audience (e.g., "intermediate to advanced musicians")</li>
                            <li>Specify the tone (e.g., "professional yet accessible")</li>
                            <li>Mention desired structure (e.g., "include introduction, key points, conclusion")</li>
                            <li>Emphasize important qualities (e.g., "technical accuracy" or "practical examples")</li>
                        </ul>
                    </div>
                </div>

                <!-- Success Message -->
                <div v-if="successMessage" class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <p class="text-green-800">{{ successMessage }}</p>
                </div>

                <!-- Error Message -->
                <div v-if="errorMessage" class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <p class="text-red-800">{{ errorMessage }}</p>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end gap-3">
                    <Link
                        :href="route('articles.index', { brandId: currentBrand })"
                        class="inline-flex items-center px-6 py-3 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                    >
                        Cancel
                    </Link>
                    <button
                        @click="saveSettings"
                        :disabled="saving"
                        class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50"
                    >
                        {{ saving ? 'Saving...' : 'Save Settings' }}
                    </button>
                </div>
            </div>
        </main>
    </div>
</template>
