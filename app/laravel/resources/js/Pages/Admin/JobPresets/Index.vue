<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    presets: Array
});

// Delete confirmation
const confirmingPresetDeletion = ref(false);
const presetToDelete = ref(null);

const confirmPresetDeletion = (preset) => {
    presetToDelete.value = preset;
    confirmingPresetDeletion.value = true;
};

const deletePreset = () => {
    router.delete(route('admin.job-presets.destroy', presetToDelete.value.id), {
        onSuccess: () => {
            confirmingPresetDeletion.value = false;
            presetToDelete.value = null;
        }
    });
};

const cancelPresetDeletion = () => {
    confirmingPresetDeletion.value = false;
    presetToDelete.value = null;
};

// Set default confirmation
const setAsDefault = (preset) => {
    if (!preset.is_default) {
        router.put(route('admin.job-presets.set-default', preset.id));
    }
};
</script>

<template>
    <Head title="Transcription Presets" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Transcription Presets</h2>
                <Link :href="route('admin.job-presets.create')" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                    Create New Preset
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    
                    <!-- No presets message -->
                    <div v-if="presets.length === 0" class="text-center py-10">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        <h3 class="mt-2 text-lg font-medium text-gray-900">No transcription presets yet</h3>
                        <p class="mt-1 text-sm text-gray-500">Get started by creating a new transcription preset.</p>
                        <div class="mt-6">
                            <Link :href="route('admin.job-presets.create')" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                                Create First Preset
                            </Link>
                        </div>
                    </div>
                    
                    <!-- Presets table -->
                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Model</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Language</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Default</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="preset in presets" :key="preset.id" class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">{{ preset.name }}</div>
                                        <div v-if="preset.description" class="text-sm text-gray-500 truncate max-w-xs" :title="preset.description">
                                            {{ preset.description }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" 
                                              :class="{
                                                'bg-green-100 text-green-800': preset.model === 'base',
                                                'bg-blue-100 text-blue-800': preset.model === 'small',
                                                'bg-purple-100 text-purple-800': preset.model === 'medium',
                                                'bg-indigo-100 text-indigo-800': preset.model === 'large' || preset.model === 'large-v2',
                                                'bg-pink-100 text-pink-800': preset.model === 'large-v3',
                                              }">
                                            {{ preset.model }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ preset.language ? preset.language : 'Auto-detect' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                              :class="preset.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
                                            {{ preset.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <button @click="setAsDefault(preset)" class="text-sm" :disabled="preset.is_default">
                                            <div v-if="preset.is_default" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                Default
                                            </div>
                                            <div v-else class="text-indigo-600 hover:text-indigo-900">
                                                Set as Default
                                            </div>
                                        </button>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <Link :href="route('admin.job-presets.edit', preset.id)" class="text-indigo-600 hover:text-indigo-900 mr-3">
                                            Edit
                                        </Link>
                                        <button @click="confirmPresetDeletion(preset)" class="text-red-600 hover:text-red-900" :disabled="preset.is_default">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Delete Confirmation Modal -->
        <Modal :show="confirmingPresetDeletion" @close="cancelPresetDeletion">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    Are you sure you want to delete this preset?
                </h2>

                <p class="mt-1 text-sm text-gray-600">
                    This action cannot be undone, and may affect videos using this preset.
                </p>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="cancelPresetDeletion" class="mr-3">
                        Cancel
                    </SecondaryButton>

                    <DangerButton @click="deletePreset">
                        Delete Preset
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template> 