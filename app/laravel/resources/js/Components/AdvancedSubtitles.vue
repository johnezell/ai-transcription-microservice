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

    <!-- Subtitles Controls -->
    <div class="subtitles-controls flex flex-wrap items-center gap-3 mt-2">
      <button 
        @click="showSubtitles = !showSubtitles" 
        class="flex items-center text-sm px-2 py-1 rounded-md transition"
        :class="showSubtitles ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700'"
      >
        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
        </svg>
        {{ showSubtitles ? 'Subtitles On' : 'Subtitles Off' }}
      </button>

      <!-- Reload transcription button -->
      <button 
        @click="reloadTranscript" 
        class="flex items-center text-sm px-2 py-1 rounded-md bg-green-500 text-white"
      >
        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
        </svg>
        Reload Transcript
      </button>

      <!-- Confidence threshold control -->
      <div class="flex items-center">
        <span class="text-sm text-gray-700 mr-2">Min. Confidence:</span>
        <input 
          type="range" 
          v-model="confidenceThreshold" 
          min="0" 
          max="1" 
          step="0.05"
          class="w-24"
        />
        <span class="text-sm text-gray-700 ml-1">{{ (confidenceThreshold * 100).toFixed(0) }}%</span>
      </div>

      <!-- Legend toggle button -->
      <button 
        @click="showLegend = !showLegend" 
        class="flex items-center text-sm px-2 py-1 rounded-md transition bg-gray-200 text-gray-700 hover:bg-gray-300"
      >
        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        Legend
      </button>
    </div>
    
    <!-- Confidence Legend -->
    <div v-if="showLegend" class="mt-3 p-3 bg-gray-100 dark:bg-gray-800 rounded-md text-sm border border-gray-200 dark:border-gray-700">
      <h4 class="font-medium mb-2">Confidence Level Legend</h4>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="flex items-center bg-black bg-opacity-80 p-2 rounded">
          <span class="word-inline mx-1 px-2 py-0.5 rounded high-confidence">High Confidence</span>
          <span class="text-xs text-gray-300 ml-2">&gt; 80%</span>
        </div>
        <div class="flex items-center bg-black bg-opacity-80 p-2 rounded">
          <span class="word-inline mx-1 px-2 py-0.5 rounded medium-confidence">Medium Confidence</span>
          <span class="text-xs text-gray-300 ml-2">{{ (confidenceThreshold * 100).toFixed(0) }}% - 80%</span>
        </div>
        <div class="flex items-center bg-black bg-opacity-80 p-2 rounded">
          <span class="word-inline mx-1 px-2 py-0.5 rounded low-confidence">Low Confidence</span>
          <span class="text-xs text-gray-300 ml-2">&lt; {{ (confidenceThreshold * 100).toFixed(0) }}%</span>
        </div>
      </div>
      <div class="mt-2 text-xs text-gray-600 dark:text-gray-400">
        <p>The Min. Confidence slider adjusts the threshold for highlighting words with uncertain transcription. 
        Edited words are automatically set to 100% confidence.</p>
      </div>
    </div>
    
    <!-- Always visible subtitles for testing -->
    <div 
      class="inline-subtitles mt-4 p-3 bg-black bg-opacity-80 text-white rounded-md text-center"
      v-if="showSubtitles && currentSegment"
    >
      <span 
        v-for="(word, index) in currentSegment.words" 
        :key="index" 
        class="word-inline mx-0.5 px-1 py-0.5 rounded cursor-pointer"
        :class="getWordClasses(word, index)"
        @click="openWordEditor(word, index)"
      >{{ word.word }}</span>
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
                @click="editingWord.start = videoRef ? parseFloat(videoRef.currentTime.toFixed(2)) : 0" 
                class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded-md text-sm whitespace-nowrap"
                title="Use current video time"
                :disabled="!allowTimeEditing"
                :class="{'opacity-50 cursor-not-allowed': !allowTimeEditing}"
              >
                Use Current
              </button>
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Current time: {{ videoRef ? videoRef.currentTime.toFixed(2) : '0.00' }}s
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
                @click="editingWord.end = videoRef ? parseFloat(videoRef.currentTime.toFixed(2)) : 0" 
                class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded-md text-sm whitespace-nowrap"
                title="Use current video time"
                :disabled="!allowTimeEditing"
                :class="{'opacity-50 cursor-not-allowed': !allowTimeEditing}"
              >
                Use Current
              </button>
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Current time: {{ videoRef ? videoRef.currentTime.toFixed(2) : '0.00' }}s
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
      required: true
    },
    transcriptJsonUrl: {
      type: String,
      default: null
    }
  },
  
  data() {
    return {
      transcriptData: null,
      segments: [],
      currentSegmentIndex: -1,
      activeWordIndices: [], // Track all words active at current time
      showSubtitles: true,
      confidenceThreshold: 0.5, // Default confidence threshold at 50%
      fontSize: 18, // Default subtitle font size
      updateInterval: null,
      lastTime: 0,
      loadError: null,
      setupDone: false, // Flag to prevent multiple setups
      
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
      showLegend: false,
      originalConfidence: 0
    };
  },
  
  computed: {
    currentSegment() {
      if (this.currentSegmentIndex === -1 || !this.segments[this.currentSegmentIndex]) {
        return null;
      }
      return this.segments[this.currentSegmentIndex];
    }
  },
  
  watch: {
    transcriptJsonUrl: {
      immediate: true,
      handler(url) {
        if (url) {
          this.fetchTranscriptData();
        }
      }
    },
    videoRef: {
      immediate: true,
      handler(newRef, oldRef) {
        if (newRef && newRef !== oldRef && !this.setupDone) {
          this.setupVideoListeners();
        }
      }
    }
  },
  
  mounted() {
    if (!this.setupDone && this.videoRef) {
      this.setupVideoListeners();
    }
    
    // If transcript URL is available, immediately try to load it
    if (this.transcriptJsonUrl) {
      this.fetchTranscriptData();
    }
  },
  
  updated() {
    // Re-setup video listeners if the video reference changes
    if (!this.setupDone && this.videoRef) {
      this.setupVideoListeners();
    }
    
    // Re-fetch transcript data if the URL changes
    if (this.transcriptJsonUrl && !this.transcriptData) {
      this.fetchTranscriptData();
    }
  },
  
  beforeUnmount() {
    this.cleanupVideoListeners();
  },
  
  methods: {
    reloadTranscript() {
      this.fetchTranscriptData();
    },
    
    setupVideoListeners() {
      if (!this.videoRef) {
        return;
      }
      
      if (this.setupDone) {
        return;
      }
      
      // Remove any existing listeners first
      this.cleanupVideoListeners();
      
      // Set up more frequent updates for smoother subtitles (every 100ms)
      this.updateInterval = setInterval(() => {
        this.checkCurrentPosition();
      }, 100);
      
      // Also listen to regular timeupdate events as backup
      this.videoRef.addEventListener('timeupdate', this.updateCurrentPosition);
      
      // Check immediately for current state
      this.updateCurrentPosition();
      
      this.setupDone = true;
    },
    
    cleanupVideoListeners() {
      if (this.videoRef) {
        this.videoRef.removeEventListener('timeupdate', this.updateCurrentPosition);
      }
      
      if (this.updateInterval) {
        clearInterval(this.updateInterval);
        this.updateInterval = null;
      }
      
      this.setupDone = false;
    },
    
    async fetchTranscriptData() {
      if (!this.transcriptJsonUrl) {
        return;
      }
      
      try {
        // Get just the path part of the URL to avoid CORS issues
        let urlToFetch = this.transcriptJsonUrl;
        
        // If it's an absolute URL, convert to a relative path
        if (urlToFetch.startsWith('http')) {
          try {
            const url = new URL(urlToFetch);
            const pathMatch = url.pathname.match(/\/storage\/(.+)/);
            if (pathMatch && pathMatch[1]) {
              urlToFetch = `/storage/${pathMatch[1]}`;
            }
          } catch (e) {
            // Silently handle URL parsing errors
          }
        }
        
        const response = await fetch(urlToFetch);
        
        if (!response.ok) {
          throw new Error(`Failed to fetch transcript: ${response.status} ${response.statusText}`);
        }
        
        this.transcriptData = await response.json();
        
        this.processTranscriptData();
        this.loadError = null;
      } catch (error) {
        this.loadError = error.message;
      }
    },
    
    processTranscriptData() {
      if (!this.transcriptData || !this.transcriptData.segments) {
        return;
      }
      
      // Process segments and add confidence-related properties
      this.segments = this.transcriptData.segments.map(segment => {
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
    
    checkCurrentPosition() {
      if (this.videoRef && typeof this.videoRef.currentTime === 'number') {
        this.updateCurrentPosition();
      }
    },
    
    updateCurrentPosition() {
      if (!this.videoRef || this.segments.length === 0) {
        return;
      }
      
      if (typeof this.videoRef.currentTime !== 'number') {
        return;
      }
      
      const currentTime = this.videoRef.currentTime;
      let newSegmentIndex = -1;
      
      // Find the current segment
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
      
      // Now find active words within the current segment
      this.updateActiveWords(currentTime);
    },
    
    updateActiveWords(currentTime) {
      this.activeWordIndices = [];
      
      if (!this.currentSegment || !this.currentSegment.words) {
        return;
      }
      
      // Find all words that should be active at the current time
      for (let i = 0; i < this.currentSegment.words.length; i++) {
        const word = this.currentSegment.words[i];
        
        // Make sure we're using floating point numbers for comparison
        const wordStart = parseFloat(word.start);
        const wordEnd = parseFloat(word.end);
        
        if (currentTime >= wordStart && currentTime <= wordEnd) {
          this.activeWordIndices.push(i);
        }
      }
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
      if (word.probability < this.confidenceThreshold) {
        classes.push('low-confidence');
      } else if (word.probability < 0.8) {
        classes.push('medium-confidence');
      } else {
        classes.push('high-confidence');
      }
      
      return classes;
    },
    
    openWordEditor(word, index) {
      // Pause the video when opening editor
      if (this.videoRef && !this.videoRef.paused) {
        this.videoRef.pause();
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
    
    testTranscriptUrl() {
      // Test the transcript URL directly
      if (!this.transcriptJsonUrl) {
        this.loadError = "No transcript URL provided";
        return;
      }
      
      this.loadError = "Testing URL...";
      
      // Get just the path part of the URL to avoid CORS issues
      let urlToFetch = this.transcriptJsonUrl;
      
      // If it's an absolute URL, convert to a relative path
      if (urlToFetch.startsWith('http')) {
        try {
          const url = new URL(urlToFetch);
          const pathMatch = url.pathname.match(/\/storage\/(.+)/);
          if (pathMatch && pathMatch[1]) {
            urlToFetch = `/storage/${pathMatch[1]}`;
          }
        } catch (e) {
          // Handle URL parsing errors
        }
      }
      
      fetch(urlToFetch)
        .then(response => {
          if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          this.loadError = `Success! Found ${data.segments?.length || 0} segments`;
          // Try to process this data
          this.transcriptData = data;
          this.processTranscriptData();
        })
        .catch(error => {
          this.loadError = `Error: ${error.message}`;
        });
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