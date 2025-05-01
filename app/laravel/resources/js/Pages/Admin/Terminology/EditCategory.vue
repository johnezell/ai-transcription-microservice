<template>
  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Edit Category
        </h2>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 bg-white border-b border-gray-200">
            <form @submit.prevent="submit">
              <div class="mb-4">
                <InputLabel for="name" value="Category Name" />
                <TextInput
                  id="name"
                  type="text"
                  class="mt-1 block w-full"
                  v-model="form.name"
                  required
                  autofocus
                />
                <InputError class="mt-2" :message="form.errors.name" />
              </div>

              <div class="mb-4">
                <InputLabel for="description" value="Description (Optional)" />
                <TextArea
                  id="description"
                  class="mt-1 block w-full"
                  v-model="form.description"
                  rows="3"
                />
                <InputError class="mt-2" :message="form.errors.description" />
              </div>

              <div class="mb-4">
                <InputLabel for="active" value="Status" />
                <div class="mt-2">
                  <label class="inline-flex items-center">
                    <input 
                      type="checkbox" 
                      id="active"
                      v-model="form.active"
                      class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                    />
                    <span class="ml-2 text-gray-700">Active</span>
                  </label>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                  Inactive categories and their terms won't be used in terminology recognition.
                </p>
                <InputError class="mt-2" :message="form.errors.active" />
              </div>

              <div class="mb-4">
                <InputLabel for="display_order" value="Display Order" />
                <TextInput
                  id="display_order"
                  type="number"
                  min="0"
                  step="1"
                  class="mt-1 block w-full"
                  v-model="form.display_order"
                  required
                />
                <p class="mt-1 text-sm text-gray-500">
                  Determines the order in which categories are displayed (lower numbers first).
                </p>
                <InputError class="mt-2" :message="form.errors.display_order" />
              </div>

              <div class="mb-6">
                <InputLabel for="color_class" value="Color" />
                <div class="mt-2 grid grid-cols-7 gap-2">
                  <button
                    v-for="(color, index) in colors"
                    :key="index"
                    type="button"
                    class="w-10 h-10 rounded-full ring-2 ring-offset-2 focus:outline-none"
                    :class="{
                      [color.bgClass]: true,
                      'ring-offset-gray-100 ring-gray-400': form.color_class !== color.value,
                      'ring-offset-gray-100 ring-blue-500': form.color_class === color.value
                    }"
                    @click="form.color_class = color.value"
                  ></button>
                </div>
                <InputError class="mt-2" :message="form.errors.color_class" />
              </div>

              <div class="flex items-center justify-end mt-6">
                <Link
                  :href="route('admin.terminology.index')"
                  class="px-4 py-2 bg-gray-100 border border-transparent rounded-md font-semibold text-xs text-gray-800 tracking-widest hover:bg-gray-200 active:bg-gray-300 focus:outline-none focus:border-gray-900 focus:ring ring-gray-300 disabled:opacity-25 transition ease-in-out duration-150 ml-4"
                >
                  Cancel
                </Link>

                <PrimaryButton
                  class="ml-4"
                  :class="{ 'opacity-25': form.processing }"
                  :disabled="form.processing"
                >
                  Update Category
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
import { onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';

const props = defineProps({
  category: Object,
});

const colors = [
  { value: 'blue', bgClass: 'bg-blue-500' },
  { value: 'green', bgClass: 'bg-green-500' },
  { value: 'purple', bgClass: 'bg-purple-500' },
  { value: 'orange', bgClass: 'bg-orange-500' },
  { value: 'pink', bgClass: 'bg-pink-500' },
  { value: 'indigo', bgClass: 'bg-indigo-500' },
  { value: 'cyan', bgClass: 'bg-cyan-500' }
];

const form = useForm({
  name: props.category.name,
  description: props.category.description || '',
  color_class: props.category.color_class,
  active: props.category.active,
  display_order: props.category.display_order,
});

const submit = () => {
  form.put(route('admin.terminology.categories.update', props.category.id), {
    onSuccess: () => {
      // Form is automatically reset on completion
    },
  });
};
</script> 