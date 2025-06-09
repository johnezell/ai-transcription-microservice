# WhisperX Frontend Integration Examples
**Phase 7: Frontend Integration - Vue.js Component Examples**

## Overview

This document provides comprehensive examples of Vue.js components that integrate with WhisperX enhanced features, including speaker diarization, improved timing, and performance metrics display.

## Enhanced Subtitle Components

### 1. WhisperX Enhanced Subtitle Component

```vue
<template>
  <div class="whisperx-subtitles">
    <!-- Enhanced Features Status -->
    <div v-if="showEnhancementStatus" class="enhancement-status">
      <div class="status-indicators">
        <span 
          class="status-badge"
          :class="{ active: hasAlignment }"
          title="Enhanced Timing Accuracy"
        >
          <i class="icon-clock"></i>
          Precision Timing
        </span>
        <span 
          class="status-badge"
          :class="{ active: hasSpeakerDiarization }"
          title="Speaker Identification"
        >
          <i class="icon-users"></i>
          Speaker ID
        </span>
        <span 
          class="status-badge"
          :class="{ active: hasPerformanceMetrics }"
          title="Performance Monitoring"
        >
          <i class="icon-activity"></i>
          Metrics
        </span>
      </div>
    </div>

    <!-- Speaker Legend -->
    <SpeakerLegend 
      v-if="hasSpeakerDiarization && showSpeakerLegend"
      :speaker-info="transcriptionData.speaker_info"
      :current-speaker="currentSpeaker"
      @speaker-selected="onSpeakerSelected"
    />

    <!-- Main Subtitle Display -->
    <div class="subtitle-container">
      <!-- Current Segment Display -->
      <div 
        v-if="currentSegment" 
        class="current-subtitle"
        :class="getSubtitleClasses()"
      >
        <!-- Speaker Indicator -->
        <div 
          v-if="currentSegment.speaker && hasSpeakerDiarization"
          class="speaker-indicator"
          :class="getSpeakerClass(currentSegment.speaker)"
        >
          {{ formatSpeakerLabel(currentSegment.speaker) }}
        </div>

        <!-- Word-by-Word Display -->
        <div class="subtitle-text">
          <span
            v-for="(word, index) in currentSegment.words"
            :key="`${word.start}-${index}`"
            class="subtitle-word"
            :class="getWordClasses(word, index)"
            @click="onWordClick(word, index)"
            :title="getWordTooltip(word)"
          >
            {{ word.word }}
          </span>
        </div>

        <!-- Timing Accuracy Indicator -->
        <div v-if="hasAlignment" class="timing-indicator">
          <div class="accuracy-bar">
            <div 
              class="accuracy-fill"
              :style="{ width: timingAccuracy + '%' }"
            ></div>
          </div>
          <span class="accuracy-text">{{ timingAccuracy }}% accuracy</span>
        </div>
      </div>

      <!-- Loading State -->
      <div v-else-if="isLoading" class="subtitle-loading">
        <div class="loading-spinner"></div>
        <span>Loading enhanced transcription...</span>
      </div>

      <!-- No Content State -->
      <div v-else class="subtitle-empty">
        <span>No transcription available</span>
      </div>
    </div>

    <!-- Performance Metrics Panel -->
    <PerformanceMetrics
      v-if="hasPerformanceMetrics && showPerformancePanel"
      :metrics="transcriptionData.performance_metrics"
      :processing-info="transcriptionData.whisperx_processing"
    />

    <!-- Enhanced Controls -->
    <div class="subtitle-controls">
      <button 
        @click="toggleSpeakerLegend"
        :disabled="!hasSpeakerDiarization"
        class="control-button"
      >
        <i class="icon-users"></i>
        Speakers
      </button>
      
      <button 
        @click="togglePerformancePanel"
        :disabled="!hasPerformanceMetrics"
        class="control-button"
      >
        <i class="icon-activity"></i>
        Metrics
      </button>
      
      <button 
        @click="exportEnhancedData"
        class="control-button"
      >
        <i class="icon-download"></i>
        Export
      </button>
    </div>
  </div>
</template>

<script>
import SpeakerLegend from './SpeakerLegend.vue';
import PerformanceMetrics from './PerformanceMetrics.vue';

export default {
  name: 'WhisperXSubtitles',
  
  components: {
    SpeakerLegend,
    PerformanceMetrics
  },
  
  props: {
    transcriptionData: {
      type: Object,
      required: true
    },
    videoRef: {
      type: Object,
      required: true
    },
    currentTime: {
      type: Number,
      default: 0
    },
    showEnhancementStatus: {
      type: Boolean,
      default: true
    }
  },
  
  data() {
    return {
      showSpeakerLegend: false,
      showPerformancePanel: false,
      selectedSpeakers: [],
      isLoading: false,
      timingBuffer: 0.1
    };
  },
  
  computed: {
    // Enhanced feature detection
    hasAlignment() {
      return this.transcriptionData.whisperx_processing?.alignment === 'completed';
    },
    
    hasSpeakerDiarization() {
      return this.transcriptionData.speaker_info && 
             this.transcriptionData.speaker_info.detected_speakers > 0;
    },
    
    hasPerformanceMetrics() {
      return Boolean(this.transcriptionData.performance_metrics);
    },
    
    // Current segment calculation with enhanced timing
    currentSegment() {
      if (!this.transcriptionData.segments) return null;
      
      const buffer = this.getTimingBuffer();
      return this.transcriptionData.segments.find(segment => 
        this.currentTime >= (segment.start - buffer) && 
        this.currentTime <= (segment.end + buffer)
      );
    },
    
    currentSpeaker() {
      return this.currentSegment?.speaker || null;
    },
    
    // Timing accuracy calculation
    timingAccuracy() {
      if (!this.hasAlignment) return 0;
      
      const segments = this.transcriptionData.segments || [];
      let totalWords = 0;
      let accurateWords = 0;
      
      segments.forEach(segment => {
        if (segment.words) {
          segment.words.forEach(word => {
            totalWords++;
            if (word.probability > 0.8) {
              accurateWords++;
            }
          });
        }
      });
      
      return totalWords > 0 ? Math.round((accurateWords / totalWords) * 100) : 0;
    }
  },
  
  methods: {
    // Enhanced timing buffer calculation
    getTimingBuffer() {
      if (this.hasAlignment) {
        const alignmentInfo = this.transcriptionData.alignment_info;
        return alignmentInfo?.char_alignments_enabled ? 0.05 : 0.08;
      }
      return 0.15; // Larger buffer for non-aligned content
    },
    
    // Word class calculation with enhanced features
    getWordClasses(word, index) {
      const classes = ['subtitle-word'];
      
      // Active word highlighting with enhanced timing
      if (this.isWordActive(word)) {
        classes.push('active-word');
        if (this.hasAlignment) {
          classes.push('enhanced-timing');
        }
      }
      
      // Confidence-based styling
      if (word.probability >= 0.9) {
        classes.push('high-confidence');
      } else if (word.probability >= 0.7) {
        classes.push('medium-confidence');
      } else {
        classes.push('low-confidence');
      }
      
      // Speaker-specific styling
      if (word.speaker && this.hasSpeakerDiarization) {
        classes.push(this.getSpeakerClass(word.speaker));
      }
      
      return classes;
    },
    
    // Enhanced word activity detection
    isWordActive(word) {
      const buffer = this.getTimingBuffer();
      return this.currentTime >= (word.start - buffer) && 
             this.currentTime <= (word.end + buffer);
    },
    
    // Speaker class generation
    getSpeakerClass(speaker) {
      if (!this.hasSpeakerDiarization) return '';
      
      const speakers = this.transcriptionData.speaker_info.speaker_labels;
      const index = speakers.indexOf(speaker);
      return `speaker-${index}`;
    },
    
    // Speaker label formatting
    formatSpeakerLabel(speaker) {
      return speaker.replace('SPEAKER_', 'Speaker ');
    },
    
    // Subtitle container classes
    getSubtitleClasses() {
      const classes = ['subtitle-display'];
      
      if (this.hasAlignment) {
        classes.push('enhanced-timing');
      }
      
      if (this.hasSpeakerDiarization) {
        classes.push('speaker-aware');
      }
      
      return classes;
    },
    
    // Word tooltip information
    getWordTooltip(word) {
      const parts = [
        `"${word.word}"`,
        `${word.start.toFixed(2)}s - ${word.end.toFixed(2)}s`,
        `Confidence: ${(word.probability * 100).toFixed(0)}%`
      ];
      
      if (word.speaker) {
        parts.push(`Speaker: ${this.formatSpeakerLabel(word.speaker)}`);
      }
      
      return parts.join('\n');
    },
    
    // Event handlers
    onWordClick(word, index) {
      this.$emit('word-clicked', { word, index, segment: this.currentSegment });
      
      // Seek to word position
      if (this.videoRef && this.videoRef.currentTime !== word.start) {
        this.videoRef.currentTime = word.start;
      }
    },
    
    onSpeakerSelected(speaker) {
      this.$emit('speaker-selected', speaker);
    },
    
    // Control methods
    toggleSpeakerLegend() {
      this.showSpeakerLegend = !this.showSpeakerLegend;
    },
    
    togglePerformancePanel() {
      this.showPerformancePanel = !this.showPerformancePanel;
    },
    
    // Export enhanced data
    exportEnhancedData() {
      const exportData = {
        transcription: this.transcriptionData,
        export_timestamp: new Date().toISOString(),
        enhanced_features: {
          alignment: this.hasAlignment,
          speaker_diarization: this.hasSpeakerDiarization,
          performance_metrics: this.hasPerformanceMetrics
        }
      };
      
      const blob = new Blob([JSON.stringify(exportData, null, 2)], {
        type: 'application/json'
      });
      
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `whisperx-transcription-${Date.now()}.json`;
      a.click();
      
      URL.revokeObjectURL(url);
    }
  }
};
</script>

<style scoped>
.whisperx-subtitles {
  @apply space-y-4;
}

/* Enhancement Status */
.enhancement-status {
  @apply bg-gray-100 dark:bg-gray-800 rounded-lg p-3;
}

.status-indicators {
  @apply flex flex-wrap gap-2;
}

.status-badge {
  @apply inline-flex items-center px-3 py-1 rounded-full text-xs font-medium;
  @apply bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400;
  transition: all 0.2s ease;
}

.status-badge.active {
  @apply bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200;
}

.status-badge i {
  @apply mr-1;
}

/* Subtitle Container */
.subtitle-container {
  @apply relative min-h-[80px] flex items-center justify-center;
}

.current-subtitle {
  @apply bg-black bg-opacity-80 text-white rounded-lg p-4 text-center;
  @apply transition-all duration-300 ease-in-out;
}

.current-subtitle.enhanced-timing {
  @apply border-l-4 border-blue-400;
}

.current-subtitle.speaker-aware {
  @apply border-t-2 border-green-400;
}

/* Speaker Indicator */
.speaker-indicator {
  @apply inline-block px-2 py-1 rounded text-xs font-semibold mb-2;
  @apply bg-opacity-80 text-white;
}

.speaker-0 { @apply bg-blue-500; }
.speaker-1 { @apply bg-red-500; }
.speaker-2 { @apply bg-green-500; }
.speaker-3 { @apply bg-yellow-500; }
.speaker-4 { @apply bg-purple-500; }

/* Word Styling */
.subtitle-text {
  @apply text-lg leading-relaxed;
}

.subtitle-word {
  @apply inline-block mx-1 px-1 py-0.5 rounded cursor-pointer;
  @apply transition-all duration-200 ease-in-out;
  @apply opacity-70 hover:opacity-90;
}

.subtitle-word.active-word {
  @apply opacity-100 font-semibold bg-blue-500 bg-opacity-30;
  @apply transform scale-105;
}

.subtitle-word.enhanced-timing.active-word {
  @apply bg-blue-400 bg-opacity-40;
  animation: enhanced-highlight 0.3s ease-in-out;
}

.subtitle-word.high-confidence {
  @apply text-white;
}

.subtitle-word.medium-confidence {
  @apply text-gray-200;
}

.subtitle-word.low-confidence {
  @apply text-gray-400 underline decoration-dotted;
}

/* Timing Indicator */
.timing-indicator {
  @apply flex items-center justify-center mt-2 space-x-2;
}

.accuracy-bar {
  @apply w-24 h-2 bg-gray-600 rounded-full overflow-hidden;
}

.accuracy-fill {
  @apply h-full bg-gradient-to-r from-yellow-400 to-green-400;
  @apply transition-all duration-500 ease-out;
}

.accuracy-text {
  @apply text-xs text-gray-300;
}

/* Loading and Empty States */
.subtitle-loading,
.subtitle-empty {
  @apply flex items-center justify-center space-x-2;
  @apply text-gray-500 dark:text-gray-400;
}

.loading-spinner {
  @apply w-4 h-4 border-2 border-gray-300 border-t-blue-500 rounded-full;
  animation: spin 1s linear infinite;
}

/* Controls */
.subtitle-controls {
  @apply flex justify-center space-x-2;
}

.control-button {
  @apply inline-flex items-center px-3 py-2 rounded-md text-sm font-medium;
  @apply bg-gray-200 text-gray-700 hover:bg-gray-300;
  @apply dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600;
  @apply disabled:opacity-50 disabled:cursor-not-allowed;
  @apply transition-colors duration-200;
}

.control-button i {
  @apply mr-1;
}

/* Animations */
@keyframes enhanced-highlight {
  0% { background-color: rgba(59, 130, 246, 0.2); }
  50% { background-color: rgba(59, 130, 246, 0.6); }
  100% { background-color: rgba(59, 130, 246, 0.4); }
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Responsive Design */
@media (max-width: 640px) {
  .subtitle-text {
    @apply text-base;
  }
  
  .status-indicators {
    @apply justify-center;
  }
  
  .subtitle-controls {
    @apply flex-wrap;
  }
}
</style>
```

