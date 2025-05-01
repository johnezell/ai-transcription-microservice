<template>
  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Add New Term
        </h2>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 bg-white border-b border-gray-200">
            <div v-if="categories.length === 0" class="bg-yellow-50 p-4 rounded-md text-yellow-800 mb-6">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M8.485 3.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 3.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-yellow-800">No categories found</h3>
                  <div class="mt-2 text-sm text-yellow-700">
                    <p>You need to create at least one category before adding terms.</p>
                  </div>
                  <div class="mt-4">
                    <div class="-mx-2 -my-1.5 flex">
                      <Link 
                        :href="route('admin.music-terms.categories.create')" 
                        class="rounded-md bg-yellow-50 px-2 py-1.5 text-sm font-medium text-yellow-800 hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-yellow-600 focus:ring-offset-2 focus:ring-offset-yellow-50"
                      >
                        Create Category
                      </Link>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <form v-else @submit.prevent="submit">
              <div class="mb-4">
                <InputLabel for="category_id" value="Category" />
                <select
                  id="category_id"
                  v-model="form.category_id"
                  class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                  required
                >
                  <option value="" disabled>Select category</option>
                  <option 
                    v-for="category in categories" 
                    :key="category.id" 
                    :value="category.id"
                  >
                    {{ category.name }}
                  </option>
                </select>
                <InputError class="mt-2" :message="form.errors.category_id" />
              </div>

              <div class="mb-4">
                <InputLabel for="term" value="Term" />
                <TextInput
                  id="term"
                  type="text"
                  class="mt-1 block w-full"
                  v-model="form.term"
                  required
                  autofocus
                />
                <InputError class="mt-2" :message="form.errors.term" />
              </div>

              <div class="mb-6">
                <InputLabel for="description" value="Description (Optional)" />
                <TextArea
                  id="description"
                  class="mt-1 block w-full"
                  v-model="form.description"
                  rows="3"
                />
                <InputError class="mt-2" :message="form.errors.description" />
              </div>

              <div class="flex items-center justify-end mt-6">
                <Link
                  :href="route('admin.music-terms.index')"
                  class="px-4 py-2 bg-gray-100 border border-transparent rounded-md font-semibold text-xs text-gray-800 tracking-widest hover:bg-gray-200 active:bg-gray-300 focus:outline-none focus:border-gray-900 focus:ring ring-gray-300 disabled:opacity-25 transition ease-in-out duration-150 ml-4"
                >
                  Cancel
                </Link>

                <PrimaryButton
                  class="ml-4"
                  :class="{ 'opacity-25': form.processing }"
                  :disabled="form.processing"
                >
                  Add Term
                </PrimaryButton>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';

const props = defineProps({
  categories: Array,
});

const form = useForm({
  category_id: '',
  term: '',
  description: '',
});

const submit = () => {
  form.post(route('admin.music-terms.terms.store'), {
    onSuccess: () => {
      form.reset();
    },
  });
};
</script> 