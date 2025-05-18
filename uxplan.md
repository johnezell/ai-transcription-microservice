# AWS Transcription Service UX Improvement Plan

## Project Alignment

This UX improvement plan aligns with the existing AWS Transcription Service infrastructure as documented in `AI_INSTRUCTIONS.md`. While enhancing the user experience, we will maintain compatibility with:

- **SQS-Based Communication System**: The existing queue-based architecture for video processing
- **S3 Storage Integration**: All file management using AWS S3 buckets
- **Auto-Scaling Services**: Enhanced transcription job configuration must work with current auto-scaling
- **Service Discovery**: Inter-service communication patterns already in place
- **IAM Roles & Security**: Permission models for accessing AWS services

Any infrastructure changes needed to support UX improvements will be properly documented and added to `AI_INSTRUCTIONS.md`.

## Current State Assessment

The application currently serves its core functionality but has a prototype-like feel with several UX issues:

- **Navigation**: Cluttered top bar with too many equal-weight options
- **Video Management**: Basic card layout without thumbnails or intuitive status indicators
- **Content Organization**: Limited filtering and sorting capabilities
- **User Flow**: Complex processes lack guided flows or progress indicators
- **Visual Design**: Inconsistent visual language and minimal feedback mechanisms
- **Mobile Experience**: Suboptimal responsive design for smaller screens
- **Authentication**: Inconsistent protection of routes and features
- **Configuration Options**: Lack of preset configurations for transcription jobs

## UX Improvement Goals

1. Create a more intuitive, hierarchical navigation structure
2. Enhance visual feedback and status communication
3. Improve content organization and discoverability
4. Streamline complex workflows with guided experiences
5. Apply consistent visual design language across the application
6. Optimize the experience across device sizes
7. Implement consistent authentication and user management
8. Add job preset configurations for customized transcription options

## Development & Testing Plan

This plan outlines the features we'll implement in order of priority. Rather than following a fixed timeline, we'll work collaboratively as an AI and developer/QA team until completion, focusing on delivering value incrementally.

### Priority 1: Core UI Improvements

#### Developer Tasks:
1. Redesign main navigation with clear hierarchy
   - Create sidebar navigation for admin functions
   - Organize content sections logically
   - Implement responsive behavior for mobile devices

2. Improve video card components
   - Add video thumbnail previews
   - Redesign status indicators with intuitive colors/icons
   - Reorganize card layout for better information hierarchy
   - Add hover states and transition animations

3. Create consistent button styles and action patterns
   - Primary/secondary/tertiary button hierarchy
   - Consistent positioning of action buttons
   - Add micro-interactions for user feedback

#### QA Testing Criteria:
- Navigation is accessible from all pages and device sizes
- All existing functionality remains accessible
- Cards clearly communicate video status at a glance
- UI elements respond to user interaction with appropriate feedback
- Layout adapts properly across breakpoints (mobile, tablet, desktop)

### Priority 2: Job Preset Management

#### Developer Tasks:
1. Create job preset management system
   - Admin interface for creating and managing presets
   - Preset selection UI for transcription process
   - Default preset settings

2. Implement preset selection in upload workflow
   - Add preset selection to the upload form
   - Display preset details 
   - Allow per-upload customization

3. Backend implementation
   - Create database models and migrations
   - Implement preset CRUD operations
   - Update transcription process to use preset settings

#### QA Testing Criteria:
- Job presets can be created, edited, and deleted by admins
- Appropriate presets are available during transcription
- Preset settings correctly affect transcription results
- User can select different presets during upload
- Default preset is automatically selected

### Priority 3: Workflow Improvements

#### Developer Tasks:
1. Implement filtering and sorting for video/course lists
   - Add search functionality
   - Create filter options (status, date, size, etc.)
   - Add sort controls

2. Create dashboard view for content overview
   - Recently uploaded videos
   - Processing status summary
   - Quick access to frequent actions

3. Improve upload and processing workflows
   - Multi-step upload wizard
   - Progress indicators for transcription
   - Improved feedback for processing stages
   - Add job preset selection to workflow

#### QA Testing Criteria:
- Filters correctly display matching content
- Search returns relevant results quickly
- Dashboard accurately reflects system status
- Upload process provides clear feedback at each step
- Users can easily track progress of long-running operations

### Priority 4: Enhanced Content Experience

