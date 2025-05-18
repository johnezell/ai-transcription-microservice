<template>
  <div class="enhanced-transcript-viewer">
    <div class="transcript-toolbar flex flex-wrap items-center justify-between gap-3 mb-4">
      <div class="flex items-center gap-2">
        <h3 class="text-lg font-medium flex items-center">
          <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
          </svg>
          Enhanced Transcript
        </h3>
      </div>
      
      <div class="flex flex-wrap items-center gap-2">
        <!-- View Mode Toggle -->
        <div class="flex items-center bg-gray-100 p-1 rounded-md">
          <button 
            @click="viewMode = 'segments'" 
            :class="[
              'px-3 py-1 text-sm rounded-md transition-colors', 
              viewMode === 'segments' ? 'bg-blue-600 text-white' : 'hover:bg-gray-200'
            ]"
          >
            Segments
          </button>
          <button 
            @click="viewMode = 'continuous'" 
            :class="[
              'px-3 py-1 text-sm rounded-md transition-colors', 
              viewMode === 'continuous' ? 'bg-blue-600 text-white' : 'hover:bg-gray-200'
            ]"
          >
            Continuous
          </button>
        </div>
        
        <!-- Auto-Scroll Toggle Button - REMOVED -->
        
        <!-- Search Box -->
        <div class="relative flex items-center">
          <input 
            v-model="searchQuery" 
            type="text" 
            placeholder="Search transcript..." 
            class="border border-gray-300 rounded-md py-1 pl-9 pr-3 text-sm focus:ring-blue-500 focus:border-blue-500"
          />
          <svg class="w-4 h-4 text-gray-400 absolute left-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
          </svg>
          <button 
            v-if="searchQuery" 
            @click="searchQuery = ''" 
            class="absolute right-2 text-gray-400 hover:text-gray-600"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>
      </div>
    </div>
    
    <!-- Loading State -->
    <div v-if="loading" class="bg-gray-50 rounded-lg p-6 shadow-sm border border-gray-200">
      <div class="flex justify-center items-center h-32">
        <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-indigo-600"></div>
      </div>
    </div>
    
    <!-- Error State -->
    <div v-else-if="error" class="bg-gray-50 rounded-lg p-6 shadow-sm border border-red-200">
      <div class="text-center text-red-600">
        <svg class="w-10 h-10 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <p class="text-lg font-medium">Error loading transcript</p>
        <p class="text-sm mt-1">{{ error }}</p>
        <button @click="reload" class="mt-3 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
          Try Again
        </button>
      </div>
    </div>
    
    <!-- Empty State -->
    <div v-else-if="!hasTranscript" class="bg-gray-50 rounded-lg p-6 shadow-sm border border-gray-200">
      <div class="text-center text-gray-500">
        <svg class="w-10 h-10 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        <p class="text-lg font-medium">No transcript available</p>
        <p class="text-sm mt-1">Transcript data could not be found for this video.</p>
      </div>
    </div>
    
    <!-- Transcript Content -->
    <div 
      v-else 
      ref="transcriptContainer" 
      class="transcript-content bg-gray-50 rounded-lg p-4 max-h-[500px] overflow-y-auto shadow-sm border border-gray-200"
    >
      <!-- Segments View Mode -->
      <div v-if="viewMode === 'segments'" class="space-y-3">
        <div 
          v-for="(segment, segmentIndex) in displayedSegments" 
          :key="segmentIndex"
          :ref="el => { if (el) segmentRefs[segmentIndex] = el }"
          class="segment-item p-3 rounded transition-colors duration-150"
          :class="{
            'bg-blue-50 border border-blue-100': currentSegmentIndex === segmentIndex,
            'bg-blue-50/50 border border-blue-50': isActiveSegment(segmentIndex) && currentSegmentIndex !== segmentIndex,
            'hover:bg-gray-100 border border-transparent': currentSegmentIndex !== segmentIndex && !isActiveSegment(segmentIndex)
          }"
        >
          <!-- Segment header with timestamp -->
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center">
              <span class="text-xs bg-gray-200 text-gray-700 rounded px-1.5 py-0.5">
                {{ formatTime(segment.start) }}
              </span>
              <button 
                @click="seekToTime(segment.start)" 
                class="ml-2 text-xs text-blue-600 hover:text-blue-800 hover:underline flex items-center"
              >
                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Play
              </button>
            </div>
            <span v-if="segment.confidence" class="text-xs px-1.5 py-0.5 rounded" :class="getConfidenceClass(segment.confidence)">
              {{ (segment.confidence * 100).toFixed(0) }}% confidence
            </span>
          </div>
          
          <!-- Segment content with word-level highlighting -->
          <div class="text-base leading-relaxed">
            <template v-if="segment.words && segment.words.length > 0">
              <span 
                v-for="(word, wordIndex) in segment.words" 
                :key="`${segmentIndex}-${wordIndex}`"
                @click="seekToTime(word.start)"
                @click.ctrl="openWordEditor(segment, word, segmentIndex, wordIndex)"
                @click.meta="openWordEditor(segment, word, segmentIndex, wordIndex)"
                class="word-item px-0.5 cursor-pointer transition-colors duration-100"
                :class="[
                  getWordConfidenceClass(word.probability),
                  { 'bg-blue-200 rounded': isCurrentWord(word) },
                  { 'highlighted bg-yellow-100 rounded': isHighlighted(word.word) },
                  { 'hover:bg-gray-200 hover:rounded': !isCurrentWord(word) && !isHighlighted(word.word) }
                ]"
                :title="'Click to play, Ctrl/Cmd+click to edit'"
              >
                {{ word.word }}
              </span>
            </template>
            <template v-else>
              <p>{{ segment.text }}</p>
            </template>
          </div>
        </div>
      </div>
      
      <!-- Continuous View Mode -->
      <div v-else class="continuous-transcript p-2">
        <p class="text-base leading-relaxed">
          <template v-for="(segment, segmentIndex) in displayedSegments">
            <template v-if="segment.words && segment.words.length > 0">
              <span 
                v-for="(word, wordIndex) in segment.words" 
                :key="`${segmentIndex}-${wordIndex}`"
                @click="seekToTime(word.start)"
                @click.ctrl="openWordEditor(segment, word, segmentIndex, wordIndex)"
                @click.meta="openWordEditor(segment, word, segmentIndex, wordIndex)"
                class="word-item px-0.5 cursor-pointer transition-colors duration-100"
                :class="[
                  getWordConfidenceClass(word.probability),
                  { 'bg-blue-200 rounded': isCurrentWord(word) },
                  { 'highlighted bg-yellow-100 rounded': isHighlighted(word.word) },
                  { 'hover:bg-gray-200 hover:rounded': !isCurrentWord(word) && !isHighlighted(word.word) }
                ]"
                :title="'Click to play, Ctrl/Cmd+click to edit'"
              >
                {{ word.word }}
              </span>
            </template>
            <template v-else>
              {{ segment.text }}
            </template>
            <span v-if="segmentIndex < displayedSegments.length - 1" class="segment-divider mx-2">
              •
            </span>
          </template>
        </p>
      </div>
      
      <!-- No results message -->
      <div v-if="searchQuery && displayedSegments.length === 0" class="text-center py-8 text-gray-500">
        <svg class="w-10 h-10 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
        </svg>
        <p class="text-lg font-medium">No matching results</p>
        <p class="text-sm mt-1">No transcript segments match your search query.</p>
      </div>
    </div>

    <!-- Word Editor Modal -->
    <div 
      v-if="showWordEditor" 
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
      @click.self="closeWordEditor" 
    >
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 m-4">
        <div class="flex justify-between items-center mb-4 border-b pb-3">
          <h3 class="text-xl font-bold">Edit Word</h3>
          <button @click="closeWordEditor" class="text-gray-500 hover:text-gray-700">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>
        
        <div class="space-y-4">
          <!-- Word text input -->
          <div>
            <label for="word-text" class="block text-sm font-medium text-gray-700 mb-1">Word</label>
            <input 
              type="text" 
              id="word-text" 
              v-model="editingWord.word" 
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
              autocomplete="off"
              ref="wordInput"
            />
          </div>
          
          <!-- Start time input -->
          <div>
            <label for="start-time" class="block text-sm font-medium text-gray-700 mb-1">
              Start Time (seconds)
            </label>
            <div class="flex gap-2">
              <input 
                type="number"
                step="0.01" 
                id="start-time" 
                v-model="editingWord.start" 
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                :disabled="!allowTimeEditing"
                :class="{'bg-gray-100': !allowTimeEditing}"
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
            <div class="mt-1 text-xs text-gray-500">
              Current time: {{ videoRef ? videoRef.currentTime.toFixed(2) : '0.00' }}s
            </div>
          </div>
          
          <!-- End time input -->
          <div>
            <label for="end-time" class="block text-sm font-medium text-gray-700 mb-1">
              End Time (seconds)
            </label>
            <div class="flex gap-2">
              <input 
                type="number"
                step="0.01" 
                id="end-time" 
                v-model="editingWord.end" 
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                :disabled="!allowTimeEditing"
                :class="{'bg-gray-100': !allowTimeEditing}"
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
            <div class="mt-1 text-xs text-gray-500">
              Current time: {{ videoRef ? videoRef.currentTime.toFixed(2) : '0.00' }}s
            </div>
          </div>
          
          <!-- Timing edit confirmation toggle -->
          <div class="mt-2 pt-3 border-t border-gray-200">
            <div class="flex items-center">
              <input 
                type="checkbox" 
                id="allow-time-editing" 
                v-model="allowTimeEditing"
                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
              />
              <label for="allow-time-editing" class="ml-2 block text-sm text-gray-900">
                Enable timing adjustments
              </label>
            </div>
            <div v-if="allowTimeEditing" class="mt-2 text-sm text-red-600 bg-red-50 p-2 rounded">
              <strong>Warning:</strong> Changing word timings may affect video synchronization. Only modify if you're sure about the changes.
            </div>
          </div>
          
          <!-- Preview -->
          <div class="bg-gray-100 p-4 rounded-md mt-3">
            <div class="text-sm font-medium text-gray-700 mb-2">Preview:</div>
            <div class="flex items-center gap-3 bg-black bg-opacity-80 p-2 rounded">
              <span 
                class="word-item px-3 py-1 rounded high-confidence"
              >{{ editingWord.word }}</span>
              <span class="text-xs text-gray-300">
                {{ editingWord.start.toFixed(2) }}s - {{ editingWord.end.toFixed(2) }}s
              </span>
            </div>
          </div>
          
          <!-- Original confidence score display -->
          <div class="mt-3 bg-gray-100 p-3 rounded-md">
            <div class="flex items-center justify-between">
              <span class="text-sm font-medium text-gray-700">Original Confidence:</span>
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
          <div class="mt-2 text-xs text-gray-500">
            <svg class="inline-block w-4 h-4 mr-1 -mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Note: Edited words automatically get 100% confidence.
          </div>
          
          <!-- Action buttons -->
          <div class="flex justify-end space-x-3 mt-6">
            <button 
              @click="closeWordEditor" 
              class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition"
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
    },
    srtUrl: {
      type: String,
      default: null
    },
    transcriptText: {
      type: String,
      default: null
    },
    terminology: {
      type: Array,
      default: () => []
    },
    disableScroll: {
      type: Boolean,
      default: false
    }
  },
  
  data() {
    return {
      segments: [],
      loading: true,
      error: null,
      currentSegmentIndex: -1,
      currentWordIndex: -1,
      viewMode: 'segments', // 'segments' or 'continuous'
      autoScroll: false, // Auto-scroll permanently disabled
      segmentRefs: [],
      searchQuery: '',
      updateInterval: null,
      lastTime: 0,
      showWordEditor: false,
      editingWord: {
        word: '',
        start: 0,
        end: 0
      },
      editingSegmentIndex: -1,
      editingWordIndex: -1,
      allowTimeEditing: false,
      originalConfidence: 1.0,
      activeSegmentIds: new Set()
    };
  },
  
  computed: {
    hasTranscript() {
      return this.segments && this.segments.length > 0;
    },
    
    displayedSegments() {
      if (!this.searchQuery) {
        return this.segments;
      }
      
      const query = this.searchQuery.toLowerCase();
      return this.segments.filter(segment => {
        // Check segment text
        if (segment.text && segment.text.toLowerCase().includes(query)) {
          return true;
        }
        
        // Check individual words
        if (segment.words && segment.words.length > 0) {
          return segment.words.some(word => 
            word.word && word.word.toLowerCase().includes(query)
          );
        }
        
        return false;
      });
    }
  },
  
  watch: {
    transcriptJsonUrl: {
      immediate: true,
      handler(url) {
        if (url) {
          this.fetchTranscriptJson();
        } else if (this.srtUrl) {
          this.fetchSRT();
        } else if (this.transcriptText) {
          this.segments = [{
            start: 0,
            end: 0,
            text: this.transcriptText,
            words: []
          }];
          this.loading = false;
        } else {
          this.segments = [];
          this.loading = false;
        }
      }
    },
  },
  
  mounted() {
    if (this.videoRef) {
      this.videoRef.addEventListener('timeupdate', this.updateCurrentPosition);
      // More frequent updates for better synchronization
      this.updateInterval = setInterval(() => this.checkCurrentPosition(), 50);
    }
  },
  
  beforeUnmount() {
    if (this.videoRef) {
      this.videoRef.removeEventListener('timeupdate', this.updateCurrentPosition);
    }
    if (this.updateInterval) {
      clearInterval(this.updateInterval);
    }
  },
  
  methods: {
    async fetchTranscriptJson() {
      if (!this.transcriptJsonUrl) {
        if (this.srtUrl) {
          this.fetchSRT();
        } else {
          this.segments = this.transcriptText ? [{ start: 0, end: 0, text: this.transcriptText, words: [] }] : [];
          this.loading = false;
        }
        return;
      }
      
      try {
        this.loading = true;
        this.error = null;
        
        const response = await fetch(this.transcriptJsonUrl);
        if (!response.ok) {
          throw new Error(`HTTP error ${response.status} fetching transcript JSON`);
        }
        
        const data = await response.json();
        this.parseTranscriptJson(data);
      } catch (error) {
        console.error('Error fetching transcript JSON:', error);
        this.error = error.message;
        
        // Try fallback sources
        if (this.srtUrl) {
          this.fetchSRT();
        } else {
          this.segments = this.transcriptText ? [{ start: 0, end: 0, text: this.transcriptText, words: [] }] : [];
          this.loading = false;
        }
      }
    },
    
    parseTranscriptJson(data) {
      if (!data || !data.segments || !Array.isArray(data.segments)) {
        this.error = 'Invalid transcript JSON format';
        this.segments = [];
        this.loading = false;
        return;
      }
      
      this.segments = data.segments.map(segment => {
        // Calculate segment confidence from words
        let totalConfidence = 0;
        let wordCount = 0;
        
        const words = Array.isArray(segment.words) 
          ? segment.words.map(word => {
              const probability = word.probability !== undefined ? parseFloat(word.probability) : 1.0;
              totalConfidence += probability;
              wordCount++;
              
              return {
                word: word.word || word.text || '',
                start: word.start !== undefined ? parseFloat(word.start) : 0,
                end: word.end !== undefined ? parseFloat(word.end) : 0,
                probability: probability
              };
            })
          : [];
        
        // Calculate average confidence for the segment
        const segmentConfidence = wordCount > 0 ? totalConfidence / wordCount : null;
        
        return {
          start: segment.start !== undefined ? parseFloat(segment.start) : 0,
          end: segment.end !== undefined ? parseFloat(segment.end) : 0,
          text: segment.text || '',
          words: words,
          confidence: segmentConfidence
        };
      });
      
      this.loading = false;
    },
    
    async fetchSRT() {
      if (!this.srtUrl) {
        this.segments = this.transcriptText ? [{ start: 0, end: 0, text: this.transcriptText, words: [] }] : [];
        this.loading = false;
        return;
      }
      
      try {
        this.loading = true;
        this.error = null;
        
        const response = await fetch(this.srtUrl);
        if (!response.ok) {
          throw new Error(`HTTP error ${response.status} fetching SRT`);
        }
        
        const srtContent = await response.text();
        this.parseSRT(srtContent);
      } catch (error) {
        console.error('Error fetching SRT:', error);
        this.error = error.message;
        this.segments = this.transcriptText ? [{ start: 0, end: 0, text: this.transcriptText, words: [] }] : [];
      } finally {
        this.loading = false;
      }
    },
    
    parseSRT(srtContent) {
      const segments = [];
      const blocks = srtContent.trim().split(/\n\s*\n/);
      
      blocks.forEach(block => {
        const lines = block.split('\n');
        if (lines.length >= 3) {
          const timeCodes = lines[1].split(' --> ');
          if (timeCodes.length === 2) {
            const start = this.parseTimeCode(timeCodes[0]);
            const end = this.parseTimeCode(timeCodes[1]);
            const text = lines.slice(2).join(' ');
            segments.push({ start, end, text, words: [] });
          }
        }
      });
      
      this.segments = segments;
    },
    
    parseTimeCode(timeCode) {
      // Format: 00:00:00,000
      const parts = timeCode.replace(',', '.').match(/(\d+):(\d+):(\d+)\.(\d+)/);
      if (!parts) return 0;
      
      const hours = parseInt(parts[1], 10);
      const minutes = parseInt(parts[2], 10);
      const seconds = parseInt(parts[3], 10);
      const milliseconds = parseInt(parts[4], 10);
      
      return hours * 3600 + minutes * 60 + seconds + milliseconds / 1000;
    },
    
    formatTime(seconds) {
      if (isNaN(seconds)) return '0:00';
      
      const hrs = Math.floor(seconds / 3600);
      const mins = Math.floor((seconds % 3600) / 60);
      const secs = Math.floor(seconds % 60);
      
      let timeString = '';
      if (hrs > 0) {
        timeString += `${hrs}:${mins.toString().padStart(2, '0')}:`;
      } else {
        timeString += `${mins}:`;
      }
      timeString += secs.toString().padStart(2, '0');
      
      return timeString;
    },
    
    checkCurrentPosition() {
      if (!this.videoRef || !this.videoRef.currentTime) return;
      
      if (Math.abs(this.videoRef.currentTime - this.lastTime) > 0.05) {
        this.updateCurrentPosition();
        this.lastTime = this.videoRef.currentTime;
      }
    },
    
    updateCurrentPosition() {
      if (!this.videoRef || this.segments.length === 0) return;
      
      const currentTime = this.videoRef.currentTime;
      let newSegmentIndex = -1;
      let newWordIndex = -1;
      let activeSegments = new Set();
      
      // Find segments that are active at the current time
      for (let i = 0; i < this.segments.length; i++) {
        const segment = this.segments[i];
        if (currentTime >= segment.start && currentTime <= segment.end) {
          activeSegments.add(i);
          
          // If we haven't assigned a primary segment yet, use this one
          if (newSegmentIndex === -1) {
            newSegmentIndex = i;
          }
          
          // If this segment starts closer to the current time than our current choice,
          // prefer it as the primary segment
          if (Math.abs(segment.start - currentTime) < 
              Math.abs(this.segments[newSegmentIndex].start - currentTime)) {
            newSegmentIndex = i;
          }
          
          // Look for the active word within this segment
          const words = segment.words;
          if (words && words.length > 0) {
            for (let j = 0; j < words.length; j++) {
              if (currentTime >= words[j].start && currentTime <= words[j].end) {
                if (i === newSegmentIndex) {
                  newWordIndex = j;
                }
                break;
              }
            }
          }
        }
      }
      
      // If no segment found within the time range, try to find the closest upcoming segment
      if (newSegmentIndex === -1) {
        let closestSegmentIndex = -1;
        let minTimeDifference = Infinity;
        
        for (let i = 0; i < this.segments.length; i++) {
          const segment = this.segments[i];
          const timeDiff = segment.start - currentTime;
          
          if (timeDiff > 0 && timeDiff < minTimeDifference) {
            minTimeDifference = timeDiff;
            closestSegmentIndex = i;
          }
        }
        
        // If we're less than 0.5 seconds from the next segment, consider it active
        if (closestSegmentIndex !== -1 && minTimeDifference < 0.5) {
          newSegmentIndex = closestSegmentIndex;
          activeSegments.add(closestSegmentIndex);
        }
      }
      
      // Keep track of active segments for highlighting
      this.activeSegmentIds = activeSegments;
      
      // Update current segment index without auto-scrolling
      this.currentSegmentIndex = newSegmentIndex;
      this.currentWordIndex = newWordIndex;
    },
    
    scrollToSegment() {
      // Auto-scroll functionality disabled
    },
    
    toggleAutoScroll() {
      // Auto-scroll functionality disabled
    },
    
    handleUserScroll() {
      // Auto-scroll functionality disabled
    },
    
    isActiveSegment(segmentIndex) {
      return this.activeSegmentIds.has(segmentIndex);
    },
    
    isCurrentWord(word) {
      if (!this.videoRef) return false;
      
      const currentTime = this.videoRef.currentTime;
      return currentTime >= word.start && currentTime <= word.end;
    },
    
    seekToTime(seconds) {
      if (!this.videoRef) return;
      
      this.videoRef.currentTime = seconds;
      this.videoRef.play().catch(error => {
        console.warn('Could not play video after seeking:', error);
      });
    },
    
    getConfidenceClass(confidence) {
      if (!confidence) return '';
      
      if (confidence >= 0.8) return 'bg-green-100 text-green-800';
      if (confidence >= 0.6) return 'bg-yellow-100 text-yellow-800';
      return 'bg-red-100 text-red-800';
    },
    
    getWordConfidenceClass(probability) {
      if (!probability) return '';
      
      if (probability >= 0.8) return 'high-confidence';
      if (probability >= 0.6) return 'medium-confidence';
      return 'low-confidence';
    },
    
    isHighlighted(word) {
      if (!word || !this.searchQuery) return false;
      
      return word.toLowerCase().includes(this.searchQuery.toLowerCase());
    },
    
    reload() {
      this.error = null;
      this.loading = true;
      
      if (this.transcriptJsonUrl) {
        this.fetchTranscriptJson();
      } else if (this.srtUrl) {
        this.fetchSRT();
      } else {
        this.loading = false;
      }
    },
    
    openWordEditor(segment, word, segmentIndex, wordIndex) {
      // Pause the video when opening editor
      if (this.videoRef && !this.videoRef.paused) {
        this.videoRef.pause();
      }
      
      this.editingWord = {
        word: word.word,
        start: parseFloat(word.start),
        end: parseFloat(word.end)
      };
      this.originalConfidence = parseFloat(word.probability || 1.0);
      this.editingSegmentIndex = segmentIndex;
      this.editingWordIndex = wordIndex;
      this.allowTimeEditing = false;
      this.showWordEditor = true;
      
      // Focus on the word input field after modal is open
      this.$nextTick(() => {
        if (this.$refs.wordInput) {
          this.$refs.wordInput.focus();
          this.$refs.wordInput.select();
        }
      });
    },
    
    closeWordEditor() {
      this.showWordEditor = false;
      this.editingSegmentIndex = -1;
      this.editingWordIndex = -1;
    },
    
    saveWordEdit() {
      if (this.editingSegmentIndex === -1 || this.editingWordIndex === -1) return;
      
      const segment = this.segments[this.editingSegmentIndex];
      if (!segment || !segment.words || !segment.words[this.editingWordIndex]) return;
      
      // Update the word data
      segment.words[this.editingWordIndex] = {
        word: this.editingWord.word,
        start: parseFloat(this.editingWord.start),
        end: parseFloat(this.editingWord.end),
        probability: 1.0 // Set to 100% confidence for edited words
      };
      
      this.closeWordEditor();
      
      // Recalculate segment confidence
      this.recalculateSegmentConfidence(this.editingSegmentIndex);
    },
    
    recalculateSegmentConfidence(segmentIndex) {
      if (segmentIndex === -1 || !this.segments[segmentIndex]) return;
      
      const segment = this.segments[segmentIndex];
      if (!segment.words || segment.words.length === 0) return;
      
      let totalConfidence = 0;
      let wordCount = 0;
      
      segment.words.forEach(word => {
        if (word.probability !== undefined) {
          totalConfidence += parseFloat(word.probability);
          wordCount++;
        }
      });
      
      if (wordCount > 0) {
        segment.confidence = totalConfidence / wordCount;
      }
    },
    
    getConfidenceColor(confidence) {
      if (confidence >= 0.8) return '#10b981'; // green-500
      if (confidence >= 0.6) return '#f59e0b'; // amber-500
      return '#ef4444'; // red-500
    }
  }
};
</script>

