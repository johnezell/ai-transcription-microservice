# Course Audio Presets Saving Issue - Bug Analysis Report

## Executive Summary

**Issue**: Course Audio Presets are not saving to the local TrueFire courses table. When a user selects "premium" preset, it still shows as "balanced" on both the list view and the course detail page, indicating the selection is not being persisted to the database.

**Status**: Investigation Complete - Root Cause Identified
**Severity**: High - Core functionality not working as expected
**Impact**: Users cannot change audio extraction presets for TrueFire courses

## Current Implementation Analysis

### Database Schema

The system uses a **dual-table approach** for storing audio presets:

1. **Primary Table**: `course_audio_presets` (SQLite - local database)
   - `id` (primary key)
   - `truefire_course_id` (foreign key to local_truefire_courses.id)
   - `audio_extraction_preset` (enum: fast, balanced, high, premium)
   - `settings` (JSON)
   - `timestamps`
   - Unique constraint on `truefire_course_id`

2. **Legacy Column**: `local_truefire_courses.audio_extraction_preset`
   - Direct column on the courses table
   - Used as fallback when no preset exists in pivot table

### Data Flow Analysis

#### Frontend (Vue.js Components)

**CoursePresetManager.vue** (Lines 159-183):
```javascript
const savePreset = async () => {
    const response = await axios.put(`/truefire-courses/${props.courseId}/audio-preset`, {
        preset: selectedPreset.value
    });
    
    if (response.data.success) {
        currentPreset.value = selectedPreset.value;
        // Emits preset-updated event
    }
}
```

**TruefireCourses/Index.vue** (Lines 261-274):
```html
<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
      :class="preset-based-styling">
    {{ course.audio_extraction_preset || 'Not Set' }}
</span>
```

#### Backend API Endpoints

**Route Configuration** (web.php:109-112):
```php
Route::put('/truefire-courses/{truefireCourse}/audio-preset', [TruefireCourseController::class, 'setAudioPreset'])
Route::get('/truefire-courses/{truefireCourse}/audio-preset', [TruefireCourseController::class, 'getAudioPreset'])
```

**Controller Methods**:

1. **setAudioPreset()** (TruefireCourseController.php:2559-2615):
   ```php
   public function setAudioPreset(LocalTruefireCourse $truefireCourse, Request $request)
   {
       $validated = $request->validate([
           'preset' => 'required|string|in:fast,balanced,high,premium',
           'settings' => 'sometimes|array'
       ]);

       CourseAudioPreset::updateForCourse(
           $truefireCourse->id,
           $validated['preset'],
           $settings
       );
   }
   ```

2. **getAudioPreset()** (TruefireCourseController.php:2620-2649):
   ```php
   public function getAudioPreset(LocalTruefireCourse $truefireCourse)
   {
       $preset = CourseAudioPreset::getPresetForCourse($truefireCourse->id);
       $settings = CourseAudioPreset::getSettingsForCourse($truefireCourse->id);
   }
   ```

#### Model Logic

**CourseAudioPreset Model** (CourseAudioPreset.php:76-85):
```php
public static function updateForCourse(int $courseId, string $preset, array $settings = []): CourseAudioPreset
{
    $coursePreset = static::getOrCreateForCourse($courseId);
    $coursePreset->update([
        'audio_extraction_preset' => $preset,
        'settings' => array_merge($coursePreset->settings ?? [], $settings)
    ]);
    
    return $coursePreset;
}
```

**LocalTruefireCourse Model** (LocalTruefireCourse.php:128-139):
```php
public function getAudioExtractionPreset(): string
{
    // First check if there's a specific preset set via the pivot table
    $coursePreset = CourseAudioPreset::getPresetForCourse($this->id);
    
    if ($coursePreset) {
        return $coursePreset;
    }
    
    // Fall back to the course's default preset
    return $this->audio_extraction_preset ?? 'balanced';
}
```

## Root Cause Analysis

### Primary Issue: Data Display vs. Data Storage Mismatch

The investigation reveals a **fundamental disconnect** between:
1. **Where data is being saved** (course_audio_presets table)
2. **Where data is being displayed** (local_truefire_courses.audio_extraction_preset column)

### Specific Problems Identified

#### 1. Index Page Display Issue
**File**: `TruefireCourses/Index.vue` (Line 272)
```html
{{ course.audio_extraction_preset || 'Not Set' }}
```

**Problem**: The index page displays `course.audio_extraction_preset` directly from the `local_truefire_courses` table, but presets are saved to the `course_audio_presets` table.

**Expected Behavior**: Should display the preset from the relationship or use the `getAudioExtractionPreset()` method.

#### 2. Controller Index Method Issue
**File**: `TruefireCourseController.php` (Lines 30-77)

