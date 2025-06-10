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

// Helper function to calculate overall grade - More forgiving scoring
const overallGrade = computed(() => {
    if (!confidenceAnalysis.value && !qualityMetrics.value) {
        return { grade: 'N/A', color: 'gray', description: 'No analysis available' };
    }
    
    // Default to good score if completed with transcript
    if (segmentData.value?.status === 'completed' && segmentData.value?.transcript_text) {
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

// Simplified success indicator
const transcriptionSuccess = computed(() => {
    if (segmentData.value?.status !== 'completed') {
        return { 
            success: false, 
            title: getStatusTitle(segmentData.value?.status), 
            message: getStatusMessage(segmentData.value?.status),
            actionNeeded: segmentData.value?.status === 'failed' 
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

// Helper functions for status messaging
function getStatusMessage(status) {
    switch (status) {
        case 'ready': return 'Ready to start processing';
        case 'processing': return 'Audio extraction and transcription in progress...';
        case 'transcribing': return 'Generating transcript from audio...';
        case 'transcribed': return 'Transcript generated, applying enhancements...';
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
                                                        'bg-red-500': overallGrade.color === 'red'
                                                    }">{{ overallGrade.grade }}</div>
                                                </div>
                                                
                                                <!-- Always show restart button -->
                                                <button 
                                                    @click="restartProcessing" 
                                                    class="px-3 py-1 text-xs bg-gray-600 hover:bg-gray-700 text-white rounded transition"
                                                    title="Restart processing with intelligent detection"
                                                >
                                                    Restart
                                                </button>
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
                                    </div>
                                </div>

                                <!-- Processing Timeline -->
                                <div class="bg-gray-50 rounded-lg p-5 shadow-sm border border-gray-200 mb-6">
                                    <h3 class="text-lg font-medium mb-3">Processing Timeline</h3>
                                    <TranscriptionTimeline 
                                        :status="timelineData.status || segmentData.status"
                                        :timing="timelineData.timing"
                                        :progress-percentage="timelineData.progress_percentage"
                                        :error="segmentData.error_message"
                                        :media-duration="segmentData.audio_duration"
                                    />
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
                                <div v-if="transcriptionSuccess.success" class="mb-6">
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
                                        </div>
                                    </div>
                                </div>



                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script>
export default {
    methods: {
        getConfidenceColor(confidence) {
            if (confidence >= 0.8) return '#10b981'; // green-500
            if (confidence >= 0.5) return '#f59e0b'; // yellow-500
            return '#ef4444'; // red-500
        }
    }
}
</script> 