#### Developer Tasks:
1. Implement improved transcript viewing
   - Synchronized highlighting with audio/video playback
   - Better terminology visualization
   - Interactive transcript navigation

2. Create course visualization improvements
   - Visual timeline of course content
   - Thumbnail preview grid for lessons
   - Progress tracking visuals

3. Add empty states and guidance
   - Custom illustrations for empty states
   - Contextual help tooltips
   - First-time user onboarding flows

#### QA Testing Criteria:
- Transcript follows along with video playback
- Terminology is clearly highlighted and explained
- Course structure is visually clear and navigable
- Empty states provide clear next actions
- New users can understand core functionality without training

### Priority 5: Performance & Polish

#### Developer Tasks:
1. Implement loading states
   - Skeleton screens for content loading
   - Optimistic UI updates for common actions
   - Background processing for large operations

2. Add visual polish
   - Consistent color system for status and actions
   - Refined typography hierarchy
   - Subtle animations and transitions

3. Optimize for performance
   - Lazy loading for video content
   - Pagination improvements
   - API request optimization

#### QA Testing Criteria:
- Application feels responsive even during data loading
- Visual hierarchy clearly communicates importance
- Animations enhance rather than hinder experience
- Performance is acceptable on target devices/connections
- Overall experience feels cohesive and professional

## Development Process

### Documentation and Reference Materials

- **AWS Infrastructure Reference**: The team will refer to `AI_INSTRUCTIONS.md` for details on AWS infrastructure, environments, and deployment processes.
- **Project Updates**: As UX improvements are implemented, we will update the `AI_INSTRUCTIONS.md` file with relevant changes to maintain accurate documentation.
- **Technical Requirements**: We will ensure all UX improvements align with the existing AWS infrastructure outlined in `AI_INSTRUCTIONS.md`, particularly for aspects like S3 integration, SQS-based communication, and service scaling.

### Progress Tracking

Instead of time-based milestones, we'll track progress by:
- Completing individual checklist items for each feature
- Conducting regular QA reviews after each implementation
- Updating documentation to reflect changes
- Capturing user feedback on completed features

### Collaboration Process

#### AI-Developer Interaction:
- AI provides implementation details and recommendations
- Developer implements features and provides feedback
- AI assists with troubleshooting and optimizations
- Both maintain and update documentation

#### QA Testing:
- QA will test on multiple devices (mobile, tablet, desktop)
- Focus testing on real user workflows, not isolated features
- Capture screenshots/recordings of issues
- Document issues with clear reproduction steps

### User Feedback
- Collect feedback from representative users after implementing each priority area
- Prioritize adjustments based on user pain points
- Track usability metrics:
  - Task completion rate
  - Time to complete common tasks
  - Error rate
  - User satisfaction score

## Definition of Done

A feature is considered complete when:
1. It passes all QA testing criteria
2. It works consistently across supported browsers/devices
3. It maintains or improves performance metrics
4. It follows accessibility best practices
5. It has been documented for future reference
6. Any necessary AWS infrastructure changes are documented in `AI_INSTRUCTIONS.md`

## Success Metrics

We'll evaluate the success of UX improvements by measuring:
1. Reduction in support requests related to UI confusion
2. Increase in user engagement metrics (uploads, transcriptions)
3. Improved completion rate for multi-step processes
4. Positive feedback from user testing sessions
5. Decreased time to complete common tasks

---

# Appendix A: Detailed Implementation Plans

The following implementation plans provide detailed technical specifications for key UX improvements. These plans should be implemented with consideration for the existing AWS infrastructure as documented in `AI_INSTRUCTIONS.md`.

When implementing these features, we need to ensure:

1. **AWS Integration**: All file operations use S3 with proper IAM permissions
2. **SQS Compatibility**: Any changes to job processing maintain compatibility with the SQS-based communication system
3. **Monitoring Continuity**: New features maintain or enhance existing CloudWatch metrics and dashboards
4. **Scaling Support**: UI improvements work with the auto-scaling architecture of the backend services
5. **Security Compliance**: All changes adhere to the IAM and security model in place

For all implementation plans, we will update `AI_INSTRUCTIONS.md` as needed to document any changes to the AWS infrastructure or service communication patterns.

## Video Card Component Redesign

### Current Issues
- Cards lack visual hierarchy
- Text-only status indicators aren't immediately recognizable
- Action buttons have equal visual weight
- No thumbnail preview of video content
- Limited feedback on hover/interaction

