<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    enhancementIdea: Object,
    isAuthenticated: Boolean,
});

const showingEditModal = ref(false);
const showingDeleteModal = ref(false);
const replyingToComment = ref(null);

const editForm = useForm({
    title: props.enhancementIdea.title,
    description: props.enhancementIdea.description || '',
});

const commentForm = useForm({
    content: '',
    parent_id: null,
    author_name: '',
});

const submitEditForm = () => {
    editForm.put(route('enhancement-ideas.update', props.enhancementIdea.id), {
        onSuccess: () => {
            showingEditModal.value = false;
        },
    });
};

const submitCommentForm = () => {
    commentForm.post(route('enhancement-ideas.comments.store', props.enhancementIdea.id), {
        onSuccess: () => {
            commentForm.content = '';
            commentForm.parent_id = null;
            replyingToComment.value = null;
        },
    });
};

const deleteIdea = () => {
    useForm().delete(route('enhancement-ideas.destroy', props.enhancementIdea.id));
};

const toggleComplete = () => {
    const url = route('enhancement-ideas.toggle-complete', props.enhancementIdea.id);
    useForm().post(url);
};

const startReply = (comment) => {
    replyingToComment.value = comment;
    commentForm.parent_id = comment.id;
    commentForm.content = '';
};

const cancelReply = () => {
    replyingToComment.value = null;
    commentForm.parent_id = null;
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
</script>

<template>
    <Head :title="enhancementIdea.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Enhancement Idea Details
                </h2>
                <div class="flex space-x-3">
                    <SecondaryButton @click="showingEditModal = true">
                        Edit
                    </SecondaryButton>
                    <DangerButton @click="showingDeleteModal = true">
                        Delete
                    </DangerButton>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <!-- Idea Header -->
                    <div class="border-b border-gray-200 p-6">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="flex items-center">
                                    <h1 
                                        class="text-2xl font-bold text-gray-900"
                                        :class="{ 'line-through text-gray-400': enhancementIdea.completed }"
                                    >
                                        {{ enhancementIdea.title }}
                                    </h1>
                                    <span v-if="enhancementIdea.completed" class="ml-3 inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                        Completed
                                    </span>
                                </div>
                                <div class="mt-1 flex items-center text-sm text-gray-500">
                                    <span>Added by {{ enhancementIdea.user ? enhancementIdea.user.name : (enhancementIdea.author_name || 'Anonymous') }}</span>
                                    <span class="mx-1">•</span>
                                    <span>{{ formatDate(enhancementIdea.created_at) }}</span>
                                </div>
                            </div>
                            
                            <div class="flex items-center">
                                <label for="completed-toggle" class="mr-2 text-sm font-medium text-gray-700">
                                    {{ enhancementIdea.completed ? 'Completed' : 'Mark as completed' }}
                                </label>
                                <button
                                    type="button"
                                    @click="toggleComplete"
                                    :class="[
                                        enhancementIdea.completed ? 'bg-indigo-600' : 'bg-gray-200',
                                        'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2'
                                    ]"
                                >
                                    <span
                                        aria-hidden="true"
                                        :class="[
                                            enhancementIdea.completed ? 'translate-x-5' : 'translate-x-0',
                                            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out'
                                        ]"
                                    ></span>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mt-4 text-gray-700">
                            <p v-if="enhancementIdea.description" class="whitespace-pre-line">
                                {{ enhancementIdea.description }}
                            </p>
                            <p v-else class="italic text-gray-500">
                                No description provided.
                            </p>
                        </div>
                        
                        <div v-if="enhancementIdea.completed_at" class="mt-4 text-sm text-gray-500">
                            <p>Completed on {{ formatDate(enhancementIdea.completed_at) }}</p>
                        </div>
                    </div>
                    
                    <!-- Comments Section -->
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            {{ enhancementIdea.root_comments.length }} {{ enhancementIdea.root_comments.length === 1 ? 'Comment' : 'Comments' }}
                        </h2>
                        
                        <!-- New Comment Form -->
                        <div class="mt-6">
                            <form @submit.prevent="submitCommentForm">
                                <TextArea
                                    v-model="commentForm.content"
                                    :placeholder="replyingToComment ? `Reply to ${replyingToComment.user ? replyingToComment.user.name : (replyingToComment.author_name || 'Anonymous')}...` : 'Add a comment...'"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    rows="3"
                                    required
                                />
                                
                                <div v-if="!isAuthenticated" class="mt-3">
                                    <InputLabel for="comment_author_name" value="Your Name (optional)" />
                                    <TextInput
                                        id="comment_author_name"
                                        v-model="commentForm.author_name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        placeholder="Anonymous"
                                    />
                                </div>
                                
                                <div 
                                    v-if="replyingToComment" 
                                    class="mt-2 flex items-center text-sm text-gray-500"
                                >
                                    <span>Replying to comment by {{ replyingToComment.user ? replyingToComment.user.name : (replyingToComment.author_name || 'Anonymous') }}</span>
                                    <button 
                                        type="button"
                                        @click="cancelReply"
                                        class="ml-2 text-indigo-600 hover:text-indigo-900"
                                    >
                                        Cancel
                                    </button>
                                </div>
                                
                                <div class="mt-3 flex justify-end">
                                    <PrimaryButton :disabled="commentForm.processing">
                                        {{ replyingToComment ? 'Reply' : 'Add Comment' }}
                                    </PrimaryButton>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Comments List -->
                        <div v-if="enhancementIdea.root_comments.length > 0" class="mt-8 space-y-8">
                            <div
                                v-for="comment in enhancementIdea.root_comments"
                                :key="comment.id"
                                class="border-b border-gray-200 pb-8"
                            >
                                <!-- Parent Comment -->
                                <div class="flex space-x-3">
                                    <div class="flex-shrink-0">
                                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                                            <span class="text-indigo-800 font-medium">
                                                {{ comment.user ? comment.user.name.charAt(0).toUpperCase() : (comment.author_name || 'Anonymous').charAt(0).toUpperCase() }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-1 space-y-1">
                                        <div class="flex items-center justify-between">
                                            <h3 class="text-sm font-medium">{{ comment.user ? comment.user.name : (comment.author_name || 'Anonymous') }}</h3>
                                            <p class="text-xs text-gray-500">{{ formatDate(comment.created_at) }}</p>
                                        </div>
                                        <p class="text-sm text-gray-700 whitespace-pre-line">{{ comment.content }}</p>
                                        <div class="mt-2">
                                            <button
                                                type="button"
                                                @click="startReply(comment)"
                                                class="text-xs font-medium text-indigo-600 hover:text-indigo-900"
                                            >
                                                Reply
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Replies to this comment -->
                                <div
                                    v-if="comment.replies && comment.replies.length > 0"
                                    class="mt-4 space-y-4 ml-12"
                                >
                                    <div
                                        v-for="reply in comment.replies"
                                        :key="reply.id"
                                        class="flex space-x-3"
                                    >
                                        <div class="flex-shrink-0">
                                            <div class="h-8 w-8 rounded-full bg-gray-100 flex items-center justify-center">
                                                <span class="text-gray-800 font-medium">
                                                    {{ reply.user ? reply.user.name.charAt(0).toUpperCase() : (reply.author_name || 'Anonymous').charAt(0).toUpperCase() }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-1 space-y-1">
                                            <div class="flex items-center justify-between">
                                                <h3 class="text-sm font-medium">{{ reply.user ? reply.user.name : (reply.author_name || 'Anonymous') }}</h3>
                                                <p class="text-xs text-gray-500">{{ formatDate(reply.created_at) }}</p>
                                            </div>
                                            <p class="text-sm text-gray-700 whitespace-pre-line">{{ reply.content }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Empty comments state -->
                        <div 
                            v-else 
                            class="mt-6 rounded-md bg-gray-50 p-4 text-center"
                        >
                            <p class="text-sm text-gray-500">No comments yet. Be the first to add one!</p>
                        </div>
                    </div>
                </div>
                
                <!-- Back to list button -->
                <div class="mt-6">
                    <Link
                        :href="route('enhancement-ideas.index')"
                        class="text-indigo-600 hover:text-indigo-900"
                    >
                        ← Back to all enhancement ideas
                    </Link>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <Modal
            :show="showingEditModal"
            @close="showingEditModal = false"
        >
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    Edit Enhancement Idea
                </h2>

                <form @submit.prevent="submitEditForm" class="mt-6 space-y-6">
                    <div>
                        <InputLabel for="edit-title" value="Title" />
                        <TextInput
                            id="edit-title"
                            v-model="editForm.title"
                            type="text"
                            class="mt-1 block w-full"
                            required
                            autofocus
                        />
                    </div>

                    <div>
                        <InputLabel for="edit-description" value="Description (optional)" />
                        <TextArea
                            id="edit-description"
                            v-model="editForm.description"
                            class="mt-1 block w-full"
                            rows="4"
                        />
                    </div>

                    <div class="flex items-center justify-end gap-4">
                        <SecondaryButton @click="showingEditModal = false">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton :disabled="editForm.processing">
                            Save Changes
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal
            :show="showingDeleteModal"
            @close="showingDeleteModal = false"
        >
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    Delete Enhancement Idea
                </h2>
                <p class="mt-1 text-sm text-gray-600">
                    Are you sure you want to delete this enhancement idea? This action cannot be undone.
                </p>

                <div class="mt-6 flex justify-end gap-4">
                    <SecondaryButton @click="showingDeleteModal = false">
                        Cancel
                    </SecondaryButton>
                    <DangerButton @click="deleteIdea">
                        Delete Idea
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template> 