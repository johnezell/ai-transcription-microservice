<template>
    <div class="min-h-screen bg-gray-50">
        <!-- Header -->
        <div class="bg-white shadow-sm border-b">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center space-x-4">
                        <Link :href="route('truefire-courses.show', course.id)" 
                              class="text-blue-600 hover:text-blue-800 font-medium">
                            ← Back to Course
                        </Link>
                        <div class="h-6 w-px bg-gray-300"></div>
                        <h1 class="text-xl font-semibold text-gray-900">
                            Transcript Review: {{ segment.title || segment.name }}
                        </h1>
                    </div>
                    
                    <!-- Quality Badge -->
                    <div class="flex items-center space-x-3">
                        <div :class="qualityBadgeClass" class="px-3 py-1 rounded-full text-sm font-medium">
                            {{ segment.quality_grade }}
                        </div>
                        <Link :href="route('truefire-courses.segments.show', [course.id, segment.id])" 
                              class="text-gray-600 hover:text-gray-800 text-sm">
                            Technical View →
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 h-[calc(100vh-200px)]">
                
                <!-- Video Player (Left Column) -->
                <div class="lg:col-span-1 lg:sticky lg:top-6 lg:self-start lg:h-fit">
                    <div class="bg-white rounded-lg shadow-sm p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-4">Video</h2>
                        
                        <div v-if="segment.url" class="aspect-video bg-black rounded-lg overflow-hidden">
                            <video
                                ref="videoPlayer"
                                :src="segment.url"
                                controls
                                preload="metadata"
                                class="w-full h-full"
                                @timeupdate="updateCurrentPosition"
                                @loadedmetadata="onVideoLoaded"
                                @play="startHighlightingLoop"
                                @pause="stopHighlightingLoop"
                                @ended="stopHighlightingLoop"
                                @seeking="updateCurrentPosition"
                                @seeked="updateCurrentPosition"
                            >
                                Your browser does not support the video tag.
                            </video>
                        </div>
                        
                        <div v-else class="aspect-video bg-gray-100 rounded-lg flex items-center justify-center">
                            <div class="text-center text-gray-500">
                                <svg class="w-12 h-12 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                          d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <p>Video not available</p>
                            </div>
                        </div>
                        
                        <!-- Video Info -->
                        <div class="mt-4 space-y-1 text-sm text-gray-600">
                            <div v-if="segment.formatted_duration" class="flex justify-between">
                                <span>Duration:</span> <span class="font-medium">{{ segment.formatted_duration }}</span>
                            </div>
                            <div v-if="currentTime" class="flex justify-between">
                                <span>Current:</span> <span class="font-medium">{{ formatTime(currentTime) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transcript (Right Column) -->
                <div class="lg:col-span-2 lg:overflow-y-auto lg:max-h-full">
                    <div class="bg-white rounded-lg shadow-sm">
                        <div class="p-6 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-medium text-gray-900">Transcript</h2>
                                <div class="flex items-center space-x-3">
                                    <button
                                        @click="toggleHighlighting"
                                        :class="highlightingEnabled ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'"
                                        class="px-3 py-1 rounded text-sm font-medium transition-colors"
                                    >
                                        {{ highlightingEnabled ? 'Highlighting: ON' : 'Highlighting: OFF' }}
                                    </button>
                                    <div class="text-sm text-gray-500">
                                        {{ totalWords }} words
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Transcript Content -->
                        <div class="p-6 overflow-hidden pb-20">
                            <div v-if="!segment.has_transcript && transcriptSegments.length === 0" class="text-center py-12 text-gray-500">
                                <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <h3 class="text-lg font-medium mb-2">No Transcript Available</h3>
                                <p>This segment has not been transcribed yet.</p>
                            </div>

                            <!-- Enhanced Segmented Transcript -->
                            <div v-if="segmentedTranscript.length > 0" class="space-y-4">
                                <!-- Review Focus Legend -->
                                <div v-if="highlightingEnabled" class="flex flex-wrap items-center gap-3 text-xs bg-gray-50 p-3 rounded-lg border">
                                    <span class="font-medium text-gray-600">Review Focus:</span>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="px-2 py-1 rounded text-gray-800 border border-gray-200">Clean Display (High Confidence)</span>
                                        <span class="px-2 py-1 rounded bg-red-100 text-red-800 border border-red-200">Needs Review (shows % - Low Confidence)</span>
                                        <span class="px-2 py-1 rounded bg-blue-200 text-blue-800 border border-blue-300">Currently Playing</span>
                                    </div>
                                </div>
                                
                                <!-- Transcript Segments -->
                                <div 
                                    v-for="(segment, segmentIndex) in segmentedTranscript" 
                                    :key="segmentIndex"
                                    class="transcript-segment p-4 bg-white border border-gray-200 rounded-lg hover:border-gray-300 transition-colors shadow-sm overflow-hidden"
                                >
                                    <!-- Segment Header -->
                                    <div class="flex items-center justify-between mb-3 pb-2 border-b border-gray-100">
                                        <div class="flex items-center space-x-2">
                                            <button 
                                                @click="seekToTime(segment.start)"
                                                class="text-xs font-medium text-blue-600 hover:text-blue-800 hover:underline cursor-pointer"
                                                :title="`Jump to ${formatTime(segment.start)}`"
                                            >
                                                {{ formatTime(segment.start) }} - {{ formatTime(segment.end) }}
                                            </button>
                                            <span class="text-xs text-gray-400">•</span>
                                            <span class="text-xs text-gray-500">{{ segment.words.length }} words</span>
                                        </div>
                                        <div v-if="highlightingEnabled" class="text-xs text-gray-500">
                                            Avg: {{ calculateSegmentConfidence(segment) }}%
                                        </div>
                                    </div>
                                    
                                    <!-- Segment Words -->
                                    <div class="text-gray-800 leading-relaxed text-base break-words overflow-wrap-anywhere">
                                        <span 
                                            v-for="(word, wordIndex) in segment.words" 
                                            :key="wordIndex"
                                            class="word-span cursor-pointer transition-all duration-200 rounded px-1 py-0.5 mr-1 inline-block max-w-full break-words"
                                            :class="getWordClasses(word)"
                                            :title="highlightingEnabled && word.confidence < 0.7 ? `${word.word} (${Math.round(word.confidence * 100)}% confidence)` : word.word"
                                            @click="seekToWord(word)"
                                        >{{ word.word }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Fallback Word-by-Word Display -->
                            <div v-else-if="transcriptSegments.length > 0" class="space-y-1 break-words overflow-wrap-anywhere">
                                <span
                                    v-for="(word, index) in transcriptSegments"
                                    :key="index"
                                    :class="getWordClasses(word)"
                                    @click="seekToWord(word)"
                                    class="inline cursor-pointer transition-colors break-words"
                                >
                                    {{ word.word }}{{ word.isPunctuation ? '' : ' ' }}
                                </span>
                            </div>

                            <!-- Fallback Plain Text -->
                            <div v-else-if="segment.transcript_text" 
                                 class="prose max-w-none text-gray-900 leading-relaxed break-words overflow-wrap-anywhere">
                                {{ segment.transcript_text }}
                            </div>
                        </div>
                    </div>

                    <!-- Review Feedback Section -->
                    <div class="mt-6 bg-white rounded-lg shadow-sm">
                        <div class="p-6 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">Review Feedback</h3>
                            <p class="mt-1 text-sm text-gray-600">Provide feedback on transcript accuracy and mark as approved when ready.</p>
                        </div>
                        
                        <!-- Existing Review Status Display -->
                        <div v-if="segment.review_status" class="p-6 border-b border-gray-200">
                            <div class="flex items-start space-x-4">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center" :class="getStatusBadgeClass(segment.review_status)">
                                        <svg v-if="segment.review_status === 'approved'" class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                        <svg v-else-if="segment.review_status === 'needs_revision'" class="w-5 h-5 text-yellow-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                        </svg>
                                        <svg v-else-if="segment.review_status === 'rejected'" class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                </div>
                                <div class="flex-1">
                                    <h4 class="text-sm font-medium text-gray-900">
                                        {{ formatReviewStatus(segment.review_status) }}
                                    </h4>
                                    <p v-if="segment.review_feedback" class="mt-1 text-sm text-gray-600">
                                        {{ segment.review_feedback }}
                                    </p>
                                    <div class="mt-2 text-xs text-gray-500">
                                        Reviewed by {{ segment.reviewed_by }} on {{ formatDate(segment.reviewed_at) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Review Form -->
                        <div class="p-6">
                            <form @submit.prevent="submitReview">
                                <!-- Feedback textarea -->
                                <div class="mb-6">
                                    <label for="feedback" class="block text-sm font-medium text-gray-700 mb-2">
                                        Review Comments
                                    </label>
                                    <textarea
                                        id="feedback"
                                        v-model="reviewForm.feedback"
                                        rows="4"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        placeholder="Provide detailed feedback about transcript accuracy, any corrections needed, or approval notes..."
                                    ></textarea>
                                    <div class="mt-1 text-xs text-gray-500">
                                        Optional: Provide specific feedback about accuracy, terminology, timestamps, or any corrections needed.
                                    </div>
                                </div>

                                <!-- Review Status Options -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 mb-3">Review Decision</label>
                                    <div class="flex items-center space-x-4">
                                        <div class="flex items-center">
                                            <input
                                                id="approved"
                                                v-model="reviewForm.status"
                                                type="radio"
                                                value="approved"
                                                class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300"
                                            >
                                            <label for="approved" class="ml-2 text-sm text-gray-700">
                                                ✅ Approve Transcript
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input
                                                id="needs_revision"
                                                v-model="reviewForm.status"
                                                type="radio"
                                                value="needs_revision"
                                                class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-gray-300"
                                            >
                                            <label for="needs_revision" class="ml-2 text-sm text-gray-700">
                                                ⚠️ Needs Revision
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input
                                                id="rejected"
                                                v-model="reviewForm.status"
                                                type="radio"
                                                value="rejected"
                                                class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300"
                                            >
                                            <label for="rejected" class="ml-2 text-sm text-gray-700">
                                                ❌ Reject Transcript
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <div class="flex items-center justify-between pt-4">
                                    <div class="text-sm text-gray-500">
                                        <span class="font-medium">Reviewer:</span> {{ $page.props.auth.user.name }}
                                    </div>
                                    
                                    <div class="flex space-x-3">
                                        <button
                                            v-if="segment.review_status"
                                            type="button"
                                            @click="clearReview"
                                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500"
                                        >
                                            Clear Review
                                        </button>
                                        
                                        <button
                                            type="submit"
                                            :disabled="isSubmitting || !reviewForm.status"
                                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <span v-if="isSubmitting">Submitting...</span>
                                            <span v-else>Submit Review</span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Transcription Technical Details Section -->
                    <div class="mt-6 bg-white rounded-lg shadow-sm">
                        <div class="p-6 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">Transcription Technical Details</h3>
                                    <p class="mt-1 text-sm text-gray-600">Model settings and prompt used for this transcription</p>
                                </div>
                                <button
                                    @click="showTranscriptionDetails = !showTranscriptionDetails"
                                    class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 focus:ring-2 focus:ring-blue-500 transition-colors"
                                >
                                    <svg v-if="!showTranscriptionDetails" class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                    <svg v-else class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    </svg>
                                    {{ showTranscriptionDetails ? 'Hide Details' : 'Show Details' }}
                                </button>
                            </div>
                        </div>
                        
                        <!-- Technical Details Content (Collapsible) -->
                        <div v-show="showTranscriptionDetails" class="p-6 space-y-6">
                            <!-- Model Configuration -->
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <div class="text-xs font-medium text-gray-600 uppercase tracking-wide mb-1">Model Used</div>
                                    <div class="text-lg font-semibold text-gray-900">{{ transcriptionMetadata.model || 'Small' }}</div>
                                </div>
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <div class="text-xs font-medium text-gray-600 uppercase tracking-wide mb-1">Preset</div>
                                    <div class="text-lg font-semibold text-gray-900">{{ transcriptionMetadata.preset || 'Balanced' }}</div>
                                </div>
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <div class="text-xs font-medium text-gray-600 uppercase tracking-wide mb-1">Processing Time</div>
                                    <div class="text-lg font-semibold text-gray-900">{{ transcriptionMetadata.processingTime || 'N/A' }}</div>
                                </div>
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <div class="text-xs font-medium text-gray-600 uppercase tracking-wide mb-1">Enhancement</div>
                                    <div class="text-lg font-semibold text-gray-900">
                                        <span class="inline-flex items-center">
                                            <svg class="w-4 h-4 mr-1 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                                            </svg>
                                            Guitar Terms
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- WhisperX Prompt Display -->
                            <div v-if="transcriptionPrompt.text" class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-blue-900">WhisperX Transcription Prompt</h4>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ transcriptionPrompt.length }} characters
                                        </span>
                                        <span v-if="transcriptionPrompt.dynamic" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                            Dynamic Context
                                        </span>
                                    </div>
                                    <button
                                        @click="showFullPrompt = !showFullPrompt"
                                        class="text-sm text-blue-700 hover:text-blue-900 font-medium"
                                    >
                                        {{ showFullPrompt ? 'Show Preview' : 'Show Full Prompt' }}
                                    </button>
                                </div>
                                
                                <div class="bg-white border border-blue-200 rounded-lg p-4">
                                    <div v-if="!showFullPrompt" class="text-sm text-gray-700 font-mono leading-relaxed">
                                        {{ transcriptionPrompt.preview }}
                                        <span v-if="transcriptionPrompt.text.length > 200" class="text-blue-600 font-sans">
                                            ... <button @click="showFullPrompt = true" class="underline hover:no-underline">(show full prompt)</button>
                                        </span>
                                    </div>
                                    <div v-else class="text-sm text-gray-700 font-mono leading-relaxed max-h-96 overflow-y-auto">
                                        {{ transcriptionPrompt.text }}
                                    </div>
                                </div>

                                <!-- Prompt Context Information -->
                                <div v-if="transcriptionPrompt.context" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                    <div v-if="transcriptionPrompt.context.productName">
                                        <div class="text-xs font-medium text-gray-600 mb-1">Product Context</div>
                                        <div class="text-gray-900">{{ transcriptionPrompt.context.productName }}</div>
                                    </div>
                                    <div v-if="transcriptionPrompt.context.courseTitle">
                                        <div class="text-xs font-medium text-gray-600 mb-1">Course Context</div>
                                        <div class="text-gray-900">{{ transcriptionPrompt.context.courseTitle }}</div>
                                    </div>
                                    <div v-if="transcriptionPrompt.context.instructorName">
                                        <div class="text-xs font-medium text-gray-600 mb-1">Instructor Context</div>
                                        <div class="text-gray-900">{{ transcriptionPrompt.context.instructorName }}</div>
                                    </div>
                                </div>
                            </div>

                            <!-- No Prompt Available -->
                            <div v-else class="bg-gray-50 border border-gray-200 rounded-lg p-6 text-center">
                                <div class="text-gray-500">
                                    <svg class="w-8 h-8 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <p class="text-sm">Transcription prompt not available for this segment.</p>
                                    <p class="text-xs text-gray-400 mt-1">This may be from an older transcription before prompt tracking was implemented.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'

export default {
    name: 'SegmentReview',
    components: {
        Head,
        Link,
    },
    props: {
        course: {
            type: Object,
            required: true
        },
        segment: {
            type: Object,
            required: true
        }
    },
    setup(props) {
        // Reactive data
        const videoPlayer = ref(null)
        const currentTime = ref(0)
        const videoDuration = ref(0)
        const transcriptSegments = ref([])
        const highlightingEnabled = ref(true)
        const animationFrameId = ref(null)
        
        // Review functionality
        const reviewForm = ref({
            feedback: '',
            status: null
        })
        const isSubmitting = ref(false)

        // Transcription technical details
        const showTranscriptionDetails = ref(false)
        const showFullPrompt = ref(false)
        const transcriptionPrompt = ref({
            text: '',
            preview: '',
            length: 0,
            dynamic: false,
            context: {
                productName: '',
                courseTitle: '',
                instructorName: ''
            }
        })
        const transcriptionMetadata = ref({
            model: '',
            preset: '',
            processingTime: '',
            enhancement: ''
        })

        // Computed properties
        const qualityBadgeClass = computed(() => {
            const grade = props.segment.quality_grade
            switch (grade) {
                case 'Excellent':
                    return 'bg-green-100 text-green-800'
                case 'Good':
                    return 'bg-blue-100 text-blue-800'
                case 'Fair':
                    return 'bg-yellow-100 text-yellow-800'
                case 'Needs Review':
                    return 'bg-red-100 text-red-800'
                default:
                    return 'bg-gray-100 text-gray-800'
            }
        })

        const totalWords = computed(() => {
            return transcriptSegments.value.length
        })

        const segmentedTranscript = computed(() => {
            if (!transcriptSegments.value.length) return []
            
            // Group words into segments for better readability
            const segments = []
            let currentSegment = null
            
            transcriptSegments.value.forEach((word, index) => {
                // Start a new segment every ~15-20 words or at natural breaks
                const shouldStartNewSegment = !currentSegment || 
                    currentSegment.words.length >= 18 ||
                    (word.start && currentSegment.end && (word.start - currentSegment.end) > 2) // 2 second gap
                
                if (shouldStartNewSegment) {
                    if (currentSegment) {
                        segments.push(currentSegment)
                    }
                    currentSegment = {
                        start: word.start || 0,
                        end: word.end || 0,
                        words: []
                    }
                }
                
                if (currentSegment) {
                    currentSegment.words.push(word)
                    currentSegment.end = word.end || currentSegment.end
                }
            })
            
            // Add the last segment
            if (currentSegment && currentSegment.words.length > 0) {
                segments.push(currentSegment)
            }
            
            return segments
        })

        // Methods
        const formatTime = (seconds) => {
            if (!seconds || isNaN(seconds)) return '0:00'
            const mins = Math.floor(seconds / 60)
            const secs = Math.floor(seconds % 60)
            return `${mins}:${secs.toString().padStart(2, '0')}`
        }

        const updateCurrentPosition = () => {
            if (videoPlayer.value) {
                currentTime.value = videoPlayer.value.currentTime
            }
        }

        // Fast highlighting update using requestAnimationFrame
        const startHighlightingLoop = () => {
            const updateHighlighting = () => {
                if (videoPlayer.value && !videoPlayer.value.paused && !videoPlayer.value.ended) {
                    currentTime.value = videoPlayer.value.currentTime
                }
                animationFrameId.value = requestAnimationFrame(updateHighlighting)
            }
            updateHighlighting()
        }

        const stopHighlightingLoop = () => {
            if (animationFrameId.value) {
                cancelAnimationFrame(animationFrameId.value)
                animationFrameId.value = null
            }
        }

        const onVideoLoaded = () => {
            if (videoPlayer.value) {
                videoDuration.value = videoPlayer.value.duration
            }
        }

        const getWordClasses = (word) => {
            const baseClasses = ''
            
            if (!highlightingEnabled.value) {
                return baseClasses
            }

            // Highlight current word (video sync)
            const isCurrentWord = currentTime.value >= word.start && currentTime.value <= word.end
            if (isCurrentWord) {
                return `${baseClasses} bg-blue-200 text-blue-900 font-medium border border-blue-300`
            }

            // Only highlight low confidence words that need review (< 70%)
            const confidence = word.confidence || 0
            if (confidence < 0.7) {
                return `${baseClasses} bg-red-100 text-red-900 hover:bg-red-200 border border-red-200`
            } else {
                // High confidence words: clean display with no background color
                return `${baseClasses} hover:bg-gray-50`
            }
        }

        const calculateSegmentConfidence = (segment) => {
            if (!segment.words || !segment.words.length) return 0
            
            const totalConfidence = segment.words.reduce((sum, word) => {
                return sum + (word.confidence || 0)
            }, 0)
            
            return Math.round((totalConfidence / segment.words.length) * 100)
        }

        const seekToTime = (time) => {
            if (videoPlayer.value && typeof time === 'number') {
                videoPlayer.value.currentTime = time
                // Auto-play if paused to help user see the content
                if (videoPlayer.value.paused) {
                    videoPlayer.value.play().catch(e => {
                        // Ignore auto-play errors (user interaction may be required)
                        console.log('Auto-play not allowed:', e)
                    })
                }
            }
        }

        const seekToWord = (word) => {
            if (videoPlayer.value && word.start) {
                videoPlayer.value.currentTime = word.start
            }
        }

        const toggleHighlighting = () => {
            highlightingEnabled.value = !highlightingEnabled.value
        }

        const loadTranscriptData = async () => {
            // Use the correct transcript JSON endpoint
            const transcriptApiUrl = `/api/truefire-courses/${props.course.id}/segments/${props.segment.id}/transcript-json`
            
            try {
                const response = await fetch(transcriptApiUrl)
                if (!response.ok) {
                    console.error('Failed to load transcript data:', response.status)
                    return
                }
                const data = await response.json()
                
                // Store for manual inspection if needed
                window.transcriptDebugData = data
                
                if (data.segments) {
                    // Flatten all words from all segments
                    const words = []
                    data.segments.forEach((segment, segIndex) => {
                        
                        if (segment.words && Array.isArray(segment.words)) {
                            segment.words.forEach((word, wordIndex) => {
                                // Handle multiple possible confidence field names - score is primary
                                let confidence = word.score || word.confidence || word.probability || 0;
                                
                                // WhisperX sometimes stores confidence as a percentage (0-100) vs decimal (0-1)
                                if (confidence > 1) {
                                    confidence = confidence / 100;
                                }
                                
                                const processedWord = {
                                    word: word.word || word.text || '',
                                    start: word.start || 0,
                                    end: word.end || 0,
                                    confidence: confidence,
                                    originalConfidence: word.original_confidence || confidence,
                                    isPunctuation: /^[^\w\s]$/.test(word.word || word.text || '')
                                }
                                
                                words.push(processedWord)
                            })
                        }
                    })
                    

                    
                    transcriptSegments.value = words
                } else if (data.words && Array.isArray(data.words)) {
                    // Direct words array
                    transcriptSegments.value = data.words.map(word => {
                        // Handle multiple possible confidence field names - score is primary
                        let confidence = word.score || word.confidence || word.probability || 0;
                        
                        // WhisperX sometimes stores confidence as a percentage (0-100) vs decimal (0-1)
                        if (confidence > 1) {
                            confidence = confidence / 100;
                        }
                        
                        return {
                            word: word.word || word.text || '',
                            start: word.start || 0,
                            end: word.end || 0,
                            confidence: confidence,
                            originalConfidence: word.original_confidence || confidence,
                            isPunctuation: /^[^\w\s]$/.test(word.word || word.text || '')
                        }
                    })
                }

                // Load technical details after transcript data is loaded
                loadTechnicalDetails(data)
                
            } catch (error) {
                console.error('Error loading transcript data:', error)
            }
        }

        const loadTechnicalDetails = (transcriptData) => {
            try {
                // Extract transcription metadata
                const settings = transcriptData.settings || {}
                const metadata = transcriptData.metadata || {}
                
                // Load transcription metadata
                transcriptionMetadata.value = {
                    model: settings.model || metadata.model || 'small',
                    preset: settings.preset_name || settings.preset || 'balanced',
                    processingTime: metadata.processing_time || settings.processing_time || 'N/A',
                    enhancement: settings.enable_guitar_term_evaluation ? 'Guitar Terms' : 'Standard'
                }

                // Load transcription prompt
                const promptUsed = settings.initial_prompt_used || settings.initial_prompt || ''
                
                if (promptUsed) {
                    transcriptionPrompt.value = {
                        text: promptUsed,
                        preview: promptUsed.length > 200 ? promptUsed.substring(0, 200) : promptUsed,
                        length: promptUsed.length,
                        dynamic: promptUsed.includes('TrueFire') || promptUsed.includes(props.course.title) || promptUsed.includes('instructor'),
                        context: {
                            productName: promptUsed.includes('TrueFire') ? 'TrueFire Guitar Lessons' : '',
                            courseTitle: promptUsed.includes(props.course.title) ? props.course.title : '',
                            instructorName: extractInstructorFromPrompt(promptUsed)
                        }
                    }
                } else {
                    // Clear prompt data if not available
                    transcriptionPrompt.value = {
                        text: '',
                        preview: '',
                        length: 0,
                        dynamic: false,
                        context: {}
                    }
                }
                
            } catch (error) {
                console.error('Error loading technical details:', error)
            }
        }

        const extractInstructorFromPrompt = (prompt) => {
            // Try to extract instructor name from prompt patterns
            const instructorMatch = prompt.match(/featuring ([^.]+)/i) || 
                                   prompt.match(/taught by ([^.]+)/i) ||
                                   prompt.match(/instructor ([^.]+)/i)
            
            return instructorMatch ? instructorMatch[1].trim() : ''
        }

        // Review methods
        const submitReview = async () => {
            if (!reviewForm.value.status) {
                alert('Please select a review status.')
                return
            }

            isSubmitting.value = true

            try {
                const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${props.segment.id}/review`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        feedback: reviewForm.value.feedback,
                        status: reviewForm.value.status
                    })
                })

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`)
                }

                const result = await response.json()
                
                // Update segment data with new review info
                Object.assign(props.segment, result.segment)
                
                // Reset form
                reviewForm.value.feedback = ''
                reviewForm.value.status = null
                
                alert('Review submitted successfully!')
                
            } catch (error) {
                console.error('Error submitting review:', error)
                alert('Failed to submit review. Please try again.')
            } finally {
                isSubmitting.value = false
            }
        }

        const clearReview = async () => {
            if (!confirm('Are you sure you want to clear this review?')) {
                return
            }

            isSubmitting.value = true

            try {
                const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${props.segment.id}/review`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json'
                    }
                })

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`)
                }

                const result = await response.json()
                
                // Update segment data
                Object.assign(props.segment, result.segment)
                
                // Reset form
                reviewForm.value.feedback = ''
                reviewForm.value.status = null
                
                alert('Review cleared successfully!')
                
            } catch (error) {
                console.error('Error clearing review:', error)
                alert('Failed to clear review. Please try again.')
            } finally {
                isSubmitting.value = false
            }
        }

        const formatReviewStatus = (status) => {
            switch (status) {
                case 'approved':
                    return 'Approved'
                case 'needs_revision':
                    return 'Needs Revision'
                case 'rejected':
                    return 'Rejected'
                default:
                    return status
            }
        }

        const getStatusBadgeClass = (status) => {
            switch (status) {
                case 'approved':
                    return 'bg-green-50 border-green-200'
                case 'needs_revision':
                    return 'bg-yellow-50 border-yellow-200'
                case 'rejected':
                    return 'bg-red-50 border-red-200'
                default:
                    return 'bg-gray-50 border-gray-200'
            }
        }

        const formatDate = (dateString) => {
            if (!dateString) return ''
            const date = new Date(dateString)
            return date.toLocaleString()
        }

        // Lifecycle
        onMounted(() => {
            loadTranscriptData()
        })

        onUnmounted(() => {
            stopHighlightingLoop()
        })

        return {
            // Refs
            videoPlayer,
            currentTime,
            videoDuration,
            transcriptSegments,
            highlightingEnabled,
            reviewForm,
            isSubmitting,
            showTranscriptionDetails,
            showFullPrompt,
            transcriptionPrompt,
            transcriptionMetadata,

            // Computed
            qualityBadgeClass,
            totalWords,
            segmentedTranscript,

            // Methods
            formatTime,
            updateCurrentPosition,
            startHighlightingLoop,
            stopHighlightingLoop,
            onVideoLoaded,
            getWordClasses,
            seekToWord,
            seekToTime,
            toggleHighlighting,
            loadTranscriptData,
            loadTechnicalDetails,
            extractInstructorFromPrompt,
            calculateSegmentConfidence,
            submitReview,
            clearReview,
            formatReviewStatus,
            getStatusBadgeClass,
            formatDate,
        }
    }
}
</script> 