### Design Goals
- Create a more visual, scannable video card
- Provide clear status communication
- Prioritize actions by importance
- Improve information hierarchy

### Technical Implementation Details

#### 1. Component Structure
```vue
<VideoCard
  :video="video"
  :showThumbnail="true"
  :showCourseInfo="true"
  @view="handleView"
  @transcribe="handleTranscribe"
  @delete="handleDelete"
/>
```

#### 2. Thumbnail Generation
- Use ffmpeg on the backend to extract thumbnail from video
- Store thumbnails in S3 alongside the video
- Generate a default thumbnail with video metadata if extraction fails
- Implement lazy loading for thumbnails

#### 3. Status Visualization
- Create a status badge component:
  - `completed`: Green checkmark icon + "Completed" text
  - `processing/is_processing`: Yellow circular progress + "Processing" text
  - `uploaded`: Blue upload icon + "Ready" text
  - `failed`: Red error icon + "Failed" text
- Add subtle background color to card based on status

#### 4. Layout Changes
```html
<div class="video-card">
  <!-- Thumbnail area with status overlay -->
  <div class="thumbnail-container">
    <img :src="thumbnailUrl" alt="Video thumbnail" class="thumbnail" />
    <StatusBadge :status="video.status" class="status-badge" />
  </div>
  
  <!-- Content area -->
  <div class="content">
    <h3 class="title">{{ video.original_filename }}</h3>
    <div class="metadata">
      <span>{{ formatFileSize(video.size_bytes) }}</span>
      <span>{{ formatDate(video.created_at) }}</span>
    </div>
    
    <!-- Course information if available -->
    <div v-if="video.course" class="course-info">
      <CourseIndicator :course="video.course" :lessonNumber="video.lesson_number" />
    </div>
    
    <!-- Feature badges -->
    <div class="features">
      <FeatureBadge v-if="video.transcript_path" type="transcript" />
      <FeatureBadge v-if="video.has_terminology" 
                   type="terminology" 
                   :count="video.terminology_count" />
    </div>
  </div>
  
  <!-- Action area -->
  <div class="actions">
    <PrimaryButton @click="$emit('view', video)">View</PrimaryButton>
    <SecondaryButton @click="$emit('transcribe', video)">Transcribe</SecondaryButton>
    <DangerButton @click="$emit('delete', video)" class="delete-btn">Delete</DangerButton>
  </div>
</div>
```

#### 5. Interaction Enhancements
- Add hover state to entire card (subtle elevation)
- Add transition animations:
  - Hover effect (150ms ease-in-out)
  - Status changes (300ms ease)
- Add loading state for actions
- Confirm dialogs for destructive actions

#### 6. Responsive Behavior
- Cards stack in a single column on mobile
- 2 columns on tablet
- 3+ columns on desktop
- Adjustable density setting (compact vs. comfortable)

### QA Checklist
- [ ] Thumbnails load correctly for various video formats
- [ ] Status badges accurately reflect current video state
- [ ] All metadata is correctly displayed
- [ ] Cards adapt appropriately to different screen sizes
- [ ] Action buttons trigger correct functions
- [ ] Hover/focus states work consistently across browsers
- [ ] Delete confirmation appears and functions correctly
- [ ] Keyboard navigation functions properly
- [ ] Animations don't cause layout shifts
- [ ] Fallback visual exists when thumbnails fail to load

## Navigation Redesign

### Current Issues
- All navigation items have equal visual weight
- Mobile navigation collapses to a basic hamburger menu
- No clear hierarchy between primary and secondary actions
- Admin functions mixed with content navigation
- Import sources dropdown doesn't provide clear context

### Design Goals
- Create clear distinction between content and admin sections
- Improve information hierarchy in navigation
- Enhance mobile navigation experience
- Provide contextual navigation based on current section

### Technical Implementation Details

#### 1. Component Structure
```vue
<template>
  <div>
    <!-- Top header - always visible -->
    <TopHeader
      :user="user"
      @toggle-sidebar="toggleSidebar"
      @search="handleSearch"
    />
    
    <!-- Side navigation -->
    <SideNavigation
      :isOpen="sidebarOpen"
      :currentRoute="currentRoute"
      :user="user"
      @close="closeSidebar"
    />
    
    <!-- Main content area -->
    <main :class="{ 'sidebar-open': sidebarOpen }">
      <!-- Page-specific subnavigation -->
      <SubNavigation 
        v-if="hasSubNavigation" 
        :items="subNavigationItems" 
      />
      
      <slot></slot>
    </main>
  </div>
</template>
```

