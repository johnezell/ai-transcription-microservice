<template>
  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Import Terminology Data
        </h2>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <!-- Flash Messages -->
        <div v-if="$page.props.flash && $page.props.flash.success" class="mb-6 bg-green-50 border border-green-200 text-green-800 rounded-md p-4">
          {{ $page.props.flash.success }}
        </div>
        
        <div v-if="$page.props.flash && $page.props.flash.error" class="mb-6 bg-red-50 border border-red-200 text-red-800 rounded-md p-4">
          {{ $page.props.flash.error }}
        </div>

        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 bg-white border-b border-gray-200">
            <div class="mb-6">
              <h3 class="text-lg font-medium text-gray-700 mb-4">Import Categories and Terms</h3>
              <p class="mb-4 text-gray-600">
                This tool allows you to import categories and terms from JSON data. You can either replace all existing data or merge with the current data.
              </p>
              <p class="mb-4 text-gray-600">
                The expected JSON format is: <code>{ "category_slug": ["term1", "term2", ...], ... }</code>
              </p>
              <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
                <div class="flex">
                  <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                  </div>
                  <div class="ml-3">
                    <p class="text-sm text-yellow-700">
                      <span class="font-medium">Warning:</span> Using "Replace" mode will delete all existing categories and terms.
                    </p>
                  </div>
                </div>
              </div>
            </div>
            
            <form @submit.prevent="submit">
              <div class="mb-4">
                <InputLabel for="json_data" value="JSON Data" />
                <TextArea
                  id="json_data"
                  v-model="form.json_data"
                  class="mt-1 block w-full"
                  rows="15"
                  placeholder='{ "category_slug": ["term1", "term2"] }'
                  required
                ></TextArea>
                <InputError :message="form.errors.json_data" class="mt-2" />
              </div>
              
              <div class="mb-6">
                <InputLabel for="mode" value="Import Mode" />
                <div class="mt-2 space-y-2">
                  <label class="inline-flex items-center">
                    <input 
                      type="radio" 
                      v-model="form.mode"
                      value="merge"
                      class="border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                    />
                    <span class="ml-2 text-gray-700">Merge</span>
                    <span class="ml-2 text-sm text-gray-500">- Add new categories and terms while preserving existing ones</span>
                  </label>
                  <label class="inline-flex items-center">
                    <input 
                      type="radio"
                      v-model="form.mode"
                      value="replace"
                      class="border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                    />
                    <span class="ml-2 text-gray-700">Replace</span>
                    <span class="ml-2 text-sm text-gray-500">- Delete all existing categories and terms before importing</span>
                  </label>
                </div>
                <InputError :message="form.errors.mode" class="mt-2" />
              </div>
              
              <div class="flex items-center justify-between mt-8">
                <div>
                  <a 
                    :href="route('admin.music-terms.export')" 
                    target="_blank"
                    class="text-indigo-600 hover:text-indigo-500 underline text-sm"
                  >
                    Export current data
                  </a>
                  <span class="mx-2 text-gray-500 text-sm">|</span>
                  <button 
                    type="button" 
                    @click="loadSampleData"
                    class="text-indigo-600 hover:text-indigo-500 underline text-sm"
                  >
                    Load sample data
                  </button>
                </div>
                
                <div class="flex space-x-2">
                  <Link
                    :href="route('admin.music-terms.index')"
                    class="inline-flex items-center px-4 py-2 bg-gray-100 border border-transparent rounded-md font-semibold text-xs text-gray-800 tracking-widest hover:bg-gray-200 active:bg-gray-300 focus:outline-none focus:border-gray-900 focus:ring ring-gray-300 disabled:opacity-25 transition ease-in-out duration-150"
                  >
                    Cancel
                  </Link>
                  
                  <PrimaryButton
                    class="ml-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                  >
                    Import
                  </PrimaryButton>
                </div>
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
import TextArea from '@/Components/TextArea.vue';

const form = useForm({
  json_data: '',
  mode: 'merge',
});

const submit = () => {
  form.post(route('admin.music-terms.import.process'));
};

const loadSampleData = () => {
  const sampleData = {
    "programming_languages": [
      "JavaScript", "Python", "PHP", "Java", "C#", "C++", "Ruby", "Go", "Swift"
    ],
    "frameworks": [
      "React", "Vue", "Angular", "Laravel", "Django", "Flask", "Spring", "Rails", ".NET"
    ],
    "databases": [
      "MySQL", "PostgreSQL", "MongoDB", "SQLite", "Redis", "Oracle", "SQL Server", "Cassandra"
    ],
    "tools": [
      "Git", "Docker", "Kubernetes", "Terraform", "Ansible", "Jenkins", "GitHub Actions", "CircleCI"
    ]
  };
  
  form.json_data = JSON.stringify(sampleData, null, 2);
};
</script> 