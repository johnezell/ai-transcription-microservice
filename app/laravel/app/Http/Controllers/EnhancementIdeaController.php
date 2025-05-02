<?php

namespace App\Http\Controllers;

use App\Models\EnhancementIdea;
use App\Models\EnhancementComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EnhancementIdeaController extends Controller
{
    /**
     * Display a listing of enhancement ideas.
     */
    public function index()
    {
        $enhancementIdeas = EnhancementIdea::with(['user:id,name', 'rootComments' => function($query) {
            $query->with(['user:id,name', 'replies.user:id,name'])->latest();
        }])
        ->latest()
        ->get();
        
        return Inertia::render('EnhancementIdeas/Index', [
            'enhancementIdeas' => $enhancementIdeas,
            'isAuthenticated' => Auth::check()
        ]);
    }

    /**
     * Store a newly created enhancement idea.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'author_name' => 'nullable|string|max:255',
        ]);

        $enhancementIdea = EnhancementIdea::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'user_id' => Auth::id(), // Will be null for unauthenticated users
            'author_name' => $validated['author_name'] ?? 'Anonymous',
        ]);

        return redirect()->route('enhancement-ideas.index');
    }

    /**
     * Display the specified enhancement idea.
     */
    public function show(EnhancementIdea $enhancementIdea)
    {
        $enhancementIdea->load([
            'user:id,name', 
            'rootComments' => function($query) {
                $query->with(['user:id,name', 'replies.user:id,name'])->latest();
            }
        ]);
        
        return Inertia::render('EnhancementIdeas/Show', [
            'enhancementIdea' => $enhancementIdea,
            'isAuthenticated' => Auth::check()
        ]);
    }

    /**
     * Update the specified enhancement idea.
     */
    public function update(Request $request, EnhancementIdea $enhancementIdea)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'completed' => 'boolean',
            'author_name' => 'nullable|string|max:255',
        ]);

        $enhancementIdea->update($validated);

        if (isset($validated['completed'])) {
            if ($validated['completed']) {
                $enhancementIdea->markAsCompleted();
            } else {
                $enhancementIdea->markAsNotCompleted();
            }
        }

        return redirect()->back();
    }

    /**
     * Remove the specified enhancement idea.
     */
    public function destroy(EnhancementIdea $enhancementIdea)
    {
        $enhancementIdea->delete();

        return redirect()->route('enhancement-ideas.index');
    }

    /**
     * Add a comment to an enhancement idea.
     */
    public function addComment(Request $request, EnhancementIdea $enhancementIdea)
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'parent_id' => 'nullable|exists:enhancement_comments,id',
            'author_name' => 'nullable|string|max:255',
        ]);

        $comment = $enhancementIdea->comments()->create([
            'content' => $validated['content'],
            'user_id' => Auth::id(), // Will be null for unauthenticated users
            'author_name' => $validated['author_name'] ?? 'Anonymous',
            'parent_id' => $validated['parent_id'] ?? null,
        ]);

        return redirect()->back();
    }

    /**
     * Toggle the completion status of an enhancement idea.
     */
    public function toggleComplete(EnhancementIdea $enhancementIdea)
    {
        if ($enhancementIdea->completed) {
            $enhancementIdea->markAsNotCompleted();
        } else {
            $enhancementIdea->markAsCompleted();
        }

        return redirect()->back();
    }
} 