#### 2. Navigation Hierarchy
- **Primary Navigation (Sidebar)**
  - Dashboard (Home/Overview)
  - Content
    - Videos
    - Courses
  - Import
    - TrueFire Courses
    - Channel Content
  - Admin
    - Terminology
    - Job Presets
    - Enhancement Ideas
  - User
    - Profile
    - Settings
    - Logout

#### 3. Top Header Implementation
```html
<header class="top-header">
  <!-- Left side: Logo and menu toggle -->
  <div class="left-section">
    <button class="menu-toggle" @click="$emit('toggle-sidebar')">
      <MenuIcon />
    </button>
    <Logo />
  </div>
  
  <!-- Center: Search -->
  <div class="center-section">
    <SearchBar @search="$emit('search', $event)" />
  </div>
  
  <!-- Right side: Quick actions and user menu -->
  <div class="right-section">
    <QuickActions />
    <UserMenu :user="user" />
  </div>
</header>
```

#### 4. Sidebar Implementation
```html
<aside :class="['sidebar', { 'open': isOpen }]">
  <!-- Close button for mobile -->
  <button class="close-btn" @click="$emit('close')">
    <CloseIcon />
  </button>
  
  <!-- Navigation sections -->
  <nav>
    <NavSection title="Main">
      <NavItem 
        icon="dashboard"
        label="Dashboard"
        :route="{ name: 'dashboard' }"
        :active="currentRoute === 'dashboard'"
      />
    </NavSection>
    
    <NavSection title="Content">
      <NavItem 
        icon="video"
        label="Videos"
        :route="{ name: 'videos.index' }"
        :active="currentRoute.startsWith('videos')"
      />
      <NavItem 
        icon="courses"
        label="Courses"
        :route="{ name: 'courses.index' }"
        :active="currentRoute.startsWith('courses')"
      />
    </NavSection>
    
    <NavSection title="Import">
      <NavItem 
        icon="truefire"
        label="TrueFire Courses"
        :route="{ name: 'truefire.index' }"
        :active="currentRoute.startsWith('truefire')"
      />
      <NavItem 
        icon="channels"
        label="Channel Content"
        :route="{ name: 'channels.index' }"
        :active="currentRoute.startsWith('channels')"
      />
    </NavSection>
    
    <NavSection title="Administration">
      <NavItem 
        icon="terminology"
        label="Terminology"
        :route="{ name: 'admin.terminology.index' }"
        :active="currentRoute.startsWith('admin.terminology')"
      />
      <NavItem 
        icon="preset"
        label="Job Presets"
        :route="{ name: 'admin.job-presets.index' }"
        :active="currentRoute.startsWith('admin.job-presets')"
      />
      <NavItem 
        icon="ideas"
        label="Enhancement Ideas"
        :route="{ name: 'enhancement-ideas.index' }"
        :active="currentRoute.startsWith('enhancement-ideas')"
      />
    </NavSection>
  </nav>
</aside>
```

#### 5. Responsive Behavior
- Desktop: 
  - Persistent sidebar (can be collapsed)
  - Full navigation visible
  - Hover states on menu items

- Tablet:
  - Collapsible sidebar (closed by default)
  - Full menu when opened, icon-only when collapsed
  - Top bar with essential actions always visible

- Mobile:
  - Slide-in sidebar covers most of screen
  - Detailed navigation with larger touch targets
  - Bottom navigation bar for most common actions

#### 6. Contextual Navigation
- Add contextual sub-navigation based on current section
- For example, when in Videos section:
  - "All Videos"
  - "Recently Uploaded"
  - "Processing"
  - "Completed"
  - "Failed"

### QA Checklist
- [ ] All navigation links work correctly
- [ ] Current page is clearly indicated
- [ ] Sidebar opens and closes smoothly on all devices
- [ ] Navigation is accessible via keyboard
- [ ] Touch targets are appropriately sized on mobile
- [ ] Navigation states persist correctly when navigating
- [ ] Top header remains accessible when scrolling
- [ ] Search functionality is accessible from all pages
- [ ] User menu displays correct user information
- [ ] Transitions and animations are smooth

