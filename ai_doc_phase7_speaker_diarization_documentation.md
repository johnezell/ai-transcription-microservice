# WhisperX Speaker Diarization Documentation
**Phase 7: Frontend Integration - Speaker Diarization Data Structure & Usage**

## Overview

WhisperX provides advanced speaker diarization capabilities that identify and label different speakers in audio content. This document details the speaker diarization data structure, usage patterns, and frontend integration guidelines.

## Speaker Diarization Data Structure

### Speaker Information Object

```json
{
  "speaker_info": {
    "detected_speakers": 2,
    "speaker_labels": ["SPEAKER_00", "SPEAKER_01"],
    "min_speakers_configured": 1,
    "max_speakers_configured": 3
  }
}
```

#### Fields Description

- **`detected_speakers`** (integer): Number of unique speakers identified in the audio
- **`speaker_labels`** (array): List of speaker identifiers used throughout the transcription
- **`min_speakers_configured`** (integer): Minimum speakers setting used for diarization
- **`max_speakers_configured`** (integer): Maximum speakers setting used for diarization

### Speaker-Labeled Segments

Each transcription segment includes speaker identification when diarization is enabled:

```json
{
  "start": 12.34,
  "end": 18.67,
  "text": "Welcome to this guitar lesson. Today we'll learn about chord progressions.",
  "speaker": "SPEAKER_00",
  "words": [
    {
      "word": "Welcome",
      "start": 12.34,
      "end": 12.78,
      "probability": 0.95,
      "speaker": "SPEAKER_00"
    },
    {
      "word": "to",
      "start": 12.79,
      "end": 12.89,
      "probability": 0.98,
      "speaker": "SPEAKER_00"
    }
  ]
}
```

### Diarization Metadata

Comprehensive diarization processing information:

```json
{
  "diarization_metadata": {
    "min_speakers": 1,
    "max_speakers": 3,
    "detected_speakers": 2,
    "speaker_labels": ["SPEAKER_00", "SPEAKER_01"],
    "model_used": "pyannote/speaker-diarization",
    "processing_time": 12.3,
    "confidence_threshold": 0.7
  }
}
```

## Speaker Identification and Labeling Conventions

### Speaker Label Format

- **Pattern**: `SPEAKER_XX` where XX is a zero-padded number
- **Examples**: `SPEAKER_00`, `SPEAKER_01`, `SPEAKER_02`
- **Consistency**: Labels remain consistent throughout the entire transcription

### Speaker Assignment Logic

1. **Primary Speaker**: Usually `SPEAKER_00` (first detected speaker)
2. **Secondary Speakers**: `SPEAKER_01`, `SPEAKER_02`, etc.
3. **Unknown Speakers**: Segments without clear speaker assignment may lack speaker field

### Speaker Confidence and Quality

```json
{
  "speaker_confidence": {
    "SPEAKER_00": 0.92,
    "SPEAKER_01": 0.87
  },
  "speaker_duration": {
    "SPEAKER_00": 145.6,
    "SPEAKER_01": 89.3
  }
}
```

## Preset-Specific Speaker Diarization

### High Preset (medium model)
- **Diarization**: Enabled
- **Min Speakers**: 1
- **Max Speakers**: 3
- **Quality**: Good speaker separation

### Premium Preset (large-v3 model)
- **Diarization**: Enabled
- **Min Speakers**: 1
- **Max Speakers**: 5
- **Quality**: Excellent speaker separation and accuracy

### Fast/Balanced Presets
- **Diarization**: Disabled (for performance)
- **Speaker Info**: Not available

## Frontend Integration Guidelines

### Detecting Speaker Diarization

```javascript
// Check if speaker diarization is available
function hasSpeakerDiarization(response) {
  return response.speaker_info && 
         response.speaker_info.detected_speakers > 0 &&
         response.whisperx_processing.diarization === 'completed';
}

// Get speaker information
function getSpeakerInfo(response) {
  if (!hasSpeakerDiarization(response)) {
    return null;
  }
  
  return {
    speakers: response.speaker_info.speaker_labels,
    count: response.speaker_info.detected_speakers,
    segments: response.segments.filter(segment => segment.speaker)
  };
}
```