<style scoped>
.word-item {
  display: inline-block;
  margin-right: 4px;
  transition: all 0.2s ease;
  position: relative;
}

.word-item:hover::after {
  content: 'Click to play, Ctrl/Cmd+click to edit';
  position: absolute;
  bottom: -28px;
  left: 50%;
  transform: translateX(-50%);
  background-color: rgba(0, 0, 0, 0.7);
  color: white;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  white-space: nowrap;
  z-index: 10;
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}

.word-item:hover:hover::after {
  opacity: 1;
}

.word-item.high-confidence {
  color: inherit;
}

.word-item.medium-confidence {
  color: #b45309; /* amber-700 */
}

.word-item.low-confidence {
  color: #b91c1c; /* red-700 */
  text-decoration: underline dotted;
}

.transcript-content::-webkit-scrollbar {
  width: 8px;
}

.transcript-content::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 8px;
}

.transcript-content::-webkit-scrollbar-thumb {
  background: #cbd5e0;
  border-radius: 8px;
}

.transcript-content::-webkit-scrollbar-thumb:hover {
  background: #a0aec0;
}

.highlighted {
  position: relative;
}

.highlighted::after {
  content: '';
  position: absolute;
  bottom: -2px;
  left: 0;
  right: 0;
  height: 2px;
  background-color: #fbbf24; /* amber-400 */
}

/* Sentence-like spacing in continuous mode */
.continuous-transcript .word-item:last-of-type {
  margin-right: 0;
}

.segment-divider {
  display: inline-block;
  color: #9ca3af; /* gray-400 */
}

/* Fix for sentence-ending punctuation */
.word-item:nth-last-child(1):after {
  content: " ";
}

/* Add more space after sentence-ending punctuation */
.word-item:has([word$="."]):after,
.word-item:has([word$="!"]):after,
.word-item:has([word$="?"]):after {
  content: "  ";
  white-space: pre;
}

/* Better spacing in continuous mode */
.continuous-transcript {
  line-height: 1.8;
  white-space: normal;
  word-wrap: break-word;
  padding: 0.75rem;
  text-align: justify;
}

/* Space after punctuation */
.continuous-transcript .word-item[word$="."],
.continuous-transcript .word-item[word$="!"],
.continuous-transcript .word-item[word$="?"],
.continuous-transcript .word-item[word$=","],
.continuous-transcript .word-item[word$=";"],
.continuous-transcript .word-item[word$=":"] {
  margin-right: 6px;
}
</style> 