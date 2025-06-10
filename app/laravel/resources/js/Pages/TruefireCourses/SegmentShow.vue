<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount, computed, nextTick } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
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

// Simple restart options
const showRestartConfirm = ref(false);

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
    
    // Calculate original vs enhanced confidence
    let originalSum = 0;
    let enhancedSum = 0;
    let totalWords = 0;
    let guitarTermsCount = 0;
    
    // Process enhanced terms
    enhancedTerms.forEach(term => {
        originalSum += term.original_confidence || 0;
        enhancedSum += term.new_confidence || 0;
        guitarTermsCount++;
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
        guitarTermsFound: guitarTermsCount,
        originalAverageConfidence: guitarTermsCount > 0 ? originalSum / guitarTermsCount : 0,
        enhancedAverageConfidence: guitarTermsCount > 0 ? enhancedSum / guitarTermsCount : 0,
        improvementPercentage: guitarTermsCount > 0 ? ((enhancedSum / guitarTermsCount) - (originalSum / guitarTermsCount)) * 100 : 0,
        enhancedTerms: enhancedTerms,
        totalWordsEvaluated: enhancement.total_words_evaluated || totalWords,
        evaluatorVersion: enhancement.evaluator_version || 'Unknown',
        llmUsed: enhancement.llm_used || 'Library Only',
        libraryStats: enhancement.library_statistics || {},
        enhancementEnabled: true
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
            alert('Failed to start terminology recognition: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error triggering terminology recognition:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
    } finally {
        processingTerminology.value = false;
    }
}

// Add transcription request method
async function requestTranscription() {
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
            console.log('Transcription requested successfully');
            // Start polling for segment status
            startPolling();
        } else {
            console.error('Failed to request transcription:', data.message);
            alert('Failed to start transcription: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error requesting transcription:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
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
            alert('Failed to restart transcription: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error restarting transcription:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
    }
}

// Add abort processing method
async function abortProcessing() {
    if (!confirm('Are you sure you want to abort the current processing? This will stop all running jobs and reset the segment to ready status.')) {
        return;
    }
    
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
            alert('Failed to abort processing: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error aborting processing:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
    }
}



// Simplified restart processing method using intelligent detection
async function restartProcessing() {
    if (!confirm('Are you sure you want to restart the entire processing? This will overwrite all existing audio, transcript, and terminology data for this segment using intelligent detection for optimal settings.')) {
        return;
    }
    
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
            showRestartConfirm.value = false;
            startPolling();
        } else {
            console.error('Failed to start restart processing:', data.message);
            alert('Failed to start restart processing: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error starting restart processing:', error);
        alert('Error: ' + (error.message || 'Failed to communicate with server'));
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

// Helper function to calculate overall grade
const overallGrade = computed(() => {
    if (!confidenceAnalysis.value && !qualityMetrics.value) {
        return { grade: 'N/A', color: 'gray', description: 'No analysis available' };
    }
    
    // Calculate composite score from available metrics
    let totalScore = 0;
    let weightedSum = 0;
    
    // Confidence score (40% weight)
    if (confidenceAnalysis.value?.averageConfidence) {
        const confidence = confidenceAnalysis.value.averageConfidence;
        totalScore += confidence * 0.4;
        weightedSum += 0.4;
    }
    
    // Quality metrics (40% weight)
    if (qualityMetrics.value?.overall_quality_score) {
        const quality = qualityMetrics.value.overall_quality_score;
        totalScore += quality * 0.4;
        weightedSum += 0.4;
    }
    
    // Guitar enhancement impact (20% weight)
    if (guitarEnhancementAnalysis.value?.guitarTermsFound > 0) {
        const enhancementBonus = Math.min(0.2, guitarEnhancementAnalysis.value.guitarTermsFound * 0.05);
        totalScore += enhancementBonus;
        weightedSum += 0.2;
    }
    
    // Normalize score
    const finalScore = weightedSum > 0 ? totalScore / weightedSum : 0;
    
    // Convert to grade
    if (finalScore >= 0.9) return { grade: 'A', color: 'green', description: 'Excellent transcription quality' };
    if (finalScore >= 0.8) return { grade: 'B', color: 'blue', description: 'Good transcription quality' };
    if (finalScore >= 0.7) return { grade: 'C', color: 'yellow', description: 'Fair transcription quality' };
    if (finalScore >= 0.6) return { grade: 'D', color: 'orange', description: 'Poor transcription quality' };
    return { grade: 'F', color: 'red', description: 'Failed transcription quality' };
});

// Simplified success indicator
const transcriptionSuccess = computed(() => {
    if (segmentData.value?.status !== 'completed') {
        return { 
            success: false, 
            title: 'Processing Incomplete', 
            message: 'Transcription is not yet complete',
            actionNeeded: true 
        };
    }
    
    if (!segmentData.value?.transcript_text) {
        return { 
            success: false, 
            title: 'No Transcript Available', 
            message: 'Transcription completed but no text was generated',
            actionNeeded: true 
        };
    }
    
    const grade = overallGrade.value;
    if (grade.grade === 'F') {
        return { 
            success: false, 
            title: 'Low Quality Transcript', 
            message: 'Transcription completed but quality is very poor',
            actionNeeded: true 
        };
    }
    
    return { 
        success: true, 
        title: 'Transcription Successful', 
        message: `High quality transcript generated (Grade: ${grade.grade})`,
        actionNeeded: false 
    };
});

// Calculate key summary metrics
const summaryMetrics = computed(() => {
    const metrics = {
        wordCount: 0,
        duration: segmentData.value?.formatted_duration || 'Unknown',
        confidence: 0,
        musicTerms: 0,
        issues: 0
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
    
    return metrics;
});

// Control visibility of detailed sections
const showAdvancedMetrics = ref(false);
const showProcessingDetails = ref(false);
</script> 