### Speaker-Aware UI Components

#### Speaker Legend Component

```javascript
// Vue.js Speaker Legend Component
const SpeakerLegend = {
  props: ['speakerInfo'],
  template: `
    <div class="speaker-legend" v-if="speakerInfo">
      <h4>Speakers ({{ speakerInfo.detected_speakers }})</h4>
      <div class="speaker-list">
        <div 
          v-for="speaker in speakerInfo.speaker_labels" 
          :key="speaker"
          class="speaker-item"
          :class="getSpeakerClass(speaker)"
        >
          <span class="speaker-indicator"></span>
          <span class="speaker-label">{{ formatSpeakerLabel(speaker) }}</span>
        </div>
      </div>
    </div>
  `,
  methods: {
    getSpeakerClass(speaker) {
      const index = this.speakerInfo.speaker_labels.indexOf(speaker);
      return `speaker-${index}`;
    },
    formatSpeakerLabel(speaker) {
      return speaker.replace('SPEAKER_', 'Speaker ');
    }
  }
};
```

#### Speaker-Aware Subtitle Display

```javascript
// Enhanced subtitle component with speaker awareness
const SpeakerSubtitles = {
  props: ['segments', 'currentTime', 'speakerInfo'],
  computed: {
    currentSegment() {
      return this.segments.find(segment => 
        this.currentTime >= segment.start && 
        this.currentTime <= segment.end
      );
    }
  },
  template: `
    <div class="speaker-subtitles">
      <div v-if="currentSegment" class="subtitle-container">
        <div 
          v-if="currentSegment.speaker" 
          class="speaker-indicator"
          :class="getSpeakerClass(currentSegment.speaker)"
        >
          {{ formatSpeakerLabel(currentSegment.speaker) }}
        </div>
        <div class="subtitle-text">
          {{ currentSegment.text }}
        </div>
      </div>
    </div>
  `,
  methods: {
    getSpeakerClass(speaker) {
      if (!this.speakerInfo) return '';
      const index = this.speakerInfo.speaker_labels.indexOf(speaker);
      return `speaker-${index}`;
    },
    formatSpeakerLabel(speaker) {
      return speaker.replace('SPEAKER_', 'Speaker ');
    }
  }
};
```

### Speaker Color Coding

```css
/* Speaker-specific color coding */
.speaker-0 {
  --speaker-color: #3b82f6; /* Blue */
}

.speaker-1 {
  --speaker-color: #ef4444; /* Red */
}

.speaker-2 {
  --speaker-color: #10b981; /* Green */
}

.speaker-3 {
  --speaker-color: #f59e0b; /* Amber */
}

.speaker-4 {
  --speaker-color: #8b5cf6; /* Purple */
}

.speaker-indicator {
  background-color: var(--speaker-color);
  color: white;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 600;
}

.speaker-subtitle {
  border-left: 4px solid var(--speaker-color);
  padding-left: 12px;
}
```

## Speaker Metadata and Confidence Information

### Speaker Statistics

```javascript
// Calculate speaker statistics
function calculateSpeakerStats(segments, speakerInfo) {
  const stats = {};
  
  speakerInfo.speaker_labels.forEach(speaker => {
    const speakerSegments = segments.filter(s => s.speaker === speaker);
    const totalDuration = speakerSegments.reduce((sum, s) => sum + (s.end - s.start), 0);
    const wordCount = speakerSegments.reduce((sum, s) => sum + (s.words?.length || 0), 0);
    
    stats[speaker] = {
      segments: speakerSegments.length,
      duration: totalDuration,
      words: wordCount,
      percentage: (totalDuration / getTotalDuration(segments)) * 100
    };
  });
  
  return stats;
}
```

### Speaker Confidence Visualization

```javascript
// Speaker confidence indicator component
const SpeakerConfidence = {
  props: ['speaker', 'confidence'],
  template: `
    <div class="speaker-confidence">
      <div class="confidence-bar">
        <div 
          class="confidence-fill"
          :style="{ width: confidence * 100 + '%' }"
        ></div>
      </div>
      <span class="confidence-text">{{ (confidence * 100).toFixed(0) }}%</span>
    </div>
  `
};
```

