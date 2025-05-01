<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Models\TermCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TerminologyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categories = TermCategory::with(['terms' => function($query) {
            $query->orderBy('term');
        }])
        ->orderBy('display_order')
        ->orderBy('name')
        ->get();
        
        return Inertia::render('Admin/Terminology/Index', [
            'categories' => $categories
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function createCategory()
    {
        return Inertia::render('Admin/Terminology/CreateCategory');
    }

    /**
     * Store a newly created category in storage.
     */
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color_class' => 'required|string|max:50',
        ]);
        
        // Generate slug from name
        $validated['slug'] = Str::slug($validated['name']);
        
        // Check for existing slug
        $count = 0;
        $originalSlug = $validated['slug'];
        while (TermCategory::where('slug', $validated['slug'])->exists()) {
            $count++;
            $validated['slug'] = $originalSlug . '-' . $count;
        }
        
        $validated['active'] = true;
        $validated['display_order'] = TermCategory::max('display_order') + 1;
        
        $category = TermCategory::create($validated);
        
        return redirect()->route('admin.terminology.index')
            ->with('success', 'Category created successfully');
    }

    /**
     * Show the form for editing a category.
     */
    public function editCategory(string $id)
    {
        $category = TermCategory::findOrFail($id);
        
        return Inertia::render('Admin/Terminology/EditCategory', [
            'category' => $category
        ]);
    }

    /**
     * Update the specified category in storage.
     */
    public function updateCategory(Request $request, string $id)
    {
        $category = TermCategory::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color_class' => 'required|string|max:50',
            'active' => 'boolean',
            'display_order' => 'integer',
        ]);
        
        // Only update the slug if the name changed
        if ($request->name !== $category->name) {
            // Generate slug from name
            $validated['slug'] = Str::slug($validated['name']);
            
            // Check for existing slug
            $count = 0;
            $originalSlug = $validated['slug'];
            while (TermCategory::where('slug', $validated['slug'])
                ->where('id', '!=', $id)
                ->exists()) {
                $count++;
                $validated['slug'] = $originalSlug . '-' . $count;
            }
        }
        
        $category->update($validated);
        
        return redirect()->route('admin.terminology.index')
            ->with('success', 'Category updated successfully');
    }

    /**
     * Create a new term.
     */
    public function createTerm()
    {
        $categories = TermCategory::where('active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
            
        return Inertia::render('Admin/Terminology/CreateTerm', [
            'categories' => $categories
        ]);
    }

    /**
     * Store a newly created term in storage.
     */
    public function storeTerm(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:term_categories,id',
            'term' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request) {
                    // Check for uniqueness within the category
                    $exists = Term::where('category_id', $request->category_id)
                        ->where('term', $value)
                        ->exists();
                        
                    if ($exists) {
                        $fail('This term already exists in the selected category.');
                    }
                },
            ],
            'description' => 'nullable|string',
        ]);
        
        $validated['active'] = true;
        
        $term = Term::create($validated);
        
        return redirect()->route('admin.terminology.index')
            ->with('success', 'Term added successfully');
    }

    /**
     * Update a term.
     */
    public function updateTerm(Request $request, string $id)
    {
        $term = Term::findOrFail($id);
        
        $validated = $request->validate([
            'category_id' => 'required|exists:term_categories,id',
            'term' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request, $term) {
                    // Check for uniqueness within the category, excluding this term
                    $exists = Term::where('category_id', $request->category_id)
                        ->where('term', $value)
                        ->where('id', '!=', $term->id)
                        ->exists();
                        
                    if ($exists) {
                        $fail('This term already exists in the selected category.');
                    }
                },
            ],
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);
        
        $term->update($validated);
        
        return redirect()->route('admin.terminology.index')
            ->with('success', 'Term updated successfully');
    }

    /**
     * Remove the specified term.
     */
    public function destroyTerm(string $id)
    {
        $term = Term::findOrFail($id);
        $term->delete();
        
        return redirect()->route('admin.terminology.index')
            ->with('success', 'Term deleted successfully');
    }

    /**
     * Remove the specified category and all its terms.
     */
    public function destroyCategory(string $id)
    {
        $category = TermCategory::findOrFail($id);
        
        // The terms will be deleted automatically due to onDelete('cascade')
        $category->delete();
        
        return redirect()->route('admin.terminology.index')
            ->with('success', 'Category and all its terms deleted successfully');
    }

    /**
     * Export all terms as JSON.
     */
    public function export()
    {
        $categories = TermCategory::where('active', true)
            ->with(['activeTerms' => function($query) {
                $query->orderBy('term');
            }])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
            
        $result = [];
        
        foreach ($categories as $category) {
            $result[$category->slug] = $category->activeTerms->pluck('term')->toArray();
        }
        
        return response()->json($result);
    }
    
    /**
     * Show form for importing categories and terms from JSON.
     */
    public function importForm()
    {
        return Inertia::render('Admin/Terminology/Import');
    }
    
    /**
     * Import categories and terms from JSON.
     */
    public function import(Request $request)
    {
        $request->validate([
            'json_data' => 'required|string',
            'mode' => 'required|in:replace,merge',
        ]);
        
        try {
            // Parse JSON data
            $jsonData = json_decode($request->json_data, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return redirect()->back()->with('error', 'Invalid JSON format: ' . json_last_error_msg());
            }
            
            // Begin transaction
            DB::beginTransaction();
            
            // If in replace mode, delete all existing categories (will cascade to terms)
            if ($request->mode === 'replace') {
                TermCategory::truncate();
            }
            
            $stats = [
                'categories_added' => 0,
                'categories_updated' => 0,
                'terms_added' => 0,
                'terms_deleted' => 0,
            ];
            
            $displayOrder = TermCategory::max('display_order') ?? 0;
            
            // Process each category in the JSON
            foreach ($jsonData as $categorySlug => $terms) {
                // Skip if the category key is not a string
                if (!is_string($categorySlug)) {
                    continue;
                }
                
                // Skip if terms is not an array
                if (!is_array($terms)) {
                    continue;
                }
                
                // Format the category name from the slug
                $categoryName = ucwords(str_replace('_', ' ', $categorySlug));
                
                // Find or create the category
                $category = TermCategory::firstOrNew(['slug' => $categorySlug]);
                $isNewCategory = !$category->exists;
                
                if ($isNewCategory) {
                    $category->name = $categoryName;
                    $category->color_class = $this->getDefaultColorForSlug($categorySlug);
                    $category->active = true;
                    $displayOrder++;
                    $category->display_order = $displayOrder;
                    $stats['categories_added']++;
                } else {
                    $stats['categories_updated']++;
                }
                
                // Save the category
                $category->save();
                
                // If in replace mode, remove all existing terms for this category
                if ($request->mode === 'replace') {
                    $termCount = $category->terms()->count();
                    $category->terms()->delete();
                    $stats['terms_deleted'] += $termCount;
                }
                
                // Add the new terms
                foreach ($terms as $term) {
                    // Skip if the term is not a string
                    if (!is_string($term)) {
                        continue;
                    }
                    
                    // Add the term if it doesn't exist
                    if (!$category->terms()->where('term', $term)->exists()) {
                        $category->terms()->create([
                            'term' => $term,
                            'active' => true,
                        ]);
                        $stats['terms_added']++;
                    }
                }
            }
            
            DB::commit();
            
            return redirect()->route('admin.terminology.index')
                ->with('success', "Import completed successfully: {$stats['categories_added']} categories added, {$stats['categories_updated']} categories updated, {$stats['terms_added']} terms added, {$stats['terms_deleted']} terms deleted.");
            
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Error importing data: ' . $e->getMessage());
        }
    }
    
    /**
     * Get a default color for a category based on its slug.
     */
    private function getDefaultColorForSlug($slug)
    {
        $colors = ['blue', 'green', 'purple', 'orange', 'pink', 'indigo', 'cyan'];
        
        // Use a hash of the slug to pick a color
        $hashValue = crc32($slug);
        $colorIndex = abs($hashValue) % count($colors);
        
        return $colors[$colorIndex];
    }
}