## Video Upload and Transcription Workflow

### Current Issues
- Upload process lacks clear step indicators
- No visual feedback during file uploads
- Transcription process starts separately from upload
- Status updates are minimal during processing
- Error handling provides limited guidance
- No ability to select transcription settings/presets

### Design Goals
- Create a guided, step-by-step upload experience
- Provide clear visual feedback throughout the process
- Integrate upload and transcription into a single flow
- Communicate progress and status effectively
- Handle errors gracefully with actionable guidance
- Allow selection of transcription presets during upload

### Technical Implementation Details

#### 1. Component Structure
```vue
<template>
  <div class="upload-wizard">
    <!-- Stepper component -->
    <Stepper 
      :steps="steps" 
      :currentStep="currentStep" 
    />
    
    <!-- Step content -->
    <div class="step-content">
      <!-- Step 1: File Selection -->
      <FileUploadStep 
        v-if="currentStep === 1" 
        :maxFileSize="maxFileSize"
        :acceptedFormats="acceptedFormats"
        @filesSelected="handleFilesSelected"
        @continue="nextStep"
      />
      
      <!-- Step 2: File Details & Job Configuration -->
      <FileDetailsStep
        v-if="currentStep === 2" 
        :selectedFiles="selectedFiles"
        :courses="availableCourses"
        :jobPresets="availableJobPresets"
        @detailsUpdated="updateFileDetails"
        @presetSelected="updateJobPreset"
        @continue="nextStep"
        @back="previousStep"
      />
      
      <!-- Step 3: Upload Progress -->
      <UploadProgressStep
        v-if="currentStep === 3"
        :files="selectedFiles"
        :uploadProgress="uploadProgress"
        @uploadComplete="handleUploadComplete"
        @uploadError="handleUploadError"
        @continue="nextStep"
        @back="previousStep"
      />
      
      <!-- Step 4: Processing -->
      <ProcessingStep
        v-if="currentStep === 4"
        :files="uploadedFiles"
        :processingStatus="processingStatus"
        @processingComplete="handleProcessingComplete"
        @viewVideos="navigateToVideos"
        @uploadMore="resetWizard"
      />
    </div>
  </div>
</template>
```

#### 2. Upload Flow Steps

**Step 1: File Selection**
- Drag and drop area with visual cues
- File type validation with clear format indicators
- Size limit indicators
- Option to select multiple files
- Preview of selected files

**Step 2: File Details & Job Configuration**
- For each file:
  - Editable name field
  - Option to assign to course
  - Lesson number assignment (if part of a course)
  - Job preset selection dropdown
  - Add tags or description
- Batch editing options for multiple files
- Option to customize preset settings if needed

**Step 3: Upload Progress**
- Individual progress bar for each file
- Overall progress indicator
- Speed and time remaining estimates
- Option to cancel uploads in progress
- Background upload support (can navigate away)

**Step 4: Processing**
- Clear status indicators for each file:
  - Queued for processing
  - Extracting audio
  - Transcribing
  - Analyzing terminology
  - Completed
  - Failed (with reason)
- Estimated completion times
- Option to receive notification when complete

#### 3. Real-time Updates with WebSockets

```js
// Setup WebSocket connection for real-time updates
setupWebSocketConnection() {
  Echo.private(`user.${this.userId}`)
    .listen('VideoProcessingStatusUpdated', (e) => {
      this.updateProcessingStatus(e.videoId, e.status, e.progressData);
    })
    .listen('VideoProcessingComplete', (e) => {
      this.markProcessingComplete(e.videoId, e.videoData);
    })
    .listen('VideoProcessingFailed', (e) => {
      this.handleProcessingFailure(e.videoId, e.errorData);
    });
}

// Update the processing status for a specific video
updateProcessingStatus(videoId, status, progressData) {
  const index = this.uploadedFiles.findIndex(file => file.id === videoId);
  if (index !== -1) {
    this.uploadedFiles[index].status = status;
    this.uploadedFiles[index].progress = progressData.progress;
    this.uploadedFiles[index].statusMessage = progressData.message;
    this.uploadedFiles[index].estimatedTimeRemaining = progressData.estimatedTimeRemaining;
  }
}
```

#### 4. Error Handling and Recovery