## Advanced Speaker Features

### Speaker Timeline Visualization

```javascript
// Speaker timeline component
const SpeakerTimeline = {
  props: ['segments', 'speakerInfo', 'duration'],
  computed: {
    timelineData() {
      return this.segments.map(segment => ({
        start: segment.start,
        end: segment.end,
        speaker: segment.speaker,
        percentage: {
          start: (segment.start / this.duration) * 100,
          width: ((segment.end - segment.start) / this.duration) * 100
        }
      }));
    }
  },
  template: `
    <div class="speaker-timeline">
      <div class="timeline-track">
        <div 
          v-for="segment in timelineData"
          :key="segment.start"
          class="timeline-segment"
          :class="getSpeakerClass(segment.speaker)"
          :style="{
            left: segment.percentage.start + '%',
            width: segment.percentage.width + '%'
          }"
          :title="formatTimeRange(segment.start, segment.end)"
        ></div>
      </div>
    </div>
  `
};
```

### Speaker Search and Filtering

```javascript
// Speaker filtering functionality
const SpeakerFilter = {
  data() {
    return {
      selectedSpeakers: []
    };
  },
  computed: {
    filteredSegments() {
      if (this.selectedSpeakers.length === 0) {
        return this.segments;
      }
      return this.segments.filter(segment => 
        this.selectedSpeakers.includes(segment.speaker)
      );
    }
  },
  methods: {
    toggleSpeaker(speaker) {
      const index = this.selectedSpeakers.indexOf(speaker);
      if (index > -1) {
        this.selectedSpeakers.splice(index, 1);
      } else {
        this.selectedSpeakers.push(speaker);
      }
    }
  }
};
```

## Error Handling and Fallbacks

### Diarization Failure Handling

```javascript
// Handle diarization failures gracefully
function handleDiarizationStatus(response) {
  const diarizationStatus = response.whisperx_processing?.diarization;
  
  switch (diarizationStatus) {
    case 'completed':
      return {
        available: true,
        speakers: response.speaker_info
      };
    
    case 'failed':
      return {
        available: false,
        error: response.diarization_metadata?.error,
        fallback: 'Single speaker assumed'
      };
    
    case 'skipped':
      return {
        available: false,
        reason: 'Diarization not enabled for this preset'
      };
    
    default:
      return {
        available: false,
        reason: 'Unknown diarization status'
      };
  }
}
```

### Progressive Enhancement

```javascript
// Progressive enhancement for speaker features
function enhanceWithSpeakerFeatures(transcriptionComponent, response) {
  const speakerStatus = handleDiarizationStatus(response);
  
  if (speakerStatus.available) {
    // Enable speaker-aware features
    transcriptionComponent.enableSpeakerMode(response.speaker_info);
    transcriptionComponent.showSpeakerLegend(true);
    transcriptionComponent.enableSpeakerFiltering(true);
  } else {
    // Fallback to basic transcription display
    transcriptionComponent.showSpeakerLegend(false);
    transcriptionComponent.enableSpeakerFiltering(false);
    
    if (speakerStatus.error) {
      transcriptionComponent.showWarning(`Speaker diarization failed: ${speakerStatus.error}`);
    }
  }
}
```

## Best Practices

### Performance Considerations

1. **Lazy Loading**: Load speaker features only when diarization data is available
2. **Caching**: Cache speaker statistics and timeline data
3. **Debouncing**: Debounce speaker filter changes to avoid excessive re-renders

### Accessibility

1. **Screen Readers**: Provide speaker information in accessible format
2. **Color Independence**: Don't rely solely on color for speaker differentiation
3. **Keyboard Navigation**: Ensure speaker controls are keyboard accessible

### User Experience

1. **Clear Labeling**: Use human-readable speaker labels (Speaker 1, Speaker 2)
2. **Visual Hierarchy**: Make speaker changes visually distinct
3. **Context**: Provide speaker statistics and timeline overview

---

**Generated**: 2025-06-09T11:34:18-04:00  
**Phase**: 7 - Frontend Integration  
**Version**: 1.0  
**Status**: Complete