<template>
  <div class="advanced-subtitles mt-4">
    <!-- Debug panel - hidden in production -->
    <div class="mt-2 p-2 bg-gray-100 text-xs rounded" v-if="false">
      <div><strong>Video Ref:</strong> {{ videoRef ? 'Available' : 'Not available' }}</div>
      <div><strong>Current Time:</strong> {{ videoRef ? videoRef.currentTime?.toFixed(2) : 'No video' }}s</div>
      <div>
        <strong>Transcript URL:</strong> 
        <span v-if="transcriptJsonUrl">
          {{ transcriptJsonUrl }} 
          <a :href="transcriptJsonUrl" target="_blank" class="text-blue-600 hover:underline">(View)</a>
          <button @click="testTranscriptUrl" class="ml-2 px-1 py-0.5 bg-blue-500 text-white text-xs rounded">Test URL</button>
        </span>
        <span v-else>None</span>
      </div>
      <div v-if="loadError" class="text-red-600">Error: {{ loadError }}</div>
      <div><strong>Segments:</strong> {{ segments.length }}</div>
      <div><strong>Current Segment:</strong> {{ currentSegmentIndex }}</div>
      <div><strong>Current Segment Words:</strong> {{ currentSegment ? currentSegment.words.length : 0 }}</div>
      <div><strong>Active Word Indices:</strong> {{ activeWordIndices.join(', ') || 'None' }}</div>
    </div>

    <!-- Controls removed - not functional for this use case -->
    
    <!-- Interactive subtitles - only show content during video playback -->
    <div 
      class="inline-subtitles mt-4 p-3 bg-black bg-opacity-80 text-white rounded-md text-center"
      v-if="transcriptData && segments.length > 0"
    >
      <!-- Show synchronized transcript only when video is playing -->
      <div v-if="videoPlaying && currentMediaElement">
        <!-- Show current segment words if we have an active segment -->
        <div v-if="currentSegment && currentSegment.words && currentSegment.words.length > 0">
          <div class="text-xs text-gray-300 mb-2 flex items-center justify-center">
            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
              <path d="M2 6a2 2 0 012-2h6l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
            </svg>
            {{ currentMediaElement.currentTime.toFixed(2) }}s
          </div>
          <span 
            v-for="(word, index) in currentSegment.words" 
            :key="index" 
            class="word-inline mx-0.5 px-1 py-0.5 rounded cursor-pointer"
            :class="getWordClasses(word, index)"
            @click="openWordEditor(word, index)"
          >{{ word.word }}</span>
        </div>
        
        <!-- Show message when video is playing but no current segment -->
        <div v-else class="text-xs text-gray-400">
          <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          {{ currentMediaElement.currentTime.toFixed(2) }}s - No active transcript segment
        </div>
      </div>
      
      <!-- Show ready message when video is not playing -->
      <div v-else class="text-sm text-gray-300">
        <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h8m-5-3a3 3 0 11-6 0 3 3 0 016 0z M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        Interactive transcript ready - click play to see word-by-word highlighting
      </div>
    </div>
    
    <!-- Loading message when transcript is being fetched -->
    <div 
      v-else-if="isLoading || (!transcriptData && (transcriptJsonUrl || transcriptJsonApiUrl))"
      class="inline-subtitles mt-4 p-3 bg-gray-100 text-gray-600 rounded-md text-center"
    >
      <svg class="inline-block w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
      </svg>
      Loading transcript...
    </div>
    
    <!-- Error message if transcript failed to load -->
    <div 
      v-else-if="loadError"
      class="inline-subtitles mt-4 p-3 bg-red-100 text-red-600 rounded-md text-center"
    >
      <svg class="inline-block w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
      </svg>
      Failed to load transcript: {{ loadError }}
    </div>
    
    <!-- Word Editor Modal -->
    <div 
      v-if="showWordEditor" 
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
      @click.self="closeWordEditor" 
      @keydown.esc="closeWordEditor" 
      tabindex="0"
    >
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md p-6 m-4">
        <div class="flex justify-between items-center mb-4 border-b pb-3 dark:border-gray-700">
          <h3 class="text-xl font-bold text-gray-900 dark:text-white">Edit Word</h3>
          <button @click="closeWordEditor" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>
        
        <div class="space-y-4">
          <!-- Word text input -->
          <div>
            <label for="word-text" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Word</label>
            <input 
              type="text" 
              id="word-text" 
              v-model="editingWord.word" 
              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
              autocomplete="off"
              ref="wordInput"
            />
          </div>
          
          <!-- Start time input -->
          <div>
            <label for="start-time" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Start Time (seconds)
            </label>
            <div class="flex gap-2">
              <input 
                type="number"
                step="0.01" 
                id="start-time" 
                v-model="editingWord.start" 
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                :disabled="!allowTimeEditing"
                :class="{'bg-gray-100 dark:bg-gray-800': !allowTimeEditing}"
              />
              <button 
                @click="editingWord.start = currentMediaElement ? parseFloat(currentMediaElement.currentTime.toFixed(2)) : 0" 
                class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded-md text-sm whitespace-nowrap"
                title="Use current media time"
                :disabled="!allowTimeEditing"
                :class="{'opacity-50 cursor-not-allowed': !allowTimeEditing}"
              >
                Use Current
              </button>
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Current time: {{ currentMediaElement ? currentMediaElement.currentTime.toFixed(2) : '0.00' }}s
            </div>
          </div>
          
          <!-- End time input -->
          <div>
            <label for="end-time" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              End Time (seconds)
            </label>
            <div class="flex gap-2">
              <input 
                type="number"
                step="0.01" 
                id="end-time" 
                v-model="editingWord.end" 
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                :disabled="!allowTimeEditing"
                :class="{'bg-gray-100 dark:bg-gray-800': !allowTimeEditing}"
              />
              <button 
                @click="editingWord.end = currentMediaElement ? parseFloat(currentMediaElement.currentTime.toFixed(2)) : 0" 
                class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded-md text-sm whitespace-nowrap"
                title="Use current media time"
                :disabled="!allowTimeEditing"
                :class="{'opacity-50 cursor-not-allowed': !allowTimeEditing}"
              >
                Use Current
              </button>
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Current time: {{ currentMediaElement ? currentMediaElement.currentTime.toFixed(2) : '0.00' }}s
            </div>
          </div>
          
          <!-- Timing edit confirmation toggle -->
          <div class="mt-2 pt-3 border-t border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
              <input 
                type="checkbox" 
                id="allow-time-editing" 
                v-model="allowTimeEditing"
                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
              />
              <label for="allow-time-editing" class="ml-2 block text-sm text-gray-900 dark:text-gray-300">
                Enable timing adjustments
              </label>
            </div>
            <div v-if="allowTimeEditing" class="mt-2 text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900 dark:bg-opacity-20 p-2 rounded">
              <strong>Warning:</strong> Changing word timings may affect video synchronization. Only modify if you're sure about the changes.
            </div>
          </div>
          
          <!-- Preview -->
          <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded-md mt-3">
            <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Preview:</div>
            <div class="flex items-center gap-3 bg-black bg-opacity-80 p-2 rounded">
              <span 
                class="word-inline px-3 py-1 rounded high-confidence"
              >{{ editingWord.word }}</span>
              <span class="text-xs text-gray-300">
                {{ editingWord.start.toFixed(2) }}s - {{ editingWord.end.toFixed(2) }}s
              </span>
            </div>
          </div>
          
          <!-- Original confidence score display -->
          <div class="mt-3 bg-gray-100 dark:bg-gray-700 p-3 rounded-md">
            <div class="flex items-center justify-between">
              <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Original Confidence:</span>
              <div class="flex items-center">
                <div class="w-24 h-3 bg-gray-300 rounded-full overflow-hidden">
                  <div 
                    class="h-full" 
                    :style="{
                      width: `${(originalConfidence * 100).toFixed(0)}%`,
                      backgroundColor: getConfidenceColor(originalConfidence)
                    }"
                  ></div>
                </div>
                <span class="ml-2 text-sm font-semibold">{{ (originalConfidence * 100).toFixed(0) }}%</span>
              </div>
            </div>
          </div>
          
          <!-- Note about edited words -->
          <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            <svg class="inline-block w-4 h-4 mr-1 -mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Note: Edited words automatically get 100% confidence.
          </div>
          
          <!-- Action buttons -->
          <div class="flex justify-end space-x-3 mt-6">
            <button 
              @click="closeWordEditor" 
              class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-md hover:bg-gray-300 dark:hover:bg-gray-600 transition"
            >
              Cancel
            </button>
            <button 
              @click="saveWordEdit" 
              class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition"
            >
              Save Changes
            </button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Display transcript data structure -->
    <div v-if="currentSegment && false" class="mt-4 p-3 bg-gray-100 rounded-md text-xs">
      <div class="font-bold">Current Segment Data:</div>
      <pre class="mt-1 overflow-auto max-h-32">{{ JSON.stringify(currentSegment, null, 2) }}</pre>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    videoRef: {
      type: Object,
      default: null
    },
    transcriptJsonUrl: {
      type: String,
      default: ''
    },
    transcriptJsonApiUrl: {
      type: String,
      default: ''
    },
    // Add prop to receive transcript data from parent
    transcriptData: {
      type: Object,
      default: null
    },
    // Add prop to show loading state
    isLoading: {
      type: Boolean,
      default: false
    }
  },
  
  data() {
    return {
      segments: [],
      currentSegmentIndex: -1,
      activeWordIndices: [], // Track all words active at current time

      fontSize: 18, // Default subtitle font size
      updateInterval: null,
      highFrequencyInterval: null, // High-frequency timer for smooth word highlighting
      lastTime: 0,
      loadError: null,
      setupDone: false, // Flag to prevent multiple setups
      
      // Video player tracking
      videoPlaying: false,
      
      // Word editor state
      showWordEditor: false,
      editingWord: {
        word: '',
        start: 0,
        end: 0
      },
      editingWordIndex: -1,
      editingSegmentIndex: -1,
      allowTimeEditing: false,
      originalConfidence: 0
    };
  },
  
  computed: {
    currentSegment() {
      if (this.currentSegmentIndex === -1 || !this.segments[this.currentSegmentIndex]) {
        return null;
      }
      return this.segments[this.currentSegmentIndex];
    },
    
    // Use video player for sync
    currentMediaElement() {
      return this.videoRef;
    }
  },
  
  watch: {
    // Watch for transcript data changes from parent
    transcriptData: {
      immediate: true,
      handler(newData) {
        if (newData) {
          this.processTranscriptData(newData);
        }
      }
    },
    videoRef: {
      immediate: true,
      handler(newRef, oldRef) {
        if (newRef !== oldRef) {
          this.setupVideoListeners();
        }
      }
    }
  },
  
  mounted() {
    // Set up video listeners
    this.setupVideoListeners();
    
    // Process transcript data if available from props
    if (this.transcriptData) {
      this.processTranscriptData(this.transcriptData);
    }
  },
  
  updated() {
    // Re-setup video listeners if the video reference changes
    if (!this.setupDone && this.videoRef) {
      this.setupVideoListeners();
    }
    
    // Process transcript data if it's now available from props
    if (this.transcriptData && this.segments.length === 0) {
      this.processTranscriptData(this.transcriptData);
    }
  },
  
  beforeUnmount() {
    this.cleanupVideoListeners();
    this.stopHighFrequencyUpdates();
  },
  
  methods: {
    
    setupVideoListeners() {
      // Clean up existing listeners first
      this.cleanupVideoListeners();
      
      // Set up listeners for video element
      if (this.videoRef) {
        this.videoRef.addEventListener('play', () => {
          this.videoPlaying = true;
          this.startHighFrequencyUpdates();
        });
        
        this.videoRef.addEventListener('pause', () => {
          this.videoPlaying = false;
          this.stopHighFrequencyUpdates();
          // Update position immediately when paused to show current scrubhead position
          this.updateCurrentPosition();
        });
        
        this.videoRef.addEventListener('ended', () => {
          this.videoPlaying = false;
          this.stopHighFrequencyUpdates();
          // Update position when ended
          this.updateCurrentPosition();
        });
        
        // Update position during playback - native timeupdate is our source of truth
        this.videoRef.addEventListener('timeupdate', () => {
          this.updateCurrentPosition();
        });
        
        // Update position immediately when user scrubs/seeks
        this.videoRef.addEventListener('seeked', () => {
          this.updateCurrentPosition();
        });
      }
      
      // Check immediately for current state
      this.updateCurrentPosition();
      this.setupDone = true;
    },
    
        cleanupVideoListeners() {
      // Stop high-frequency updates
      this.stopHighFrequencyUpdates();
      
      // Note: Arrow function event listeners cannot be removed with removeEventListener
      // They will be cleaned up automatically when the component unmounts
      // This is acceptable as the media elements are tied to component lifecycle
      
      this.setupDone = false;
    },
    
    startHighFrequencyUpdates() {
      // Stop any existing high-frequency timer
      this.stopHighFrequencyUpdates();
      
      // Start high-frequency position updates for smooth word highlighting
      // Check every 16ms (60fps) for very smooth highlighting
      this.highFrequencyInterval = setInterval(() => {
        if (this.videoRef && !this.videoRef.paused && !this.videoRef.ended) {
          this.updateActiveWords(this.videoRef.currentTime);
        }
      }, 16); // 60fps for ultra-smooth highlighting
    },
    
    stopHighFrequencyUpdates() {
      if (this.highFrequencyInterval) {
        clearInterval(this.highFrequencyInterval);
        this.highFrequencyInterval = null;
      }
    },
    
        processTranscriptData(data = null) {
      const transcriptData = data || this.transcriptData;
      
      if (!transcriptData || !transcriptData.segments) {
        return;
      }
      
      // Process segments and add confidence-related properties
      this.segments = transcriptData.segments.map(segment => {
        // Check if the segment has words property and its format
        let words = [];
        
        if (Array.isArray(segment.words)) {
          // Standard format with words array
          words = segment.words.map(word => {
            return {
              word: word.word || word.text || '',
              start: parseFloat(word.start) || 0,
              end: parseFloat(word.end) || 0,
              probability: word.probability !== undefined ? word.probability : 1.0
            };
          });
        } else {
          // No word-level data, create a single word for the entire segment
          words = [{
            word: segment.text || '',
            start: parseFloat(segment.start) || 0,
            end: parseFloat(segment.end) || 0,
            probability: 1.0
          }];
        }
        
        return {
          start: parseFloat(segment.start) || 0,
          end: parseFloat(segment.end) || 0,
          text: segment.text || '',
          words: words
        };
      });
      
      // Force an update of the current position
      this.updateCurrentPosition();
    },
    

    
        updateCurrentPosition() {
      const mediaElement = this.currentMediaElement;
      if (!mediaElement || this.segments.length === 0) {
        return;
      }
      
      if (typeof mediaElement.currentTime !== 'number') {
        return;
      }
      
      const currentTime = mediaElement.currentTime;
      
      // Find current segment (this happens regardless of play state)
      let newSegmentIndex = -1;
      
      // Find the current segment using direct video time (no offset needed)
      for (let i = 0; i < this.segments.length; i++) {
        const segment = this.segments[i];
        if (currentTime >= segment.start && currentTime <= segment.end) {
          newSegmentIndex = i;
          break;
        }
      }
      
      // If no segment found, try to find the closest one
      if (newSegmentIndex === -1 && this.segments.length > 0) {
        let closestIndex = 0;
        let closestDiff = Infinity;
        
        for (let i = 0; i < this.segments.length; i++) {
          const segment = this.segments[i];
          const startDiff = Math.abs(segment.start - currentTime);
          const endDiff = Math.abs(segment.end - currentTime);
          const minDiff = Math.min(startDiff, endDiff);
          
          if (minDiff < closestDiff) {
            closestDiff = minDiff;
            closestIndex = i;
          }
        }
        
        // Only use closest if it's within 2 seconds
        if (closestDiff < 2) {
          newSegmentIndex = closestIndex;
        }
      }
      
      // Update the current segment index
      if (newSegmentIndex !== this.currentSegmentIndex) {
        this.currentSegmentIndex = newSegmentIndex;
      }
      
      // Handle word highlighting based on play state
      if (mediaElement.paused || mediaElement.ended) {
        // Clear active words when not playing (or update once for current position)
        this.updateActiveWords(currentTime);
      }
      // When playing, word highlighting is handled by high-frequency timer
    },
    
    updateActiveWords(currentTime) {
      this.activeWordIndices = [];
      
      if (!this.currentSegment || !this.currentSegment.words) {
        return;
      }
      
      // STRICT SCRUBHEAD-BASED HIGHLIGHTING - Only highlight words currently being spoken
      // NO BUFFER - Use exact WhisperX timestamps for precise synchronization
      
      for (let i = 0; i < this.currentSegment.words.length; i++) {
        const word = this.currentSegment.words[i];
        
        // Use exact timestamps without any buffer - only highlight during actual word duration
        const wordStart = parseFloat(word.start);
        const wordEnd = parseFloat(word.end);
        
        // Only highlight if scrubhead is exactly within the word's time range
        if (currentTime >= wordStart && currentTime <= wordEnd) {
          this.activeWordIndices.push(i);
        }
      }
      
      // NO FALLBACK - Only highlight words when scrubhead is exactly in their time range
      // This ensures highlighting is strictly based on playback position, not proximity
    },
    
    getWordClasses(word, index) {
      // Generate classes based on confidence and if word is active
      const classes = [];
      
      // Check if this word is currently active (being spoken)
      if (this.activeWordIndices.includes(index)) {
        classes.push('active-word');
      }
      
      if (!word.probability && word.probability !== 0) {
        return classes; // No probability data
      }
      
      // Apply different styling based on confidence thresholds
      if (word.probability < 0.5) {
        classes.push('low-confidence');
      } else if (word.probability < 0.8) {
        classes.push('medium-confidence');
      } else {
        classes.push('high-confidence');
      }
      
      return classes;
    },
    
    openWordEditor(word, index) {
      // Pause the active media when opening editor
      const mediaElement = this.currentMediaElement;
      if (mediaElement && !mediaElement.paused) {
        mediaElement.pause();
      }
      
      // Make a deep copy to avoid direct mutation
      this.editingWord = { 
        word: word.word,
        start: parseFloat(word.start),
        end: parseFloat(word.end)
      };
      
      // Store original confidence score for display
      this.originalConfidence = parseFloat(word.probability || 0);
      
      this.editingWordIndex = index;
      this.editingSegmentIndex = this.currentSegmentIndex;
      this.allowTimeEditing = false; // Reset the toggle for time editing
      this.showWordEditor = true;
      
      // Focus on the word input field after modal is open
      this.$nextTick(() => {
        if (this.$refs.wordInput) {
          this.$refs.wordInput.focus();
          this.$refs.wordInput.select();
        }
      });
    },
    
    saveWordEdit() {
      // Only apply changes if we have valid indices
      if (this.editingSegmentIndex >= 0 && this.editingWordIndex >= 0) {
        const segment = this.segments[this.editingSegmentIndex];
        if (segment && segment.words && segment.words[this.editingWordIndex]) {
          // Update the word data - always set confidence to 1.0 (100%) for edited words
          segment.words[this.editingWordIndex] = { 
            word: this.editingWord.word,
            start: parseFloat(this.editingWord.start),
            end: parseFloat(this.editingWord.end),
            probability: 1.0 // Always set to 100% confidence for edited words
          };
          
          // TODO: Here we would call the API to save the changes
          console.log('Word edited with 100% confidence:', this.editingWord.word);
        }
      }
      
      // Close the editor and reset the editing state
      this.closeWordEditor();
    },
    

    
    closeWordEditor() {
      this.showWordEditor = false;
      this.allowTimeEditing = false;
    },
    
    getConfidenceColor(confidence) {
      if (confidence < 0.5) {
        return '#ff0000'; // Red for low confidence
      } else if (confidence < 0.8) {
        return '#ffff00'; // Yellow for medium confidence
      } else {
        return '#00ff00'; // Green for high confidence
      }
    }
  }
};
</script>

<style scoped>
.word-inline {
  transition: all 0.2s ease;
  opacity: 0.7;
}

.word-inline.active-word {
  background-color: rgba(59, 130, 246, 0.7); /* Blue background */
  opacity: 1;
  font-weight: bold;
}

.word-inline.low-confidence {
  color: #ff9999; /* Light red for low confidence */
  text-decoration: underline dotted;
}

.word-inline.medium-confidence {
  color: #ffffcc; /* Light yellow for medium confidence */
}

.word-inline.high-confidence {
  color: white;
}

/* Make active words more visible regardless of confidence */
.word-inline.active-word.low-confidence {
  color: #ffcccc;
  background-color: rgba(239, 68, 68, 0.5); /* Red background */
}

.word-inline.active-word.medium-confidence {
  color: #ffffdd;
  background-color: rgba(234, 179, 8, 0.5); /* Yellow background */
}

.word-inline.active-word.high-confidence {
  color: white;
  background-color: rgba(59, 130, 246, 0.7); /* Blue background */
}

/* Form input styles */
input[type="text"],
input[type="number"] {
  @apply w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm;
}

input[type="range"] {
  @apply w-full cursor-pointer;
}

button {
  @apply transition;
}
</style> 