```js
// Handle upload errors
handleUploadError(file, error) {
  // Update file status
  const index = this.selectedFiles.findIndex(f => f.tempId === file.tempId);
  if (index !== -1) {
    this.selectedFiles[index].status = 'error';
    this.selectedFiles[index].error = {
      code: error.code,
      message: this.getErrorMessage(error.code),
      details: error.details,
      recovery: this.getRecoveryOptions(error.code)
    };
  }
  
  // Log error for tracking
  this.logUploadError(file, error);
  
  // Show error notification
  this.showNotification({
    type: 'error',
    title: 'Upload Failed',
    message: this.getErrorMessage(error.code),
    action: {
      label: 'Retry',
      callback: () => this.retryUpload(file)
    }
  });
}

// Get user-friendly error message
getErrorMessage(errorCode) {
  const messages = {
    'network_error': 'Connection lost. Please check your internet connection.',
    'file_too_large': 'The file exceeds the maximum allowed size.',
    'invalid_format': 'The file format is not supported.',
    'server_error': 'The server encountered an error. Our team has been notified.',
    'storage_limit': 'You have reached your storage limit.'
  };
  
  return messages[errorCode] || 'An unexpected error occurred.';
}

// Get recovery options based on error type
getRecoveryOptions(errorCode) {
  const options = {
    'network_error': [
      { label: 'Retry Upload', action: 'retry' },
      { label: 'Try Smaller File', action: 'compress' }
    ],
    'file_too_large': [
      { label: 'Compress File', action: 'compress' },
      { label: 'Upgrade Storage', action: 'upgrade' }
    ],
    'storage_limit': [
      { label: 'Manage Storage', action: 'manage_storage' },
      { label: 'Upgrade Plan', action: 'upgrade' }
    ]
  };
  
  return options[errorCode] || [{ label: 'Try Again', action: 'retry' }];
}
```

#### 5. Progress Visualization

```html
<div class="processing-status">
  <div v-for="file in uploadedFiles" :key="file.id" class="file-status-card">
    <!-- File info -->
    <div class="file-info">
      <h4>{{ file.name }}</h4>
      <p>{{ formatFileSize(file.size) }}</p>
    </div>
    
    <!-- Status visualization -->
    <div class="status-visualization">
      <!-- Multi-step progress indicator -->
      <div class="progress-steps">
        <div 
          v-for="(step, index) in processingSteps" 
          :key="index"
          :class="['step', {
            'completed': getStepStatus(file, step) === 'completed',
            'current': getStepStatus(file, step) === 'current',
            'waiting': getStepStatus(file, step) === 'waiting',
            'error': getStepStatus(file, step) === 'error'
          }]"
        >
          <div class="step-icon">
            <CheckIcon v-if="getStepStatus(file, step) === 'completed'" />
            <SpinnerIcon v-else-if="getStepStatus(file, step) === 'current'" />
            <ErrorIcon v-else-if="getStepStatus(file, step) === 'error'" />
            <WaitingIcon v-else />
          </div>
          <div class="step-label">{{ step.label }}</div>
        </div>
      </div>
      
      <!-- Current step details -->
      <div class="current-step-details" v-if="file.currentStep">
        <h5>{{ getCurrentStepLabel(file) }}</h5>
        <ProgressBar 
          :progress="file.progress" 
          :status="file.status"
        />
        <p v-if="file.statusMessage" class="status-message">
          {{ file.statusMessage }}
        </p>
        <p v-if="file.estimatedTimeRemaining" class="time-remaining">
          {{ formatTimeRemaining(file.estimatedTimeRemaining) }}
        </p>
      </div>
    </div>
    
    <!-- Action buttons -->
    <div class="actions">
      <button 
        v-if="file.status === 'completed'"
        @click="viewVideo(file.id)"
        class="primary-button"
      >
        View Video
      </button>
      <button 
        v-if="file.status === 'error'"
        @click="retryProcessing(file.id)"
        class="secondary-button"
      >
        Retry
      </button>
    </div>
  </div>
</div>
```

#### 6. Backend Integration

- Update `VideoController` to support chunked uploads
- Add WebSocket events for real-time progress updates
- Create middleware for handling upload authentication
- Implement resumable uploads for better reliability
- Add detailed logging for troubleshooting

