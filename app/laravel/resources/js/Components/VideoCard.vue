<template>
    <div class="video-card" :class="{ 'is-processing': isProcessing, 'has-error': isFailed }">
        <!-- Thumbnail area with status overlay -->
        <div class="thumbnail-container">
            <img 
                v-if="thumbnailUrl" 
                :src="thumbnailUrl" 
                alt="Video thumbnail" 
                class="thumbnail"
                @error="handleThumbnailError"
            />
            <div v-else class="thumbnail-placeholder">
                <svg class="w-12 h-12 text-gray-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <div class="placeholder-text">{{ video.original_filename }}</div>
            </div>
            <StatusBadge :status="video.status" class="status-badge" />
        </div>
        
        <!-- Content area -->
        <div class="content">
            <h3 class="title" :title="video.original_filename">{{ video.original_filename }}</h3>
            <div class="metadata">
                <span class="size">{{ formatFileSize(video.size_bytes) }}</span>
                <span class="date">{{ formatDate(video.created_at) }}</span>
            </div>
            
            <!-- Course information if available -->
            <div v-if="video.course" class="course-container">
                <CourseIndicator :course="video.course" :lessonNumber="video.lesson_number" />
            </div>
            
            <!-- Feature badges -->
            <div class="features">
                <FeatureBadge v-if="video.transcript_path" type="transcript" />
                <FeatureBadge v-if="video.has_terminology" 
                            type="terminology" 
                            :count="video.terminology_count || 0" />
            </div>
        </div>
        
        <!-- Action area -->
        <div class="actions">
            <button 
                class="action-btn view-btn"
                @click="$emit('view', video)"
            >
                View
            </button>
            <button 
                class="action-btn delete-btn"
                @click="confirmDelete"
            >
                Delete
            </button>
        </div>

        <!-- Delete confirmation dialog -->
        <Teleport to="body">
            <div v-if="showDeleteConfirm" class="delete-confirm-overlay" @click.self="showDeleteConfirm = false">
                <div class="delete-confirm-dialog">
                    <h4 class="delete-title">Delete Video?</h4>
                    <p class="delete-message">Are you sure you want to delete "{{ video.original_filename }}"? This action cannot be undone.</p>
                    <div class="confirm-actions">
                        <button class="cancel-btn" @click="showDeleteConfirm = false">Cancel</button>
                        <button class="confirm-delete-btn" @click="handleDelete">Delete</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script>
import { defineComponent, ref, computed } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import FeatureBadge from '@/Components/FeatureBadge.vue';
import CourseIndicator from '@/Components/CourseIndicator.vue';

export default defineComponent({
    components: {
        StatusBadge,
        FeatureBadge,
        CourseIndicator
    },
    props: {
        video: {
            type: Object,
            required: true
        },
        showThumbnail: {
            type: Boolean,
            default: true
        },
        showCourseInfo: {
            type: Boolean,
            default: true
        }
    },
    emits: ['view', 'delete'],
    setup(props, { emit }) {
        const showDeleteConfirm = ref(false);
        const thumbnailError = ref(false);
        
        const thumbnailUrl = computed(() => {
            if (!props.showThumbnail || thumbnailError.value) return null;
            
            // If we have a thumbnail_url property, use it
            if (props.video.thumbnail_url) return props.video.thumbnail_url;
            
            // For this implementation, we generate a placeholder URL
            // In production, this would be replaced with a real thumbnail from S3
            return null;
        });
        
        const isProcessing = computed(() => {
            return props.video.status === 'processing' || props.video.status === 'is_processing';
        });
        
        const isFailed = computed(() => {
            return props.video.status === 'failed';
        });
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        function formatDate(dateString) {
            return new Date(dateString).toLocaleString();
        }
        
        function handleThumbnailError() {
            thumbnailError.value = true;
        }
        
        function confirmDelete() {
            showDeleteConfirm.value = true;
        }
        
        function handleDelete() {
            emit('delete', props.video);
            showDeleteConfirm.value = false;
        }
        
        return {
            showDeleteConfirm,
            thumbnailUrl,
            isProcessing,
            isFailed,
            formatFileSize,
            formatDate,
            handleThumbnailError,
            confirmDelete,
            handleDelete
        };
    }
});
</script>

<style scoped>
.video-card {
    position: relative;
    display: flex;
    flex-direction: column;
    background-color: white;
    border-radius: 0.5rem;
    overflow: hidden;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    transition: all 0.15s ease-in-out;
    border: 1px solid rgba(0, 0, 0, 0.05);
}

.video-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
}

.thumbnail-container {
    position: relative;
    width: 100%;
    height: 0;
    padding-top: 56.25%; /* 16:9 aspect ratio */
    background-color: rgba(0, 0, 0, 0.025);
    overflow: hidden;
}

.thumbnail {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.thumbnail-placeholder {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background-color: rgba(0, 0, 0, 0.025);
}

.placeholder-text {
    font-size: 0.75rem;
    color: #6b7280;
    margin-top: 0.5rem;
    padding: 0 1rem;
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.status-badge {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    z-index: 1;
}

.content {
    flex: 1;
    padding: 1rem;
}

.title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 0.5rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.metadata {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
    font-size: 0.75rem;
    color: #6b7280;
}

.features {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.actions {
    display: flex;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background-color: rgba(0, 0, 0, 0.02);
    border-top: 1px solid rgba(0, 0, 0, 0.05);
}

.action-btn {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 0.25rem;
    transition: all 0.15s ease;
    cursor: pointer;
    border: 1px solid transparent;
}

.view-btn {
    background-color: #3b82f6;
    color: white;
}

.view-btn:hover {
    background-color: #2563eb;
}

.delete-btn {
    background-color: white;
    color: #ef4444;
    border-color: #fecaca;
    margin-left: auto;
}

.delete-btn:hover {
    background-color: #fee2e2;
}

.delete-confirm-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 50;
}

.delete-confirm-dialog {
    background-color: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    max-width: 24rem;
    width: 90%;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

.delete-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 0.75rem;
}

.delete-message {
    font-size: 0.875rem;
    color: #4b5563;
    margin-bottom: 1.5rem;
}

.confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.cancel-btn {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: 0.375rem;
    background-color: #f3f4f6;
    color: #1f2937;
    transition: all 0.15s ease;
}

.cancel-btn:hover {
    background-color: #e5e7eb;
}

.confirm-delete-btn {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: 0.375rem;
    background-color: #ef4444;
    color: white;
    transition: all 0.15s ease;
}

.confirm-delete-btn:hover {
    background-color: #dc2626;
}
</style> 