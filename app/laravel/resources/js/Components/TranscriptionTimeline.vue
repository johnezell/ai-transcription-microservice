<template>
  <div class="w-full">
    <h3 class="text-lg font-medium mb-4">Processing Timeline</h3>
    
    <!-- Timeline container -->
    <div class="flex">
      <!-- Vertical timeline -->
      <div class="relative mr-6">
        <!-- Progress bar (vertical) -->
        <div class="absolute left-6 top-0 h-full w-1 bg-gray-200">
          <div 
            class="absolute left-0 top-0 w-1 bg-blue-500 transition-all duration-500 ease-in-out"
            :style="{ height: `${progressPercentage}%` }"
          ></div>
        </div>
        
        <!-- Timeline steps (vertical) -->
        <div class="relative flex flex-col">
          <template v-for="(step, index) in steps" :key="step.id">
            <div class="flex mb-16 last:mb-0 items-start">
              <!-- Step circle -->
              <div 
                class="relative z-10 w-12 h-12 rounded-full flex items-center justify-center transition-all duration-300"
                :class="{
                  'bg-gray-200 text-gray-500': step.status === 'pending',
                  'bg-blue-100 text-blue-600 animate-pulse': step.status === 'active',
                  'bg-green-100 text-green-600': step.status === 'completed',
                  'bg-red-100 text-red-600': step.status === 'failed'
                }"
              >
                <svg v-if="step.status === 'pending'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <svg v-else-if="step.status === 'active'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
                <svg v-else-if="step.status === 'completed'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </div>
              
              <!-- Step label -->
              <div class="ml-4">
                <div class="text-sm font-medium">{{ step.label }}</div>
                <div 
                  v-if="step.timing" 
                  class="text-xs text-gray-500"
                  :title="step.timing.full"
                >
                  {{ step.timing.display }}
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>
      
      <!-- Current status message -->
      <div class="flex-1 p-3 rounded-md" :class="statusClass">
        <div class="font-medium">{{ currentStatusMessage }}</div>
        <div v-if="estimatedCompletion" class="text-sm mt-1">
          Estimated completion: {{ estimatedCompletion }}
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    status: {
      type: String,
      required: true
    },
    timing: {
      type: Object,
      default: () => ({})
    },
    progressPercentage: {
      type: Number,
      default: 0
    },
    error: {
      type: String,
      default: null
    },
    mediaDuration: {
      type: Number,
      default: 0
    }
  },
  
  computed: {
    steps() {
      const now = new Date();
      const timing = this.timing || {};
      
      return [
        {
          id: 'upload',
          label: 'Upload',
          status: 'completed',
          timing: this.formatTiming(null, timing.started_at)
        },
        {
          id: 'audio_extraction',
          label: 'Audio Extraction',
          status: this.getStepStatus('audio_extraction'),
          timing: this.formatTiming(
            timing.audio_extraction_started_at, 
            timing.audio_extraction_completed_at,
            timing.audio_extraction_duration_seconds
          )
        },
        {
          id: 'transcription',
          label: 'Transcription',
          status: this.getStepStatus('transcription'),
          timing: this.formatTiming(
            timing.transcription_started_at, 
            timing.transcription_completed_at,
            timing.transcription_duration_seconds
          )
        },
        {
          id: 'complete',
          label: 'Complete',
          status: this.status === 'completed' ? 'completed' : 
                 (this.status === 'failed' ? 'failed' : 'pending'),
          timing: this.formatTiming(null, timing.completed_at)
        }
      ];
    },
    
    currentStatusMessage() {
      if (this.error) {
        return `Error: ${this.error}`;
      }
      
      switch(this.status) {
        case 'uploaded':
          return 'Video uploaded and ready for processing';
        case 'processing':
          return 'Extracting audio from video...';
        case 'transcribing':
          return 'Transcribing audio...';
        case 'completed':
          return 'Transcription completed successfully';
        case 'failed':
          return 'Processing failed';
        default:
          return 'Unknown status';
      }
    },
    
    statusClass() {
      switch(this.status) {
        case 'uploaded':
          return 'bg-gray-100 text-gray-800';
        case 'processing':
        case 'transcribing':
          return 'bg-blue-50 text-blue-800';
        case 'completed':
          return 'bg-green-50 text-green-800';
        case 'failed':
          return 'bg-red-50 text-red-800';
        default:
          return 'bg-gray-100 text-gray-800';
      }
    },
    
    estimatedCompletion() {
      if (this.status !== 'processing' && this.status !== 'transcribing') {
        return null;
      }
      
      if (!this.mediaDuration) {
        return 'Calculating...';
      }
      
      // Estimate based on media duration
      let estimatedRemainingSeconds = 0;
      
      if (this.status === 'processing') {
        // Audio extraction typically takes ~10% of media duration
        estimatedRemainingSeconds = this.mediaDuration * 0.1;
      } else if (this.status === 'transcribing') {
        // Transcription typically takes ~2x media duration
        estimatedRemainingSeconds = this.mediaDuration * 2;
      }
      
      if (estimatedRemainingSeconds < 60) {
        return 'Less than a minute';
      }
      
      const minutes = Math.round(estimatedRemainingSeconds / 60);
      return `About ${minutes} minute${minutes !== 1 ? 's' : ''}`;
    }
  },
  
  methods: {
    getStepStatus(stepId) {
      if (this.status === 'failed') {
        // If failed and we have timing info for this step, it was processing when failed
        const startKey = `${stepId}_started_at`;
        const endKey = `${stepId}_completed_at`;
        
        if (this.timing && this.timing[startKey] && !this.timing[endKey]) {
          return 'failed';
        }
      }
      
      if (stepId === 'audio_extraction') {
        if (this.status === 'processing') {
          return 'active';
        }
        if (['transcribing', 'completed'].includes(this.status)) {
          return 'completed';
        }
      }
      
      if (stepId === 'transcription') {
        if (this.status === 'transcribing') {
          return 'active';
        }
        if (this.status === 'completed') {
          return 'completed';
        }
      }
      
      // Default status based on current position in workflow
      const workflowOrder = ['uploaded', 'processing', 'transcribing', 'completed'];
      const stepOrder = ['upload', 'audio_extraction', 'transcription', 'complete'];
      
      const currentPosition = workflowOrder.indexOf(this.status);
      const stepPosition = stepOrder.indexOf(stepId);
      
      if (currentPosition >= 0 && stepPosition >= 0) {
        if (stepPosition < currentPosition) {
          return 'completed';
        }
        if (stepPosition === currentPosition) {
          return 'active';
        }
      }
      
      return 'pending';
    },
    
    formatTiming(startTime, endTime, durationSeconds) {
      if (!startTime && !endTime) {
        return null;
      }
      
      const formatDate = (dateString) => {
        if (!dateString) return null;
        const date = new Date(dateString);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      };
      
      const start = formatDate(startTime);
      const end = formatDate(endTime);
      
      let display = '';
      let full = '';
      
      if (start && end) {
        display = `${start} - ${end}`;
        
        // If we have duration in seconds
        if (durationSeconds) {
          // Format as m:ss
          const minutes = Math.floor(durationSeconds / 60);
          const seconds = Math.floor(durationSeconds % 60);
          const formattedDuration = `${minutes}:${seconds.toString().padStart(2, '0')}`;
          
          display = `${formattedDuration}`;
          full = `Started: ${start}, Completed: ${end}, Duration: ${formattedDuration}`;
        } else {
          full = `Started: ${start}, Completed: ${end}`;
        }
      } else if (start) {
        display = `Started ${start}`;
        full = `Started: ${start}`;
      } else if (end) {
        display = `Completed ${end}`;
        full = `Completed: ${end}`;
      }
      
      return { display, full };
    }
  }
}
</script> 