<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount, computed, nextTick, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import TranscriptionTimeline from '@/Components/TranscriptionTimeline.vue';
import SynchronizedTranscript from '@/Components/SynchronizedTranscript.vue';
import AdvancedSubtitles from '@/Components/AdvancedSubtitles.vue';
import MusicTermsViewer from '@/Components/MusicTermsViewer.vue';

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
const showSynchronizedTranscript = ref(false); // Hidden by default
const showDetailedQualityMetrics = ref(false); // Hidden by default
const showGuitarEnhancementDetails = ref(false); // Hidden by default

// Modal state
const showStartProcessingModal = ref(false);
const showRestartProcessingModal = ref(false);
const showAbortProcessingModal = ref(false);
const showErrorModal = ref(false);
const errorMessage = ref('');
const confirmAction = ref(null);

// Model testing state
const showModelTestingPanel = ref(false);
const availableModels = ref([]);
const selectedTestModel = ref('llama3:latest');
const selectedComparisonModels = ref(['llama3:latest']);
const confidenceThreshold = ref(0.75);
const isTestingModel = ref(false);
const isComparingModels = ref(false);
const singleModelTestResults = ref(null);
const modelComparisonResults = ref(null);
const modelTestError = ref('');

// Model testing UI state
const expandedModelDetails = ref({});
const expandedModelResponses = ref({});
const showPromptEditor = ref(false);
const customPrompt = ref('');
const defaultPrompt = ref('');
const useCustomPrompt = ref(false);

// Teaching pattern model testing state
const showTeachingPatternPanel = ref(false);
const selectedTeachingPatternModels = ref(['llama3.2:3b']);
const isComparingTeachingPatterns = ref(false);
const teachingPatternComparisonResults = ref(null);
const teachingPatternError = ref('');

// Teaching pattern UI state
const expandedTeachingModelDetails = ref({});
const expandedTeachingModelResponses = ref({});

// Custom prompt testing state
const showCustomPromptPanel = ref(false);
const customPromptText = ref('');
const productName = ref('TrueFire Guitar Lessons');
const courseTitle = ref('');
const instructorName = ref('');
const selectedCustomPromptPreset = ref('balanced');
const selectedCustomPromptModel = ref('');
const enableComparisonMode = ref(false);
const isTestingCustomPrompt = ref(false);
const customPromptTestResults = ref(null);
const customPromptTestError = ref('');

// WhisperX transcription prompt display state
const showWhisperPrompt = ref(false);
const whisperPromptText = ref('');
const whisperPromptLength = ref(0);
const isLoadingWhisperPrompt = ref(false);

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
                                  segmentData.value.status === 'transcribed';
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
                     data.segment.has_transcript)) {
                    window.location.reload();
                    return;
                }
            }
            
            // Copy all available properties from the response to our segment data
            if (data.segment) {
                // Copy standard properties
                segmentData.value.error_message = data.segment.error_message;
                segmentData.value.is_processing = data.segment.is_processing || 
                    ['processing', 'transcribing', 'transcribed'].includes(data.segment.status);
                
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
    
    let totalWords = 0;
    let confidenceSum = 0;
    let lowConfidenceWords = 0;
    let highConfidenceWords = 0;
    let confidenceDistribution = {
        excellent: 0, // 90%+
        good: 0,      // 70-90%
        fair: 0,      // 50-70%
        poor: 0,      // 30-50%
        veryPoor: 0   // <30%
    };
    
    // Go through all segments and words
    transcriptData.value.segments.forEach(segment => {
        if (Array.isArray(segment.words)) {
            segment.words.forEach(word => {
                const confidence = word.probability !== undefined ? word.probability : word.score;
                if (confidence !== undefined) {
                    confidenceSum += parseFloat(confidence);
                    totalWords++;
                    
                    // Count confidence levels
                    if (confidence < 0.5) lowConfidenceWords++;
                    if (confidence >= 0.8) highConfidenceWords++;
                    
                    // Distribution analysis
                    if (confidence >= 0.9) confidenceDistribution.excellent++;
                    else if (confidence >= 0.7) confidenceDistribution.good++;
                    else if (confidence >= 0.5) confidenceDistribution.fair++;
                    else if (confidence >= 0.3) confidenceDistribution.poor++;
                    else confidenceDistribution.veryPoor++;
                }
            });
        }
    });
    
    if (totalWords === 0) {
        return null;
    }
    
    return {
        totalWords,
        averageConfidence: confidenceSum / totalWords,
        lowConfidenceWords,
        highConfidenceWords,
        lowConfidencePercentage: (lowConfidenceWords / totalWords) * 100,
        highConfidencePercentage: (highConfidenceWords / totalWords) * 100,
        confidenceDistribution: confidenceDistribution,
        distributionPercentages: {
            excellent: (confidenceDistribution.excellent / totalWords) * 100,
            good: (confidenceDistribution.good / totalWords) * 100,
            fair: (confidenceDistribution.fair / totalWords) * 100,
            poor: (confidenceDistribution.poor / totalWords) * 100,
            veryPoor: (confidenceDistribution.veryPoor / totalWords) * 100
        }
    };
});

// Model testing functions
async function fetchAvailableModels() {
    try {
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${props.segment.id}/available-models`);
        const data = await response.json();
        
        if (data.success) {
            availableModels.value = data.models || [];
            console.log('Available models loaded:', availableModels.value);
        } else {
            console.error('Failed to fetch available models:', data.message);
            modelTestError.value = 'Failed to fetch available models: ' + (data.message || 'Unknown error');
        }
    } catch (error) {
        console.error('Error fetching available models:', error);
        modelTestError.value = 'Error fetching available models: ' + error.message;
    }
}

async function testSingleModel() {
    if (!selectedTestModel.value) {
        modelTestError.value = 'Please select a model to test';
        return;
    }
    
    isTestingModel.value = true;
    modelTestError.value = '';
    singleModelTestResults.value = null;
    
    try {
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${props.segment.id}/test-contextual-evaluation`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                model: selectedTestModel.value,
                confidence_threshold: confidenceThreshold.value
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Validate the data structure before setting it
            // Check if contextual_evaluation is in results (Laravel wrapper) or at top level (direct service)
            const contextualEval = data.contextual_evaluation || (data.results && data.results.contextual_evaluation);
            if (contextualEval && typeof contextualEval === 'object') {
                // Flatten the structure for consistent access
                const flattenedData = {
                    ...data,
                    contextual_evaluation: contextualEval
                };
                singleModelTestResults.value = flattenedData;
                console.log('Single model contextual test completed:', flattenedData);
            } else {
                modelTestError.value = 'Invalid test data received from server';
                console.error('Invalid test data:', data);
            }
        } else {
            modelTestError.value = 'Test failed: ' + (data.message || 'Unknown error');
        }
    } catch (error) {
        console.error('Error testing model:', error);
        modelTestError.value = 'Error testing model: ' + error.message;
    } finally {
        isTestingModel.value = false;
    }
}

async function compareModels() {
    if (!selectedComparisonModels.value || selectedComparisonModels.value.length === 0) {
        modelTestError.value = 'Please select at least one model to compare';
        return;
    }
    
    isComparingModels.value = true;
    modelTestError.value = '';
    modelComparisonResults.value = null;
    
    try {
        const response = await fetch(`/api/truefire-courses/${props.course.id}/segments/${props.segment.id}/compare-contextual-models`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                models: selectedComparisonModels.value,
                confidence_threshold: confidenceThreshold.value
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Validate the data structure before setting it
            // Check if comparison is at top level or nested in results
            const comparison = data.comparison || (data.results && data.results.comparison);
            if (comparison && typeof comparison === 'object') {
                // Flatten the structure for consistent access
                const flattenedData = {
                    ...data,
                    comparison: comparison
                };
                modelComparisonResults.value = flattenedData;
                console.log('Model contextual comparison completed:', flattenedData);
            } else {
                modelTestError.value = 'Invalid comparison data received from server';
                console.error('Invalid comparison data:', data);
            }
        } else {
            modelTestError.value = 'Comparison failed: ' + (data.message || 'Unknown error');
        }
    } catch (error) {
        console.error('Error comparing models:', error);
        modelTestError.value = 'Error comparing models: ' + error.message;
    } finally {
        isComparingModels.value = false;
    }
}

