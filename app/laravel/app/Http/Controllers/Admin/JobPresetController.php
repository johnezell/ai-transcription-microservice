<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TranscriptionPreset;
use App\Services\TranscriptionConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class JobPresetController extends Controller
{
    protected $configService;
    
    /**
     * Create a new controller instance.
     */
    public function __construct(TranscriptionConfigService $configService)
    {
        $this->configService = $configService;
    }
    
    /**
     * Display a listing of the transcription presets.
     */
    public function index()
    {
        $presets = TranscriptionPreset::orderBy('name')->get();
        
        return Inertia::render('Admin/JobPresets/Index', [
            'presets' => $presets
        ]);
    }

    /**
     * Show the form for creating a new preset.
     */
    public function create()
    {
        return Inertia::render('Admin/JobPresets/Create', [
            'modelOptions' => $this->configService->getModelOptions(),
            'languageOptions' => $this->configService->getLanguageOptions(),
            'transcriptionOptions' => $this->configService->getTranscriptionOptions(),
            'audioExtractionOptions' => $this->configService->getAudioExtractionOptions(),
        ]);
    }

    /**
     * Store a newly created preset.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:transcription_presets,name',
            'description' => 'nullable|string',
            'model' => 'required|string|in:base,small,medium,large,large-v2,large-v3',
            'language' => 'nullable|string|max:10',
            'configuration' => 'nullable|array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        // Ensure configuration exists
        if (!isset($validated['configuration'])) {
            $validated['configuration'] = TranscriptionPreset::getDefaultConfiguration();
        }

        // If this is the first preset or marked as default, 
        // make sure all other presets are not default
        if ($validated['is_default'] ?? false) {
            TranscriptionPreset::where('is_default', true)
                ->update(['is_default' => false]);
        }
        
        // Create the preset
        $preset = TranscriptionPreset::create($validated);
        
        // If no default preset exists and this is the first one, make it default
        if (TranscriptionPreset::count() === 1) {
            $preset->update(['is_default' => true]);
        }
        
        return redirect()->route('admin.job-presets.index')
            ->with('success', 'Transcription preset created successfully!');
    }

    /**
     * Show the form for editing the specified preset.
     */
    public function edit(TranscriptionPreset $preset)
    {
        return Inertia::render('Admin/JobPresets/Edit', [
            'preset' => $preset,
            'modelOptions' => $this->configService->getModelOptions(),
            'languageOptions' => $this->configService->getLanguageOptions(),
            'transcriptionOptions' => $this->configService->getTranscriptionOptions(),
            'audioExtractionOptions' => $this->configService->getAudioExtractionOptions(),
        ]);
    }

    /**
     * Update the specified preset.
     */
    public function update(Request $request, TranscriptionPreset $preset)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:transcription_presets,name,' . $preset->id,
            'description' => 'nullable|string',
            'model' => 'required|string|in:base,small,medium,large,large-v2,large-v3',
            'language' => 'nullable|string|max:10',
            'configuration' => 'nullable|array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        // If this preset is being set as default, 
        // make sure all other presets are not default
        if ($validated['is_default'] ?? false) {
            TranscriptionPreset::where('id', '!=', $preset->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
        
        // Update the preset
        $preset->update($validated);
        
        return redirect()->route('admin.job-presets.index')
            ->with('success', 'Transcription preset updated successfully!');
    }

    /**
     * Remove the specified preset.
     */
    public function destroy(TranscriptionPreset $preset)
    {
        try {
            // Don't allow deletion if this is the default preset
            if ($preset->is_default) {
                return redirect()->route('admin.job-presets.index')
                    ->with('error', 'Cannot delete the default preset. Please set another preset as default first.');
            }
            
            // Check if this preset is being used by any videos
            $videoCount = $preset->videos()->count();
            if ($videoCount > 0) {
                return redirect()->route('admin.job-presets.index')
                    ->with('error', "Cannot delete preset that is being used by {$videoCount} videos.");
            }
            
            // Delete the preset
            $preset->delete();
            
            return redirect()->route('admin.job-presets.index')
                ->with('success', 'Transcription preset deleted successfully!');
                
        } catch (\Exception $e) {
            Log::error('Error deleting preset', [
                'preset_id' => $preset->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('admin.job-presets.index')
                ->with('error', 'Error deleting preset: ' . $e->getMessage());
        }
    }
    
    /**
     * Set a preset as the default.
     */
    public function setDefault(TranscriptionPreset $preset)
    {
        try {
            // Update all presets to not be default
            TranscriptionPreset::where('is_default', true)
                ->update(['is_default' => false]);
            
            // Set this preset as default
            $preset->update(['is_default' => true]);
            
            return redirect()->route('admin.job-presets.index')
                ->with('success', 'Default preset updated successfully!');
                
        } catch (\Exception $e) {
            Log::error('Error setting default preset', [
                'preset_id' => $preset->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('admin.job-presets.index')
                ->with('error', 'Error setting default preset: ' . $e->getMessage());
        }
    }
} 