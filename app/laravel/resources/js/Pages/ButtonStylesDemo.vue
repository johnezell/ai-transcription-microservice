<template>
    <Head title="Button Styles Demo" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Button Styles Guide</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h2 class="text-2xl font-semibold mb-8">Button Styles Guide</h2>
                        
                        <div class="space-y-10">
                            <!-- Primary Buttons -->
                            <section>
                                <h3 class="text-lg font-medium mb-4 pb-2 border-b">Primary Buttons</h3>
                                <p class="text-gray-600 mb-4">Used for primary actions like Save, Submit, or Create.</p>
                                
                                <div class="flex flex-wrap gap-4 items-center">
                                    <button class="btn-primary">Primary Button</button>
                                    <button class="btn-primary btn-sm">Small Primary</button>
                                    <button class="btn-primary btn-lg">Large Primary</button>
                                    <button class="btn-primary" disabled>Disabled Primary</button>
                                    <button class="btn-primary btn-with-icon">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        With Icon
                                    </button>
                                    <button class="btn-primary btn-loading">Loading State</button>
                                </div>
                                
                                <div class="mt-4 bg-gray-100 p-4 rounded">
                                    <p class="text-sm font-mono">Class: <code>btn-primary</code></p>
                                </div>
                            </section>
                            
                            <!-- Secondary Buttons -->
                            <section>
                                <h3 class="text-lg font-medium mb-4 pb-2 border-b">Secondary Buttons</h3>
                                <p class="text-gray-600 mb-4">Used for secondary actions like Cancel or Back.</p>
                                
                                <div class="flex flex-wrap gap-4 items-center">
                                    <button class="btn-secondary">Secondary Button</button>
                                    <button class="btn-secondary btn-sm">Small Secondary</button>
                                    <button class="btn-secondary btn-lg">Large Secondary</button>
                                    <button class="btn-secondary" disabled>Disabled Secondary</button>
                                    <button class="btn-secondary btn-with-icon">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                                        </svg>
                                        With Icon
                                    </button>
                                    <button class="btn-secondary btn-loading">Loading State</button>
                                </div>
                                
                                <div class="mt-4 bg-gray-100 p-4 rounded">
                                    <p class="text-sm font-mono">Class: <code>btn-secondary</code></p>
                                </div>
                            </section>
                            
                            <!-- Danger Buttons -->
                            <section>
                                <h3 class="text-lg font-medium mb-4 pb-2 border-b">Danger Buttons</h3>
                                <p class="text-gray-600 mb-4">Used for destructive actions like Delete or Remove.</p>
                                
                                <div class="flex flex-wrap gap-4 items-center">
                                    <button class="btn-danger">Danger Button</button>
                                    <button class="btn-danger btn-sm">Small Danger</button>
                                    <button class="btn-danger btn-lg">Large Danger</button>
                                    <button class="btn-danger" disabled>Disabled Danger</button>
                                    <button class="btn-danger btn-with-icon">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                        Delete Item
                                    </button>
                                    <button class="btn-danger btn-loading">Loading State</button>
                                </div>
                                
                                <div class="mt-4 bg-gray-100 p-4 rounded">
                                    <p class="text-sm font-mono">Class: <code>btn-danger</code></p>
                                </div>
                            </section>
                            
                            <!-- Success Buttons -->
                            <section>
                                <h3 class="text-lg font-medium mb-4 pb-2 border-b">Success Buttons</h3>
                                <p class="text-gray-600 mb-4">Used for completion actions like Approve or Confirm.</p>
                                
                                <div class="flex flex-wrap gap-4 items-center">
                                    <button class="btn-success">Success Button</button>
                                    <button class="btn-success btn-sm">Small Success</button>
                                    <button class="btn-success btn-lg">Large Success</button>
                                    <button class="btn-success" disabled>Disabled Success</button>
                                    <button class="btn-success btn-with-icon">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Approve
                                    </button>
                                    <button class="btn-success btn-loading">Loading State</button>
                                </div>
                                
                                <div class="mt-4 bg-gray-100 p-4 rounded">
                                    <p class="text-sm font-mono">Class: <code>btn-success</code></p>
                                </div>
                            </section>
                            
                            <!-- Text Buttons -->
                            <section>
                                <h3 class="text-lg font-medium mb-4 pb-2 border-b">Text Buttons</h3>
                                <p class="text-gray-600 mb-4">Used for subtle actions, often inline or in tight spaces.</p>
                                
                                <div class="flex flex-wrap gap-4 items-center">
                                    <button class="btn-text">Text Button</button>
                                    <button class="btn-text btn-sm">Small Text</button>
                                    <button class="btn-text btn-lg">Large Text</button>
                                    <button class="btn-text" disabled>Disabled Text</button>
                                    <button class="btn-text btn-with-icon">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        More Info
                                    </button>
                                </div>
                                
                                <div class="mt-4 bg-gray-100 p-4 rounded">
                                    <p class="text-sm font-mono">Class: <code>btn-text</code></p>
                                </div>
                            </section>
                            
                            <!-- Icon Buttons -->
                            <section>
                                <h3 class="text-lg font-medium mb-4 pb-2 border-b">Icon Buttons</h3>
                                <p class="text-gray-600 mb-4">Used for actions represented by an icon.</p>
                                
                                <div class="flex flex-wrap gap-4 items-center">
                                    <button class="btn-icon">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                    </button>
                                    <button class="btn-icon">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                        </svg>
                                    </button>
                                    <button class="btn-icon">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                    <button class="btn-icon" disabled>
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                                        </svg>
                                    </button>
                                </div>
                                
                                <div class="mt-4 bg-gray-100 p-4 rounded">
                                    <p class="text-sm font-mono">Class: <code>btn-icon</code></p>
                                </div>
                            </section>
                            
                            <!-- Link Buttons -->
                            <section>
                                <h3 class="text-lg font-medium mb-4 pb-2 border-b">Link Buttons</h3>
                                <p class="text-gray-600 mb-4">Used for navigation actions that look like links.</p>
                                
                                <div class="flex flex-wrap gap-4 items-center">
                                    <button class="btn-link">Link Button</button>
                                    <button class="btn-link" disabled>Disabled Link</button>
                                    <a href="#" class="btn-link">Actual Link</a>
                                </div>
                                
                                <div class="mt-4 bg-gray-100 p-4 rounded">
                                    <p class="text-sm font-mono">Class: <code>btn-link</code></p>
                                </div>
                            </section>
                            
                            <!-- Usage Guidelines -->
                            <section>
                                <h3 class="text-lg font-medium mb-4 pb-2 border-b">Usage Guidelines</h3>
                                
                                <div class="space-y-4">
                                    <div>
                                        <h4 class="font-medium">Button Hierarchy</h4>
                                        <p class="text-gray-600">Use only one primary button per section. Secondary buttons should be used for less important actions.</p>
                                    </div>
                                    
                                    <div>
                                        <h4 class="font-medium">Button Placement</h4>
                                        <p class="text-gray-600">Place the primary action on the right and secondary actions on the left in action bars.</p>
                                    </div>
                                    
                                    <div>
                                        <h4 class="font-medium">Button Labels</h4>
                                        <p class="text-gray-600">Use clear, concise, action-oriented labels like "Save" instead of "Submit".</p>
                                    </div>
                                    
                                    <div>
                                        <h4 class="font-medium">Loading States</h4>
                                        <p class="text-gray-600">Always show loading state when an action is being processed to prevent multiple submissions.</p>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
</script> 