function toggleModelComparison(modelName) {
    const index = selectedComparisonModels.value.indexOf(modelName);
    if (index > -1) {
        selectedComparisonModels.value.splice(index, 1);
    } else {
        selectedComparisonModels.value.push(modelName);
    }
}

    function clearModelTestResults() {
        singleModelTestResults.value = null;
        modelComparisonResults.value = null;
        modelTestError.value = '';
    }

    // Toggle functions for model details and responses
    function toggleModelDetails(modelName) {
        expandedModelDetails.value[modelName] = !expandedModelDetails.value[modelName];
    }

    function toggleModelResponses(modelName) {
        expandedModelResponses.value[modelName] = !expandedModelResponses.value[modelName];
    }

    // Teaching Pattern Model Testing Functions
    function toggleTeachingPatternModel(modelName) {
        const index = selectedTeachingPatternModels.value.indexOf(modelName);
        if (index > -1) {
            selectedTeachingPatternModels.value.splice(index, 1);
        } else {
            selectedTeachingPatternModels.value.push(modelName);
        }
    }

    async function compareTeachingPatternModels() {
        if (selectedTeachingPatternModels.value.length === 0) {
            teachingPatternError.value = 'Please select at least one model to compare';
            return;
        }

        isComparingTeachingPatterns.value = true;
        teachingPatternError.value = '';
        teachingPatternComparisonResults.value = null;

        try {
            const response = await fetch('/api/teaching-pattern-models/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({
                    segment_id: props.segment.id,
                    course_id: props.course.id,
                    models: selectedTeachingPatternModels.value,
                    transcription_data: transcriptData.value
                })
            });

            const data = await response.json();

            if (data.success) {
                teachingPatternComparisonResults.value = data.results;
                console.log('Teaching pattern comparison completed:', data.results);
            } else {
                teachingPatternError.value = 'Failed to compare teaching pattern models: ' + (data.message || 'Unknown error');
                console.error('Teaching pattern comparison failed:', data.message);
            }
        } catch (error) {
            console.error('Error comparing teaching pattern models:', error);
            teachingPatternError.value = 'Error comparing teaching pattern models: ' + error.message;
        } finally {
            isComparingTeachingPatterns.value = false;
        }
    }

    function clearTeachingPatternResults() {
        teachingPatternComparisonResults.value = null;
        teachingPatternError.value = '';
    }

    // Teaching pattern UI toggle functions
    function toggleTeachingModelDetails(modelName) {
        expandedTeachingModelDetails.value[modelName] = !expandedTeachingModelDetails.value[modelName];
    }

    function toggleTeachingModelResponses(modelName) {
        expandedTeachingModelResponses.value[modelName] = !expandedTeachingModelResponses.value[modelName];
    }

    // Prompt handling functions
    async function loadDefaultPrompt() {
        try {
            const response = await fetch('/api/guitar-term-evaluator/default-prompt');
            const data = await response.json();
            defaultPrompt.value = data.prompt || 'Error loading default prompt';
        } catch (error) {
            console.error('Error loading default prompt:', error);
            defaultPrompt.value = 'Error loading default prompt';
        }
    }

    // Load WhisperX transcription prompt
    async function loadWhisperPrompt() {
        if (whisperPromptText.value) return; // Already loaded
        
        try {
            isLoadingWhisperPrompt.value = true;
            
            // Get the preset configuration from the transcription service via Laravel proxy
            const response = await fetch('/api/transcription-service/presets/info', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            const preset = segmentData.value.preset_used || 'balanced';
            const presetConfig = data.presets?.[preset];
            
            if (presetConfig?.initial_prompt) {
                // Process template variables in the WhisperX prompt
                const processedPrompt = processTemplateVariables(presetConfig.initial_prompt);
                whisperPromptText.value = processedPrompt;
                whisperPromptLength.value = processedPrompt.length;
            } else {
                whisperPromptText.value = 'Unable to load WhisperX prompt for this preset';
                whisperPromptLength.value = 0;
            }
        } catch (error) {
            console.error('Error loading WhisperX prompt:', error);
            whisperPromptText.value = 'Error loading WhisperX prompt from transcription service';
            whisperPromptLength.value = 0;
        } finally {
            isLoadingWhisperPrompt.value = false;
        }
    }

    // Custom prompt testing functions
    async function testCustomPrompt() {
        if (!customPromptText.value.trim() && !productName.value.trim() && !courseTitle.value.trim() && !instructorName.value.trim()) {
            customPromptTestError.value = 'Please provide at least one of: custom prompt, product name, course title, or instructor name';
            return;
        }
        
        isTestingCustomPrompt.value = true;
        customPromptTestError.value = '';
        customPromptTestResults.value = null;
        
        try {
            const requestBody = {
                segment_id: props.segment.id,
                course_id: props.course.id,
                preset: selectedCustomPromptPreset.value,
                comparison_mode: enableComparisonMode.value,
                enable_guitar_term_evaluation: true
            };
            
            // Add custom prompt if provided
            if (customPromptText.value.trim()) {
                requestBody.custom_prompt = customPromptText.value.trim();
            }
            
            // Add product context if provided
            if (productName.value.trim()) {
                requestBody.product_name = productName.value.trim();
            }
            
            if (courseTitle.value.trim()) {
                requestBody.course_title = courseTitle.value.trim();
            }
            
            if (instructorName.value.trim()) {
                requestBody.instructor_name = instructorName.value.trim();
            }
            
            // Add model override if selected
            if (selectedCustomPromptModel.value) {
                requestBody.model_name = selectedCustomPromptModel.value;
            }
            
            console.log('Testing custom prompt with request:', requestBody);
            
            const response = await fetch('/api/transcription-service/test-custom-prompt', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify(requestBody)
            });
            
            const data = await response.json();
            
            if (data.success) {
                customPromptTestResults.value = data;
                console.log('Custom prompt test completed:', data);
            } else {
                customPromptTestError.value = 'Test failed: ' + (data.message || 'Unknown error');
                console.error('Custom prompt test failed:', data);
            }
        } catch (error) {
            console.error('Error testing custom prompt:', error);
            customPromptTestError.value = 'Error testing custom prompt: ' + error.message;
        } finally {
            isTestingCustomPrompt.value = false;
        }
    }

    function clearCustomPromptResults() {
        customPromptTestResults.value = null;
        customPromptTestError.value = '';
    }

    function populateCourseContext() {
        // Auto-populate course context from props
        if (props.course?.title) {
            courseTitle.value = props.course.title;
        }
        if (props.course?.instructor_name) {
            instructorName.value = props.course.instructor_name;
        }
    }

    // Process template variables in prompts
    function processTemplateVariables(promptText) {
        if (!promptText) return '';
        
        let processedText = promptText;
        
        // Replace template variables with actual values
        const variables = {
            'product_name': productName.value || 'TrueFire Guitar Lessons',
            'course_title': courseTitle.value || props.course?.title || 'Guitar Course',
            'instructor_name': instructorName.value || props.course?.instructor_name || 'Guitar Instructor',
            'segment_id': props.segment?.id || 'Unknown',
            'preset': selectedCustomPromptPreset.value || segmentData.value?.preset_used || 'balanced'
        };
        
        // Replace each template variable
        Object.entries(variables).forEach(([key, value]) => {
            const pattern = new RegExp(`\\{\\{\\s*${key}\\s*\\}\\}`, 'gi');
            processedText = processedText.replace(pattern, value);
        });
        
        return processedText;
    }

    function getModelDisplayName(modelName) {
    if (!modelName || typeof modelName !== 'string') {
        return 'Unknown Model';
    }
    return modelName.replace(':latest', '').replace(':', ' ').replace(/\b\w/g, l => l.toUpperCase());
}

function getAgreementColor(percentage) {
    if (typeof percentage !== 'number' || isNaN(percentage)) return 'text-gray-600';
    if (percentage >= 75) return 'text-green-600';
    if (percentage >= 50) return 'text-yellow-600';
    return 'text-red-600';
}

function getPerformanceColor(score) {
    if (typeof score !== 'number' || isNaN(score)) return 'text-gray-600';
    if (score >= 0.8) return 'text-green-600';
    if (score >= 0.6) return 'text-yellow-600';
    return 'text-red-600';
}

// Helper function to format time in MM:SS format
function formatTime(seconds) {
    // Handle null, undefined, or NaN values
    if (seconds == null || isNaN(seconds)) {
        return '--';
    }
    
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
    

    
    // Wait time between audio extraction and transcription
    if (segmentData.value.audio_extraction_completed_at && segmentData.value.transcription_started_at) {
        const audioEnd = new Date(segmentData.value.audio_extraction_completed_at);
        const transcriptionStart = new Date(segmentData.value.transcription_started_at);
        durations.queueWait = Math.max(0, (transcriptionStart - audioEnd) / 1000);
    }
    
    // Total end-to-end duration
    const firstStart = segmentData.value.audio_extraction_started_at || segmentData.value.transcription_started_at;
    const lastEnd = segmentData.value.transcription_completed_at || segmentData.value.audio_extraction_completed_at;
    
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
            transcription_end: segmentData.value.transcription_completed_at
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
        'Transcription': durations.transcription || 0
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
    showAbortProcessingModal.value = false;
    
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

// Show abort processing confirmation modal
function showAbortProcessingConfirmation() {
    showAbortProcessingModal.value = true;
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
    
    // Fetch available models for testing
    fetchAvailableModels();
    
    // Load default prompt for model testing
    loadDefaultPrompt();
    
        // Initialize custom prompt testing with course data
    populateCourseContext();
    
    // Then start polling after a short delay to ensure backend has time to update
    setTimeout(() => {
        startPolling();
    }, 1000);
    
    // Set up video synchronization when video element is ready
    nextTick(() => {
        initializeVideoSync();
    });
});

