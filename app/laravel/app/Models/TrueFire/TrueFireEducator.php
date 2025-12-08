<?php

namespace App\Models\TrueFire;

use Illuminate\Database\Eloquent\Model;

/**
 * TrueFire Legacy Educator Model
 * 
 * Represents instructors/educators in the TrueFire platform.
 * 
 * Database: truefire.educators (or authors table - verify with schema)
 */
class TrueFireEducator extends Model
{
    protected $connection = 'truefire';
    protected $table = 'educators';
    protected $guarded = [];
    
    public $timestamps = false;
    
    /**
     * Get all courses by this educator.
     */
    public function courses()
    {
        return $this->hasMany(TrueFireCourse::class, 'educator_id', 'id');
    }
}
