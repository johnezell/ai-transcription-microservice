<template>
    <div :class="['feature-badge', typeClass]">
        <component :is="typeIcon" class="feature-icon" />
        <span class="feature-text">{{ typeLabel }}</span>
        <span v-if="count" class="feature-count">{{ count }}</span>
    </div>
</template>

<script>
import { defineComponent, h } from 'vue';

// Feature icons as render functions
const TranscriptIcon = () => h('svg', { 
    class: 'w-3 h-3', 
    xmlns: 'http://www.w3.org/2000/svg', 
    fill: 'none', 
    viewBox: '0 0 24 24', 
    stroke: 'currentColor', 
    'stroke-width': '2' 
}, [
    h('path', { 
        'stroke-linecap': 'round', 
        'stroke-linejoin': 'round', 
        d: 'M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z' 
    })
]);

const TerminologyIcon = () => h('svg', { 
    class: 'w-3 h-3', 
    xmlns: 'http://www.w3.org/2000/svg', 
    fill: 'none', 
    viewBox: '0 0 24 24', 
    stroke: 'currentColor', 
    'stroke-width': '2' 
}, [
    h('path', { 
        'stroke-linecap': 'round', 
        'stroke-linejoin': 'round', 
        d: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2' 
    })
]);

export default defineComponent({
    props: {
        type: {
            type: String,
            required: true,
            validator: (value) => ['transcript', 'terminology'].includes(value)
        },
        count: {
            type: Number,
            required: false,
            default: null
        }
    },
    computed: {
        typeClass() {
            const classes = {
                'transcript': 'bg-blue-50 text-blue-700 border-blue-100',
                'terminology': 'bg-indigo-50 text-indigo-700 border-indigo-100'
            };
            
            return classes[this.type] || 'bg-gray-50 text-gray-700 border-gray-100';
        },
        typeLabel() {
            const labels = {
                'transcript': 'Transcript',
                'terminology': 'Terminology'
            };
            
            return labels[this.type] || this.type;
        },
        typeIcon() {
            const icons = {
                'transcript': TranscriptIcon,
                'terminology': TerminologyIcon
            };
            
            return icons[this.type] || null;
        }
    }
});
</script>

<style scoped>
.feature-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    font-size: 0.675rem;
    font-weight: 500;
    border: 1px solid;
    transition: all 0.2s ease;
}

.feature-icon {
    margin-right: 0.25rem;
}

.feature-count {
    margin-left: 0.25rem;
    background-color: rgba(255, 255, 255, 0.5);
    border-radius: 9999px;
    padding: 0 0.25rem;
    font-size: 0.625rem;
    min-width: 1rem;
    text-align: center;
}
</style> 