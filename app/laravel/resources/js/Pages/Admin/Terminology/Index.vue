<template>
  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Terminology Management
        </h2>
        <div class="flex space-x-3">
          <Link :href="route('admin.terminology.import')" class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white tracking-widest hover:bg-green-700 focus:bg-green-700 active:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition ease-in-out duration-150">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3-3m0 0l3 3m-3-3v12"></path>
            </svg>
            Import from JSON
          </Link>
          <Link :href="route('admin.terminology.categories.create')" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Add Category
          </Link>
          <Link :href="route('admin.terminology.terms.create')" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Add Term
          </Link>
        </div>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Flash Messages -->
        <div v-if="$page.props.flash && $page.props.flash.success" class="mb-6 bg-green-50 border border-green-200 text-green-800 rounded-md p-4">
          {{ $page.props.flash.success }}
        </div>
        
        <div v-if="$page.props.flash && $page.props.flash.error" class="mb-6 bg-red-50 border border-red-200 text-red-800 rounded-md p-4">
          {{ $page.props.flash.error }}
        </div>

        <!-- Categories List -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6 bg-white border-b border-gray-200">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-lg font-semibold text-gray-700">Term Categories</h3>
              
              <Link :href="route('admin.terminology.export')" target="_blank" class="inline-flex items-center px-3 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition ease-in-out duration-150">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                Export as JSON
              </Link>
            </div>
            
            <div v-if="categories.length === 0" class="text-gray-500 text-center py-8">
              No categories found. Create your first category to get started.
            </div>
            
            <div v-else class="grid grid-cols-1 gap-6">
              <div v-for="category in categories" :key="category.id" class="border border-gray-200 rounded-lg overflow-hidden">
                <div class="flex items-center justify-between p-4" :class="getCategoryHeaderClass(category.color_class)">
                  <div class="flex items-center">
                    <span class="w-3 h-3 rounded-full mr-2" :class="getCategoryDotClass(category.color_class)"></span>
                    <h4 class="font-medium text-gray-800">{{ category.name }}</h4>
                    <span class="ml-2 text-sm text-gray-600">({{ category.terms ? category.terms.length : 0 }} terms)</span>
                  </div>
                  
                  <div class="flex items-center space-x-2">
                    <span v-if="!category.active" class="px-2 py-1 bg-red-100 text-red-800 text-xs rounded-full">
                      Inactive
                    </span>
                    
                    <Link :href="route('admin.terminology.categories.edit', category.id)" class="text-gray-600 hover:text-gray-900 p-1">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 0L11.828 15H9v-2.828l8.586-8.586z"></path>
                      </svg>
                    </Link>
                    
                    <button 
                      @click="confirmDeleteCategory(category)" 
                      class="text-red-600 hover:text-red-900 p-1"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                      </svg>
                    </button>
                  </div>
                </div>
                
                <div class="p-4">
                  <p v-if="category.description" class="text-sm text-gray-600 mb-4">
                    {{ category.description }}
                  </p>
                  
                  <div v-if="category.terms && category.terms.length > 0" class="mt-2">
                    <div class="flex flex-wrap gap-2">
                      <span 
                        v-for="term in category.terms" 
                        :key="term.id" 
                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-white border"
                        :class="getTermClass(term, category.color_class)"
                      >
                        {{ term.term }}
                        <button 
                          @click="confirmDeleteTerm(term)"
                          class="ml-1 text-gray-500 hover:text-red-500 focus:outline-none"
                        >
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                          </svg>
                        </button>
                      </span>
                    </div>
                  </div>
                  
                  <div v-else class="text-sm text-gray-500 italic">
                    No terms in this category yet.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Delete Category Modal -->
    <Modal :show="deleteCategoryModal" @close="deleteCategoryModal = false">
      <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Delete Category</h3>
        
        <p class="mb-4 text-gray-600">
          Are you sure you want to delete the category <span class="font-bold">{{ categoryToDelete?.name }}</span>?
          This will also delete all {{ categoryToDelete?.terms?.length || 0 }} terms associated with this category.
        </p>
        
        <div class="mt-6 flex justify-end">
          <button 
            @click="deleteCategoryModal = false" 
            class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 mr-3"
          >
            Cancel
          </button>
          
          <form @submit.prevent="deleteCategory">
            <button 
              type="submit"
              class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
            >
              Delete Category
            </button>
          </form>
        </div>
      </div>
    </Modal>
    
    <!-- Delete Term Modal -->
    <Modal :show="deleteTermModal" @close="deleteTermModal = false">
      <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Delete Term</h3>
        
        <p class="mb-4 text-gray-600">
          Are you sure you want to delete the term <span class="font-bold">{{ termToDelete?.term }}</span>?
        </p>
        
        <div class="mt-6 flex justify-end">
          <button 
            @click="deleteTermModal = false" 
            class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 mr-3"
          >
            Cancel
          </button>
          
          <form @submit.prevent="deleteTerm">
            <button 
              type="submit"
              class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
            >
              Delete Term
            </button>
          </form>
        </div>
      </div>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';