// Watch for when the WhisperX prompt section is expanded to load the prompt
watch(showWhisperPrompt, (newValue) => {
    if (newValue && !whisperPromptText.value) {
        loadWhisperPrompt();
    }
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
                                                    v-else-if="['processing', 'transcribing', 'transcribed'].includes(segmentData.status)"
                                                    class="flex items-center space-x-2"
                                                >
                                                    <div class="px-3 py-1 text-xs bg-yellow-100 text-yellow-800 rounded border border-yellow-200">
                                                        Processing...
                                                    </div>
                                                    <button 
                                                        @click="showAbortProcessingConfirmation" 
                                                        class="px-2 py-1 text-xs bg-red-600 hover:bg-red-700 text-white rounded transition"
                                                        title="Abort processing and reset to ready state"
                                                    >
                                                        Abort
                                                    </button>
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
                <div v-if="['processing', 'transcribing', 'transcribed'].includes(segmentData.status)" 
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

                            <!-- MAIN CONTENT AREA - Model Testing Lab -->
                            <div class="md:w-2/3">
                                <!-- 🧪 MODEL TESTING LAB - Primary Focus -->
                                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                                    <div class="flex items-center justify-between mb-6">
                                        <h3 class="text-xl font-semibold text-gray-900 flex items-center space-x-2">
                                            <svg class="w-6 h-6 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                            </svg>
                                            <span>🧪 Model Testing Lab</span>
                                        </h3>
                                    </div>
                                    <p class="text-gray-600 mb-6">Test different LLM models and prompts for contextual guitar terminology evaluation on this segment's low-confidence words.</p>
                                    
                                    <!-- Error Display -->
                                    <div v-if="modelTestError" class="p-4 bg-red-100 border border-red-200 rounded-lg text-red-700 text-sm mb-6">
                                        {{ modelTestError }}
                                    </div>
                                    
                                    <!-- Configuration Section -->
                                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                                        <!-- Confidence Threshold -->
                                        <div class="bg-gray-50 rounded-lg p-4">
                                            <label class="block text-sm font-medium text-gray-700 mb-3">Confidence Threshold</label>
                                            <div class="flex items-center space-x-4">
                                                <input 
                                                    v-model.number="confidenceThreshold" 
                                                    type="range" 
                                                    min="0.1" 
                                                    max="1.0" 
                                                    step="0.05" 
                                                    class="flex-1"
                                                >
                                                <div class="flex items-center space-x-4 text-sm text-gray-600">
                                                    <span>0.1 (Low)</span>
                                                    <span class="font-medium bg-white px-2 py-1 rounded border">{{ confidenceThreshold.toFixed(2) }}</span>
                                                    <span>1.0 (High)</span>
                                                </div>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-2">Only words below this confidence level will be evaluated for enhancement</p>
                                        </div>

                                        <!-- Quick Actions -->
                                        <div class="bg-gray-50 rounded-lg p-4">
                                            <label class="block text-sm font-medium text-gray-700 mb-3">Quick Test</label>
                                            <div class="flex items-center space-x-3">
                                                <select v-model="selectedTestModel" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                                    <option v-for="model in availableModels" :key="model.name" :value="model.name">
                                                        {{ getModelDisplayName(model.name) }}
                                                    </option>
                                                </select>
                                                <button 
                                                    @click="testSingleModel" 
                                                    :disabled="isTestingModel || !selectedTestModel"
                                                    class="px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:bg-gray-400 text-white rounded-lg transition text-sm"
                                                >
                                                    <span v-if="isTestingModel" class="flex items-center">
                                                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                        </svg>
                                                        Testing...
                                                    </span>
                                                    <span v-else>Test Model</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- LLM Prompt Configuration - Prominent -->
                                    <div class="bg-blue-50 rounded-lg p-6 mb-8 border border-blue-200">
                                        <div class="flex items-center justify-between mb-4">
                                            <h4 class="text-lg font-semibold text-blue-900 flex items-center">
                                                <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                                </svg>
                                                Prompt Editor
                                            </h4>
                                            <button 
                                                @click="showPromptEditor = !showPromptEditor"
                                                class="flex items-center space-x-2 px-3 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors text-sm"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                                </svg>
                                                <span>{{ showPromptEditor ? 'Hide' : 'Customize' }} Prompt</span>
                                            </button>
                                        </div>
                                        <p class="text-blue-700 text-sm mb-4">Customize how the LLM evaluates words in context to identify guitar terminology</p>
                                        
                                        <div v-if="showPromptEditor" class="space-y-4">
                                            <!-- Use Custom Prompt Toggle -->
                                            <div class="flex items-center space-x-2">
                                                <input 
                                                    id="useCustomPrompt"
                                                    v-model="useCustomPrompt" 
                                                    type="checkbox" 
                                                    class="rounded border-blue-300 text-blue-600 focus:ring-blue-500"
                                                >
                                                <label for="useCustomPrompt" class="text-sm font-medium text-blue-800">
                                                    Use Custom Prompt
                                                </label>
                                            </div>
                                            
                                            <!-- Default Prompt Display -->
                                            <div class="bg-white rounded-lg p-4 border border-blue-200">
                                                <h6 class="font-medium text-gray-800 mb-2 text-sm">Default Prompt:</h6>
                                                <div class="text-xs text-gray-600 font-mono bg-gray-50 p-3 rounded border border-gray-200 max-h-32 overflow-y-auto">
                                                    {{ defaultPrompt || 'Loading default prompt...' }}
                                                </div>
                                            </div>
                                            
                                            <!-- Custom Prompt Editor -->
                                            <div v-if="useCustomPrompt">
                                                <label class="block text-sm font-medium text-blue-800 mb-2">Custom Prompt:</label>
                                                <textarea 
                                                    v-model="customPrompt"
                                                    rows="6"
                                                    class="w-full rounded-lg border-blue-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm font-mono"
                                                    placeholder="Enter your custom prompt for contextual guitar term evaluation..."
                                                ></textarea>
                                                <div class="text-xs text-blue-600 mt-2">
                                                    The prompt should instruct the LLM how to evaluate low-confidence words in their context to identify legitimate guitar terms.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Whisper AI Transcription Prompt Display -->
                                    <div class="bg-blue-50 rounded-lg p-6 mb-8 border border-blue-200">
                                        <div class="flex items-center justify-between mb-4">
                                            <h4 class="text-lg font-semibold text-blue-900 flex items-center">
                                                <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                </svg>
                                                🎤 WhisperX Transcription Prompt
                                            </h4>
                                            <button 
                                                @click="showWhisperPrompt = !showWhisperPrompt"
                                                class="text-blue-600 hover:text-blue-800 transition"
                                            >
                                                <svg 
                                                    :class="{ 'transform rotate-180': showWhisperPrompt }"
                                                    class="w-5 h-5 transition-transform"
                                                    fill="none" 
                                                    stroke="currentColor" 
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                            </button>
                                        </div>
                                        
                                        <div v-if="!showWhisperPrompt" class="text-sm text-blue-700">
                                            View the comprehensive guitar lesson context prompt used by WhisperX for transcription (~1,200 words)
                                        </div>
                                        
                                        <div v-if="showWhisperPrompt" class="space-y-4">
                                            <div class="bg-white rounded-lg p-4 border border-blue-200">
                                                <div class="flex items-center justify-between mb-3">
                                                    <div class="flex items-center space-x-2">
                                                        <h5 class="font-medium text-blue-800">Current Transcription Prompt ({{segmentData.preset_used || 'Balanced'}} preset)</h5>
                                                        <span class="px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full flex items-center">
                                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                            </svg>
                                                            Variables Processed
                                                        </span>
                                                    </div>
                                                    <div class="text-xs text-blue-600">
                                                        Length: ~{{whisperPromptLength}} characters
                                                    </div>
                                                </div>
                                                
                                                <div class="text-xs text-blue-600 font-mono bg-gray-50 p-3 rounded border border-gray-200 max-h-64 overflow-y-auto leading-relaxed">
                                                    {{ whisperPromptText || 'Loading WhisperX prompt...' }}
                                                </div>
                                                
                                                <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Model Used</div>
                                                        <div class="font-medium">{{ segmentData.model_used || 'Small' }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Language</div>
                                                        <div class="font-medium">English</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Temperature</div>
                                                        <div class="font-medium">0 (Deterministic)</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Word Timestamps</div>
                                                        <div class="font-medium">✅ Enabled</div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="bg-blue-100 rounded-lg p-3 border border-blue-200">
                                                <h6 class="font-medium text-blue-800 mb-2">📋 Key Features of This Prompt:</h6>
                                                <ul class="text-sm text-blue-700 space-y-1">
                                                    <li>• <strong>Musical Terminology:</strong> Comprehensive guitar, chord, and music theory context</li>
                                                    <li>• <strong>Critical Corrections:</strong> "chord" never "cord", "C sharp" not "see sharp"</li>
                                                    <li>• <strong>Guitar Anatomy:</strong> Fretboard, capo, pickup, strings, hardware</li>
                                                    <li>• <strong>Advanced Techniques:</strong> Fingerpicking, hammer-on, pull-off, string bending</li>
                                                    <li>• <strong>Music Theory:</strong> Scale patterns, chord progressions, modal theory</li>
                                                    <li>• <strong>Instructor Context:</strong> {{ props.course?.instructor_name || 'Educational content' }}</li>
                                                    <li>• <strong>Dynamic Variables:</strong> Course title, instructor name, and segment-specific context automatically inserted</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Custom Prompt Testing - New Feature -->
                                    <div class="bg-green-50 rounded-lg p-6 mb-8 border border-green-200">
                                        <div class="flex items-center justify-between mb-4">
                                            <h4 class="text-lg font-semibold text-green-900 flex items-center">
                                                <svg class="w-5 h-5 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                                🎯 Custom Prompt Testing
                                            </h4>
                                            <button 
                                                @click="showCustomPromptPanel = !showCustomPromptPanel"
                                                class="flex items-center space-x-2 px-3 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors text-sm"
                                            >
                                                <svg 
                                                    :class="{ 'transform rotate-180': showCustomPromptPanel }"
                                                    class="w-4 h-4 transition-transform" 
                                                    fill="none" 
                                                    stroke="currentColor" 
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                                <span>{{ showCustomPromptPanel ? 'Hide' : 'Test' }} Custom Prompts</span>
                                            </button>
                                        </div>
                                        <p class="text-green-700 text-sm mb-4">Test transcription with custom prompts including product-specific context like TrueFire branding</p>
                                        
                                        <!-- Custom Prompt Testing Panel -->
                                        <div v-if="showCustomPromptPanel" class="space-y-6">
                                            <!-- Error Display -->
                                            <div v-if="customPromptTestError" class="p-4 bg-red-100 border border-red-200 rounded-lg text-red-700 text-sm">
                                                {{ customPromptTestError }}
                                            </div>
                                            
                                            <!-- Configuration Grid -->
                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                                <!-- Product Context -->
                                                <div class="bg-white rounded-lg p-4 border border-green-200">
                                                    <h5 class="font-medium text-green-800 mb-3 flex items-center">
                                                        <svg class="w-4 h-4 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                                        </svg>
                                                        Product Context
                                                    </h5>
                                                    <div class="space-y-3">
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">Product Name</label>
                                                            <input 
                                                                v-model="productName"
                                                                type="text"
                                                                class="w-full rounded-md border-green-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm"
                                                                placeholder="e.g., TrueFire Guitar Lessons"
                                                            >
                                                        </div>
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">Course Title</label>
                                                            <input 
                                                                v-model="courseTitle"
                                                                type="text"
                                                                class="w-full rounded-md border-green-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm"
                                                                placeholder="e.g., Advanced Fingerpicking Techniques"
                                                            >
                                                        </div>
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">Instructor Name</label>
                                                            <input 
                                                                v-model="instructorName"
                                                                type="text"
                                                                class="w-full rounded-md border-green-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm"
                                                                placeholder="e.g., Tommy Emmanuel"
                                                            >
                                                        </div>
                                                        <button 
                                                            @click="populateCourseContext"
                                                            class="text-xs text-green-600 hover:text-green-800 font-medium"
                                                        >
                                                            📋 Auto-fill from course data
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <!-- Custom Prompt -->
                                                <div class="bg-white rounded-lg p-4 border border-green-200">
                                                    <h5 class="font-medium text-green-800 mb-3 flex items-center">
                                                        <svg class="w-4 h-4 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                                        </svg>
                                                        Custom Prompt
                                                    </h5>
                                                    
                                                    <!-- Template Variables Guide -->
                                                    <div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                                        <div class="flex items-center mb-2">
                                                            <svg class="w-4 h-4 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                            </svg>
                                                            <span class="text-sm font-medium text-blue-800">✨ Template Variables Available</span>
                                                        </div>
                                                        <div class="text-xs text-blue-700 space-y-1">
                                                            <div><code class="bg-blue-100 px-1.5 py-0.5 rounded font-mono">{{product_name}}</code> - Product/platform name</div>
                                                            <div><code class="bg-blue-100 px-1.5 py-0.5 rounded font-mono">{{course_title}}</code> - Course title</div>
                                                            <div><code class="bg-blue-100 px-1.5 py-0.5 rounded font-mono">{{instructor_name}}</code> - Instructor name</div>
                                                            <div><code class="bg-blue-100 px-1.5 py-0.5 rounded font-mono">{{segment_id}}</code> - Current segment ID</div>
                                                            <div><code class="bg-blue-100 px-1.5 py-0.5 rounded font-mono">{{preset}}</code> - Transcription preset name</div>
                                                        </div>
                                                    </div>
                                                    
                                                    <textarea 
                                                        v-model="customPromptText"
                                                        rows="6"
                                                        class="w-full rounded-md border-green-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm font-mono"
                                                        placeholder="This is a {{product_name}} lesson about {{course_title}} taught by {{instructor_name}}. Please focus on guitar terminology, chord names, and musical techniques."
                                                    ></textarea>
                                                    <div class="text-xs text-green-600 mt-2">
                                                        🧪 Use template variables like {{product_name}} for dynamic content, or write a custom prompt from scratch
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Test Configuration -->
                                            <div class="bg-white rounded-lg p-4 border border-green-200">
                                                <h5 class="font-medium text-green-800 mb-3">Test Configuration</h5>
                                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                    <div>
                                                        <label class="block text-sm font-medium text-gray-700 mb-1">Base Preset</label>
                                                        <select 
                                                            v-model="selectedCustomPromptPreset"
                                                            class="w-full rounded-md border-green-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm"
                                                        >
                                                            <option value="fast">Fast (Tiny Model)</option>
                                                            <option value="balanced">Balanced (Small Model)</option>
                                                            <option value="high">High Quality (Medium Model)</option>
                                                            <option value="premium">Premium (Large Model)</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="block text-sm font-medium text-gray-700 mb-1">Model Override (Optional)</label>
                                                        <select 
                                                            v-model="selectedCustomPromptModel"
                                                            class="w-full rounded-md border-green-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm"
                                                        >
                                                            <option value="">Use Preset Default</option>
                                                            <option value="tiny">Tiny</option>
                                                            <option value="base">Base</option>
                                                            <option value="small">Small</option>
                                                            <option value="medium">Medium</option>
                                                            <option value="large-v3">Large-v3</option>
                                                        </select>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <input 
                                                            id="enableComparisonMode"
                                                            v-model="enableComparisonMode"
                                                            type="checkbox"
                                                            class="rounded border-green-300 text-green-600 focus:ring-green-500"
                                                        >
                                                        <label for="enableComparisonMode" class="ml-2 text-sm text-gray-700">
                                                            Compare with original prompt
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Test Button -->
                                            <div class="flex justify-center">
                                                <button 
                                                    @click="testCustomPrompt"
                                                    :disabled="isTestingCustomPrompt"
                                                    class="px-8 py-3 bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white rounded-lg transition text-base font-medium flex items-center space-x-2"
                                                >
                                                    <span v-if="isTestingCustomPrompt" class="flex items-center">
                                                        <svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                        </svg>
                                                        Testing Custom Prompt...
                                                    </span>
                                                    <span v-else class="flex items-center">
                                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                        </svg>
                                                        🚀 Test Custom Prompt
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Multi-Model Comparison - Primary Feature -->
                                    <div class="bg-purple-50 rounded-lg p-6 border border-purple-200">
                                        <h4 class="text-lg font-semibold text-purple-900 mb-4">Multi-Model Comparison</h4>
                                        <div class="mb-4">
                                            <p class="text-purple-700 text-sm mb-3">Select models to compare:</p>
                                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                                <label v-for="model in availableModels" :key="model.name" class="flex items-center space-x-2 p-3 bg-white border border-purple-200 rounded-lg hover:bg-purple-50 text-sm cursor-pointer">
                                                    <input 
                                                        type="checkbox" 
                                                        :value="model.name"
                                                        @change="toggleModelComparison(model.name)"
                                                        :checked="selectedComparisonModels.includes(model.name)"
                                                        class="rounded border-purple-300 text-purple-600 focus:ring-purple-500"
                                                    >
                                                    <div class="flex-1 min-w-0">
                                                        <div class="font-medium text-gray-900 truncate">{{ getModelDisplayName(model.name) }}</div>
                                                        <div v-if="model.size_gb > 0" class="text-xs text-gray-500">{{ model.size_gb }}GB</div>
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-sm text-purple-700 font-medium">{{ selectedComparisonModels.length }} model(s) selected</span>
                                            <button 
                                                @click="compareModels" 
                                                :disabled="isComparingModels || selectedComparisonModels.length === 0"
                                                class="px-6 py-3 bg-purple-600 hover:bg-purple-700 disabled:bg-gray-400 text-white rounded-lg transition text-sm font-medium"
                                            >
                                                <span v-if="isComparingModels" class="flex items-center">
                                                    <svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                    Comparing Models...
                                                </span>
                                                <span v-else>🚀 Compare Models</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Interactive Transcript - Secondary -->
                                <div v-if="segmentData.transcript_json_api_url && transcriptData" class="bg-white rounded-lg shadow-md p-6 mb-6">
                                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                                        </svg>
                                        Interactive Transcript
                                    </h3>
                                    <div class="mb-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
                                        <div class="flex items-center">
                                            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span class="text-sm text-blue-700">Click words to jump to timestamps - low confidence words are highlighted for testing</span>
                                        </div>
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

                                <!-- Video Player - Tertiary -->
                                <div class="bg-white rounded-lg shadow-md p-6">
                                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Video Player</h3>
                                    <div class="bg-gray-900 rounded-lg overflow-hidden shadow-lg relative">
                                        <video 
                                            ref="videoElement"
                                            :src="segmentData.url" 
                                            controls
                                            class="w-full max-h-[400px]"
                                            preload="metadata"
                                            @error="handleVideoError"
                                        ></video>
                                        
                                        <div v-if="videoError" class="p-4 bg-red-50 text-red-800 text-sm">
                                            <div class="font-medium">Error loading video:</div>
                                            {{ videoError }}
                                        </div>
                                    </div>
                                </div>

                                <!-- MODEL TESTING RESULTS -->
                                <div v-if="singleModelTestResults || modelComparisonResults || customPromptTestResults" class="bg-white rounded-lg shadow-md p-6 mb-6">
                                    <div class="flex items-center justify-between mb-6">
                                        <h3 class="text-xl font-semibold text-gray-900 flex items-center space-x-2">
                                            <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                            </svg>
                                            <span>📊 Testing Results</span>
                                        </h3>
                                        <button 
                                            @click="clearModelTestResults(); clearCustomPromptResults();" 
                                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition text-sm"
                                        >
                                            Clear All Results
                                        </button>
                                    </div>
                                    
                                    <!-- Custom Prompt Test Results -->
                                    <div v-if="customPromptTestResults" class="mb-8">
                                        <h4 class="text-lg font-semibold text-gray-900 mb-4">🎯 Custom Prompt Test Results</h4>
                                        <div class="bg-green-50 rounded-lg p-6 border border-green-200">
                                            <!-- Test Configuration Summary -->
                                            <div class="mb-6 p-4 bg-white rounded-lg border border-green-200">
                                                <h5 class="font-medium text-green-800 mb-3">Test Configuration</h5>
                                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Model Used</div>
                                                        <div class="font-medium">{{ customPromptTestResults.test_configuration?.model_used || 'Unknown' }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Preset Used</div>
                                                        <div class="font-medium">{{ customPromptTestResults.test_configuration?.preset_used || 'Unknown' }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Product Context</div>
                                                        <div class="font-medium">{{ customPromptTestResults.test_configuration?.product_name_injected ? '✅ Yes' : '❌ No' }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Comparison Mode</div>
                                                        <div class="font-medium">{{ customPromptTestResults.test_configuration?.comparison_mode ? '✅ Enabled' : '❌ Disabled' }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Custom Prompt Performance -->
                                            <div class="mb-6">
                                                <h5 class="font-medium text-green-800 mb-4">Custom Prompt Performance</h5>
                                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                                                    <div>
                                                        <div class="text-2xl font-bold text-green-700">{{ (customPromptTestResults.results?.custom_prompt?.confidence_score * 100).toFixed(1) || '0' }}%</div>
                                                        <div class="text-sm text-green-600">Confidence Score</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-2xl font-bold text-blue-700">{{ customPromptTestResults.results?.custom_prompt?.guitar_term_evaluation?.musical_terms_found || 0 }}</div>
                                                        <div class="text-sm text-blue-600">Guitar Terms Found</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-2xl font-bold text-purple-700">{{ customPromptTestResults.results?.custom_prompt?.word_segments?.length || 0 }}</div>
                                                        <div class="text-sm text-purple-600">Total Words</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-2xl font-bold text-orange-700">{{ (customPromptTestResults.results?.custom_prompt?.processing_time || 0).toFixed(1) }}s</div>
                                                        <div class="text-sm text-orange-600">Processing Time</div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Comparison Results (if enabled) -->
                                            <div v-if="customPromptTestResults.results?.comparison_analysis" class="mb-6 p-4 bg-white rounded-lg border border-green-200">
                                                <h5 class="font-medium text-green-800 mb-4">📊 Comparison Analysis</h5>
                                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                    <div class="text-center">
                                                        <div class="text-lg font-bold text-blue-700">
                                                            {{ customPromptTestResults.results.comparison_analysis.confidence_scores?.improvement > 0 ? '+' : '' }}{{ (customPromptTestResults.results.comparison_analysis.confidence_scores?.improvement * 100).toFixed(1) || '0' }}%
                                                        </div>
                                                        <div class="text-sm text-blue-600">Confidence Improvement</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-lg font-bold text-green-700">
                                                            {{ customPromptTestResults.results.comparison_analysis.guitar_term_comparison?.terms_improvement > 0 ? '+' : '' }}{{ customPromptTestResults.results.comparison_analysis.guitar_term_comparison?.terms_improvement || 0 }}
                                                        </div>
                                                        <div class="text-sm text-green-600">Additional Guitar Terms</div>
                                                    </div>
                                                    <div class="text-center">
                                                        <div class="text-lg font-bold text-purple-700">{{ customPromptTestResults.results.comparison_analysis.text_comparison?.similarity_percentage || 0 }}%</div>
                                                        <div class="text-sm text-purple-600">Text Similarity</div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Overall Assessment -->
                                                <div class="mt-4 p-3 bg-gray-50 rounded-lg">
                                                    <div class="text-sm">
                                                        <div class="font-medium text-gray-800 mb-2">📋 Assessment:</div>
                                                        <div class="text-gray-700">{{ customPromptTestResults.results.comparison_analysis.overall_assessment || 'No assessment available' }}</div>
                                                        
                                                        <!-- Recommendations -->
                                                        <div v-if="customPromptTestResults.results.comparison_analysis.recommendation?.length" class="mt-3">
                                                            <div class="font-medium text-gray-800 mb-2">💡 Recommendations:</div>
                                                            <ul class="list-disc list-inside space-y-1 text-gray-700">
                                                                <li v-for="rec in customPromptTestResults.results.comparison_analysis.recommendation" :key="rec" class="text-sm">
                                                                    {{ rec }}
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Prompt Details -->
                                            <div class="bg-white rounded-lg p-4 border border-green-200">
                                                <h5 class="font-medium text-green-800 mb-3">🔍 Prompt Analysis</h5>
                                                <div class="grid grid-cols-2 gap-4 text-sm">
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Custom Prompt Length</div>
                                                        <div class="font-medium">{{ customPromptTestResults.prompt_analysis?.custom_prompt_length || 0 }} characters</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-xs text-gray-600 mb-1">Enhancement Applied</div>
                                                        <div class="font-medium">{{ customPromptTestResults.prompt_analysis?.enhancement_applied ? '✅ Yes' : '❌ No' }}</div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Expandable Prompt Preview -->
                                                <details class="mt-3">
                                                    <summary class="text-xs text-green-600 cursor-pointer hover:text-green-800">Show final prompt used</summary>
                                                    <div class="mt-2 p-3 bg-gray-50 rounded border border-gray-200 text-xs text-gray-600 font-mono max-h-32 overflow-y-auto">
                                                        {{ customPromptTestResults.results?.custom_prompt?.prompt_used || 'Prompt not available' }}
                                                    </div>
                                                </details>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Single Model Results -->
                                    <div v-if="singleModelTestResults" class="mb-8">
                                        <h4 class="text-lg font-semibold text-gray-900 mb-4">Single Model Test Results</h4>
                                        <div class="bg-purple-50 rounded-lg p-6 border border-purple-200">
                                            <h5 class="font-medium text-purple-800 mb-4">{{ getModelDisplayName(singleModelTestResults.model) }} Performance</h5>
                                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                                                <div>
                                                    <div class="text-2xl font-bold text-purple-700">{{ singleModelTestResults.contextual_evaluation?.enhanced_words_count || 0 }}</div>
                                                    <div class="text-sm text-purple-600">Terms Enhanced</div>
                                                </div>
                                                <div>
                                                    <div class="text-2xl font-bold text-blue-700">{{ singleModelTestResults.contextual_evaluation?.low_confidence_words_analyzed || 0 }}</div>
                                                    <div class="text-sm text-blue-600">Words Analyzed</div>
                                                </div>
                                                <div>
                                                    <div class="text-2xl font-bold text-green-700">{{ (singleModelTestResults.processing_time || 0).toFixed(2) }}s</div>
                                                    <div class="text-sm text-green-600">Processing Time</div>
                                                </div>
                                                <div>
                                                    <div class="text-2xl font-bold text-orange-700">{{ (((singleModelTestResults.contextual_evaluation?.enhanced_words_count || 0) / Math.max(singleModelTestResults.contextual_evaluation?.low_confidence_words_analyzed || 1, 1)) * 100).toFixed(0) }}%</div>
                                                    <div class="text-sm text-orange-600">Precision Rate</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Model Comparison Results -->
                                    <div v-if="modelComparisonResults"
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
                                        
                                        <!-- Model Testing Panel -->
                                        <div v-if="transcriptionSuccess.success && guitarEnhancementAnalysis" class="space-y-4">
                                            <div class="bg-purple-50 rounded-lg p-4 border border-purple-200">
                                                <div class="flex items-center justify-between mb-4">
                                                    <h4 class="font-medium text-purple-800 flex items-center">
                                                        <svg class="w-5 h-5 text-purple-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                                        </svg>
                                                        Contextual Guitar Term Evaluation
                                                    </h4>
                                                    <button 
                                                        @click="showModelTestingPanel = !showModelTestingPanel"
                                                        class="text-purple-600 hover:text-purple-800 transition"
                                                    >
                                                        <svg 
                                                            :class="{ 'transform rotate-180': showModelTestingPanel }"
                                                            class="w-5 h-5 transition-transform"
                                                            fill="none" 
                                                            stroke="currentColor" 
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                        </svg>
                                                    </button>
                                                </div>
                                                
                                                <div v-if="!showModelTestingPanel" class="text-sm text-purple-700">
                                                    Test different LLM models for contextual guitar terminology evaluation in low-confidence segments
                                                </div>
                                                
                                                <!-- Model Testing Panel Content -->
                                                <div v-if="showModelTestingPanel" class="space-y-4">
                                                    <!-- Error Display -->
                                                    <div v-if="modelTestError" class="p-3 bg-red-100 border border-red-200 rounded-lg text-red-700 text-sm">
                                                        {{ modelTestError }}
                                                    </div>
                                                    
                                                    <!-- Confidence Threshold Setting -->
                                                    <div>
                                                        <label class="block text-sm font-medium text-purple-700 mb-2">Confidence Threshold</label>
                                                        <input 
                                                            v-model.number="confidenceThreshold" 
                                                            type="range" 
                                                            min="0.1" 
                                                            max="1.0" 
                                                            step="0.05" 
                                                            class="w-full"
                                                        >
                                                        <div class="flex justify-between text-xs text-purple-600 mt-1">
                                                            <span>0.1 (Low)</span>
                                                            <span class="font-medium">{{ confidenceThreshold.toFixed(2) }}</span>
                                                            <span>1.0 (High)</span>
                                                        </div>
                                                    </div>

                                                    <!-- LLM Prompt Configuration -->
                                                    <div class="border border-purple-200 rounded-lg p-4 bg-white">
                                                        <div class="flex items-center justify-between mb-3">
                                                            <h5 class="font-medium text-purple-800">LLM Prompt Configuration</h5>
                                                            <button 
                                                                @click="showPromptEditor = !showPromptEditor"
                                                                class="text-purple-600 hover:text-purple-800 transition text-sm"
                                                            >
                                                                <svg 
                                                                    :class="{ 'transform rotate-180': showPromptEditor }"
                                                                    class="w-4 h-4 transition-transform inline mr-1"
                                                                    fill="none" 
                                                                    stroke="currentColor" 
                                                                    viewBox="0 0 24 24"
                                                                >
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                                </svg>
                                                                {{ showPromptEditor ? 'Hide' : 'Show' }} Prompt Editor
                                                            </button>
                                                        </div>
                                                        
                                                        <div v-if="!showPromptEditor" class="text-sm text-purple-700">
                                                            Customize the LLM prompt to control contextual guitar term evaluation
                                                        </div>
                                                        
                                                        <div v-if="showPromptEditor" class="space-y-4">
                                                            <!-- Use Custom Prompt Toggle -->
                                                            <div class="flex items-center space-x-2">
                                                                <input 
                                                                    id="useCustomPrompt"
                                                                    v-model="useCustomPrompt" 
                                                                    type="checkbox" 
                                                                    class="rounded border-purple-300 text-purple-600 focus:ring-purple-500"
                                                                >
                                                                <label for="useCustomPrompt" class="text-sm font-medium text-purple-700">
                                                                    Use Custom Prompt
                                                                </label>
                                                            </div>
                                                            
                                                            <!-- Default Prompt Display -->
                                                            <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                                                                <h6 class="font-medium text-gray-800 mb-2 text-sm">Default Prompt:</h6>
                                                                <div class="text-xs text-gray-600 font-mono bg-white p-2 rounded border border-gray-200 max-h-32 overflow-y-auto">
                                                                    {{ defaultPrompt || 'Loading default prompt...' }}
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Custom Prompt Editor -->
                                                            <div v-if="useCustomPrompt">
                                                                <label class="block text-sm font-medium text-purple-700 mb-2">Custom Prompt:</label>
                                                                <textarea 
                                                                    v-model="customPrompt"
                                                                    rows="6"
                                                                    class="w-full rounded-md border-purple-300 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm font-mono"
                                                                    placeholder="Enter your custom prompt for contextual guitar term evaluation..."
                                                                ></textarea>
                                                                <div class="text-xs text-purple-600 mt-1">
                                                                    The prompt should instruct the LLM how to evaluate low-confidence words in their context to identify legitimate guitar terms.
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Single Model Testing -->
                                                    <div class="border border-purple-200 rounded-lg p-4 bg-white">
                                                        <h5 class="font-medium text-purple-800 mb-3">Test Single Model</h5>
                                                        <div class="flex items-center space-x-3 mb-3">
                                                            <select 
                                                                v-model="selectedTestModel" 
                                                                class="flex-1 rounded-md border-purple-300 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm"
                                                            >
                                                                <option v-for="model in availableModels" :key="model.name" :value="model.name">
                                                                    {{ getModelDisplayName(model.name) }}
                                                                    <span v-if="model.size_gb > 0" class="text-gray-500">({{ model.size_gb }}GB)</span>
                                                                </option>
                                                            </select>
                                                            <button 
                                                                @click="testSingleModel" 
                                                                :disabled="isTestingModel || !selectedTestModel"
                                                                class="px-3 py-2 bg-purple-600 hover:bg-purple-700 disabled:bg-gray-400 text-white rounded-md transition text-sm"
                                                            >
                                                                <span v-if="isTestingModel" class="flex items-center">
                                                                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                                    </svg>
                                                                    Testing...
                                                                </span>
                                                                <span v-else>Test</span>
                                                            </button>
                                                        </div>
                                                        
                                                        <!-- Single Model Results -->
                                                        <div v-if="singleModelTestResults" class="bg-purple-50 rounded-lg p-3 border border-purple-200">
                                                            <h6 class="font-medium text-purple-800 mb-2">{{ getModelDisplayName(singleModelTestResults.model) }} Results</h6>
                                                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                                                <div class="text-center">
                                                                    <div class="text-lg font-bold text-purple-700">{{ singleModelTestResults.contextual_evaluation?.enhanced_words_count || 0 }}</div>
                                                                    <div class="text-purple-600 text-xs">Terms Enhanced</div>
                                                                </div>
                                                                <div class="text-center">
                                                                    <div class="text-lg font-bold text-blue-700">{{ singleModelTestResults.contextual_evaluation?.low_confidence_words_analyzed || 0 }}</div>
                                                                    <div class="text-blue-600 text-xs">Words Analyzed</div>
                                                                </div>
                                                                <div class="text-center">
                                                                    <div class="text-lg font-bold text-green-700">{{ (singleModelTestResults.processing_time || 0).toFixed(2) }}s</div>
                                                                    <div class="text-green-600 text-xs">Processing Time</div>
                                                                </div>
                                                                <div class="text-center">
                                                                    <div class="text-lg font-bold text-orange-700">{{ (((singleModelTestResults.contextual_evaluation?.enhanced_words_count || 0) / Math.max(singleModelTestResults.contextual_evaluation?.low_confidence_words_analyzed || 1, 1)) * 100).toFixed(0) }}%</div>
                                                                    <div class="text-orange-600 text-xs">Precision Rate</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Multi-Model Comparison -->
                                                    <div class="border border-purple-200 rounded-lg p-4 bg-white">
                                                        <h5 class="font-medium text-purple-800 mb-3">Compare Multiple Models</h5>
                                                        <div class="mb-3">
                                                            <p class="text-sm text-purple-700 mb-2">Select models to compare:</p>
                                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-32 overflow-y-auto">
                                                                <label v-for="model in availableModels" :key="model.name" class="flex items-center space-x-2 p-2 border rounded hover:bg-purple-50 text-sm">
                                                                    <input 
                                                                        type="checkbox" 
                                                                        :value="model.name"
                                                                        @change="toggleModelComparison(model.name)"
                                                                        :checked="selectedComparisonModels.includes(model.name)"
                                                                        class="rounded border-purple-300 text-purple-600 focus:ring-purple-500"
                                                                    >
                                                                    <span class="text-sm">
                                                                        {{ getModelDisplayName(model.name) }}
                                                                        <span v-if="model.size_gb > 0" class="text-gray-500">({{ model.size_gb }}GB)</span>
                                                                    </span>
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="flex justify-between items-center">
                                                            <span class="text-sm text-purple-700">{{ selectedComparisonModels.length }} model(s) selected</span>
                                                            <button 
                                                                @click="compareModels" 
                                                                :disabled="isComparingModels || selectedComparisonModels.length === 0"
                                                                class="px-3 py-2 bg-purple-600 hover:bg-purple-700 disabled:bg-gray-400 text-white rounded-md transition text-sm"
                                                            >
                                                                <span v-if="isComparingModels" class="flex items-center">
                                                                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                                    </svg>
                                                                    Comparing...
                                                                </span>
                                                                <span v-else>Compare</span>
                                                            </button>
                                                        </div>
                                                        
                                                        <!-- Model Comparison Results -->
                                                        <div v-if="modelComparisonResults" class="mt-4">
                                                            <h6 class="font-medium text-purple-800 mb-3">Comparison Results</h6>
                                                            
                                                            <!-- Best Performer Highlight -->
                                                            <div v-if="modelComparisonResults.comparison?.best_performer" class="mb-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                                                                <div class="flex items-center justify-between">
                                                                    <div>
                                                                        <span class="text-sm font-medium text-green-800">🏆 Best Performer:</span>
                                                                        <span class="text-sm text-green-700 ml-2">{{ getModelDisplayName(modelComparisonResults.comparison.best_performer.model) }}</span>
                                                                    </div>
                                                                    <div class="text-sm text-green-600">
                                                                        Score: {{ (modelComparisonResults.comparison.best_performer.score * 100).toFixed(1) }}%
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Comparison Table -->
                                                            <div class="overflow-x-auto">
                                                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                                                    <thead class="bg-purple-50">
                                                                        <tr>
                                                                            <th class="px-3 py-2 text-left text-xs font-medium text-purple-700 uppercase tracking-wider">Model</th>
                                                                            <th class="px-3 py-2 text-left text-xs font-medium text-purple-700 uppercase tracking-wider">Enhanced</th>
                                                                            <th class="px-3 py-2 text-left text-xs font-medium text-purple-700 uppercase tracking-wider">Precision</th>
                                                                            <th class="px-3 py-2 text-left text-xs font-medium text-purple-700 uppercase tracking-wider">Speed</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody class="bg-white divide-y divide-gray-200">
                                                                        <tr v-for="(modelData, modelName) in modelComparisonResults.comparison?.models" :key="modelName || 'unknown'">
                                                                            <td class="px-3 py-2 whitespace-nowrap text-gray-900 font-medium text-sm">
                                                                                {{ getModelDisplayName(modelName) }}
                                                                                <span v-if="modelComparisonResults.comparison?.best_performer?.model === modelName" class="ml-1 text-yellow-500">🏆</span>
                                                                            </td>
                                                                            <td class="px-3 py-2 whitespace-nowrap text-gray-900 text-sm">{{ (modelData && modelData.enhanced_words_count) || 0 }}</td>
                                                                            <td class="px-3 py-2 whitespace-nowrap text-sm" :class="getPerformanceColor((modelData && modelData.precision_rate) ? modelData.precision_rate / 100 : 0)">
                                                                                {{ ((modelData && modelData.precision_rate) || 0).toFixed(1) }}%
                                                                            </td>
                                                                            <td class="px-3 py-2 whitespace-nowrap text-gray-900 text-sm">{{ ((modelData && modelData.response_time) || 0).toFixed(2) }}s</td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                            
                                                            <!-- Agreement Analysis -->
                                                            <div v-if="modelComparisonResults.comparison?.agreement_analysis" class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                                                <h6 class="font-medium text-blue-800 mb-2 text-sm">Term Agreement</h6>
                                                                <div class="grid grid-cols-3 gap-3 text-sm">
                                                                    <div class="text-center">
                                                                        <div class="font-bold text-green-600">{{ modelComparisonResults.comparison.agreement_analysis.high_agreement_terms }}</div>
                                                                        <div class="text-green-600 text-xs">High (75%+)</div>
                                                                    </div>
                                                                    <div class="text-center">
                                                                        <div class="font-bold text-yellow-600">{{ modelComparisonResults.comparison.agreement_analysis.moderate_agreement_terms }}</div>
                                                                        <div class="text-yellow-600 text-xs">Moderate</div>
                                                                    </div>
                                                                    <div class="text-center">
                                                                        <div class="font-bold text-red-600">{{ modelComparisonResults.comparison.agreement_analysis.low_agreement_terms }}</div>
                                                                        <div class="text-red-600 text-xs">Low (<50%)</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Detailed Term Analysis -->
                                                            <div v-if="modelComparisonResults.results" class="mt-4 bg-white border border-gray-200 rounded-lg overflow-hidden">
                                                                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200">
                                                                    <h6 class="font-medium text-gray-800 text-sm flex items-center">
                                                                        🔍 Detailed Terms Found by Each Model
                                                                        <span class="ml-2 text-xs text-gray-500">(Click to expand)</span>
                                                                    </h6>
                                                                </div>
                                                                <div class="max-h-96 overflow-y-auto">
                                                                    <div v-for="(modelResult, modelName) in modelComparisonResults.results" :key="modelName" class="border-b border-gray-100 last:border-b-0">
                                                                        <button @click="toggleModelDetails(modelName)" class="w-full text-left px-4 py-3 hover:bg-gray-50 focus:outline-none focus:bg-gray-50">
                                                                            <div class="flex items-center justify-between">
                                                                                <div class="flex items-center">
                                                                                    <span class="font-medium text-gray-900">{{ getModelDisplayName(modelName) }}</span>
                                                                                    <span class="ml-2 text-sm text-gray-500">
                                                                                        ({{ (modelResult.contextual_evaluation?.enhanced_words || []).length }} terms)
                                                                                    </span>
                                                                                </div>
                                                                                <svg class="w-4 h-4 transform transition-transform" :class="{ 'rotate-180': expandedModelDetails[modelName] }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                                                </svg>
                                                                            </div>
                                                                        </button>
                                                                        
                                                                        <div v-if="expandedModelDetails[modelName]" class="px-4 pb-4">
                                                                            <div v-if="modelResult.contextual_evaluation?.enhanced_words?.length > 0" class="space-y-2">
                                                                                <div v-for="(word, index) in modelResult.contextual_evaluation.enhanced_words" :key="index" 
                                                                                     class="p-3 bg-gray-50 rounded border border-gray-200">
                                                                                    <div class="flex items-start justify-between mb-2">
                                                                                        <div>
                                                                                            <span class="font-medium text-gray-900">"{{ word.word }}"</span>
                                                                                            <span class="ml-2 text-xs text-gray-500">
                                                                                                {{ formatTime(word.start) }} - {{ formatTime(word.end) }}
                                                                                            </span>
                                                                                        </div>
                                                                                        <div class="text-xs text-gray-600">
                                                                                            {{ (word.original_confidence != null ? (word.original_confidence * 100).toFixed(0) + '%' : 'N/A') }} → {{ (word.confidence != null ? (word.confidence * 100).toFixed(0) + '%' : 'N/A') }}
                                                                                        </div>
                                                                                    </div>
                                                                                    <div v-if="word.context" class="text-xs text-gray-600 mb-2">
                                                                                        <strong>Context:</strong> "{{ word.context }}"
                                                                                    </div>
                                                                                    <div v-if="word.llm_response" class="text-xs">
                                                                                        <strong class="text-blue-600">LLM Response:</strong> 
                                                                                        <span class="font-mono bg-blue-50 px-1 py-0.5 rounded">{{ word.llm_response }}</span>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            <div v-else class="text-sm text-gray-500 italic">
                                                                                No contextual guitar terms found by this model.
                                                                            </div>
                                                                            
                                                                            <!-- Show model responses for debugging -->
                                                                            <div v-if="modelResult.contextual_evaluation?.analysis_details?.length > 0" class="mt-4">
                                                                                <button @click="toggleModelResponses(modelName)" class="text-xs text-blue-600 hover:text-blue-800">
                                                                                    {{ expandedModelResponses[modelName] ? 'Hide' : 'Show' }} All LLM Responses ({{ modelResult.contextual_evaluation.analysis_details.length }})
                                                                                </button>
                                                                                
                                                                                <div v-if="expandedModelResponses[modelName]" class="mt-2 max-h-64 overflow-y-auto space-y-1">
                                                                                    <div v-for="(analysis, idx) in modelResult.contextual_evaluation.analysis_details" :key="idx" 
                                                                                         class="p-2 text-xs border rounded" 
                                                                                         :class="analysis.enhanced ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'">
                                                                                        <div class="flex items-center justify-between mb-1">
                                                                                            <span class="font-medium">"{{ analysis.word }}"</span>
                                                                                            <span :class="analysis.enhanced ? 'text-green-600' : 'text-red-600'">
                                                                                                {{ analysis.enhanced ? '✓ Contextual Guitar Term' : '✗ Not Contextual Guitar Term' }}
                                                                                            </span>
                                                                                        </div>
                                                                                        <div class="text-gray-600 mb-1">Context: "{{ analysis.context }}"</div>
                                                                                        <div class="font-mono bg-gray-100 px-2 py-1 rounded">{{ analysis.llm_response }}</div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Clear Results Button -->
                                                    <div v-if="singleModelTestResults || modelComparisonResults" class="text-center">
                                                        <button 
                                                            @click="clearModelTestResults" 
                                                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md transition text-sm"
                                                        >
                                                            Clear Results
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Teaching Pattern Model Comparison Panel -->
                                        <div v-if="transcriptionSuccess.success && teachingPatternAnalysis" class="space-y-4">
                                            <div class="bg-indigo-50 rounded-lg p-4 border border-indigo-200">
                                                <div class="flex items-center justify-between mb-4">
                                                    <h4 class="font-medium text-indigo-800 flex items-center">
                                                        <svg class="w-5 h-5 text-indigo-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                                        </svg>
                                                        Teaching Pattern Analysis
                                                    </h4>
                                                    <button 
                                                        @click="showTeachingPatternPanel = !showTeachingPatternPanel"
                                                        class="text-indigo-600 hover:text-indigo-800 transition"
                                                    >
                                                        <svg 
                                                            :class="{ 'transform rotate-180': showTeachingPatternPanel }"
                                                            class="w-5 h-5 transition-transform"
                                                            fill="none" 
                                                            stroke="currentColor" 
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                        </svg>
                                                    </button>
                                                </div>
                                                
                                                <div v-if="!showTeachingPatternPanel" class="text-sm text-indigo-700">
                                                    Compare different LLM models' ability to analyze pedagogical quality and teaching effectiveness
                                                </div>
                                                
                                                <!-- Teaching Pattern Panel Content -->
                                                <div v-if="showTeachingPatternPanel" class="space-y-4">
                                                    <!-- Error Display -->
                                                    <div v-if="teachingPatternError" class="p-3 bg-red-100 border border-red-200 rounded-lg text-red-700 text-sm">
                                                        {{ teachingPatternError }}
                                                    </div>
                                                    
                                                    <!-- Current Teaching Pattern Analysis -->
                                                    <div v-if="teachingPatternAnalysis" class="border border-indigo-200 rounded-lg p-4 bg-white">
                                                        <h5 class="font-medium text-indigo-800 mb-3">Current Analysis (from transcription)</h5>
                                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                            <div>
                                                                <div class="text-sm font-medium text-gray-700 mb-1">Primary Pattern</div>
                                                                <div class="flex items-center">
                                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                                                                          :class="getPatternStyle(teachingPatternAnalysis.content_classification.primary_type).bgColor + ' ' + getPatternStyle(teachingPatternAnalysis.content_classification.primary_type).textColor">
                                                                        {{ getPatternStyle(teachingPatternAnalysis.content_classification.primary_type).icon }}
                                                                        {{ teachingPatternAnalysis.content_classification.primary_type }}
                                                                    </span>
                                                                    <span class="ml-2 text-sm text-gray-600">
                                                                        ({{ (teachingPatternAnalysis.content_classification.confidence * 100).toFixed(0) }}% confidence)
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <div class="text-sm font-medium text-gray-700 mb-1">Teaching Cycles</div>
                                                                <div class="text-lg font-bold text-indigo-600">
                                                                    {{ teachingPatternAnalysis.temporal_analysis?.alternation_cycles || 0 }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div v-if="teachingPatternAnalysis.summary?.recommendations" class="mt-3">
                                                            <div class="text-sm font-medium text-gray-700 mb-1">Recommendations</div>
                                                            <ul class="text-sm text-gray-600 list-disc list-inside space-y-1">
                                                                <li v-for="rec in teachingPatternAnalysis.summary.recommendations" :key="rec">{{ rec }}</li>
                                                            </ul>
                                                        </div>
                                                    </div>

                                                    <!-- Multi-Model Teaching Pattern Comparison -->
                                                    <div class="border border-indigo-200 rounded-lg p-4 bg-white">
                                                        <h5 class="font-medium text-indigo-800 mb-3">Compare Teaching Pattern Models</h5>
                                                        <div class="mb-3">
                                                            <p class="text-sm text-indigo-700 mb-2">Select models to compare for pedagogical analysis:</p>
                                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-32 overflow-y-auto">
                                                                <label v-for="model in availableModels" :key="model.name" class="flex items-center space-x-2 p-2 border rounded hover:bg-indigo-50 text-sm">
                                                                    <input 
                                                                        type="checkbox" 
                                                                        :value="model.name"
                                                                        @change="toggleTeachingPatternModel(model.name)"
                                                                        :checked="selectedTeachingPatternModels.includes(model.name)"
                                                                        class="rounded border-indigo-300 text-indigo-600 focus:ring-indigo-500"
                                                                    >
                                                                    <span class="text-sm">
                                                                        {{ getModelDisplayName(model.name) }}
                                                                        <span v-if="model.size_gb > 0" class="text-gray-500">({{ model.size_gb }}GB)</span>
                                                                    </span>
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="flex justify-between items-center">
                                                            <span class="text-sm text-indigo-700">{{ selectedTeachingPatternModels.length }} model(s) selected</span>
                                                            <button 
                                                                @click="compareTeachingPatternModels" 
                                                                :disabled="isComparingTeachingPatterns || selectedTeachingPatternModels.length === 0"
                                                                class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:bg-gray-400 text-white rounded-md transition text-sm"
                                                            >
                                                                <span v-if="isComparingTeachingPatterns" class="flex items-center">
                                                                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                                    </svg>
                                                                    Analyzing...
                                                                </span>
                                                                <span v-else>Analyze Teaching Patterns</span>
                                                            </button>
                                                        </div>
                                                        
                                                        <!-- Teaching Pattern Comparison Results -->
                                                        <div v-if="teachingPatternComparisonResults" class="mt-4">
                                                            <h6 class="font-medium text-indigo-800 mb-3">Pedagogical Analysis Results</h6>
                                                            
                                                            <!-- Best Performer Highlight -->
                                                            <div v-if="teachingPatternComparisonResults.comparison_summary?.best_pedagogical_analyzer" class="mb-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                                                                <div class="flex items-center justify-between">
                                                                    <div>
                                                                        <span class="text-sm font-medium text-green-800">🏆 Best Pedagogical Analyzer:</span>
                                                                        <span class="text-sm text-green-700 ml-2">{{ getModelDisplayName(teachingPatternComparisonResults.comparison_summary.best_pedagogical_analyzer) }}</span>
                                                                    </div>
                                                                    <div class="text-sm text-green-600">
                                                                        Fastest: {{ getModelDisplayName(teachingPatternComparisonResults.comparison_summary.fastest_model) }}
                                                                    </div>
                                                                </div>
                                                                <div v-if="teachingPatternComparisonResults.comparison_summary.recommendation" class="mt-2 text-sm text-green-700">
                                                                    {{ teachingPatternComparisonResults.comparison_summary.recommendation }}
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Comparison Table -->
                                                            <div class="overflow-x-auto">
                                                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                                                    <thead class="bg-indigo-50">
                                                                        <tr>
                                                                            <th class="px-3 py-2 text-left text-xs font-medium text-indigo-700 uppercase tracking-wider">Model</th>
                                                                            <th class="px-3 py-2 text-left text-xs font-medium text-indigo-700 uppercase tracking-wider">Pattern</th>
                                                                            <th class="px-3 py-2 text-left text-xs font-medium text-indigo-700 uppercase tracking-wider">Quality</th>
                                                                            <th class="px-3 py-2 text-left text-xs font-medium text-indigo-700 uppercase tracking-wider">Cycles</th>
                                                                            <th class="px-3 py-2 text-left text-xs font-medium text-indigo-700 uppercase tracking-wider">Speed</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody class="bg-white divide-y divide-gray-200">
                                                                        <tr v-for="(modelData, modelName) in teachingPatternComparisonResults.model_results" :key="modelName || 'unknown'">
                                                                            <td class="px-3 py-2 whitespace-nowrap text-gray-900 font-medium text-sm">
                                                                                {{ getModelDisplayName(modelName) }}
                                                                                <span v-if="teachingPatternComparisonResults.comparison_summary?.best_pedagogical_analyzer === modelName" class="ml-1 text-yellow-500">🏆</span>
                                                                            </td>
                                                                            <td class="px-3 py-2 whitespace-nowrap text-sm">
                                                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                                                                                      :class="getPatternStyle(modelData.teaching_pattern_detected).bgColor + ' ' + getPatternStyle(modelData.teaching_pattern_detected).textColor">
                                                                                    {{ getPatternStyle(modelData.teaching_pattern_detected).icon }}
                                                                                    {{ modelData.teaching_pattern_detected }}
                                                                                </span>
                                                                            </td>
                                                                            <td class="px-3 py-2 whitespace-nowrap text-sm" :class="getPerformanceColor(modelData.pedagogical_quality_score / 10)">
                                                                                {{ (modelData.pedagogical_quality_score || 0).toFixed(1) }}/10
                                                                            </td>
                                                                            <td class="px-3 py-2 whitespace-nowrap text-gray-900 text-sm">{{ modelData.teaching_cycles_detected || 0 }}</td>
                                                                            <td class="px-3 py-2 whitespace-nowrap text-gray-900 text-sm">{{ (modelData.processing_time || 0).toFixed(2) }}s</td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                            
                                                            <!-- Model Agreement Analysis -->
                                                            <div v-if="teachingPatternComparisonResults.comparison_summary?.model_agreement_analysis" class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                                                <h6 class="font-medium text-blue-800 mb-2 text-sm">Model Agreement</h6>
                                                                <div class="grid grid-cols-3 gap-3 text-sm">
                                                                    <div class="text-center">
                                                                        <div class="font-bold text-blue-600">{{ (teachingPatternComparisonResults.comparison_summary.model_agreement_analysis.pattern_agreement_score * 100).toFixed(0) }}%</div>
                                                                        <div class="text-blue-600 text-xs">Pattern Agreement</div>
                                                                    </div>
                                                                    <div class="text-center">
                                                                        <div class="font-bold text-green-600">{{ teachingPatternComparisonResults.comparison_summary.model_agreement_analysis.average_quality_score?.toFixed(1) || 'N/A' }}</div>
                                                                        <div class="text-green-600 text-xs">Avg Quality</div>
                                                                    </div>
                                                                    <div class="text-center">
                                                                        <div class="font-bold" :class="teachingPatternComparisonResults.comparison_summary.model_agreement_analysis.high_agreement ? 'text-green-600' : 'text-orange-600'">
                                                                            {{ teachingPatternComparisonResults.comparison_summary.model_agreement_analysis.high_agreement ? 'High' : 'Low' }}
                                                                        </div>
                                                                        <div class="text-gray-600 text-xs">Agreement</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Detailed Analysis per Model -->
                                                            <div v-if="teachingPatternComparisonResults.model_results" class="mt-4 bg-white border border-gray-200 rounded-lg overflow-hidden">
                                                                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200">
                                                                    <h6 class="font-medium text-gray-800 text-sm flex items-center">
                                                                        📚 Detailed Pedagogical Analysis by Model
                                                                        <span class="ml-2 text-xs text-gray-500">(Click to expand)</span>
                                                                    </h6>
                                                                </div>
                                                                <div class="max-h-96 overflow-y-auto">
                                                                    <div v-for="(modelResult, modelName) in teachingPatternComparisonResults.model_results" :key="modelName" class="border-b border-gray-100 last:border-b-0">
                                                                        <button @click="toggleTeachingModelDetails(modelName)" class="w-full text-left px-4 py-3 hover:bg-gray-50 focus:outline-none focus:bg-gray-50">
                                                                            <div class="flex items-center justify-between">
                                                                                <div class="flex items-center">
                                                                                    <span class="font-medium text-gray-900">{{ getModelDisplayName(modelName) }}</span>
                                                                                    <span class="ml-2 text-sm text-gray-500">
                                                                                        (Quality: {{ (modelResult.pedagogical_quality_score || 0).toFixed(1) }}/10)
                                                                                    </span>
                                                                                </div>
                                                                                <svg class="w-4 h-4 transform transition-transform" :class="{ 'rotate-180': expandedTeachingModelDetails[modelName] }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                                                </svg>
                                                                            </div>
                                                                        </button>
                                                                        
                                                                        <div v-if="expandedTeachingModelDetails[modelName]" class="px-4 pb-4">
                                                                            <!-- Model Analysis Summary -->
                                                                            <div class="bg-gray-50 rounded-lg p-3 mb-3">
                                                                                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                                                                    <div>
                                                                                        <div class="font-medium text-gray-700">Pattern</div>
                                                                                        <div class="text-gray-600">{{ modelResult.teaching_pattern_detected }}</div>
                                                                                    </div>
                                                                                    <div>
                                                                                        <div class="font-medium text-gray-700">Confidence</div>
                                                                                        <div class="text-gray-600">{{ (modelResult.confidence_score * 100).toFixed(0) }}%</div>
                                                                                    </div>
                                                                                    <div>
                                                                                        <div class="font-medium text-gray-700">Target</div>
                                                                                        <div class="text-gray-600">{{ modelResult.target_audience }}</div>
                                                                                    </div>
                                                                                    <div>
                                                                                        <div class="font-medium text-gray-700">Effectiveness</div>
                                                                                        <div class="text-gray-600">{{ modelResult.lesson_effectiveness }}</div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            
                                                                            <!-- Strengths -->
                                                                            <div v-if="modelResult.strengths_identified?.length > 0" class="mb-3">
                                                                                <div class="font-medium text-green-700 mb-2 text-sm">✅ Identified Strengths</div>
                                                                                <ul class="space-y-1">
                                                                                    <li v-for="strength in modelResult.strengths_identified" :key="strength" 
                                                                                        class="text-sm text-green-800 bg-green-50 px-2 py-1 rounded border border-green-200">
                                                                                        {{ strength }}
                                                                                    </li>
                                                                                </ul>
                                                                            </div>
                                                                            
                                                                            <!-- Improvement Suggestions -->
                                                                            <div v-if="modelResult.improvement_suggestions?.length > 0" class="mb-3">
                                                                                <div class="font-medium text-blue-700 mb-2 text-sm">💡 Improvement Suggestions</div>
                                                                                <ul class="space-y-1">
                                                                                    <li v-for="suggestion in modelResult.improvement_suggestions" :key="suggestion" 
                                                                                        class="text-sm text-blue-800 bg-blue-50 px-2 py-1 rounded border border-blue-200">
                                                                                        {{ suggestion }}
                                                                                    </li>
                                                                                </ul>
                                                                            </div>
                                                                            
                                                                            <!-- Raw LLM Response -->
                                                                            <div v-if="modelResult.raw_llm_response" class="mt-3">
                                                                                <button @click="toggleTeachingModelResponses(modelName)" class="text-xs text-blue-600 hover:text-blue-800">
                                                                                    {{ expandedTeachingModelResponses[modelName] ? 'Hide' : 'Show' }} Raw LLM Response
                                                                                </button>
                                                                                
                                                                                <div v-if="expandedTeachingModelResponses[modelName]" class="mt-2 max-h-64 overflow-y-auto">
                                                                                    <div class="p-3 text-xs border rounded bg-gray-50 font-mono">
                                                                                        {{ modelResult.raw_llm_response }}
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Clear Results Button -->
                                                    <div v-if="teachingPatternComparisonResults" class="text-center">
                                                        <button 
                                                            @click="clearTeachingPatternResults" 
                                                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md transition text-sm"
                                                        >
                                                            Clear Results
                                                        </button>
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
                                                <div v-if="confidenceAnalysis.lowConfidenceWords > 0" class="mt-4 bg-orange-100 rounded-lg p-3 border border-orange-200">
                                                    <div class="flex items-start">
                                                        <svg class="w-5 h-5 mr-2 text-orange-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.734 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                                        </svg>
                                                        <div class="flex-1">
                                                            <div class="font-medium text-orange-800 mb-1">
                                                                {{ confidenceAnalysis.lowConfidenceWords }} section(s) may need review
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
                        Are you sure you want to restart the entire processing? This will overwrite all existing audio and transcript data for this segment using intelligent detection for optimal settings.
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

        <!-- Abort Processing Confirmation Modal -->
        <Modal :show="showAbortProcessingModal" @close="showAbortProcessingModal = false" max-width="md">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </div>
                </div>
                
                <div class="text-center">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Abort Processing</h3>
                    <p class="text-sm text-gray-500 mb-6">
                        Are you sure you want to abort the current processing? This will stop all processing jobs and reset the segment to ready state. Any partial progress will be lost.
                    </p>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <SecondaryButton @click="showAbortProcessingModal = false">
                        Cancel
                    </SecondaryButton>
                    <DangerButton @click="abortProcessing">
                        Abort Processing
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