**Problem**: The `index()` method loads courses but doesn't eager-load the audio preset relationship or call the `getAudioExtractionPreset()` method.

**Current Code**:
```php
$query = LocalTruefireCourse::query();
// ... filtering logic
$courses = $query->paginate($perPage);
```

**Missing**: No relationship loading or preset resolution.

#### 3. Data Serialization Issue
**Problem**: When courses are serialized for the frontend, the `audio_extraction_preset` attribute comes from the database column, not the computed method.

### Secondary Issues

#### 4. Inconsistent Data Access Patterns
- **Save Operation**: Uses `CourseAudioPreset::updateForCourse()` → Saves to pivot table
- **Read Operation**: Uses direct column access → Reads from courses table
- **Fallback Logic**: Exists in model but not utilized in controllers

#### 5. Missing Relationship Utilization
The `LocalTruefireCourse` model has proper relationships defined:
```php
public function audioPreset() // HasOne relationship
public function currentAudioPreset() // Latest preset
```

But these relationships are not being used in the controllers or serialized for the frontend.

## Impact Assessment

### User Experience Impact
- **High**: Users see no visual feedback when changing presets
- **Confusing**: UI appears broken - selections don't persist
- **Workflow Disruption**: Cannot configure different quality levels per course

### Data Integrity Impact
- **Low**: Data is being saved correctly to the database
- **Architectural**: Inconsistent data access patterns
- **Maintenance**: Future developers may be confused by dual storage approach

### System Functionality Impact
- **Backend Processing**: Audio extraction likely works correctly (uses `getAudioExtractionPreset()`)
- **Frontend Display**: Completely broken for preset visualization
- **API Consistency**: GET/SET operations work but display is disconnected

## Recommended Fix Strategy

### Option 1: Fix Display Layer (Recommended)
**Approach**: Modify controllers and frontend to properly display saved presets

**Changes Required**:
1. Update `TruefireCourseController::index()` to include preset data
2. Modify frontend components to display the correct preset value
3. Ensure proper serialization of preset data

**Pros**: Minimal changes, preserves existing architecture
**Cons**: Maintains dual-table complexity

### Option 2: Consolidate to Single Table
**Approach**: Use only the `course_audio_presets` table

**Changes Required**:
1. Remove `audio_extraction_preset` column from `local_truefire_courses`
2. Update all references to use relationship
3. Modify fallback logic

**Pros**: Cleaner architecture, single source of truth
**Cons**: More extensive changes, potential migration complexity

### Option 3: Consolidate to Course Column
**Approach**: Use only the `local_truefire_courses.audio_extraction_preset` column

**Changes Required**:
1. Update `setAudioPreset()` to save to course column
2. Remove `course_audio_presets` table usage
3. Simplify model methods

**Pros**: Simplest approach, direct column access
**Cons**: Loses settings flexibility, removes pivot table benefits

## Detailed Fix Implementation (Option 1)

### Step 1: Fix Controller Index Method
```php
// In TruefireCourseController::index()
$query->with('audioPreset'); // Eager load relationship

// Or add computed attribute to serialization
$courses->each(function ($course) {
    $course->current_audio_preset = $course->getAudioExtractionPreset();
});
```

### Step 2: Fix Frontend Display
```html
<!-- In TruefireCourses/Index.vue -->
{{ course.current_audio_preset || course.audio_extraction_preset || 'Not Set' }}
```

### Step 3: Add Proper Serialization
```php
// In LocalTruefireCourse model
protected $appends = ['current_audio_preset'];

public function getCurrentAudioPresetAttribute(): string
{
    return $this->getAudioExtractionPreset();
}
```

## Testing Strategy

### Manual Testing Steps
1. **Preset Setting**: Change preset via CoursePresetManager component
2. **Index Verification**: Check if new preset appears in course list
3. **Detail Verification**: Check if preset appears correctly in course detail
4. **Persistence**: Refresh page and verify preset persists
5. **Multiple Courses**: Test with different presets on different courses

### Automated Testing
1. **Feature Test**: Test preset save/retrieve API endpoints
2. **Unit Test**: Test model methods for preset resolution
3. **Browser Test**: Test full user workflow with Dusk

## Conclusion

The Course Audio Presets saving issue is **not a data persistence problem** but a **data display problem**. The backend correctly saves presets to the `course_audio_presets` table, but the frontend displays data from the `local_truefire_courses.audio_extraction_preset` column.

**Root Cause**: Architectural inconsistency between save and display operations.

**Recommended Solution**: Implement Option 1 (Fix Display Layer) to maintain existing architecture while ensuring proper data display.

**Priority**: High - This affects core user functionality and creates confusion about system reliability.

**Estimated Fix Time**: 2-4 hours for implementation and testing.