// Props
const props = defineProps({
  categories: Array,
});

// State
const deleteCategoryModal = ref(false);
const categoryToDelete = ref(null);
const deleteTermModal = ref(false);
const termToDelete = ref(null);

// Methods
const getCategoryHeaderClass = (colorClass) => {
  switch (colorClass) {
    case 'blue': return 'bg-blue-50';
    case 'green': return 'bg-green-50';
    case 'purple': return 'bg-purple-50';
    case 'orange': return 'bg-orange-50';
    case 'pink': return 'bg-pink-50';
    case 'indigo': return 'bg-indigo-50';
    case 'cyan': return 'bg-cyan-50';
    default: return 'bg-gray-50';
  }
};

const getCategoryDotClass = (colorClass) => {
  switch (colorClass) {
    case 'blue': return 'bg-blue-500';
    case 'green': return 'bg-green-500';
    case 'purple': return 'bg-purple-500';
    case 'orange': return 'bg-orange-500';
    case 'pink': return 'bg-pink-500';
    case 'indigo': return 'bg-indigo-500';
    case 'cyan': return 'bg-cyan-500';
    default: return 'bg-gray-500';
  }
};

const getTermClass = (term, colorClass) => {
  const baseClass = term.active ? '' : 'opacity-50';
  
  switch (colorClass) {
    case 'blue': return `${baseClass} border-blue-200 text-blue-800`;
    case 'green': return `${baseClass} border-green-200 text-green-800`;
    case 'purple': return `${baseClass} border-purple-200 text-purple-800`;
    case 'orange': return `${baseClass} border-orange-200 text-orange-800`;
    case 'pink': return `${baseClass} border-pink-200 text-pink-800`;
    case 'indigo': return `${baseClass} border-indigo-200 text-indigo-800`;
    case 'cyan': return `${baseClass} border-cyan-200 text-cyan-800`;
    default: return `${baseClass} border-gray-200 text-gray-800`;
  }
};

const confirmDeleteCategory = (category) => {
  categoryToDelete.value = category;
  deleteCategoryModal.value = true;
};

const deleteCategory = () => {
  if (categoryToDelete.value) {
    router.delete(route('admin.terminology.categories.destroy', categoryToDelete.value.id), {
      onSuccess: () => {
        deleteCategoryModal.value = false;
        categoryToDelete.value = null;
      }
    });
  }
};

const confirmDeleteTerm = (term) => {
  termToDelete.value = term;
  deleteTermModal.value = true;
};

const deleteTerm = () => {
  if (termToDelete.value) {
    router.delete(route('admin.terminology.terms.destroy', termToDelete.value.id), {
      onSuccess: () => {
        deleteTermModal.value = false;
        termToDelete.value = null;
      }
    });
  }
};
</script> 