### QA Checklist
- [ ] Files can be added via drag & drop and file dialog
- [ ] Uploads work for all supported file types
- [ ] Multiple files can be uploaded simultaneously
- [ ] Progress indicators accurately show upload status
- [ ] File details can be edited before uploading
- [ ] Job presets can be selected during upload
- [ ] Processing status updates in real-time
- [ ] Error messages are clear and actionable
- [ ] Canceling an upload works properly
- [ ] Upload process can continue in background
- [ ] Interrupted uploads can be resumed
- [ ] Completion notifications work as expected
- [ ] Large files (>1GB) upload successfully
- [ ] Mobile experience works properly
- [ ] Keyboard navigation functions throughout the process

## Job Preset Management

### Current Issues
- No ability to create reusable transcription configuration presets
- Transcription settings are not customizable per job
- Lack of admin interface for managing configuration presets
- No default settings management

### Design Goals
- Create an intuitive interface for managing job presets
- Allow custom configuration per transcription job
- Provide sensible defaults with clear descriptions
- Make preset selection simple during video upload

### Technical Implementation Details

#### 1. Component Structure
```vue
<template>
  <div class="job-presets-manager">
    <!-- Presets list sidebar -->
    <div class="presets-sidebar">
      <div class="sidebar-header">
        <h2>Job Presets</h2>
        <button @click="createNewPreset" class="primary-button">
          <PlusIcon /> New Preset
        </button>
      </div>
      
      <div class="presets-list">
        <PresetListItem 
          v-for="preset in presets" 
          :key="preset.id"
          :preset="preset"
          :isActive="activePresetId === preset.id"
          @click="selectPreset(preset.id)"
        />
      </div>
    </div>
    
    <!-- Preset editor -->
    <div class="preset-editor">
      <template v-if="activePreset">
        <div class="editor-header">
          <h3>
            <input 
              v-if="isEditing" 
              v-model="editingPreset.name" 
              type="text" 
              class="preset-name-input"
              placeholder="Preset Name"
            />
            <span v-else>{{ activePreset.name }}</span>
          </h3>
          
          <div class="editor-actions">
            <button 
              v-if="isEditing" 
              @click="savePreset" 
              class="primary-button"
            >
              Save
            </button>
            <button 
              v-if="isEditing" 
              @click="cancelEditing" 
              class="secondary-button"
            >
              Cancel
            </button>
            <button 
              v-if="!isEditing" 
              @click="startEditing" 
              class="secondary-button"
            >
              Edit
            </button>
            <button 
              v-if="!isEditing && !activePreset.is_default" 
              @click="setAsDefault" 
              class="secondary-button"
              :class="{ 'is-active': activePreset.is_default }"
            >
              Set as Default
            </button>
            <button 
              v-if="!isEditing && !activePreset.is_default" 
              @click="confirmDelete" 
              class="danger-button"
            >
              Delete
            </button>
          </div>
        </div>
        
        <div class="preset-form">
          <!-- Preset configuration options -->
          <div class="form-section">
            <h4>Transcription Settings</h4>
            
            <div class="form-group">
              <label>Language</label>
              <select 
                v-model="editingPreset.language" 
                :disabled="!isEditing"
              >
                <option value="en-US">English (US)</option>
                <option value="en-GB">English (UK)</option>
                <option value="es-ES">Spanish</option>
                <option value="fr-FR">French</option>
                <!-- Additional languages -->
              </select>
            </div>
            
            <div class="form-group">
              <label>Speaker Identification</label>
              <toggle-switch 
                v-model="editingPreset.speaker_identification" 
                :disabled="!isEditing"
              />
              <p class="help-text">Identify different speakers in the transcript</p>
            </div>
            
            <div class="form-group">
              <label>Maximum Speakers</label>
              <input 
                type="number" 
                v-model="editingPreset.max_speakers" 
                min="2" 
                max="10" 
                :disabled="!isEditing || !editingPreset.speaker_identification"
              />
            </div>
            
            <div class="form-group">
              <label>Vocabulary Filter</label>
              <select 
                v-model="editingPreset.vocabulary_filter_method" 
                :disabled="!isEditing"
              >
                <option value="none">None</option>
                <option value="mask">Mask</option>
                <option value="remove">Remove</option>
              </select>
            </div>
          </div>
          
          <div class="form-section">
            <h4>Post-Processing</h4>
            
            <div class="form-group">
              <label>Auto-Generate Subtitles</label>
              <toggle-switch 
                v-model="editingPreset.generate_subtitles" 
                :disabled="!isEditing"
              />
            </div>
            
            <div class="form-group">
              <label>Terminology Recognition</label>
              <toggle-switch 
                v-model="editingPreset.terminology_recognition" 
                :disabled="!isEditing"
              />
              <p class="help-text">Identify music terminology in transcripts</p>
            </div>
            
            <div class="form-group">
              <label>Content Categories</label>
              <multi-select 
                v-model="editingPreset.content_categories" 
                :options="availableCategories"
                :disabled="!isEditing"
              />
            </div>
          </div>
        </div>
      </template>
      
      <div v-else class="empty-state">
        <p>Select a preset to view or edit its settings</p>
      </div>
    </div>
  </div>
</template>
```

