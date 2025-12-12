<template>
    <div class="tiptap-editor h-full flex flex-col">
        <!-- Editor Menu Bar -->
        <div v-if="editor" class="editor-menu border-b border-gray-200 bg-gray-50 p-2 flex flex-wrap gap-1 flex-shrink-0">
            <button
                @click="editor.chain().focus().toggleBold().run()"
                :class="{ 'is-active': editor.isActive('bold') }"
                class="menu-button"
                title="Bold"
                type="button"
            >
                <strong>B</strong>
            </button>
            <button
                @click="editor.chain().focus().toggleItalic().run()"
                :class="{ 'is-active': editor.isActive('italic') }"
                class="menu-button"
                title="Italic"
                type="button"
            >
                <em>I</em>
            </button>
            <button
                @click="editor.chain().focus().toggleStrike().run()"
                :class="{ 'is-active': editor.isActive('strike') }"
                class="menu-button"
                title="Strikethrough"
                type="button"
            >
                <s>S</s>
            </button>

            <div class="border-l border-gray-300 mx-1"></div>

            <button
                @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
                :class="{ 'is-active': editor.isActive('heading', { level: 2 }) }"
                class="menu-button"
                title="Heading 2"
                type="button"
            >
                H2
            </button>
            <button
                @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"
                :class="{ 'is-active': editor.isActive('heading', { level: 3 }) }"
                class="menu-button"
                title="Heading 3"
                type="button"
            >
                H3
            </button>

            <div class="border-l border-gray-300 mx-1"></div>

            <button
                @click="editor.chain().focus().toggleBulletList().run()"
                :class="{ 'is-active': editor.isActive('bulletList') }"
                class="menu-button"
                title="Bullet List"
                type="button"
            >
                • List
            </button>
            <button
                @click="editor.chain().focus().toggleOrderedList().run()"
                :class="{ 'is-active': editor.isActive('orderedList') }"
                class="menu-button"
                title="Numbered List"
                type="button"
            >
                1. List
            </button>

            <div class="border-l border-gray-300 mx-1"></div>

            <button
                @click="editor.chain().focus().toggleBlockquote().run()"
                :class="{ 'is-active': editor.isActive('blockquote') }"
                class="menu-button"
                title="Quote"
                type="button"
            >
                " Quote
            </button>
            <button
                @click="editor.chain().focus().setHorizontalRule().run()"
                class="menu-button"
                title="Horizontal Line"
                type="button"
            >
                —
            </button>

            <div class="border-l border-gray-300 mx-1"></div>

            <button
                @click="setLink"
                :class="{ 'is-active': editor.isActive('link') }"
                class="menu-button"
                title="Add Link"
                type="button"
            >
                🔗
            </button>
            <button
                v-if="editor.isActive('link')"
                @click="editor.chain().focus().unsetLink().run()"
                class="menu-button"
                title="Remove Link"
                type="button"
            >
                ✕
            </button>

            <div class="border-l border-gray-300 mx-1"></div>

            <button
                @click="editor.chain().focus().undo().run()"
                :disabled="!editor.can().undo()"
                class="menu-button"
                title="Undo"
                type="button"
            >
                ↶
            </button>
            <button
                @click="editor.chain().focus().redo().run()"
                :disabled="!editor.can().redo()"
                class="menu-button"
                title="Redo"
                type="button"
            >
                ↷
            </button>
        </div>

        <!-- Editor Content -->
        <div class="editor-content-wrapper flex-1 overflow-y-auto p-6 bg-white">
            <editor-content :editor="editor" class="prose max-w-none" />
        </div>
    </div>
</template>

<script setup>
import { useEditor, EditorContent } from '@tiptap/vue-3';
import { StarterKit } from '@tiptap/starter-kit';
import { Placeholder } from '@tiptap/extension-placeholder';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import { Highlight } from '@tiptap/extension-highlight';
import { Link } from '@tiptap/extension-link';
import { watch, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    placeholder: {
        type: String,
        default: 'Start writing your article...'
    },
    disabled: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:modelValue']);

const editor = useEditor({
    extensions: [
        StarterKit,
        Placeholder.configure({
            placeholder: props.placeholder,
        }),
        TextStyle,
        Color,
        Highlight,
        Link.configure({
            openOnClick: false,
            HTMLAttributes: {
                class: 'text-blue-600 hover:underline',
            },
        }),
    ],
    content: props.modelValue,
    editable: !props.disabled,
    editorProps: {
        attributes: {
            class: 'prose prose-sm sm:prose lg:prose-lg focus:outline-none min-h-[300px]'
        }
    },
    onUpdate: ({ editor }) => {
        emit('update:modelValue', editor.getHTML());
    }
});

// Update editor content when prop changes
watch(() => props.modelValue, (newContent) => {
    if (editor.value && newContent !== editor.value.getHTML()) {
        editor.value.commands.setContent(newContent);
    }
});

// Update editable state
watch(() => props.disabled, (disabled) => {
    if (editor.value) {
        editor.value.setEditable(!disabled);
    }
});

// Add link
const setLink = () => {
    const previousUrl = editor.value.getAttributes('link').href;
    const url = window.prompt('URL', previousUrl);

    if (url === null) {
        return;
    }

    if (url === '') {
        editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
        return;
    }

    editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
};

onBeforeUnmount(() => {
    if (editor.value) {
        editor.value.destroy();
    }
});

// Expose editor instance to parent
defineExpose({
    editor
});
</script>

<style scoped>
.tiptap-editor {
    @apply border border-gray-300 rounded-lg overflow-hidden;
}

.menu-button {
    @apply px-3 py-1 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed;
}

.menu-button.is-active {
    @apply bg-blue-100 text-blue-700 border-blue-300;
}

.editor-content-wrapper {
    @apply bg-white;
}

:deep(.ProseMirror) {
    @apply focus:outline-none min-h-[300px];
}

:deep(.ProseMirror p.is-editor-empty:first-child::before) {
    color: #adb5bd;
    content: attr(data-placeholder);
    float: left;
    height: 0;
    pointer-events: none;
}

:deep(.ProseMirror h2) {
    @apply text-2xl font-bold mt-6 mb-4;
}

:deep(.ProseMirror h3) {
    @apply text-xl font-bold mt-4 mb-3;
}

:deep(.ProseMirror p) {
    @apply mb-4;
}

:deep(.ProseMirror ul) {
    @apply list-disc list-inside my-4;
}

:deep(.ProseMirror ol) {
    @apply list-decimal list-inside my-4;
}

:deep(.ProseMirror blockquote) {
    @apply border-l-4 border-gray-300 pl-4 italic my-4;
}

:deep(.ProseMirror code) {
    @apply bg-gray-100 rounded px-1 py-0.5 text-sm font-mono;
}

:deep(.ProseMirror pre) {
    @apply bg-gray-900 text-gray-100 rounded p-4 my-4 overflow-x-auto;
}

:deep(.ProseMirror hr) {
    @apply my-8 border-t-2 border-gray-200;
}

:deep(.ProseMirror a) {
    @apply text-blue-600 hover:underline cursor-pointer;
}
</style>