### 2. Speaker Legend Component

```vue
<template>
  <div class="speaker-legend">
    <div class="legend-header">
      <h4 class="legend-title">
        <i class="icon-users"></i>
        Speakers ({{ speakerInfo.detected_speakers }})
      </h4>
      <button 
        @click="toggleExpanded"
        class="expand-button"
        :class="{ expanded: isExpanded }"
      >
        <i class="icon-chevron-down"></i>
      </button>
    </div>
    
    <transition name="slide-down">
      <div v-if="isExpanded" class="legend-content">
        <!-- Speaker List -->
        <div class="speaker-list">
          <div
            v-for="(speaker, index) in speakerInfo.speaker_labels"
            :key="speaker"
            class="speaker-item"
            :class="{ 
              active: currentSpeaker === speaker,
              selected: selectedSpeakers.includes(speaker)
            }"
            @click="toggleSpeaker(speaker)"
          >
            <div class="speaker-visual">
              <div 
                class="speaker-color"
                :class="`speaker-${index}`"
              ></div>
              <div class="speaker-waveform">
                <div 
                  v-for="i in 5" 
                  :key="i"
                  class="waveform-bar"
                  :style="{ height: getWaveformHeight(speaker, i) }"
                ></div>
              </div>
            </div>
            
            <div class="speaker-info">
              <div class="speaker-name">
                {{ formatSpeakerName(speaker) }}
              </div>
              <div class="speaker-stats">
                <span class="stat-item">
                  {{ getSpeakerDuration(speaker) }}s
                </span>
                <span class="stat-item">
                  {{ getSpeakerPercentage(speaker) }}%
                </span>
              </div>
            </div>
            
            <div class="speaker-controls">
              <button 
                @click.stop="focusSpeaker(speaker)"
                class="focus-button"
                :title="`Focus on ${formatSpeakerName(speaker)}`"
              >
                <i class="icon-target"></i>
              </button>
            </div>
          </div>
        </div>
        
        <!-- Speaker Timeline -->
        <div class="speaker-timeline">
          <div class="timeline-header">
            <span class="timeline-title">Speaker Timeline</span>
            <span class="timeline-duration">{{ totalDuration }}s</span>
          </div>
          <div class="timeline-track">
            <div
              v-for="segment in speakerSegments"
              :key="segment.id"
              class="timeline-segment"
              :class="getSpeakerClass(segment.speaker)"
              :style="{
                left: segment.startPercent + '%',
                width: segment.widthPercent + '%'
              }"
              :title="getSegmentTooltip(segment)"
              @click="seekToSegment(segment)"
            ></div>
          </div>
        </div>
        
        <!-- Speaker Controls -->
        <div class="speaker-controls-panel">
          <button 
            @click="selectAllSpeakers"
            class="control-btn"
          >
            Select All
          </button>
          <button 
            @click="clearSelection"
            class="control-btn"
          >
            Clear
          </button>
          <button 
            @click="exportSpeakerData"
            class="control-btn"
          >
            Export
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
export default {
  name: 'SpeakerLegend',
  
  props: {
    speakerInfo: {
      type: Object,
      required: true
    },
    currentSpeaker: {
      type: String,
      default: null
    },
    segments: {
      type: Array,
      default: () => []
    }
  },
  
  data() {
    return {
      isExpanded: true,
      selectedSpeakers: [],
      speakerStats: {}
    };
  },
  
  computed: {
    speakerSegments() {
      return this.segments
        .filter(segment => segment.speaker)
        .map((segment, index) => ({
          id: index,
          speaker: segment.speaker,
          start: segment.start,
          end: segment.end,
          duration: segment.end - segment.start,
          startPercent: (segment.start / this.totalDuration) * 100,
          widthPercent: ((segment.end - segment.start) / this.totalDuration) * 100,
          text: segment.text
        }));
    },
    
    totalDuration() {
      if (this.segments.length === 0) return 0;
      return Math.max(...this.segments.map(s => s.end));
    }
  },
  
  mounted() {
    this.calculateSpeakerStats();
  },
  
  methods: {
    toggleExpanded() {
      this.isExpanded = !this.isExpanded;
    },
    
    toggleSpeaker(speaker) {
      const index = this.selectedSpeakers.indexOf(speaker);
      if (index > -1) {
        this.selectedSpeakers.splice(index, 1);
      } else {
        this.selectedSpeakers.push(speaker);
      }
      
      this.$emit('speaker-selected', {
        speaker,
        selected: this.selectedSpeakers.includes(speaker),
        allSelected: this.selectedSpeakers
      });
    },
    
    focusSpeaker(speaker) {
      this.$emit('speaker-focused', speaker);
    },
    
    formatSpeakerName(speaker) {
      return speaker.replace('SPEAKER_', 'Speaker ');
    },
    
    getSpeakerClass(speaker) {
      const index = this.speakerInfo.speaker_labels.indexOf(speaker);
      return `speaker-${index}`;
    },
    
    calculateSpeakerStats() {
      const stats = {};
      
      this.speakerInfo.speaker_labels.forEach(speaker => {
        const speakerSegments = this.segments.filter(s => s.speaker === speaker);
        const duration = speakerSegments.reduce((sum, s) => sum + (s.end - s.start), 0);
        const percentage = this.totalDuration > 0 ? (duration / this.totalDuration) * 100 : 0;
        
        stats[speaker] = {
          duration: Math.round(duration * 10) / 10,
          percentage: Math.round(percentage),
          segments: speakerSegments.length
        };
      });
      
      this.speakerStats = stats;
    },
    
    getSpeakerDuration(speaker) {
      return this.speakerStats[speaker]?.duration || 0;
    },
    
    getSpeakerPercentage(speaker) {
      return this.speakerStats[speaker]?.percentage || 0;
    },
    
    getWaveformHeight(speaker, index) {
      const base = 20;
      const variation = Math.sin(index * 0.5) * 10;
      const speakerMultiplier = this.getSpeakerPercentage(speaker) / 100;
      return `${base + variation * speakerMultiplier}px`;
    },
    
    getSegmentTooltip(segment) {
      return `${this.formatSpeakerName(segment.speaker)}\n${segment.start.toFixed(1)}s - ${segment.end.toFixed(1)}s\n"${segment.text.substring(0, 50)}..."`;
    },
    
    seekToSegment(segment) {
      this.$emit('seek-to-time', segment.start);
    },
    
    selectAllSpeakers() {
      this.selectedSpeakers = [...this.speakerInfo.speaker_labels];
      this.$emit('speakers-selected', this.selectedSpeakers);
    },
    
    clearSelection() {
      this.selectedSpeakers = [];
      this.$emit('speakers-selected', []);
    },
    
    exportSpeakerData() {
      const exportData = {
        speaker_info: this.speakerInfo,
        speaker_stats: this.speakerStats,
        speaker_timeline: this.speakerSegments,
        export_timestamp: new Date().toISOString()
      };
      
      this.$emit('export-speaker-data', exportData);
    }
  }
};
</script>

<style scoped>
.speaker-legend {
  @apply bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700;
  @apply shadow-sm;
}

.legend-header {
  @apply flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700;
}

.legend-title {
  @apply text-sm font-semibold text-gray-900 dark:text-white;
  @apply flex items-center space-x-2;
}

.expand-button {
  @apply p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700;
  @apply transition-all duration-200;
}

.expand-button.expanded {
  @apply transform rotate-180;
}

.legend-content {
  @apply p-4 space-y-4;
}

/* Speaker List */
.speaker-list {
  @apply space-y-2;
}

.speaker-item {
  @apply flex items-center space-x-3 p-3 rounded-lg;
  @apply bg-gray-50 dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600;
  @apply cursor-pointer transition-all duration-200;
}

.speaker-item.active {
  @apply ring-2 ring-blue-500 bg-blue-50 dark:bg-blue-900;
}

.speaker-item.selected {
  @apply bg-green-50 dark:bg-green-900;
}

.speaker-visual {
  @apply flex items-center space-x-2;
}

.speaker-color {
  @apply w-4 h-4 rounded-full;
}

.speaker-waveform {
  @apply flex items-end space-x-1;
}

.waveform-bar {
  @apply w-1 bg-current opacity-60 rounded-full;
  @apply transition-all duration-300;
}

.speaker-info {
  @apply flex-1;
}

.speaker-name {
  @apply font-medium text-gray-900 dark:text-white;
}

.speaker-stats {
  @apply flex space-x-3 text-xs text-gray-500 dark:text-gray-400;
}

.speaker-controls {
  @apply flex space-x-1;
}

.focus-button {
  @apply p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-600;
  @apply text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200;
}

/* Speaker Timeline */
.speaker-timeline {
  @apply space-y-2;
}

.timeline-header {
  @apply flex justify-between items-center text-sm;
}

.timeline-title {
  @apply font-medium text-gray-700 dark:text-gray-300;
}

.timeline-duration {
  @apply text-gray-500 dark:text-gray-400;
}

.timeline-track {
  @apply relative h-6 bg-gray-200 dark:bg-gray-600 rounded-full overflow-hidden;
}

.timeline-segment {
  @apply absolute top-0 h-full cursor-pointer;
  @apply hover:opacity-80 transition-opacity duration-200;
}

/* Speaker Controls Panel */
.speaker-controls-panel {
  @apply flex justify-center space-x-2 pt-2 border-t border-gray-200 dark:border-gray-700;
}

.control-btn {
  @apply px-3 py-1 text-xs font-medium rounded;
  @apply bg-gray-100 text-gray-700 hover:bg-gray-200;
  @apply dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600;
}

/* Transitions */
.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.3s ease;
}

.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

/* Speaker Colors */
.speaker-0 { @apply bg-blue-500; }
.speaker-1 { @apply bg-red-500; }
.speaker-2 { @apply bg-green-500; }
.speaker-3 { @apply bg-yellow-500; }
.speaker-4 { @apply bg-purple-500; }
</style>
```

### 3. Performance Metrics Component

```vue
<template>
  <div class="performance-metrics">
    <div class="metrics-header">
      <h4 class="metrics-title">
        <i class="icon-activity"></i>
        Performance Metrics
      </h4>
      <button 
        @click="refreshMetrics"
        class="refresh-button"
        :disabled="isRefreshing"
      >
        <i class="icon-refresh" :class="{ spinning: isRefreshing }"></i>
      </button>
    </div>
    
    <div class="metrics-grid">
      <!-- Processing Times -->
      <div class="metric-card">
        <div class="metric-header">
          <i class="icon-clock"></i>
          <span>Processing Times</span>
        </div>
        <div class="metric-content">
          <div class="time-breakdown">
            <div class="time-item">
              <span class="time-label">Transcription</span>
              <span class="time-value">{{ formatTime(processingTimes.transcription_seconds) }}</span>
              <div class="time-bar">
                <div 
                  class="time-fill transcription"
                  :style="{ width: getTimePercentage('transcription') + '%' }"
                ></div>
              </div>
            </div