#### 2. Preset Data Model

```javascript
// Preset object structure
const presetModel = {
  id: null,
  name: '',
  description: '',
  is_default: false,
  created_at: null,
  updated_at: null,
  
  // AWS Transcribe settings
  language: 'en-US',
  speaker_identification: false,
  max_speakers: 2,
  vocabulary_filter_method: 'none',
  custom_vocabulary: [],
  
  // Post-processing settings
  generate_subtitles: true,
  terminology_recognition: true,
  content_categories: [],
  
  // Additional metadata
  created_by: null,
  last_updated_by: null
};
```

#### 3. Preset Selection during Upload

```html
<!-- Job Preset Selection component in the upload flow -->
<div class="job-preset-selector">
  <h3>Transcription Settings</h3>
  
  <div class="preset-dropdown">
    <label>Job Preset</label>
    <select v-model="selectedPresetId" @change="handlePresetChange">
      <option 
        v-for="preset in availablePresets" 
        :key="preset.id" 
        :value="preset.id"
      >
        {{ preset.name }}
        <span v-if="preset.is_default"> (Default)</span>
      </option>
    </select>
  </div>
  
  <!-- Show settings preview -->
  <div v-if="selectedPreset" class="preset-preview">
    <h4>Selected Settings</h4>
    <div class="settings-grid">
      <div class="setting-item">
        <span class="setting-label">Language:</span>
        <span class="setting-value">{{ getLanguageLabel(selectedPreset.language) }}</span>
      </div>
      <div class="setting-item">
        <span class="setting-label">Speaker Identification:</span>
        <span class="setting-value">{{ selectedPreset.speaker_identification ? 'Enabled' : 'Disabled' }}</span>
      </div>
      <div class="setting-item">
        <span class="setting-label">Generate Subtitles:</span>
        <span class="setting-value">{{ selectedPreset.generate_subtitles ? 'Yes' : 'No' }}</span>
      </div>
      <div class="setting-item">
        <span class="setting-label">Terminology Recognition:</span>
        <span class="setting-value">{{ selectedPreset.terminology_recognition ? 'Enabled' : 'Disabled' }}</span>
      </div>
    </div>
    
    <button @click="showCustomizeModal" class="secondary-button">
      Customize for this upload
    </button>
  </div>
</div>
```

#### 4. Backend Integration

- Create `JobPresetController` for CRUD operations
- Add routes for preset management:
  - `GET /admin/job-presets` - List all presets
  - `POST /admin/job-presets` - Create new preset
  - `GET /admin/job-presets/{id}` - Get preset details
  - `PUT /admin/job-presets/{id}` - Update preset
  - `DELETE /admin/job-presets/{id}` - Delete preset
  - `POST /admin/job-presets/{id}/set-default` - Set as default
- Create migration for job_presets table
- Update video upload/transcription to store selected preset

#### 5. Validation and Security

- Add validation rules for preset settings
- Ensure admin-only access to preset management
- Add audit logging for preset changes
- Prevent deletion of in-use presets
- Create default system presets on installation

### QA Checklist
- [ ] All preset fields are correctly saved and loaded
- [ ] Default preset is automatically selected for new uploads
- [ ] Preset settings accurately affect transcription results
- [ ] Forms validate input properly
- [ ] UI updates immediately when presets are changed
- [ ] Presets can be created, edited, and deleted
- [ ] Setting a preset as default works correctly
- [ ] Custom per-upload settings override preset defaults
- [ ] Preset selection is preserved between sessions
- [ ] Admin-only access controls are enforced 