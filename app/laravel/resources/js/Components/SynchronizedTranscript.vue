<template>
  <div class="synchronized-transcript">
    <div class="transcript-header flex justify-between items-center mb-4">
      <h3 class="text-lg font-medium flex items-center">
        <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        Synchronized Transcript
      </h3>
      <div class="flex items-center space-x-2 text-sm">
        <button 
          @click="autoScroll = !autoScroll" 
          class="flex items-center px-2 py-1 rounded"
          :class="autoScroll ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'"
        >
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
          </svg>
          {{ autoScroll ? 'Auto-scroll on' : 'Auto-scroll off' }}
        </button>
      </div>
    </div>
    
    <div ref="transcriptContainer" class="transcript-container bg-gray-50 rounded-lg p-4 max-h-80 overflow-y-auto shadow-sm border border-gray-200">
      <template v-if="loading">
        <div class="flex justify-center items-center h-32">
          <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-gray-900"></div>
        </div>
      </template>
      
      <template v-else-if="segments.length === 0">
        <p class="text-center text-gray-500 py-6">No synchronized transcript available</p>
      </template>
      
      <template v-else>
        <!-- Segments with individual word highlighting -->
        <div v-for="(segment, segmentIndex) in segments" :key="segmentIndex" 
          :ref="el => { if (el) segmentRefs[segmentIndex] = el }"
          class="py-2 px-3 rounded mb-3 transition-all duration-150"
          :class="{
            'bg-blue-50 border border-blue-100': currentSegmentIndex === segmentIndex,
            'hover:bg-gray-100 border border-transparent': currentSegmentIndex !== segmentIndex
          }"
        >
          <div class="flex items-center mb-1">
            <span class="text-xs bg-gray-200 text-gray-700 rounded px-1.5 py-0.5 mr-2">{{ formatTime(segment.start) }}</span>
            <button 
              @click="seekToTime(segment.start)" 
              class="text-xs text-blue-600 hover:text-blue-800 hover:underline flex items-center"
            >
              <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              Play
            </button>
          </div>
          
          <div class="text-base leading-relaxed">
            <template v-if="segment.words && segment.words.length > 0">
              <span 
                v-for="(word, wordIndex) in segment.words" 
                :key="`${segmentIndex}-${wordIndex}`"
                @click="seekToTime(word.start)"
                :class="{
                  'bg-blue-200 rounded px-0.5': isCurrentWord(word),
                  'cursor-pointer hover:bg-gray-200 hover:rounded px-0.5': !isCurrentWord(word)
                }"
              >
                {{ word.word }}
              </span>
            </template>
            <template v-else>
              {{ segment.text }}
            </template>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    videoRef: {
      type: Object, // Reference to the video element
      required: true
    },
    srtUrl: {
      type: String,
      default: null
    },
    transcriptJsonUrl: {
      type: String,
      default: null
    },
    transcriptText: {
      type: String,
      default: ''
    }
  },
  
  data() {
    return {
      segments: [],
      rawTranscriptData: null,
      currentSegmentIndex: -1,
      currentWordIndex: -1,
      loading: true,
      autoScroll: true,
      segmentRefs: [],
      updateInterval: null,
      lastTime: 0
    };
  },
  
  watch: {
    transcriptJsonUrl: {
      immediate: true,
      handler(newVal) {
        if (newVal) {
          this.fetchTranscriptJson();
        } else if (this.srtUrl) {
          // Fall back to SRT if no JSON
          this.fetchSRT();
        } else {
          // Use plain transcript if no structured data available
          this.segments = [{
            start: 0,
            end: 0,
            text: this.transcriptText || 'No transcript available',
            words: []
          }];
          this.loading = false;
        }
      }
    }
  },
  
  mounted() {
    // Set up video time tracking with more precision
    if (this.videoRef) {
      // Use both timeupdate and more frequent checks for smoother updates
      this.videoRef.addEventListener('timeupdate', this.updateCurrentPosition);
      
      // More frequent updates for better responsiveness
      this.updateInterval = setInterval(this.checkCurrentPosition, 100);
    }
  },
  
  beforeUnmount() {
    // Clean up event listeners
    if (this.videoRef) {
      this.videoRef.removeEventListener('timeupdate', this.updateCurrentPosition);
    }
    
    // Clear the interval
    if (this.updateInterval) {
      clearInterval(this.updateInterval);
    }
  },
  
  methods: {
    // More frequent position check for smoother word highlighting
    checkCurrentPosition() {
      if (!this.videoRef || !this.videoRef.currentTime) return;
      
      // Only update if time has changed enough to matter
      if (Math.abs(this.videoRef.currentTime - this.lastTime) > 0.05) {
        this.updateCurrentPosition();
        this.lastTime = this.videoRef.currentTime;
      }
    },
    
    async fetchTranscriptJson() {
      try {
        this.loading = true;
        const response = await fetch(this.transcriptJsonUrl);
        this.rawTranscriptData = await response.json();
        this.parseTranscriptJson(this.rawTranscriptData);
      } catch (error) {
        console.error('Error fetching transcript JSON:', error);
        
        // Fallback to SRT if available
        if (this.srtUrl) {
          this.fetchSRT();
        } else {
          // Or fall back to plain text
          this.segments = [{
            start: 0,
            end: 0,
            text: this.transcriptText || 'Error loading transcript',
            words: []
          }];
          this.loading = false;
        }
      }
    },
    
    parseTranscriptJson(data) {
      if (!data || !data.segments || !Array.isArray(data.segments)) {
        console.error('Invalid transcript JSON format');
        this.loading = false;
        return;
      }
      
      this.segments = data.segments.map(segment => {
        // Process each segment to ensure it has all needed properties
        return {
          start: segment.start || 0,
          end: segment.end || 0,
          text: segment.text || '',
          words: Array.isArray(segment.words) ? segment.words.map(word => {
            return {
              word: word.word || word.text || '',
              start: word.start || 0,
              end: word.end || 0,
              probability: word.probability || null
            };
          }) : []
        };
      });
      
      this.loading = false;
    },
    
    async fetchSRT() {
      try {
        this.loading = true;
        const response = await fetch(this.srtUrl);
        const srtContent = await response.text();
        this.parseSRT(srtContent);
      } catch (error) {
        console.error('Error fetching SRT file:', error);
        // Fallback to plain transcript
        this.segments = [{
          start: 0,
          end: 0,
          text: this.transcriptText || 'Error loading transcript',
          words: []
        }];
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
          // Parse time codes (format: 00:00:00,000 --> 00:00:00,000)
          const timeCodes = lines[1].split(' --> ');
          if (timeCodes.length === 2) {
            const start = this.parseTimeCode(timeCodes[0]);
            const end = this.parseTimeCode(timeCodes[1]);
            
            // Join the remaining lines as the text
            const text = lines.slice(2).join(' ');
            
            segments.push({ 
              start, 
              end, 
              text,
              words: [] // SRT doesn't have word-level timing
            });
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
    
    updateCurrentPosition() {
      if (!this.videoRef || this.segments.length === 0) return;
      
      const currentTime = this.videoRef.currentTime;
      
      // Find the current segment
      let newSegmentIndex = -1;
      let newWordIndex = -1;
      
      // First find the current segment
      for (let i = 0; i < this.segments.length; i++) {
        if (currentTime >= this.segments[i].start && currentTime <= this.segments[i].end) {
          newSegmentIndex = i;
          
          // Check for word-level timing within this segment
          const words = this.segments[i].words;
          if (words && words.length > 0) {
            for (let j = 0; j < words.length; j++) {
              if (currentTime >= words[j].start && currentTime <= words[j].end) {
                newWordIndex = j;
                break;
              }
            }
          }
          
          break;
        }
        
        // Handle gaps between segments
        if (i < this.segments.length - 1 && 
            currentTime > this.segments[i].end && 
            currentTime < this.segments[i + 1].start) {
          newSegmentIndex = i;
          break;
        }
      }
      
      // Handle time before first segment
      if (newSegmentIndex === -1 && this.segments.length > 0 && currentTime < this.segments[0].start) {
        newSegmentIndex = -1;
      }
      
      // Handle time after last segment
      if (newSegmentIndex === -1 && this.segments.length > 0 && 
          currentTime > this.segments[this.segments.length - 1].end) {
        newSegmentIndex = this.segments.length - 1;
      }
      
      // Update segment index if changed
      if (newSegmentIndex !== this.currentSegmentIndex) {
        this.currentSegmentIndex = newSegmentIndex;
        this.scrollToSegment();
      }
      
      // Update word index if changed
      this.currentWordIndex = newWordIndex;
    },
    
    isCurrentWord(word) {
      if (!this.videoRef) return false;
      const currentTime = this.videoRef.currentTime;
      return currentTime >= word.start && currentTime <= word.end;
    },
    
    scrollToSegment() {
      if (!this.autoScroll || this.currentSegmentIndex === -1 || !this.segmentRefs[this.currentSegmentIndex]) return;
      
      // Scroll the segment into view
      const segmentElement = this.segmentRefs[this.currentSegmentIndex];
      const container = this.$refs.transcriptContainer;
      
      if (segmentElement && container) {
        const segmentTop = segmentElement.offsetTop;
        const containerScrollTop = container.scrollTop;
        const containerHeight = container.clientHeight;
        
        // If segment is not visible, scroll to it
        if (segmentTop < containerScrollTop || segmentTop > containerScrollTop + containerHeight - 60) {
          container.scrollTo({
            top: segmentTop - containerHeight / 4, // Position segment 1/4 from the top
            behavior: 'smooth'
          });
        }
      }
    },
    
    seekToTime(seconds) {
      if (this.videoRef) {
        this.videoRef.currentTime = seconds;
        this.videoRef.play().catch(err => {
          console.warn('Could not play video after seeking', err);
        });
      }
    }
  }
};
</script>

<style scoped>
.synchronized-transcript {
  margin-top: 1.5rem;
}

.transcript-container::-webkit-scrollbar {
  width: 8px;
}

.transcript-container::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 8px;
}

.transcript-container::-webkit-scrollbar-thumb {
  background: #cbd5e0;
  border-radius: 8px;
}

.transcript-container::-webkit-scrollbar-thumb:hover {
  background: #a0aec0;
}

/* Word spacing */
.synchronized-transcript span + span {
  margin-left: 4px;
}
</style> 