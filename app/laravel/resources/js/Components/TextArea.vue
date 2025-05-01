<script setup>
import { computed } from 'vue';
import { onMounted, ref } from 'vue';

const textarea = ref(null);

const props = defineProps({
    modelValue: {
        type: String,
        required: true,
    },
    rows: {
        type: [Number, String],
        default: 4,
    }
});

defineEmits(['update:modelValue']);

const computedValue = computed({
    get() {
        return props.modelValue;
    },
    set(value) {
        emit('update:modelValue', value);
    },
});

onMounted(() => {
    if (textarea.value.hasAttribute('autofocus')) {
        textarea.value.focus();
    }
});

defineExpose({ focus: () => textarea.value.focus() });
</script>

<template>
    <textarea
        ref="textarea"
        class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full"
        :value="modelValue"
        :rows="rows"
        @input="$emit('update:modelValue', $event.target.value)"
        v-bind="$attrs"
    ></textarea>
</template> 