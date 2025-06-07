<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBatchTestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'quality_level' => ['required', Rule::in(['fast', 'balanced', 'high', 'premium'])],
            'segment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'segment_ids.*' => ['required', 'integer', 'exists:segments,id'],
            'concurrent_jobs' => ['nullable', 'integer', 'min:1', 'max:10'],
            'extraction_settings' => ['nullable', 'array'],
            'extraction_settings.enable_vad' => ['nullable', 'boolean'],
            'extraction_settings.enable_normalization' => ['nullable', 'boolean'],
            'extraction_settings.noise_reduction' => ['nullable', 'boolean'],
            'extraction_settings.custom_parameters' => ['nullable', 'array'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A batch name is required.',
            'name.max' => 'The batch name cannot exceed 255 characters.',
            'quality_level.required' => 'A quality level must be selected.',
            'quality_level.in' => 'The selected quality level is invalid.',
            'segment_ids.required' => 'At least one segment must be selected.',
            'segment_ids.min' => 'At least one segment must be selected.',
            'segment_ids.max' => 'Cannot process more than 100 segments in a single batch.',
            'segment_ids.*.exists' => 'One or more selected segments do not exist.',
            'concurrent_jobs.min' => 'At least 1 concurrent job is required.',
            'concurrent_jobs.max' => 'Cannot run more than 10 concurrent jobs.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'segment_ids' => 'segments',
            'concurrent_jobs' => 'concurrent jobs',
            'extraction_settings' => 'extraction settings',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default concurrent jobs if not provided
        if (!$this->has('concurrent_jobs') || $this->concurrent_jobs === null) {
            $this->merge([
                'concurrent_jobs' => 3
            ]);
        }

        // Ensure segment_ids is an array
        if ($this->has('segment_ids') && !is_array($this->segment_ids)) {
            $this->merge([
                'segment_ids' => explode(',', $this->segment_ids)
            ]);
        }

        // Clean up extraction settings
        if ($this->has('extraction_settings') && is_array($this->extraction_settings)) {
            $settings = array_filter($this->extraction_settings, function ($value) {
                return $value !== null && $value !== '';
            });
            
            $this->merge([
                'extraction_settings' => $settings
            ]);
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional validation logic
            $this->validateSegmentAccess($validator);
            $this->validateBatchSize($validator);
        });
    }

    /**
     * Validate that the user has access to the selected segments.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validateSegmentAccess($validator): void
    {
        if (!$this->has('segment_ids') || !is_array($this->segment_ids)) {
            return;
        }

        // Check if segments belong to the specified course (if provided)
        if ($this->route('truefireCourse')) {
            $courseId = $this->route('truefireCourse')->id;
            $validSegments = \App\Models\Segment::whereIn('id', $this->segment_ids)
                ->where('course_id', $courseId)
                ->count();

            if ($validSegments !== count($this->segment_ids)) {
                $validator->errors()->add('segment_ids', 'Some segments do not belong to the specified course.');
            }
        }
    }

    /**
     * Validate batch size based on user limits.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validateBatchSize($validator): void
    {
        if (!$this->has('segment_ids') || !is_array($this->segment_ids)) {
            return;
        }

        $segmentCount = count($this->segment_ids);
        $concurrentJobs = $this->concurrent_jobs ?? 3;

        // Check for reasonable batch size
        if ($segmentCount > 50 && $concurrentJobs > 5) {
            $validator->errors()->add('concurrent_jobs', 'For large batches (>50 segments), limit concurrent jobs to 5 or fewer.');
        }

        // Estimate processing time and warn if excessive
        $estimatedMinutes = ceil($segmentCount / $concurrentJobs) * 0.5; // Rough estimate
        if ($estimatedMinutes > 120) { // More than 2 hours
            $validator->errors()->add('segment_ids', 'This batch may take over 2 hours to complete. Consider reducing the number of segments.');
        }
    }

    /**
     * Get the validated data with additional processing.
     *
     * @return array
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        // Add computed fields
        $validated['total_segments'] = count($validated['segment_ids']);
        $validated['user_id'] = auth()->id();

        // Add course ID if available from route
        if ($this->route('truefireCourse')) {
            $validated['truefire_course_id'] = $this->route('truefireCourse')->id;
        }

        // Set default extraction settings if not provided
        if (empty($validated['extraction_settings'])) {
            $validated['extraction_settings'] = [
                'enable_vad' => true,
                'enable_normalization' => true,
                'noise_reduction' => false,
            ];
        }

        return $validated;
    }
}
