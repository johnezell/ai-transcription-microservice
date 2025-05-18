<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeveloperService
{
    /**
     * Constructor for DeveloperService.
     */
    public function __construct()
    {
        // You can inject dependencies here if needed
        // For example: MyModel $myModel
    }

    // \App\Services\DeveloperService::test();
    public static function test(): void
    {
       $courses = DB::connection('truefire')->select("SELECT count(*) FROM truefire.courses");
       dd($courses);
    }

    // Add your custom methods for one-off scripts and database tests below
    // For example:
    // public function fixUserStatuses()
    // {
    //     Log::info('[DeveloperService] Starting fixUserStatuses task...');
    //     // Your logic here
    //     Log::info('[DeveloperService] fixUserStatuses task completed.');
    // }
} 