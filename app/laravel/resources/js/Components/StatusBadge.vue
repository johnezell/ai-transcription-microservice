<template>
    <div :class="['status-badge', statusClass]">
        <component :is="statusIcon" class="status-icon" />
        <span class="status-text">{{ statusText }}</span>
    </div>
</template>

<script>
import { defineComponent, h } from 'vue';

// Status icons as render functions
const CheckIcon = () => h('svg', { 
    class: 'w-4 h-4', 
    xmlns: 'http://www.w3.org/2000/svg', 
    viewBox: '0 0 20 20', 
    fill: 'currentColor' 
}, [
    h('path', { 
        'fill-rule': 'evenodd', 
        d: 'M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z', 
        'clip-rule': 'evenodd' 
    })
]);

const SpinnerIcon = () => h('svg', { 
    class: 'w-4 h-4 animate-spin', 
    xmlns: 'http://www.w3.org/2000/svg', 
    fill: 'none', 
    viewBox: '0 0 24 24' 
}, [
    h('circle', { 
        class: 'opacity-25', 
        cx: '12', 
        cy: '12', 
        r: '10', 
        stroke: 'currentColor', 
        'stroke-width': '4' 
    }),
    h('path', { 
        class: 'opacity-75', 
        fill: 'currentColor', 
        d: 'M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z' 
    })
]);

const UploadIcon = () => h('svg', { 
    class: 'w-4 h-4', 
    xmlns: 'http://www.w3.org/2000/svg', 
    viewBox: '0 0 20 20', 
    fill: 'currentColor' 
}, [
    h('path', { 
        'fill-rule': 'evenodd', 
        d: 'M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z', 
        'clip-rule': 'evenodd' 
    })
]);

const ErrorIcon = () => h('svg', { 
    class: 'w-4 h-4', 
    xmlns: 'http://www.w3.org/2000/svg', 
    viewBox: '0 0 20 20', 
    fill: 'currentColor' 
}, [
    h('path', { 
        'fill-rule': 'evenodd', 
        d: 'M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z', 
        'clip-rule': 'evenodd' 
    })
]);

export default defineComponent({
    props: {
        status: {
            type: String,
            required: true,
            validator: (value) => ['completed', 'processing', 'is_processing', 'uploaded', 'failed', 'transcribed'].includes(value)
        }
    },
    computed: {
        statusClass() {
            const classes = {
                'completed': 'bg-green-100 text-green-800 border-green-200',
                'processing': 'bg-yellow-100 text-yellow-800 border-yellow-200',
                'is_processing': 'bg-yellow-100 text-yellow-800 border-yellow-200',
                'uploaded': 'bg-blue-100 text-blue-800 border-blue-200',
                'transcribed': 'bg-purple-100 text-purple-800 border-purple-200',
                'failed': 'bg-red-100 text-red-800 border-red-200'
            };
            
            return classes[this.status] || 'bg-gray-100 text-gray-800 border-gray-200';
        },
        statusText() {
            const texts = {
                'completed': 'Completed',
                'processing': 'Processing',
                'is_processing': 'Processing',
                'uploaded': 'Ready',
                'transcribed': 'Transcribed',
                'failed': 'Failed'
            };
            
            return texts[this.status] || this.status;
        },
        statusIcon() {
            const icons = {
                'completed': CheckIcon,
                'processing': SpinnerIcon,
                'is_processing': SpinnerIcon,
                'uploaded': UploadIcon,
                'transcribed': CheckIcon,
                'failed': ErrorIcon
            };
            
            return icons[this.status] || null;
        }
    }
});
</script>

<style scoped>
.status-badge {
    display: flex;
    align-items: center;
    padding: 0.25rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
    border: 1px solid;
    transition: all 0.3s ease;
}

.status-icon {
    margin-right: 0.25rem;
}
</style> 