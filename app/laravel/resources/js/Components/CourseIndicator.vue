<template>
    <div class="course-indicator" :title="'Course: ' + course.name">
        <div class="course-icon">
            <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
            </svg>
        </div>
        <div class="course-info">
            <div class="course-name">
                <Link :href="route('courses.show', course.id)" class="course-link">
                    {{ course.name }}
                </Link>
            </div>
            <div v-if="lessonNumber" class="lesson-number">
                Lesson {{ lessonNumber }}
            </div>
        </div>
    </div>
</template>

<script>
import { defineComponent } from 'vue';
import { Link } from '@inertiajs/vue3';

export default defineComponent({
    components: {
        Link
    },
    props: {
        course: {
            type: Object,
            required: true,
            validator: (value) => value.id !== undefined && value.name !== undefined
        },
        lessonNumber: {
            type: [Number, String],
            required: false,
            default: null
        }
    }
});
</script>

<style scoped>
.course-indicator {
    display: flex;
    align-items: center;
    padding: 0.5rem;
    background-color: rgba(79, 70, 229, 0.05);
    border-radius: 0.375rem;
    border: 1px solid rgba(79, 70, 229, 0.1);
}

.course-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(79, 70, 229, 0.8);
    margin-right: 0.5rem;
}

.course-info {
    display: flex;
    flex-direction: column;
}

.course-name {
    font-size: 0.75rem;
    font-weight: 600;
    color: rgba(79, 70, 229, 0.8);
    line-height: 1;
}

.course-link {
    text-decoration: none;
    color: inherit;
    transition: color 0.2s ease;
}

.course-link:hover {
    color: rgba(79, 70, 229, 1);
    text-decoration: underline;
}

.lesson-number {
    font-size: 0.675rem;
    color: rgba(79, 70, 229, 0.6);
    margin-top: 0.125rem;
}
</style> 