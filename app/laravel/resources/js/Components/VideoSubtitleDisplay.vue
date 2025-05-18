<template>
  <div class="video-subtitle-display">
    <div 
      class="subtitle-container bg-black bg-opacity-75 p-3 rounded-md text-white text-center text-lg"
      :class="{'has-content': currentSegment}"
    >
      <div v-if="loading" class="text-gray-300 text-base italic">
        Loading subtitles...
      </div>
      <div v-else-if="error" class="text-red-300 text-base">
        {{ error }}
      </div>
      <div v-else-if="!currentSegment" class="text-gray-300 text-base italic">
        No active subtitles
      </div>
      <div v-else class="subtitle-content">
        <div class="flex flex-wrap justify-center gap-1">
          <template v-if="currentSegment.words && currentSegment.words.length > 0">
            <span 
              v-for="(word, wordIndex) in currentSegment.words" 
              :key="wordIndex"
              class="word-item transition-colors duration-100"
              :class="[
                getWordConfidenceClass(word.probability),
                { 'bg-blue-500 bg-opacity-50 rounded px-1': isCurrentWord(word) }
              ]"
            >
              {{ word.word }}
            </span>
          </template>
          <template v-else>
            {{ currentSegment.text }}
          </template>
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
    }
  },
  
  data() {
    return {
      segments: [],
      loading: true,
      error: null,
      currentSegmentIndex: -1,
      currentWordIndex: -1,
      updateInterval: null,
      lastTime: 0
    };
  },
  
  computed: {
    currentSegment() {
      return this.currentSegmentIndex >= 0 && this.currentSegmentIndex < this.segments.length 
        ? this.segments[this.currentSegmentIndex] 
        : null;
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
        } else {
          this.segments = [];
          this.loading = false;
        }
      }
    }
  },
  
  mounted() {
    if (this.videoRef) {
      this.videoRef.addEventListener('timeupdate', this.updateCurrentPosition);
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
          this.segments = [];
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
          this.segments = [];
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
        const words = Array.isArray(segment.words) 
          ? segment.words.map(word => ({
              word: word.word || word.text || '',
              start: word.start !== undefined ? parseFloat(word.start) : 0,
              end: word.end !== undefined ? parseFloat(word.end) : 0,
              probability: word.probability !== undefined ? parseFloat(word.probability) : 1.0
            }))
          : [];
        
        return {
          start: segment.start !== undefined ? parseFloat(segment.start) : 0,
          end: segment.end !== undefined ? parseFloat(segment.end) : 0,
          text: segment.text || '',
          words
        };
      });
      
      this.loading = false;
    },
    
    async fetchSRT() {
      if (!this.srtUrl) {
        this.segments = [];
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
        this.segments = [];
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
      
      // Find the active segment at the current time
      for (let i = 0; i < this.segments.length; i++) {
        const segment = this.segments[i];
        if (currentTime >= segment.start && currentTime <= segment.end) {
          newSegmentIndex = i;
          
          // Find the active word within this segment
          const words = segment.words;
          if (words && words.length > 0) {
            for (let j = 0; j < words.length; j++) {
              if (currentTime >= words[j].start && currentTime <= words[j].end) {
                newWordIndex = j;
                break;
              }
            }
          }
          
          break; // Use the first matching segment
        }
      }
      
      // If no segment found, try to find the closest upcoming segment
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
        
        // If we're less than 1 second from the next segment, show it
        if (closestSegmentIndex !== -1 && minTimeDifference < 1) {
          newSegmentIndex = closestSegmentIndex;
        }
      }
      
      // Update current indices
      this.currentSegmentIndex = newSegmentIndex;
      this.currentWordIndex = newWordIndex;
    },
    
    isCurrentWord(word) {
      if (!this.videoRef) return false;
      
      const currentTime = this.videoRef.currentTime;
      return currentTime >= word.start && currentTime <= word.end;
    },
    
    getWordConfidenceClass(probability) {
      if (!probability) return '';
      
      if (probability >= 0.8) return 'high-confidence';
      if (probability >= 0.6) return 'medium-confidence';
      return 'low-confidence';
    }
  }
};
</script>

<style scoped>
.video-subtitle-display {
  width: 100%;
  padding: 0.5rem;
}

.subtitle-container {
  min-height: 3.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
}

.subtitle-container.has-content {
  border-left: 4px solid #3b82f6; /* Blue border for active subtitles */
}

.word-item {
  display: inline-block;
  margin-right: 4px;
  position: relative;
}

.word-item.high-confidence {
  color: white;
}

.word-item.medium-confidence {
  color: #fcd34d; /* Amber-300 */
}

.word-item.low-confidence {
  color: #f87171; /* Red-400 */
  text-decoration: underline dotted;
}

/* Add space after punctuation */
.word-item[word$="."],
.word-item[word$="!"],
.word-item[word$="?"],
.word-item[word$=","],
.word-item[word$=";"],
.word-item[word$=":"] {
  margin-right: 6px;
}
</style> 