<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    enhancementIdeas: Array,
    isAuthenticated: Boolean,
});

const showingNewIdeaModal = ref(false);

const form = useForm({
    title: '',
    description: '',
    author_name: '',
});

const submitForm = () => {
    form.post(route('enhancement-ideas.store'), {
        onSuccess: () => {
            showingNewIdeaModal.value = false;
            form.reset();
        },
    });
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const toggleComplete = (id) => {
    const url = route('enhancement-ideas.toggle-complete', id);
    useForm().post(url);
};
</script>

<template>
    <Head title="Enhancement Ideas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Enhancement Ideas
                </h2>
                <PrimaryButton @click="showingNewIdeaModal = true">
                    Add New Idea
                </PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <!-- Empty State -->
                        <div
                            v-if="enhancementIdeas.length === 0"
                            class="flex flex-col items-center justify-center py-12"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="mb-4 h-16 w-16 text-gray-400"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"
                                />
                            </svg>
                            <h3 class="mb-2 text-lg font-medium text-gray-900">
                                No enhancement ideas yet
                            </h3>
                            <p class="mb-6 text-center text-gray-500">
                                Get started by adding your first enhancement idea.
                                <br />
                                Ideas can be features, improvements, or bug fixes.
                            </p>
                            <PrimaryButton @click="showingNewIdeaModal = true">
                                Add New Idea
                            </PrimaryButton>
                        </div>

                        <!-- Ideas List -->
                        <div
                            v-else
                            class="divide-y divide-gray-200"
                        >
                            <div
                                v-for="idea in enhancementIdeas"
                                :key="idea.id"
                                class="py-4 sm:py-5"
                            >
                                <div class="flex items-start gap-4">
                                    <!-- Checkbox for completion status -->
                                    <div class="mt-1">
                                        <input
                                            type="checkbox"
                                            :checked="idea.completed"
                                            @change="toggleComplete(idea.id)"
                                            class="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                    </div>

                                    <!-- Idea content -->
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between">
                                            <Link
                                                :href="route('enhancement-ideas.show', idea.id)"
                                                class="text-lg font-medium text-indigo-600 hover:text-indigo-900"
                                                :class="{ 'line-through text-gray-400': idea.completed }"
                                            >
                                                {{ idea.title }}
                                            </Link>
                                            <span v-if="idea.completed" class="ml-3 inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                                Completed {{ formatDate(idea.completed_at) }}
                                            </span>
                                        </div>
                                        
                                        <p class="mt-1 text-sm text-gray-600" :class="{ 'text-gray-400': idea.completed }">
                                            {{ idea.description }}
                                        </p>
                                        
                                        <div class="mt-2 flex items-center text-xs text-gray-500">
                                            <span>Added by {{ idea.user ? idea.user.name : (idea.author_name || 'Anonymous') }}</span>
                                            <span class="mx-1">•</span>
                                            <span>{{ formatDate(idea.created_at) }}</span>
                                            <span class="mx-1">•</span>
                                            <span>
                                                {{ idea.root_comments.length }} {{ idea.root_comments.length === 1 ? 'comment' : 'comments' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- New Idea Modal -->
        <Modal
            :show="showingNewIdeaModal"
            @close="showingNewIdeaModal = false"
        >
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    Add New Enhancement Idea
                </h2>
                <p class="mt-1 text-sm text-gray-600">
                    Share your ideas for new features, improvements, or bug fixes.
                </p>

                <form @submit.prevent="submitForm" class="mt-6 space-y-6">
                    <div>
                        <InputLabel for="title" value="Title" />
                        <TextInput
                            id="title"
                            v-model="form.title"
                            type="text"
                            class="mt-1 block w-full"
                            required
                            autofocus
                        />
                    </div>

                    <div>
                        <InputLabel for="description" value="Description (optional)" />
                        <TextArea
                            id="description"
                            v-model="form.description"
                            class="mt-1 block w-full"
                            rows="4"
                        />
                    </div>

                    <div v-if="!isAuthenticated">
                        <InputLabel for="author_name" value="Your Name (optional)" />
                        <TextInput
                            id="author_name"
                            v-model="form.author_name"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="Anonymous"
                        />
                    </div>

                    <div class="flex items-center justify-end gap-4">
                        <SecondaryButton @click="showingNewIdeaModal = false">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton :disabled="form.processing">
                            Save Idea
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template> 