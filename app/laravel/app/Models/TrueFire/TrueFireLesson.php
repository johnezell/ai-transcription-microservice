<?php

namespace App\Models\TrueFire;

use Illuminate\Database\Eloquent\Model;

/**
 * TrueFire Lesson Model
 * 
 * Represents individual lessons/videos within a TrueFire course.
 */
class TrueFireLesson extends Model
{
    protected $connection = 'truefire';
    protected $table = 'lessons';
    protected $guarded = [];
    
    public $timestamps = false;
    
    /**
     * Get the course this lesson belongs to.
     */
    public function course()
    {
        return $this->belongsTo(TrueFireCourse::class, 'course_id', 'id');
    }
}





