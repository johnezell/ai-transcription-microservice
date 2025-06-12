<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount, computed, nextTick } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import TranscriptionTimeline from '@/Components/TranscriptionTimeline.vue';
import SynchronizedTranscript from '@/Components/SynchronizedTranscript.vue';
import AdvancedSubtitles from '@/Components/AdvancedSubtitles.vue';
import MusicTermsViewer from '@/Components/MusicTermsViewer.vue';
import TerminologyViewer from '@/Components/TerminologyViewer.vue';

const props = defineProps({
    course: Object,
    segment: Object,
});

const segmentData = ref(props.segment);
const timelineData = ref({
    timing: {},
    progress_percentage: 0,
    status: props.segment.status
});
const pollingInterval = ref(null);
const videoError = ref(null);
const videoElement = ref(null);
const audioElement = ref(null);
const isLoading = ref(false);
const lastPolled = ref(Date.now());
const transcriptData = ref(null);
const overallConfidence = ref(null);
const processingTerminology = ref(false);
const showSynchronizedTranscript = ref(false); // Hidden by default
const showDetailedQualityMetrics = ref(false); // Hidden by default
const showGuitarEnhancementDetails = ref(false); // Hidden by default

// Modal state
const showStartProcessingModal = ref(false);
const showRestartProcessingModal = ref(false);
const showErrorModal = ref(false);
const errorMessage = ref('');
const confirmAction = ref(null);

// Check if this is a newly started processing that needs monitoring
const isNewProcessing = computed(() => {
    // If created less than 2 minutes ago, treat as new
    if (!segmentData.value.updated_at) return false;
    const updatedTime = new Date(segmentData.value.updated_at).getTime();
    const now = Date.now();
    return (now - updatedTime) < 120000; // 2 minutes in milliseconds
});

function startPolling() {
    // Poll if the segment is being processed or newly updated
    const isProcessing = 
        segmentData.value.status === 'processing' || 
        segmentData.value.status === 'transcribing' || 
        segmentData.value.is_processing || 
        segmentData.value.status === 'audio_extracted' ||
        segmentData.value.status === 'transcribed' ||
        segmentData.value.status === 'processing_terminology' ||
        isNewProcessing.value;
    
    // Only poll if the segment is being processed or newly updated
    if (!isProcessing) {
        return;
    }
    
    // Poll every 3 seconds
    pollingInterval.value = setInterval(fetchStatus, 3000);
}

async function fetchStatus() {
    try {
        // Set loading state for first fetch
        if (Date.now() - lastPolled.value > 5000) {
            isLoading.value = true;
        }
        
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/status`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        
        const data = await response.json();
        
        lastPolled.value = Date.now();
        isLoading.value = false;
        
        if (data.success) {
            // Check if status changed from processing to completed
            const wasProcessing = segmentData.value.status === 'processing' || 
                                  segmentData.value.status === 'transcribing' || 
                                  segmentData.value.status === 'transcribed' ||
                                  segmentData.value.status === 'processing_terminology';
            const nowCompleted = data.segment.status === 'completed';
            
            // Update the segment data
            if (data.segment.status !== segmentData.value.status) {
                segmentData.value.status = data.segment.status;
                
                // If status changed from processing to completed, force a full refresh
                if (wasProcessing && nowCompleted) {
                    await fetchSegmentDetails();
                    return;
                }
                
                // If status changed to a new state, force refresh for newly created processing
                if (isNewProcessing.value && 
                    (data.segment.status === 'completed' || 
                     data.segment.has_audio || 
                     data.segment.has_transcript ||
                     data.segment.has_terminology)) {
                    window.location.reload();
                    return;
                }
            }
            
            // Copy all available properties from the response to our segment data
            if (data.segment) {
                // Copy standard properties
                segmentData.value.error_message = data.segment.error_message;
                segmentData.value.is_processing = data.segment.is_processing || 
                    ['processing', 'transcribing', 'transcribed', 'processing_terminology'].includes(data.segment.status);
                
                // Update terminology properties if they exist
                if (data.segment.has_terminology) {
                    segmentData.value.has_terminology = true;
                    segmentData.value.terminology_url = data.segment.terminology_url;
                    segmentData.value.terminology_count = data.segment.terminology_count;
                    segmentData.value.terminology_metadata = data.segment.terminology_metadata;
                }
                
                // Copy URLs if they exist
                if (data.segment.url) segmentData.value.url = data.segment.url;
                if (data.segment.audio_url) segmentData.value.audio_url = data.segment.audio_url;
                if (data.segment.transcript_url) segmentData.value.transcript_url = data.segment.transcript_url;
                if (data.segment.subtitles_url) segmentData.value.subtitles_url = data.segment.subtitles_url;
                if (data.segment.transcript_json_url) {
                    const oldUrl = segmentData.value.transcript_json_url;
                    segmentData.value.transcript_json_url = data.segment.transcript_json_url;
                    
                    // Only fetch if transcript_json_api_url is new or changed (not just transcript_json_url)
                    const newApiUrl = `/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/transcript-json`;
                    if (newApiUrl !== lastFetchedUrl.value) {
                        segmentData.value.transcript_json_api_url = newApiUrl;
                        fetchTranscriptData();
                    }
                }
            }
            
            // Update timeline data
            timelineData.value = {
                status: data.status,
                progress_percentage: data.progress_percentage,
                timing: data.timing || {},
                error: data.segment.error_message
            };
            
            // Stop polling once processing is complete
            if (data.segment.status === 'completed' || data.segment.status === 'failed') {
                stopPolling();
                
                // Fetch complete segment data instead of reloading
                fetchSegmentDetails();
            }
        }
    } catch (error) {
        console.error('Error fetching status:', error);
        isLoading.value = false;
    }
}

// New function to fetch full segment details
async function fetchSegmentDetails() {
    try {
        // Make sure we have an ID
        if (!segmentData.value.id) {
            console.error('Cannot fetch segment details: No segment ID available');
            return;
        }
        
        // Use relative URL to avoid CORS issues
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Update our local data with the full segment details
            Object.assign(segmentData.value, data.segment);
            
            // After updating the segment data, try to fetch the transcript JSON
            // if the transcript_json_url is now available
            if (segmentData.value.transcript_json_url) {
                await fetchTranscriptData();
            }
        } else {
            console.error('API returned error:', data.message || 'Unknown error');
        }
    } catch (error) {
        console.error('Error fetching segment details:', error);
    }
}

function stopPolling() {
    if (pollingInterval.value) {
        clearInterval(pollingInterval.value);
        pollingInterval.value = null;
    }
}

function handleVideoError(event) {
    videoError.value = event.target.error ? event.target.error.message : 'An error occurred while loading the video.';
}

function handleAudioError(event) {
    console.error('Audio error:', event.target.error);
}

// Generate audio URL from path
function getAudioUrl() {
    if (!segmentData.value.audio_path) return null;
    
    // Convert internal path to accessible URL
    // The path is like "/mnt/d_drive/truefire-courses/1/7959.wav"
    // We need to convert this to a public URL
    const pathParts = segmentData.value.audio_path.split('/');
    const filename = pathParts[pathParts.length - 1]; // "7959.wav"
    const courseId = pathParts[pathParts.length - 2]; // "1"
    
    // Return API endpoint for serving the audio file
    return `/api/truefire-courses/${courseId}/segments/${segmentData.value.id}/audio`;
}

// Add a function to fetch and process transcript JSON data
const lastFetchedUrl = ref(null);
const isTranscriptLoading = ref(false);

async function fetchTranscriptData() {
    const currentUrl = segmentData.value.transcript_json_api_url;
    
    // Skip if no URL or same URL already fetched or currently loading
    if (!currentUrl || currentUrl === lastFetchedUrl.value || isTranscriptLoading.value) {
        return;
    }
    
    isTranscriptLoading.value = true;
    
    try {
        const response = await fetch(currentUrl);
        
        if (!response.ok) {
            throw new Error('Failed to fetch transcript data');
        }
        
        transcriptData.value = await response.json();
        lastFetchedUrl.value = currentUrl;
        calculateOverallConfidence();
        
        // Extract guitar term enhancement data if available
        if (transcriptData.value.guitar_term_evaluation) {
            guitarEnhancementMetrics.value = transcriptData.value.guitar_term_evaluation;
            console.log('Guitar enhancement metrics loaded from transcript:', guitarEnhancementMetrics.value);
        }
        
        console.log('Transcript data loaded successfully');
    } catch (error) {
        console.error('Error fetching transcript data:', error);
        transcriptData.value = null;
    } finally {
        isTranscriptLoading.value = false;
    }
}

// Calculate overall confidence from transcript data
function calculateOverallConfidence() {
    if (!transcriptData.value || !transcriptData.value.segments) {
        return;
    }
    
    let totalWords = 0;
    let confidenceSum = 0;
    
    // Go through all segments and words to sum up confidence values
    transcriptData.value.segments.forEach(segment => {
        if (Array.isArray(segment.words)) {
            segment.words.forEach(word => {
                // Check for both 'probability' and 'score' fields (different transcript formats)
                const confidence = word.probability !== undefined ? word.probability : word.score;
                if (confidence !== undefined) {
                    confidenceSum += parseFloat(confidence);
                    totalWords++;
                }
            });
        }
    });
    
    // Calculate average confidence if we have words
    if (totalWords > 0) {
        overallConfidence.value = confidenceSum / totalWords;
    }
}

// Quality metrics data
const qualityMetrics = ref(null);
const guitarEnhancementMetrics = ref(null);

// Fetch quality metrics
async function fetchQualityMetrics() {
    if (!segmentData.value?.transcript_json_api_url) return;
    
    try {
        const response = await fetch(segmentData.value.transcript_json_api_url);
        const data = await response.json();
        
        // Check if quality metrics are available in the transcript data
        if (data.quality_metrics || data.speech_activity || data.content_quality) {
            qualityMetrics.value = data.quality_metrics || data;
            console.log('Quality metrics loaded:', qualityMetrics.value);
        }
        
        // Extract guitar term enhancement data
        if (data.guitar_term_evaluation) {
            guitarEnhancementMetrics.value = data.guitar_term_evaluation;
            console.log('Guitar enhancement metrics loaded:', guitarEnhancementMetrics.value);
        }
    } catch (error) {
        console.error('Error fetching quality metrics:', error);
    }
}

// Calculate guitar term enhancement analysis
const guitarEnhancementAnalysis = computed(() => {
    if (!guitarEnhancementMetrics.value || !transcriptData.value) {
        return null;
    }
    
    const enhancement = guitarEnhancementMetrics.value;
    const enhancedTerms = enhancement.enhanced_terms || [];
    
    // Calculate original vs enhanced confidence and unique terms
    let originalSum = 0;
    let enhancedSum = 0;
    let totalWords = 0;
    let totalOccurrences = 0;
    const uniqueTerms = new Set();
    
    // Process enhanced terms to get unique words and calculate averages
    enhancedTerms.forEach(term => {
        originalSum += term.original_confidence || 0;
        enhancedSum += term.new_confidence || 0;
        totalOccurrences++;
        
        // Add to set for unique count (normalize to lowercase for consistency)
        if (term.word) {
            uniqueTerms.add(term.word.toLowerCase().trim());
        }
    });
    
    // Calculate overall averages from all segments for comparison
    if (transcriptData.value.segments) {
        transcriptData.value.segments.forEach(segment => {
            if (segment.words) {
                segment.words.forEach(word => {
                    const confidence = word.score || word.probability || 0;
                    totalWords++;
                });
            }
        });
    }
    
    return {
        guitarTermsFound: uniqueTerms.size, // Count distinct terms, not total occurrences
        totalOccurrences: totalOccurrences, // Keep track of total for detailed analysis
        originalAverageConfidence: totalOccurrences > 0 ? originalSum / totalOccurrences : 0,
        enhancedAverageConfidence: totalOccurrences > 0 ? enhancedSum / totalOccurrences : 0,
        improvementPercentage: totalOccurrences > 0 ? ((enhancedSum / totalOccurrences) - (originalSum / totalOccurrences)) * 100 : 0,
        enhancedTerms: enhancedTerms,
        uniqueTermsList: Array.from(uniqueTerms).sort(), // List of unique terms for reference
        totalWordsEvaluated: enhancement.total_words_evaluated || totalWords,
        evaluatorVersion: enhancement.evaluator_version || 'Unknown',
        llmUsed: enhancement.llm_used || 'Library Only',
        libraryStats: enhancement.library_statistics || {},
        enhancementEnabled: true
    };
});

// Calculate teaching pattern analysis
const teachingPatternAnalysis = computed(() => {
    const patterns = qualityMetrics.value?.teaching_patterns;
    if (!patterns) return null;
    
    return {
        detected_patterns: patterns.detected_patterns || [],
        content_classification: patterns.content_classification || {},
        summary: patterns.summary || {},
        temporal_analysis: patterns.temporal_analysis || {},
        algorithm_metadata: patterns.algorithm_metadata || {}
    };
});



// Calculate detailed confidence analysis
const confidenceAnalysis = computed(() => {
    if (!transcriptData.value || !transcriptData.value.segments) {
        return null;
    }
    
    const analysis = {
        totalWords: 0,
        averageConfidence: 0,
        highConfidenceWords: 0,    // >= 0.8
        mediumConfidenceWords: 0,  // 0.5 - 0.8
        lowConfidenceWords: 0,     // < 0.5
        segments: [],
        confidenceDistribution: {
            excellent: 0,  // 0.9-1.0
            good: 0,       // 0.7-0.9
            fair: 0,       // 0.5-0.7
            poor: 0        // 0.0-0.5
        },
        lowConfidenceSegments: []
    };
    
    transcriptData.value.segments.forEach((segment, segmentIndex) => {
        const segmentAnalysis = {
            index: segmentIndex,
            start: segment.start,
            end: segment.end,
            text: segment.text,
            wordCount: 0,
            averageConfidence: 0,
            confidenceSum: 0,
            lowConfidenceWords: []
        };
        
        if (Array.isArray(segment.words) && segment.words.length > 0) {
            segment.words.forEach((word, wordIndex) => {
                // Check for both 'probability' and 'score' fields (different transcript formats)
                const confidenceValue = word.probability !== undefined ? word.probability : word.score;
                if (confidenceValue !== undefined) {
                    const confidence = parseFloat(confidenceValue);
                    
                    analysis.totalWords++;
                    segmentAnalysis.wordCount++;
                    segmentAnalysis.confidenceSum += confidence;
                    
                    // Categorize confidence levels
                    if (confidence >= 0.9) analysis.confidenceDistribution.excellent++;
                    else if (confidence >= 0.7) analysis.confidenceDistribution.good++;
                    else if (confidence >= 0.5) analysis.confidenceDistribution.fair++;
                    else analysis.confidenceDistribution.poor++;
                    
                    // Count confidence levels
                    if (confidence >= 0.8) analysis.highConfidenceWords++;
                    else if (confidence >= 0.5) analysis.mediumConfidenceWords++;
                    else analysis.lowConfidenceWords++;
                    
                    // Track low confidence words
                    if (confidence < 0.5) {
                        segmentAnalysis.lowConfidenceWords.push({
                            word: word.word,
                            confidence: confidence,
                            start: word.start,
                            end: word.end,
                            index: wordIndex
                        });
                    }
                }
            });
        } else if (segment.confidence !== undefined) {
            // Fallback to segment-level confidence if no word-level data
            const segmentConfidence = parseFloat(segment.confidence);
            const estimatedWordCount = Math.ceil(segment.text.split(' ').length);
            
            analysis.totalWords += estimatedWordCount;
            segmentAnalysis.wordCount = estimatedWordCount;
            segmentAnalysis.confidenceSum = segmentConfidence * estimatedWordCount;
            
            // Categorize based on segment confidence
            for (let i = 0; i < estimatedWordCount; i++) {
                if (segmentConfidence >= 0.9) analysis.confidenceDistribution.excellent++;
                else if (segmentConfidence >= 0.7) analysis.confidenceDistribution.good++;
                else if (segmentConfidence >= 0.5) analysis.confidenceDistribution.fair++;
                else analysis.confidenceDistribution.poor++;
                
                if (segmentConfidence >= 0.8) analysis.highConfidenceWords++;
                else if (segmentConfidence >= 0.5) analysis.mediumConfidenceWords++;
                else analysis.lowConfidenceWords++;
            }
        }
            
            // Calculate segment average confidence
            if (segmentAnalysis.wordCount > 0) {
                segmentAnalysis.averageConfidence = segmentAnalysis.confidenceSum / segmentAnalysis.wordCount;
            }
            
            // Track low confidence segments
            if (segmentAnalysis.averageConfidence < 0.6) {
                analysis.lowConfidenceSegments.push(segmentAnalysis);
            }
        
        analysis.segments.push(segmentAnalysis);
    });
    
    // Calculate overall average
    if (analysis.totalWords > 0) {
        const totalConfidenceSum = analysis.segments.reduce((sum, seg) => sum + seg.confidenceSum, 0);
        analysis.averageConfidence = totalConfidenceSum / analysis.totalWords;
    }
    
    return analysis;
});

// Helper function to format time in MM:SS format
function formatTime(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

// Helper function to format file sizes
function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return 'N/A';
    
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${sizes[i]}`;
}

// Helper function to format timestamps
function formatTimestamp(timestamp) {
    if (!timestamp) return 'N/A';
    
    const date = new Date(timestamp);
    return date.toLocaleString();
}

// Helper function to format duration (for processing times)
function formatDuration(seconds) {
    if (!seconds || seconds === 0) return 'N/A';
    
    if (seconds < 60) {
        return `${Math.round(seconds)}s`;
    } else if (seconds < 3600) {
        const mins = Math.floor(seconds / 60);
        const secs = Math.round(seconds % 60);
        return `${mins}m ${secs}s`;
    } else {
        const hours = Math.floor(seconds / 3600);
        const mins = Math.floor((seconds % 3600) / 60);
        return `${hours}h ${mins}m`;
    }
}

// Helper function to calculate individual processing step durations
function getProcessingStepDurations() {
    if (!segmentData.value) return {};
    
    const durations = {};
    
    // Audio extraction duration
    if (segmentData.value.audio_extraction_started_at && segmentData.value.audio_extraction_completed_at) {
        const start = new Date(segmentData.value.audio_extraction_started_at);
        const end = new Date(segmentData.value.audio_extraction_completed_at);
        durations.audioExtraction = Math.max(0, (end - start) / 1000);
    }
    
    // Transcription duration
    if (segmentData.value.transcription_started_at && segmentData.value.transcription_completed_at) {
        const start = new Date(segmentData.value.transcription_started_at);
        const end = new Date(segmentData.value.transcription_completed_at);
        durations.transcription = Math.max(0, (end - start) / 1000);
    }
    
    // Terminology processing duration
    if (segmentData.value.terminology_started_at && segmentData.value.terminology_completed_at) {
        const start = new Date(segmentData.value.terminology_started_at);
        const end = new Date(segmentData.value.terminology_completed_at);
        durations.terminology = Math.max(0, (end - start) / 1000);
    }
    
    // Wait time between audio extraction and transcription
    if (segmentData.value.audio_extraction_completed_at && segmentData.value.transcription_started_at) {
        const audioEnd = new Date(segmentData.value.audio_extraction_completed_at);
        const transcriptionStart = new Date(segmentData.value.transcription_started_at);
        durations.queueWait = Math.max(0, (transcriptionStart - audioEnd) / 1000);
    }
    
    // Total end-to-end duration
    const firstStart = segmentData.value.audio_extraction_started_at || segmentData.value.transcription_started_at;
    const lastEnd = segmentData.value.terminology_completed_at || segmentData.value.transcription_completed_at || segmentData.value.audio_extraction_completed_at;
    
    if (firstStart && lastEnd) {
        const start = new Date(firstStart);
        const end = new Date(lastEnd);
        durations.total = Math.max(0, (end - start) / 1000);
    }
    
    return durations;
}

// Helper function for backwards compatibility
function getProcessingDuration() {
    const durations = getProcessingStepDurations();
    return durations.total || 0;
}

// Calculate processing efficiency metrics
const processingMetrics = computed(() => {
    if (!segmentData.value) return null;
    
    const durations = getProcessingStepDurations();
    const videoDuration = segmentData.value.runtime || segmentData.value.audio_duration || 0;
    
    // Debug: Log what durations we're getting
    if (segmentData.value.status === 'completed') {
        console.log('DEBUG: Duration calculations:', {
            audioExtraction: durations.audioExtraction,
            transcription: durations.transcription,
            total: durations.total,
            queueWait: durations.queueWait
        });
        console.log('DEBUG: Raw timestamps:', {
            audio_start: segmentData.value.audio_extraction_started_at,
            audio_end: segmentData.value.audio_extraction_completed_at,
            transcription_start: segmentData.value.transcription_started_at,
            transcription_end: segmentData.value.transcription_completed_at,
            terminology_start: segmentData.value.terminology_started_at,
            terminology_end: segmentData.value.terminology_completed_at
        });
        
        // Debug: Manual duration calculations to identify the issue
        if (segmentData.value.audio_extraction_started_at && segmentData.value.audio_extraction_completed_at) {
            const start = new Date(segmentData.value.audio_extraction_started_at);
            const end = new Date(segmentData.value.audio_extraction_completed_at);
            console.log('DEBUG: Audio extraction calculation:', {
                start_parsed: start,
                end_parsed: end,
                start_time: start.getTime(),
                end_time: end.getTime(),
                difference_ms: end.getTime() - start.getTime(),
                difference_seconds: (end.getTime() - start.getTime()) / 1000,
                math_max_result: Math.max(0, (end.getTime() - start.getTime()) / 1000)
            });
        }
        
        if (segmentData.value.transcription_started_at && segmentData.value.transcription_completed_at) {
            const start = new Date(segmentData.value.transcription_started_at);
            const end = new Date(segmentData.value.transcription_completed_at);
            console.log('DEBUG: Transcription calculation:', {
                start_parsed: start,
                end_parsed: end,
                start_time: start.getTime(),
                end_time: end.getTime(),
                difference_ms: end.getTime() - start.getTime(),
                difference_seconds: (end.getTime() - start.getTime()) / 1000,
                math_max_result: Math.max(0, (end.getTime() - start.getTime()) / 1000)
            });
        }
    }
    
    // Show metrics even with partial data
    if (!durations.total && !durations.audioExtraction && !durations.transcription) return null;
    
    return {
        durations,
        videoDuration,
        efficiencyRatio: durations.total && videoDuration > 0 ? (durations.total / videoDuration).toFixed(2) : 'N/A',
        audioExtractionRatio: durations.audioExtraction && videoDuration > 0 ? (durations.audioExtraction / videoDuration).toFixed(2) : 'N/A',
        transcriptionRatio: durations.transcription && videoDuration > 0 ? (durations.transcription / videoDuration).toFixed(2) : 'N/A',
        bottleneck: getBiggestBottleneck(durations),
        processingRate: durations.total && videoDuration > 0 ? (videoDuration / durations.total).toFixed(2) : 'N/A'
    };
});

// Identify the biggest processing bottleneck
function getBiggestBottleneck(durations) {
    if (!durations || Object.keys(durations).length === 0) return null;
    
    const steps = {
        'Audio Extraction': durations.audioExtraction || 0,
        'Queue Wait': durations.queueWait || 0,
        'Transcription': durations.transcription || 0,
        'Terminology': durations.terminology || 0
    };
    
    const maxStep = Object.entries(steps).reduce((max, [step, duration]) => 
        duration > max.duration ? { step, duration } : max, 
        { step: null, duration: 0 }
    );
    
    return maxStep.duration > 0 ? maxStep : null;
}

// Helper function to get teaching pattern styling
function getPatternStyle(patternType) {
    const styles = {
        'instructional': {
            color: 'blue',
            bgColor: 'bg-blue-50',
            borderColor: 'border-blue-200',
            textColor: 'text-blue-800',
            icon: '🎯',
            description: 'Balanced instruction with clear teaching cycles'
        },
        'demonstration': {
            color: 'purple',
            bgColor: 'bg-purple-50',
            borderColor: 'border-purple-200',
            textColor: 'text-purple-800',
            icon: '🎸',
            description: 'Demo-heavy with extensive playing examples'
        },
        'overview': {
            color: 'green',
            bgColor: 'bg-green-50',
            borderColor: 'border-green-200',
            textColor: 'text-green-800',
            icon: '📖',
            description: 'Overview-style with structured introduction'
        },
        'performance': {
            color: 'amber',
            bgColor: 'bg-amber-50',
            borderColor: 'border-amber-200',
            textColor: 'text-amber-800',
            icon: '🎵',
            description: 'Performance-focused with minimal verbal instruction'
        }
    };
    
    return styles[patternType] || {
        color: 'gray',
        bgColor: 'bg-gray-50',
        borderColor: 'border-gray-200',
        textColor: 'text-gray-800',
        icon: '❓',
        description: 'Unknown pattern type'
    };
}

// Helper function to get pattern strength color
function getPatternStrengthColor(strength) {
    const colors = {
        'Strong': 'text-green-700',
        'Moderate': 'text-yellow-700',
        'Weak': 'text-red-700'
    };
    return colors[strength] || 'text-gray-700';
}

// Check if we have any processing timestamps to show
const hasProcessingTimestamps = computed(() => {
    if (!segmentData.value) return false;
    
    return !!(
        segmentData.value.audio_extraction_started_at ||
        segmentData.value.audio_extraction_completed_at ||
        segmentData.value.transcription_started_at ||
        segmentData.value.transcription_completed_at ||
        getProcessingDuration() > 0
    );
});

// Check if we have advanced metrics data to show
const hasAdvancedMetricsData = computed(() => {
    // Show metrics if we have transcription success OR if it's a performance video
    if (!transcriptionSuccess.value.success && !isPerformanceVideo.value) return false;
    
    return !!(
        guitarEnhancementAnalysis.value ||
        qualityMetrics.value ||
        confidenceAnalysis.value ||
        teachingPatternAnalysis.value ||
        (transcriptData.value && transcriptData.value.segments) ||
        isPerformanceVideo.value // Always show some data for performance videos
    );
});

// Function to jump to specific time in video
function jumpToTime(timeInSeconds) {
    if (videoElement.value) {
        videoElement.value.currentTime = timeInSeconds;
        // Play the video if it's paused to help user see the segment
        if (videoElement.value.paused) {
            videoElement.value.play().catch(e => {
                console.log('Could not auto-play video:', e);
            });
        }
        // Scroll video into view if needed
        videoElement.value.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Add the terminology recognition trigger method
async function triggerTerminologyRecognition() {
    if (!segmentData.value || !segmentData.value.id) {
        console.error('No segment data available');
        return;
    }
    
    processingTerminology.value = true;
    
    try {
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/terminology`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            }
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Terminology recognition triggered successfully');
            // Start polling for segment status to show processing indicator
            startPolling();
        } else {
            console.error('Failed to trigger terminology recognition:', data.message);
            showError('Failed to start terminology recognition: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error triggering terminology recognition:', error);
        showError('Error: ' + (error.message || 'Failed to communicate with server'));
    } finally {
        processingTerminology.value = false;
    }
}

// Show start processing confirmation modal
function showStartProcessingConfirmation() {
    showStartProcessingModal.value = true;
}

// Add transcription request method for segments that haven't been processed yet
async function startProcessing() {
    showStartProcessingModal.value = false;
    
    try {
        const response = await fetch(`/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/transcription`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Processing started successfully');
            // Update status and start polling
            segmentData.value.status = 'processing';
            segmentData.value.error_message = null;
            segmentData.value.progress_percentage = 0;
            startPolling();
        } else {
            console.error('Failed to start processing:', data.message);
            showError('Failed to start processing: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error starting processing:', error);
        showError('Error: ' + (error.message || 'Failed to communicate with server'));
    }
}

// Add restart transcription method for failed segments
async function restartTranscription() {
    try {
        const response = await fetch(`/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/transcription/restart`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Transcription restart requested successfully');
            // Update status to processing and start polling
            segmentData.value.status = 'processing';
            segmentData.value.error_message = null;
            startPolling();
        } else {
            console.error('Failed to restart transcription:', data.message);
            showError('Failed to restart transcription: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error restarting transcription:', error);
        showError('Error: ' + (error.message || 'Failed to communicate with server'));
    }
}

// Add abort processing method
async function abortProcessing() {
    // This function is called after confirmation from modal
    
    try {
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/abort`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Processing aborted successfully');
            // Update status and refresh data
            segmentData.value.status = 'ready';
            segmentData.value.error_message = null;
            segmentData.value.progress_percentage = 0;
            // Stop polling and refresh status
            stopPolling();
            fetchStatus();
        } else {
            console.error('Failed to abort processing:', data.message);
            showError('Failed to abort processing: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error aborting processing:', error);
        showError('Error: ' + (error.message || 'Failed to communicate with server'));
    }
}



// Show restart processing confirmation modal
function showRestartProcessingConfirmation() {
    showRestartProcessingModal.value = true;
}

// Show error modal
function showError(message) {
    errorMessage.value = message;
    showErrorModal.value = true;
}

// Simplified restart processing method using intelligent detection
async function restartProcessing() {
    showRestartProcessingModal.value = false;
    
    try {
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${segmentData.value.id}/redo`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                force_reextraction: true,
                overwrite_existing: true,
                use_intelligent_detection: true
            })
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            console.log('Processing restart started successfully with intelligent detection');
            // Update status to processing and start polling
            segmentData.value.status = 'processing';
            segmentData.value.error_message = null;
            segmentData.value.progress_percentage = 0;
            // Clear existing data
            segmentData.value.transcript_text = null;
            segmentData.value.transcript_json_url = null;
            segmentData.value.transcript_json_api_url = null;
            segmentData.value.has_terminology = false;
            segmentData.value.terminology_url = null;
            startPolling();
        } else {
            console.error('Failed to start restart processing:', data.message);
            showError('Failed to start restart processing: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error starting restart processing:', error);
        showError('Error: ' + (error.message || 'Failed to communicate with server'));
    }
}

onMounted(() => {
    // Get initial status immediately
    fetchStatus();
    
    // Fetch transcript data if available
    fetchTranscriptData();
    
    // Fetch quality metrics if available
    fetchQualityMetrics();
    
    // Then start polling after a short delay to ensure backend has time to update
    setTimeout(() => {
        startPolling();
    }, 1000);
    
    // Set up video synchronization when video element is ready
    nextTick(() => {
        initializeVideoSync();
    });
});



onBeforeUnmount(() => {
    stopPolling();
});

// Function to initialize video synchronization
function initializeVideoSync() {
    // Retry video sync setup if video element isn't ready yet
    const checkVideoReady = () => {
        const videoEl = document.querySelector('video');
        if (videoEl) {
            // Set the ref manually and force component updates
            videoElement.value = videoEl;
            forceComponentRefresh();
            console.log('Video element found and set for synchronization');
        } else {
            // Retry after a short delay
            setTimeout(checkVideoReady, 100);
        }
    };
    
    checkVideoReady();
}

// Force refresh of transcript components
const componentKey = ref(Date.now());
function forceComponentRefresh() {
    componentKey.value = Date.now();
}

// Helper function to detect performance videos with minimal speech
const isPerformanceVideo = computed(() => {
    // Check if this was already flagged as a performance video by the processing system
    const processingMetadata = segmentData.value?.processing_metadata;
    if (processingMetadata) {
        let metadata = null;
        try {
            metadata = typeof processingMetadata === 'string' ? JSON.parse(processingMetadata) : processingMetadata;
        } catch (e) {
            // Ignore JSON parse errors
        }
        
        if (metadata?.performance_video) {
            return true;
        }
    }
    
    // Check if transcript indicates performance video
    const transcriptText = segmentData.value?.transcript_text || '';
    if (transcriptText === '[Instrumental Performance]' || 
        transcriptData.value?.performance_video_metadata?.auto_generated) {
        return true;
    }
    
    // Check speech activity metrics for performance video indicators
    const speechActivity = qualityMetrics.value?.speech_activity;
    const contentQuality = qualityMetrics.value?.content_quality;
    const teachingPattern = teachingPatternAnalysis.value?.content_classification?.primary_type;
    
    // ENHANCED: Detect performance videos even without full quality metrics
    if (speechActivity) {
        // Performance video indicators:
        // 1. Very low speech activity ratio (< 30%)
        // 2. Very few words relative to duration
        // 3. Teaching pattern marked as "performance"
        // 4. High non-speech ratio with minimal instruction words
        
        const speechRatio = speechActivity.speech_activity_ratio || 0;
        const totalWords = contentQuality?.total_words || 0;
        const duration = speechActivity.total_duration_seconds || 1;
        const wordsPerMinute = (totalWords / (duration / 60));
        
        // Performance video criteria
        const lowSpeechActivity = speechRatio < 0.3; // Less than 30% speech
        const veryFewWords = totalWords < 50; // Less than 50 words total
        const lowWordRate = wordsPerMinute < 20; // Less than 20 words per minute
        const isPerformancePattern = teachingPattern === 'performance';
        
        // Classify as performance if multiple indicators present
        if ((lowSpeechActivity && (veryFewWords || lowWordRate)) || isPerformancePattern) {
            return true;
        }
    }
    
    // FALLBACK: Detect performance videos from transcript characteristics alone
    if (segmentData.value?.status === 'completed') {
        // Count actual words in transcript (excluding common filler)
        const words = transcriptText.replace(/\[.*?\]/g, '').trim().split(/\s+/).filter(word => word.length > 0);
        const wordCount = words.length;
        
        // Check confidence analysis if available
        const totalAnalyzedWords = confidenceAnalysis.value?.totalWords || 0;
        
        // If we have very few words or no meaningful content
        if (wordCount <= 5 || totalAnalyzedWords <= 5) {
            // Additional checks for performance videos:
            // 1. Empty or nearly empty transcript
            // 2. Only short phrases like "here we go" or single words
            // 3. Transcript that's mostly noise/artifacts
            
            const meaningfulContent = transcriptText.replace(/\b(uh|um|yeah|okay|alright|here|we|go|one|two|three|four)\b/gi, '').trim();
            
            if (meaningfulContent.length < 20) {
                return true;
            }
        }
        
        // Check for failed transcription patterns that might indicate performance videos
        if (!transcriptText || transcriptText.trim().length === 0) {
            return true;
        }
    }
    
    return false;
});

// Helper function to calculate overall grade - Enhanced with performance video detection
const overallGrade = computed(() => {
    // SPECIAL HANDLING FOR PERFORMANCE VIDEOS - Check this first
    if (isPerformanceVideo.value && segmentData.value?.status === 'completed') {
        const transcriptText = segmentData.value?.transcript_text || '';
        const totalWords = confidenceAnalysis.value?.totalWords || transcriptText.split(/\s+/).filter(w => w.length > 0).length || 0;
        
        // Performance videos are graded differently - focus on what speech exists
        if (totalWords === 0 || transcriptText.trim().length < 10) {
            // Pure instrumental performance - this is normal and expected
            return { 
                grade: 'P', 
                color: 'purple', 
                description: 'Performance Video (Instrumental)' 
            };
        } else if (totalWords < 20) {
            // Minimal speech performance (announcements, brief instruction)
            const avgConfidence = confidenceAnalysis.value?.averageConfidence || 0.8; // Default to decent for minimal speech
            if (avgConfidence >= 0.7) {
                return { 
                    grade: 'P', 
                    color: 'purple', 
                    description: 'Performance Video (Minimal Speech - Good Quality)' 
                };
            } else {
                return { 
                    grade: 'P-', 
                    color: 'purple', 
                    description: 'Performance Video (Minimal Speech - Low Quality)' 
                };
            }
        } else {
            // Performance with some instruction - grade the speech that exists
            const avgConfidence = confidenceAnalysis.value?.averageConfidence || 0.75; // Default for performance videos
            if (avgConfidence >= 0.75) {
                return { 
                    grade: 'P+', 
                    color: 'purple', 
                    description: 'Performance Video (With Good Instruction)' 
                };
            } else {
                return { 
                    grade: 'P', 
                    color: 'purple', 
                    description: 'Performance Video (With Instruction)' 
                };
            }
        }
    }
    
    if (!confidenceAnalysis.value && !qualityMetrics.value) {
        return { grade: 'N/A', color: 'gray', description: 'No analysis available' };
    }
    
    // Standard grading for completed transcript (non-performance videos handled above)  
    if (segmentData.value?.status === 'completed' && segmentData.value?.transcript_text) {
        // STANDARD GRADING FOR INSTRUCTIONAL VIDEOS
        // Calculate composite score from available metrics with generous weighting
        let totalScore = 0;
        let weightedSum = 0;
        
        // Confidence score (50% weight) - Realistic assessment with small boost
        if (confidenceAnalysis.value?.averageConfidence) {
            const confidence = confidenceAnalysis.value.averageConfidence;
            // Apply realistic +5% boost for transcription challenges
            const adjustedConfidence = Math.min(1.0, confidence + 0.05);
            totalScore += adjustedConfidence * 0.5;
            weightedSum += 0.5;
        }
        
        // Quality metrics (30% weight) - Realistic assessment with small boost
        if (qualityMetrics.value?.overall_quality_score) {
            const quality = qualityMetrics.value.overall_quality_score;
            const adjustedQuality = Math.min(1.0, quality + 0.05);
            totalScore += adjustedQuality * 0.3;
            weightedSum += 0.3;
        }
        
        // Guitar enhancement bonus (20% weight)
        if (guitarEnhancementAnalysis.value?.guitarTermsFound > 0) {
            totalScore += 0.2; // Full credit for guitar enhancement
            weightedSum += 0.2;
        }
        
        // If no detailed metrics, default to B grade for completed transcripts
        if (weightedSum === 0) {
            return { grade: 'B', color: 'blue', description: 'Good transcription quality' };
        }
        
        // Normalize score
        const finalScore = totalScore / weightedSum;
        
        // Convert to grade with realistic transcription scale
        if (finalScore >= 0.85) return { grade: 'A', color: 'green', description: 'Excellent transcription quality' };
        if (finalScore >= 0.75) return { grade: 'B', color: 'blue', description: 'Good transcription quality' };
        if (finalScore >= 0.65) return { grade: 'C', color: 'yellow', description: 'Fair transcription quality' };
        if (finalScore >= 0.55) return { grade: 'D', color: 'orange', description: 'Poor transcription quality' };
        return { grade: 'F', color: 'red', description: 'Failed transcription quality' };
    }
    
    return { grade: 'N/A', color: 'gray', description: 'Processing incomplete' };
});

// Enhanced success indicator with performance video support
const transcriptionSuccess = computed(() => {
    if (segmentData.value?.status !== 'completed') {
        return { 
            success: false, 
            title: getStatusTitle(segmentData.value?.status), 
            message: getStatusMessage(segmentData.value?.status),
            actionNeeded: segmentData.value?.status === 'failed' 
        };
    }
    
    const grade = overallGrade.value;
    
    // SPECIAL HANDLING FOR PERFORMANCE VIDEOS
    if (isPerformanceVideo.value) {
        // Performance videos with no transcript are actually expected and successful
        if (!segmentData.value?.transcript_text || segmentData.value.transcript_text.trim().length < 10) {
            return { 
                success: true, 
                title: 'Performance Video Processed', 
                message: 'Pure instrumental performance - no speech content expected',
                actionNeeded: false 
            };
        }
        
        // Performance videos with minimal speech are still successful
        return { 
            success: true, 
            title: 'Performance Video Processed', 
            message: `${grade.description} (Grade: ${grade.grade})`,
            actionNeeded: false 
        };
    }
    
    // STANDARD HANDLING FOR INSTRUCTIONAL VIDEOS
    if (!segmentData.value?.transcript_text) {
        return { 
            success: false, 
            title: 'No Transcript Available', 
            message: 'Transcription completed but no text was generated',
            actionNeeded: true 
        };
    }
    
    if (grade.grade === 'F') {
        return { 
            success: false, 
            title: 'Low Quality Transcript', 
            message: 'Transcription completed but quality is very poor - consider re-processing',
            actionNeeded: true 
        };
    }
    
    return { 
        success: true, 
        title: 'Transcription Successful', 
        message: `${grade.description} (Grade: ${grade.grade})`,
        actionNeeded: false 
    };
});

// Calculate key summary metrics
const summaryMetrics = computed(() => {
    const metrics = {
        wordCount: 0,
        duration: segmentData.value?.formatted_duration || formatTime(segmentData.value?.runtime || 0),
        confidence: 0,
        musicTerms: 0,
        issues: 0,
        teachingPattern: null
    };
    
    // Word count from transcript or confidence analysis
    if (confidenceAnalysis.value?.totalWords) {
        metrics.wordCount = confidenceAnalysis.value.totalWords;
    } else if (segmentData.value?.transcript_text) {
        metrics.wordCount = segmentData.value.transcript_text.split(' ').length;
    }
    
    // Overall confidence
    if (confidenceAnalysis.value?.averageConfidence) {
        metrics.confidence = confidenceAnalysis.value.averageConfidence;
    }
    
    // Music terms found
    if (guitarEnhancementAnalysis.value?.guitarTermsFound) {
        metrics.musicTerms = guitarEnhancementAnalysis.value.guitarTermsFound;
    }
    
    // Count issues (low confidence words)
    if (confidenceAnalysis.value?.lowConfidenceWords) {
        metrics.issues = confidenceAnalysis.value.lowConfidenceWords;
    }
    
    // Teaching pattern
    if (teachingPatternAnalysis.value?.content_classification?.primary_type) {
        metrics.teachingPattern = {
            type: teachingPatternAnalysis.value.content_classification.primary_type,
            confidence: teachingPatternAnalysis.value.content_classification.confidence,
            icon: getPatternStyle(teachingPatternAnalysis.value.content_classification.primary_type).icon
        };
    }
    
    return metrics;
});

// Control visibility of detailed sections
const showAdvancedMetrics = ref(false);
const showProcessingDetails = ref(false);

// Helper functions for status messaging
function getStatusMessage(status) {
    switch (status) {
        case 'ready': return 'Click "Start Processing" to extract audio and create transcript';
        case 'processing': return 'Audio extraction and transcription in progress...';
        case 'transcribing': return 'Generating transcript from audio...';
        case 'transcribed': return 'Transcript generated, applying enhancements...';
        case 'processing_terminology': return 'Analyzing musical terminology...';
        case 'completed': return 'Processing completed successfully';
        case 'failed': return 'Processing failed - see error details below';
        default: return 'Processing status unknown';
    }
}

function getStatusTitle(status) {
    switch (status) {
        case 'ready': return 'Ready to Process';
        case 'processing': return 'Processing in Progress';
        case 'transcribing': return 'Creating Transcript';
        case 'transcribed': return 'Enhancing Transcript';
        case 'processing_terminology': return 'Analyzing Terminology';
        case 'completed': return 'Processing Complete';
        case 'failed': return 'Processing Failed';
        default: return 'Processing Status Unknown';
    }
}
</script> 

<template>
    <Head :title="`${segmentData.title || segmentData.name} - ${course.title || 'TrueFire Course'}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ segmentData.title || segmentData.name || `Segment #${segmentData.id}` }}
                </h2>
                <div class="flex items-center space-x-3">
                    <Link :href="route('truefire-courses.show', course.id)" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700 transition">
                        &larr; Back to Course
                    </Link>
                    <Link :href="route('truefire-courses.index')" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700 transition">
                        &larr; All Courses
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex flex-col md:flex-row md:space-x-6">
                            <!-- LEFT SIDEBAR - Status and Controls -->
                            <div class="md:w-1/3 mb-6 md:mb-0">
                                <!-- COMPACT STATUS INDICATOR -->
                                <div class="mb-4">
                                    <div class="rounded-lg p-3 border" :class="{
                                        'bg-green-50 border-green-200': transcriptionSuccess.success,
                                        'bg-red-50 border-red-200': !transcriptionSuccess.success && transcriptionSuccess.actionNeeded,
                                        'bg-blue-50 border-blue-200': !transcriptionSuccess.success && !transcriptionSuccess.actionNeeded
                                    }">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center">
                                                <!-- Status Icon (smaller) -->
                                                <div class="flex-shrink-0 mr-3">
                                                    <div class="w-8 h-8 rounded-full flex items-center justify-center" :class="{
                                                        'bg-green-100': transcriptionSuccess.success,
                                                        'bg-red-100': !transcriptionSuccess.success && transcriptionSuccess.actionNeeded,
                                                        'bg-blue-100': !transcriptionSuccess.success && !transcriptionSuccess.actionNeeded
                                                    }">
                                                        <svg v-if="transcriptionSuccess.success" class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                        </svg>
                                                        <svg v-else-if="transcriptionSuccess.actionNeeded" class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        </svg>
                                                        <svg v-else class="w-4 h-4 text-blue-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                                
                                                <div>
                                                    <div class="font-medium text-sm" :class="{
                                                        'text-green-800': transcriptionSuccess.success,
                                                        'text-red-800': !transcriptionSuccess.success && transcriptionSuccess.actionNeeded,
                                                        'text-blue-800': !transcriptionSuccess.success && !transcriptionSuccess.actionNeeded
                                                    }">{{ transcriptionSuccess.title }}</div>
                                                    <div class="text-xs text-gray-600">{{ transcriptionSuccess.message }}</div>
                                                </div>
                                            </div>
                                            
                                            <div class="flex items-center space-x-3">
                                                <!-- Grade Badge (smaller) -->
                                                <div v-if="transcriptionSuccess.success && overallGrade.grade !== 'N/A'" class="text-center">
                                                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white" :class="{
                                                        'bg-green-500': overallGrade.color === 'green',
                                                        'bg-blue-500': overallGrade.color === 'blue',
                                                        'bg-yellow-500': overallGrade.color === 'yellow',
                                                        'bg-orange-500': overallGrade.color === 'orange',
                                                        'bg-red-500': overallGrade.color === 'red',
                                                        'bg-purple-500': overallGrade.color === 'purple'
                                                    }">{{ overallGrade.grade.length > 1 ? overallGrade.grade.charAt(0) : overallGrade.grade }}</div>
                                                </div>
                                                
                                                <!-- Show appropriate button based on segment status -->
                                                <button 
                                                    v-if="segmentData.status === 'ready'"
                                                    @click="showStartProcessingConfirmation" 
                                                    class="px-3 py-1 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded transition"
                                                    title="Start processing with intelligent detection"
                                                >
                                                    Start Processing
                                                </button>
                                                <button 
                                                    v-else-if="segmentData.status === 'completed' || segmentData.status === 'failed'"
                                                    @click="showRestartProcessingConfirmation" 
                                                    class="px-3 py-1 text-xs bg-gray-600 hover:bg-gray-700 text-white rounded transition"
                                                    title="Restart processing with intelligent detection"
                                                >
                                                    Restart
                                                </button>
                                                <div 
                                                    v-else-if="['processing', 'transcribing', 'transcribed', 'processing_terminology'].includes(segmentData.status)"
                                                    class="px-3 py-1 text-xs bg-yellow-100 text-yellow-800 rounded border border-yellow-200"
                                                    title="Processing in progress"
                                                >
                                                    Processing...
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- SEGMENT INFORMATION -->
                                <div class="bg-gray-50 rounded-lg p-5 shadow-sm border border-gray-200 mb-6">
                                    <h3 class="text-lg font-medium mb-4">Segment Information</h3>
                                    
                                    <div class="space-y-4">
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Course</div>
                                            <div class="font-medium text-sm">{{ course.title || `Course #${course.id}` }}</div>
                                        </div>

                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Segment ID</div>
                                            <div class="font-medium text-sm">{{ segmentData.id }}</div>
                                        </div>

                                        <div v-if="segmentData.name">
                                            <div class="text-gray-500 text-sm mb-1">Name</div>
                                            <div class="font-medium text-sm">{{ segmentData.name }}</div>
                                        </div>
                                        
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Status</div>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" 
                                                :class="{
                                                    'bg-green-100 text-green-800': segmentData.status === 'completed',
                                                    'bg-blue-100 text-blue-800': segmentData.status === 'processing',
                                                    'bg-purple-100 text-purple-800': segmentData.status === 'transcribing',
                                                    'bg-gray-100 text-gray-800': segmentData.status === 'ready',
                                                    'bg-red-100 text-red-800': segmentData.status === 'failed',
                                                }">
                                                {{ segmentData.status }}
                                            </span>
                                        </div>
                                        
                                        <div>
                                            <div class="text-gray-500 text-sm mb-1">Updated</div>
                                            <div class="font-medium text-sm">{{ new Date(segmentData.updated_at).toLocaleString() }}</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- KEY SUMMARY METRICS -->
                                <div v-if="summaryMetrics.wordCount > 0" class="bg-gray-50 rounded-lg p-5 shadow-sm border border-gray-200 mb-6">
                                    <h3 class="text-lg font-medium mb-3">Summary</h3>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="bg-white rounded-lg p-3 border border-gray-200 text-center">
                                            <div class="text-lg font-bold text-gray-900">{{ summaryMetrics.wordCount.toLocaleString() }}</div>
                                            <div class="text-xs text-gray-600">Words</div>
                                        </div>
                                        
                                        <div class="bg-white rounded-lg p-3 border border-gray-200 text-center">
                                            <div class="text-lg font-bold text-gray-900">{{ summaryMetrics.duration }}</div>
                                            <div class="text-xs text-gray-600">Duration</div>
                                        </div>
                                        
                                        <div v-if="summaryMetrics.confidence > 0" class="bg-white rounded-lg p-3 border border-gray-200 text-center">
                                            <div class="text-lg font-bold" :style="{color: getConfidenceColor(summaryMetrics.confidence)}">{{ (summaryMetrics.confidence * 100).toFixed(0) }}%</div>
                                            <div class="text-xs text-gray-600">Confidence</div>
                                        </div>
                                        
                                        <div v-if="summaryMetrics.musicTerms > 0" class="bg-white rounded-lg p-3 border border-gray-200 text-center">
                                            <div class="text-lg font-bold text-purple-600">{{ summaryMetrics.musicTerms }}</div>
                                            <div class="text-xs text-gray-600">Music Terms</div>
                                        </div>
                                        
                                        <div v-if="summaryMetrics.teachingPattern" class="bg-white rounded-lg p-3 border border-gray-200 text-center col-span-2">
                                            <div class="flex items-center justify-center">
                                                <span class="text-lg mr-2">{{ summaryMetrics.teachingPattern.icon }}</span>
                                                <div class="text-left">
                                                    <div class="text-sm font-bold text-indigo-600 capitalize">{{ summaryMetrics.teachingPattern.type }}</div>
                                                    <div class="text-xs text-gray-600">Teaching Pattern</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Compact Processing Status -->
                                <div v-if="['processing', 'transcribing', 'transcribed', 'processing_terminology'].includes(segmentData.status)" 
                                     class="bg-blue-50 rounded-lg p-4 border border-blue-200 mb-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center">
                                            <div class="w-3 h-3 bg-blue-500 rounded-full animate-pulse mr-2"></div>
                                            <span class="text-sm font-medium text-blue-800">{{ getStatusTitle(segmentData.status) }}</span>
                                        </div>
                                        <span class="text-xs text-blue-600">{{ timelineData.progress_percentage || 0 }}%</span>
                                    </div>
                                    <div class="w-full bg-blue-200 rounded-full h-2">
                                        <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" 
                                             :style="{ width: (timelineData.progress_percentage || 0) + '%' }"></div>
                                    </div>
                                    <div class="text-xs text-blue-600 mt-1">{{ getStatusMessage(segmentData.status) }}</div>
                                </div>

                                <!-- Technical Details -->
                                <div class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200 mb-4">
                                    <h3 class="text-sm font-medium mb-3 text-gray-800">File Details</h3>
                                    <div class="space-y-3">
                                        <div v-if="segmentData.audio_size">
                                            <div class="text-gray-500 text-xs mb-1">Audio File Size</div>
                                            <div class="font-medium text-sm">{{ formatFileSize(segmentData.audio_size) }}</div>
                                        </div>

                                        <div v-if="segmentData.video_size || segmentData.filesize">
                                            <div class="text-gray-500 text-xs mb-1">Video File Size</div>
                                            <div class="font-medium text-sm">{{ formatFileSize(segmentData.video_size || segmentData.filesize) }}</div>
                                        </div>

                                        <div v-if="segmentData.audio_path">
                                            <div class="text-gray-500 text-xs mb-1">Audio Path</div>
                                            <div class="font-mono text-xs text-gray-700 break-all">{{ segmentData.audio_path }}</div>
                                        </div>

                                        <div v-if="segmentData.video_path || segmentData.localpath">
                                            <div class="text-gray-500 text-xs mb-1">Video Path</div>
                                            <div class="font-mono text-xs text-gray-700 break-all">{{ segmentData.video_path || segmentData.localpath }}</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Processing Performance Analysis -->
                                <div v-if="hasProcessingTimestamps || segmentData.status === 'completed'" class="bg-gray-50 rounded-lg p-4 shadow-sm border border-gray-200 mb-4">
                                    <h3 class="text-sm font-medium mb-3 text-gray-800">Processing Performance</h3>
                                    
                                    <!-- Performance Summary -->
                                    <div v-if="processingMetrics" class="mb-4 p-3 bg-white rounded-lg border border-gray-200">
                                        <div class="grid grid-cols-2 gap-3 text-xs">
                                            <div>
                                                <div class="text-gray-500 mb-1">Total Time</div>
                                                <div class="font-medium">{{ formatDuration(processingMetrics.durations.total) }}</div>
                                            </div>
                                            <div>
                                                <div class="text-gray-500 mb-1">Efficiency</div>
                                                <div class="font-medium" :class="getEfficiencyColor(processingMetrics.efficiencyRatio)">
                                                    {{ processingMetrics.efficiencyRatio }}x real-time
                                                </div>
                                            </div>
                                            <div v-if="processingMetrics.bottleneck">
                                                <div class="text-gray-500 mb-1">Bottleneck</div>
                                                <div class="font-medium text-red-600">{{ processingMetrics.bottleneck.step }}</div>
                                            </div>
                                            <div>
                                                <div class="text-gray-500 mb-1">Processing Rate</div>
                                                <div class="font-medium text-blue-600">{{ processingMetrics.processingRate }}x speed</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Fallback when no detailed timing available -->
                                    <div v-else-if="segmentData.status === 'completed'" class="mb-4 p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                                        <div class="text-sm text-yellow-800 mb-2">
                                            ⏱️ Processing completed but detailed timing data not available
                                        </div>
                                        <div class="text-xs text-yellow-700 mb-3">
                                            Timing analytics aren't capturing data properly.
                                            Available fields: {{ Object.keys(segmentData).filter(key => key.includes('_at')).join(', ') || 'None' }}
                                        </div>
                                        
                                        <!-- Show any available timing info -->
                                        <div v-if="segmentData.updated_at" class="text-xs text-gray-600">
                                            <div><strong>Last Updated:</strong> {{ formatTimestamp(segmentData.updated_at) }}</div>
                                        </div>
                                    </div>

                                    <!-- Step-by-Step Breakdown -->
                                    <div v-if="processingMetrics" class="space-y-3">
                                        <!-- Audio Extraction -->
                                        <div v-if="processingMetrics?.durations.audioExtraction" class="flex items-center justify-between p-2 bg-white rounded border border-gray-200">
                                            <div class="flex items-center">
                                                <div class="w-3 h-3 bg-blue-500 rounded-full mr-2"></div>
                                                <span class="text-xs font-medium">Audio Extraction</span>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-xs font-medium">{{ formatDuration(processingMetrics.durations.audioExtraction) }}</div>
                                                <div class="text-xs text-gray-500">{{ processingMetrics.audioExtractionRatio }}x</div>
                                            </div>
                                        </div>

                                        <!-- Queue Wait Time -->
                                        <div v-if="processingMetrics?.durations.queueWait > 0" class="flex items-center justify-between p-2 bg-white rounded border border-gray-200">
                                            <div class="flex items-center">
                                                <div class="w-3 h-3 bg-yellow-500 rounded-full mr-2"></div>
                                                <span class="text-xs font-medium">Queue Wait</span>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-xs font-medium text-yellow-600">{{ formatDuration(processingMetrics.durations.queueWait) }}</div>
                                                <div class="text-xs text-gray-500">idle time</div>
                                            </div>
                                        </div>

                                        <!-- Transcription -->
                                        <div v-if="processingMetrics?.durations.transcription" class="flex items-center justify-between p-2 bg-white rounded border border-gray-200">
                                            <div class="flex items-center">
                                                <div class="w-3 h-3 bg-purple-500 rounded-full mr-2"></div>
                                                <span class="text-xs font-medium">Transcription</span>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-xs font-medium">{{ formatDuration(processingMetrics.durations.transcription) }}</div>
                                                <div class="text-xs text-gray-500">{{ processingMetrics.transcriptionRatio }}x</div>
                                            </div>
                                        </div>

                                        <!-- Terminology Processing -->
                                        <div v-if="processingMetrics?.durations.terminology" class="flex items-center justify-between p-2 bg-white rounded border border-gray-200">
                                            <div class="flex items-center">
                                                <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                                                <span class="text-xs font-medium">Terminology Analysis</span>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-xs font-medium">{{ formatDuration(processingMetrics.durations.terminology) }}</div>
                                                <div class="text-xs text-gray-500">enhancement</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Performance Insights -->
                                    <div v-if="processingMetrics" class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                                        <div class="text-xs">
                                            <div class="font-medium text-blue-800 mb-2">📊 Performance Insights</div>
                                            
                                            <!-- Efficiency Analysis -->
                                            <div v-if="processingMetrics.efficiencyRatio > 3" class="text-blue-700 mb-1">
                                                ⚡ High efficiency: Processing took {{ processingMetrics.efficiencyRatio }}x longer than video duration
                                            </div>
                                            <div v-else-if="processingMetrics.efficiencyRatio > 1.5" class="text-blue-700 mb-1">
                                                ⏱️ Good efficiency: Processing reasonably fast at {{ processingMetrics.efficiencyRatio }}x real-time
                                            </div>
                                            <div v-else class="text-green-700 mb-1">
                                                🚀 Excellent efficiency: Near real-time processing at {{ processingMetrics.efficiencyRatio }}x
                                            </div>

                                            <!-- Bottleneck Warning -->
                                            <div v-if="processingMetrics.bottleneck && processingMetrics.bottleneck.duration > 10" class="text-orange-700 mb-1">
                                                ⚠️ Bottleneck detected: {{ processingMetrics.bottleneck.step }} took {{ formatDuration(processingMetrics.bottleneck.duration) }}
                                            </div>

                                            <!-- Queue Wait Warning -->
                                            <div v-if="processingMetrics.durations.queueWait > 30" class="text-yellow-700">
                                                ⏳ Long queue wait: {{ formatDuration(processingMetrics.durations.queueWait) }} between audio extraction and transcription
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Raw Timestamps (Collapsible) -->
                                    <details class="mt-4">
                                        <summary class="text-xs text-gray-600 cursor-pointer hover:text-gray-800">Show raw timestamps</summary>
                                        <div class="mt-2 p-3 bg-gray-50 rounded border border-gray-200 text-xs text-gray-600">
                                            <div class="font-medium mb-2 text-gray-800">Processing Timestamps</div>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <div v-if="segmentData.audio_extraction_started_at">
                                                    <span class="text-gray-500">Audio started:</span><br>
                                                    <span class="font-mono">{{ new Date(segmentData.audio_extraction_started_at).toLocaleString() }}</span>
                                                </div>
                                                <div v-if="segmentData.audio_extraction_completed_at">
                                                    <span class="text-gray-500">Audio completed:</span><br>
                                                    <span class="font-mono">{{ new Date(segmentData.audio_extraction_completed_at).toLocaleString() }}</span>
                                                </div>
                                                <div v-if="segmentData.transcription_started_at">
                                                    <span class="text-gray-500">Transcription started:</span><br>
                                                    <span class="font-mono">{{ new Date(segmentData.transcription_started_at).toLocaleString() }}</span>
                                                </div>
                                                <div v-if="segmentData.transcription_completed_at">
                                                    <span class="text-gray-500">Transcription completed:</span><br>
                                                    <span class="font-mono">{{ new Date(segmentData.transcription_completed_at).toLocaleString() }}</span>
                                                </div>
                                                <div v-if="segmentData.terminology_started_at">
                                                    <span class="text-gray-500">Terminology started:</span><br>
                                                    <span class="font-mono">{{ new Date(segmentData.terminology_started_at).toLocaleString() }}</span>
                                                </div>
                                                <div v-if="segmentData.terminology_completed_at">
                                                    <span class="text-gray-500">Terminology completed:</span><br>
                                                    <span class="font-mono">{{ new Date(segmentData.terminology_completed_at).toLocaleString() }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </details>
                                </div>


                                
                                <!-- Error message -->
                                <div v-if="segmentData.error_message" class="p-3 bg-red-50 text-red-800 rounded-md border border-red-200">
                                    <div class="font-medium text-sm">Error</div>
                                    <div class="mt-1 text-xs">{{ segmentData.error_message }}</div>
                                </div>
                            </div>

                            <!-- MAIN CONTENT AREA - Video and Transcript -->
                            <div class="md:w-2/3">
                                <!-- Video player -->
                                <div class="bg-gray-900 rounded-lg overflow-hidden shadow-lg relative mb-6">
                                    <video 
                                        ref="videoElement"
                                        :src="segmentData.url" 
                                        controls
                                        class="w-full max-h-[500px]"
                                        preload="metadata"
                                        @error="handleVideoError"
                                    ></video>
                                    
                                    <div v-if="videoError" class="p-4 bg-red-50 text-red-800 text-sm">
                                        <div class="font-medium">Error loading video:</div>
                                        {{ videoError }}
                                    </div>
                                </div>

                                <!-- INTERACTIVE TRANSCRIPT - Compact -->
                                <div v-if="segmentData.transcript_json_api_url && transcriptData" class="mb-6">
                                    <div class="flex items-center justify-between mb-3 border-b border-gray-200 pb-2">
                                        <h3 class="text-lg font-medium flex items-center">
                                            <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                                            </svg>
                                            Interactive Transcript
                                        </h3>
                                    </div>
                                    <div class="max-h-96 overflow-y-auto border border-gray-200 rounded-lg">
                                        <AdvancedSubtitles
                                            :video-ref="videoElement"
                                            :transcript-json-url="segmentData.transcript_json_url"
                                            :transcript-json-api-url="segmentData.transcript_json_api_url"
                                            :transcript-data="transcriptData"
                                            :is-loading="isTranscriptLoading"
                                            :key="`advanced-${componentKey}`"
                                        />
                                    </div>
                                </div>

                                <!-- ADVANCED METRICS - Hidden by Default -->
                                <div v-if="hasAdvancedMetricsData" class="mb-6">
                                    <button 
                                        @click="showAdvancedMetrics = !showAdvancedMetrics" 
                                        class="flex items-center text-sm px-3 py-2 rounded-md transition bg-gray-100 text-gray-700 hover:bg-gray-200 mb-4"
                                    >
                                        <svg class="w-4 h-4 mr-1 transition-transform" :class="{'rotate-180': showAdvancedMetrics}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                        {{ showAdvancedMetrics ? 'Hide Advanced Metrics' : 'Show Advanced Metrics' }}
                                    </button>
                                    
                                    <div v-if="showAdvancedMetrics" class="space-y-6 border-t border-gray-200 pt-6">
                                        <!-- Guitar Enhancement Details -->
                                        <div v-if="guitarEnhancementAnalysis" class="space-y-4">
                                            <div class="bg-purple-50 rounded-lg p-4 border border-purple-200">
                                                <h4 class="font-medium text-purple-800 mb-3">Guitar Term Enhancement</h4>
                                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                                    <div class="text-center">
                                                        <div class="text-2xl font-bold text-purple-700">{{ guitarEnhancementAnalysis.guitarTermsFound }}</div>
                                                        <div class="text-sm text-purple-600">Unique Terms</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-2xl font-bold text-indigo-700">{{ guitarEnhancementAnalysis.totalOccurrences }}</div>
                                                        <div class="text-sm text-indigo-600">Total Instances</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-2xl font-bold text-orange-700">{{ (guitarEnhancementAnalysis.originalAverageConfidence * 100).toFixed(0) }}%</div>
                                                        <div class="text-sm text-orange-600">Before Enhancement</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-2xl font-bold text-green-700">{{ (guitarEnhancementAnalysis.enhancedAverageConfidence * 100).toFixed(0) }}%</div>
                                                        <div class="text-sm text-green-600">After Enhancement</div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Enhanced Terms List -->
                                                <div v-if="guitarEnhancementAnalysis.enhancedTerms.length > 0" class="bg-white rounded-lg border border-purple-200 overflow-hidden">
                                                    <div class="bg-purple-100 px-4 py-2 border-b border-purple-200">
                                                        <h5 class="font-medium text-purple-800">Enhanced Guitar Terms ({{ guitarEnhancementAnalysis.totalOccurrences }} instances of {{ guitarEnhancementAnalysis.guitarTermsFound }} unique terms)</h5>
                                                    </div>
                                                    <div class="max-h-64 overflow-y-auto">
                                                        <div v-for="(term, index) in guitarEnhancementAnalysis.enhancedTerms" :key="index" 
                                                             class="px-4 py-3 border-b border-gray-100 last:border-b-0 hover:bg-gray-50 transition">
                                                            <div class="flex items-center justify-between">
                                                                <div class="flex-1 min-w-0">
                                                                    <div class="flex items-center gap-3">
                                                                        <div class="font-medium text-gray-900">"{{ term.word }}"</div>
                                                                        <div class="text-xs text-gray-500">
                                                                            {{ formatTime(term.start) }} - {{ formatTime(term.end) }}
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="flex items-center gap-4 ml-4">
                                                                    <div class="text-right">
                                                                        <div class="text-sm text-orange-700 font-medium">{{ (term.original_confidence * 100).toFixed(0) }}%</div>
                                                                        <div class="text-xs text-gray-500">Before</div>
                                                                    </div>
                                                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                                                    </svg>
                                                                    <div class="text-right">
                                                                        <div class="text-sm text-green-700 font-bold">{{ (term.new_confidence * 100).toFixed(0) }}%</div>
                                                                        <div class="text-xs text-gray-500">After</div>
                                                                    </div>
                                                                    <div class="text-right">
                                                                        <div class="text-sm text-blue-600 font-medium">+{{ ((term.new_confidence - term.original_confidence) * 100).toFixed(0) }}%</div>
                                                                        <div class="text-xs text-gray-500">Gain</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Enhancement Technology Info -->
                                                <div class="mt-4 bg-white rounded-lg p-3 border border-purple-200">
                                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                                                        <div>
                                                            <div class="text-xs text-gray-600 mb-1">Evaluator Version</div>
                                                            <div class="font-medium">{{ guitarEnhancementAnalysis.evaluatorVersion }}</div>
                                                        </div>
                                                        <div>
                                                            <div class="text-xs text-gray-600 mb-1">AI Model Used</div>
                                                            <div class="font-medium">{{ guitarEnhancementAnalysis.llmUsed }}</div>
                                                        </div>
                                                        <div>
                                                            <div class="text-xs text-gray-600 mb-1">Library Terms</div>
                                                            <div class="font-medium">{{ guitarEnhancementAnalysis.libraryStats.total_terms || 'N/A' }}</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Detailed Quality Metrics -->
                                        <div v-if="qualityMetrics" class="space-y-4">
                                            <h4 class="font-medium text-gray-800">Detailed Quality Analysis</h4>
                                            
                                            <!-- Confidence Analysis -->
                                            <div v-if="confidenceAnalysis" class="bg-blue-50 rounded-lg p-4 border border-blue-200">
                                                <h5 class="font-medium text-blue-800 mb-3">Word Confidence Distribution</h5>
                                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                                    <div class="text-center">
                                                        <div class="font-bold text-green-700">{{ confidenceAnalysis.confidenceDistribution.excellent }}</div>
                                                        <div class="text-green-600">Excellent (90-100%)</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="font-bold text-blue-700">{{ confidenceAnalysis.confidenceDistribution.good }}</div>
                                                        <div class="text-blue-600">Good (70-90%)</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="font-bold text-yellow-700">{{ confidenceAnalysis.confidenceDistribution.fair }}</div>
                                                        <div class="text-yellow-600">Fair (50-70%)</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="font-bold text-red-700">{{ confidenceAnalysis.confidenceDistribution.poor }}</div>
                                                        <div class="text-red-600">Poor (0-50%)</div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Low Confidence Alert with Details -->
                                                <div v-if="confidenceAnalysis.lowConfidenceSegments.length > 0" class="mt-4 bg-orange-100 rounded-lg p-3 border border-orange-200">
                                                    <div class="flex items-start">
                                                        <svg class="w-5 h-5 mr-2 text-orange-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.734 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                                        </svg>
                                                        <div class="flex-1">
                                                            <div class="font-medium text-orange-800 mb-1">
                                                                {{ confidenceAnalysis.lowConfidenceSegments.length }} section(s) may need review
                                                            </div>
                                                            <div class="text-sm text-orange-700 mb-3">
                                                                These sections have average confidence below 60% and may contain transcription errors.
                                                            </div>
                                                            
                                                            <!-- Detailed Low Confidence Segments -->
                                                            <div class="bg-white rounded-lg border border-orange-200 overflow-hidden">
                                                                <div class="bg-orange-50 px-3 py-2 border-b border-orange-200">
                                                                    <h6 class="font-medium text-orange-800 text-sm">Problematic Segments</h6>
                                                                </div>
                                                                <div class="max-h-48 overflow-y-auto">
                                                                    <div v-for="(segment, index) in confidenceAnalysis.lowConfidenceSegments" :key="index" 
                                                                         class="px-3 py-3 border-b border-gray-100 last:border-b-0 hover:bg-gray-50 transition">
                                                                        <div class="flex items-start justify-between">
                                                                            <div class="flex-1 min-w-0">
                                                                                <div class="flex items-center gap-3 mb-2">
                                                                                    <div class="text-xs text-gray-500 font-medium">
                                                                                        {{ formatTime(segment.start) }} - {{ formatTime(segment.end) }}
                                                                                    </div>
                                                                                    <div class="text-xs font-medium px-2 py-1 rounded-full" :class="{
                                                                                        'bg-red-100 text-red-700': segment.averageConfidence < 0.3,
                                                                                        'bg-orange-100 text-orange-700': segment.averageConfidence >= 0.3 && segment.averageConfidence < 0.5,
                                                                                        'bg-yellow-100 text-yellow-700': segment.averageConfidence >= 0.5
                                                                                    }">
                                                                                        {{ (segment.averageConfidence * 100).toFixed(0) }}% confidence
                                                                                    </div>
                                                                                </div>
                                                                                <div class="text-sm text-gray-800 leading-relaxed">
                                                                                    "{{ segment.text }}"
                                                                                </div>
                                                                                
                                                                                <!-- Show specific low confidence words if available -->
                                                                                <div v-if="segment.lowConfidenceWords && segment.lowConfidenceWords.length > 0" class="mt-2">
                                                                                    <div class="text-xs text-gray-600 mb-1">Problematic words:</div>
                                                                                    <div class="flex flex-wrap gap-1">
                                                                                        <span v-for="word in segment.lowConfidenceWords.slice(0, 5)" :key="word.index"
                                                                                              class="inline-flex items-center px-2 py-1 rounded text-xs bg-red-100 text-red-700 border border-red-200">
                                                                                            "{{ word.word }}" ({{ (word.confidence * 100).toFixed(0) }}%)
                                                                                        </span>
                                                                                        <span v-if="segment.lowConfidenceWords.length > 5" class="text-xs text-gray-500">
                                                                                            +{{ segment.lowConfidenceWords.length - 5 }} more
                                                                                        </span>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            
                                                                            <!-- Jump to segment button -->
                                                                            <button 
                                                                                @click="jumpToTime(segment.start)"
                                                                                class="ml-3 px-2 py-1 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded transition flex-shrink-0"
                                                                                title="Jump to this segment in video"
                                                                            >
                                                                                Jump To
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Speech Activity Metrics -->
                                            <div v-if="qualityMetrics.speech_activity" class="bg-green-50 rounded-lg p-4 border border-green-200">
                                                <h5 class="font-medium text-green-800 mb-3">Speech Activity Analysis</h5>
                                                <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                                                    <div>
                                                        <div class="font-medium text-green-700">{{ qualityMetrics.speech_activity.speaking_rate_wpm?.toFixed(0) || 'N/A' }} WPM</div>
                                                        <div class="text-green-600">Speaking Rate</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-green-700">{{ (qualityMetrics.speech_activity.speech_activity_ratio * 100)?.toFixed(1) || 'N/A' }}%</div>
                                                        <div class="text-green-600">Speech Ratio</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-green-700">{{ qualityMetrics.speech_activity.pause_count || 0 }}</div>
                                                        <div class="text-green-600">Pause Count</div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Content Quality -->
                                            <div v-if="qualityMetrics.content_quality" class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                                <h5 class="font-medium text-gray-800 mb-3">Content Quality</h5>
                                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                                    <div>
                                                        <div class="font-medium text-gray-700">{{ qualityMetrics.content_quality.total_words || 0 }}</div>
                                                        <div class="text-gray-600">Total Words</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-gray-700">{{ qualityMetrics.content_quality.unique_words || 0 }}</div>
                                                        <div class="text-gray-600">Unique Words</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-gray-700">{{ (qualityMetrics.content_quality.vocabulary_richness * 100)?.toFixed(1) || 'N/A' }}%</div>
                                                        <div class="text-gray-600">Vocabulary Richness</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-gray-700">{{ qualityMetrics.content_quality.music_term_count || 0 }}</div>
                                                        <div class="text-gray-600">Music Terms</div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Teaching Pattern Analysis -->
                                            <div v-if="teachingPatternAnalysis" class="bg-indigo-50 rounded-lg p-4 border border-indigo-200">
                                                <h5 class="font-medium text-indigo-800 mb-3 flex items-center">
                                                    <span class="mr-2">🎯</span>
                                                    Teaching Pattern Analysis
                                                </h5>
                                                
                                                <!-- Primary Pattern Display -->
                                                <div v-if="teachingPatternAnalysis.content_classification.primary_type" class="mb-4">
                                                    <div class="flex items-center justify-between mb-3">
                                                        <div class="flex items-center">
                                                            <div class="text-2xl mr-3">{{ getPatternStyle(teachingPatternAnalysis.content_classification.primary_type).icon }}</div>
                                                            <div>
                                                                <div class="font-semibold text-lg text-indigo-900 capitalize">
                                                                    {{ teachingPatternAnalysis.content_classification.primary_type }} Pattern
                                                                </div>
                                                                <div class="text-sm text-indigo-700">
                                                                    {{ getPatternStyle(teachingPatternAnalysis.content_classification.primary_type).description }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="text-right">
                                                            <div class="text-2xl font-bold text-indigo-700">
                                                                {{ (teachingPatternAnalysis.content_classification.confidence * 100).toFixed(0) }}%
                                                            </div>
                                                            <div class="text-xs text-indigo-600">Confidence</div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Pattern Strength -->
                                                    <div v-if="teachingPatternAnalysis.summary.pattern_strength" class="mb-3">
                                                        <div class="flex items-center justify-between">
                                                            <span class="text-sm text-indigo-700">Pattern Strength:</span>
                                                            <span class="font-medium" :class="getPatternStrengthColor(teachingPatternAnalysis.summary.pattern_strength)">
                                                                {{ teachingPatternAnalysis.summary.pattern_strength }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Speech/Non-Speech Analysis -->
                                                <div v-if="teachingPatternAnalysis.temporal_analysis" class="mb-4 bg-white rounded-lg p-3 border border-indigo-200">
                                                    <h6 class="font-medium text-indigo-800 mb-3">Speech vs. Playing Analysis</h6>
                                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                                        <div class="text-center">
                                                            <div class="text-lg font-bold text-blue-700">
                                                                {{ (teachingPatternAnalysis.temporal_analysis.speech_ratio * 100).toFixed(1) }}%
                                                            </div>
                                                            <div class="text-blue-600">Speech</div>
                                                            <div class="text-xs text-gray-500 mt-1">Instructor talking</div>
                                                        </div>
                                                        <div class="text-center">
                                                            <div class="text-lg font-bold text-purple-700">
                                                                {{ (teachingPatternAnalysis.temporal_analysis.non_speech_ratio * 100).toFixed(1) }}%
                                                            </div>
                                                            <div class="text-purple-600">Playing</div>
                                                            <div class="text-xs text-gray-500 mt-1">Guitar demonstration</div>
                                                        </div>
                                                        <div class="text-center">
                                                            <div class="text-lg font-bold text-green-700">
                                                                {{ teachingPatternAnalysis.temporal_analysis.alternation_cycles || 0 }}
                                                            </div>
                                                            <div class="text-green-600">Teaching Cycles</div>
                                                            <div class="text-xs text-gray-500 mt-1">Explain → Play → Explain</div>
                                                        </div>
                                                        <div class="text-center">
                                                            <div class="text-lg font-bold text-orange-700">
                                                                {{ (teachingPatternAnalysis.temporal_analysis.total_duration / 60).toFixed(1) }}m
                                                            </div>
                                                            <div class="text-orange-600">Duration</div>
                                                            <div class="text-xs text-gray-500 mt-1">Total lesson length</div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Explanatory Note -->
                                                    <div class="mt-4 p-3 bg-indigo-50 rounded-lg border border-indigo-100">
                                                        <div class="text-xs text-indigo-700">
                                                            <div class="font-medium mb-1">📊 Analysis Explanation:</div>
                                                            <div class="space-y-1">
                                                                <div><strong>Teaching Cycles:</strong> Number of times the lesson alternates between verbal instruction and guitar demonstration (optimal: 3+ cycles for balanced learning)</div>
                                                                <div><strong>Speech/Playing Balance:</strong> Shows whether the lesson is explanation-heavy, demonstration-heavy, or well-balanced for different learning styles</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Visual Speech/Playing Bar -->
                                                    <div class="mt-3">
                                                        <div class="flex text-xs mb-1 justify-between">
                                                            <span class="text-blue-600">Speech</span>
                                                            <span class="text-purple-600">Guitar Playing</span>
                                                        </div>
                                                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                                            <div class="h-full flex">
                                                                <div 
                                                                    class="bg-blue-500"
                                                                    :style="{ width: (teachingPatternAnalysis.temporal_analysis.speech_ratio * 100) + '%' }"
                                                                ></div>
                                                                <div 
                                                                    class="bg-purple-500"
                                                                    :style="{ width: (teachingPatternAnalysis.temporal_analysis.non_speech_ratio * 100) + '%' }"
                                                                ></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Detected Patterns -->
                                                <div v-if="teachingPatternAnalysis.detected_patterns.length > 0" class="mb-4">
                                                    <h6 class="font-medium text-indigo-800 mb-2">All Detected Patterns</h6>
                                                    <div class="space-y-2">
                                                        <div v-for="(pattern, index) in teachingPatternAnalysis.detected_patterns" :key="index"
                                                             class="flex items-center justify-between p-2 rounded-lg border"
                                                             :class="[
                                                                 getPatternStyle(pattern.pattern_type).bgColor,
                                                                 getPatternStyle(pattern.pattern_type).borderColor
                                                             ]">
                                                            <div class="flex items-center">
                                                                <span class="text-lg mr-2">{{ getPatternStyle(pattern.pattern_type).icon }}</span>
                                                                <div>
                                                                    <div class="font-medium text-sm capitalize" :class="getPatternStyle(pattern.pattern_type).textColor">
                                                                        {{ pattern.pattern_type }}
                                                                    </div>
                                                                    <div class="text-xs text-gray-600">{{ pattern.description }}</div>
                                                                </div>
                                                            </div>
                                                            <div class="text-sm font-medium" :class="getPatternStyle(pattern.pattern_type).textColor">
                                                                {{ (pattern.confidence * 100).toFixed(0) }}%
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Teaching Recommendations -->
                                                <div v-if="teachingPatternAnalysis.summary.recommendations && teachingPatternAnalysis.summary.recommendations.length > 0" class="bg-white rounded-lg p-3 border border-indigo-200">
                                                    <h6 class="font-medium text-indigo-800 mb-2 flex items-center">
                                                        <span class="mr-2">💡</span>
                                                        Teaching Recommendations
                                                    </h6>
                                                    <ul class="space-y-1">
                                                        <li v-for="(recommendation, index) in teachingPatternAnalysis.summary.recommendations" :key="index"
                                                            class="text-sm text-gray-700 flex items-start">
                                                            <span class="text-indigo-600 mr-2 mt-0.5">•</span>
                                                            {{ recommendation }}
                                                        </li>
                                                    </ul>
                                                </div>

                                                <!-- Content Focus -->
                                                <div v-if="teachingPatternAnalysis.content_classification.content_focus" class="mt-3 text-center">
                                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                                        {{ teachingPatternAnalysis.content_classification.content_focus.replace('_', ' ') }} focused lesson
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>



                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Start Processing Confirmation Modal -->
        <Modal :show="showStartProcessingModal" @close="showStartProcessingModal = false" max-width="md">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100">
                        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                
                <div class="text-center">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Start Processing</h3>
                    <p class="text-sm text-gray-500 mb-6">
                        Are you sure you want to start processing this segment? This will extract audio and create a transcript using intelligent detection for optimal settings.
                    </p>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <SecondaryButton @click="showStartProcessingModal = false">
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton @click="startProcessing" class="bg-blue-600 hover:bg-blue-700">
                        Start Processing
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Restart Processing Confirmation Modal -->
        <Modal :show="showRestartProcessingModal" @close="showRestartProcessingModal = false" max-width="md">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100">
                        <svg class="h-6 w-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.734 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </div>
                </div>
                
                <div class="text-center">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Restart Processing</h3>
                    <p class="text-sm text-gray-500 mb-6">
                        Are you sure you want to restart the entire processing? This will overwrite all existing audio, transcript, and terminology data for this segment using intelligent detection for optimal settings.
                    </p>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <SecondaryButton @click="showRestartProcessingModal = false">
                        Cancel
                    </SecondaryButton>
                    <DangerButton @click="restartProcessing">
                        Restart Processing
                    </DangerButton>
                </div>
            </div>
        </Modal>

        <!-- Error Modal -->
        <Modal :show="showErrorModal" @close="showErrorModal = false" max-width="md">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                
                <div class="text-center">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Error</h3>
                    <p class="text-sm text-gray-500 mb-6">
                        {{ errorMessage }}
                    </p>
                </div>
                
                <div class="flex justify-end">
                    <PrimaryButton @click="showErrorModal = false">
                        OK
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<script>
export default {
    methods: {
        getConfidenceColor(confidence) {
            if (confidence >= 0.8) return '#10b981'; // green-500
            if (confidence >= 0.5) return '#f59e0b'; // yellow-500
            return '#ef4444'; // red-500
        },
        getEfficiencyColor(ratio) {
            if (ratio === 'N/A') return 'text-gray-500'; // No data available
            const numRatio = parseFloat(ratio);
            if (isNaN(numRatio)) return 'text-gray-500'; // Invalid data
            if (numRatio <= 1) return 'text-green-600'; // Faster than real-time
            if (numRatio <= 2) return 'text-blue-600';  // Good efficiency 
            if (numRatio <= 3) return 'text-yellow-600'; // Moderate efficiency
            return 'text-red-600'; // Slow processing
        }
    }
}
</script> 