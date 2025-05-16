// TrueFire Course Routes
Route::get('/truefire', [App\Http\Controllers\TrueFireController::class, 'index'])->name('truefire.index');
Route::get('/truefire/selection', [App\Http\Controllers\TrueFireController::class, 'selection'])->name('truefire.selection');
Route::get('/truefire/{id}', [App\Http\Controllers\TrueFireController::class, 'show'])->name('truefire.show');
Route::post('/truefire/import/{lessonId}', [App\Http\Controllers\TrueFireController::class, 'importLesson'])->name('truefire.import.lesson');
Route::post('/truefire/import-bulk', [App\Http\Controllers\TrueFireController::class, 'importLessonsBulk'])->name('truefire.import.lessons.bulk');

// Channel Routes
Route::get('/channels', [App\Http\Controllers\ChannelController::class, 'index'])->name('channels.index');
Route::get('/channels/selection', [App\Http\Controllers\ChannelController::class, 'selection'])->name('channels.selection');
Route::get('/channels/{id}', [App\Http\Controllers\ChannelController::class, 'show'])->name('channels.show');
Route::get('/courses/{courseId}/segments', [App\Http\Controllers\ChannelController::class, 'getCourseSegments'])->name('channels.course.segments');
Route::get('/courses/segments', [App\Http\Controllers\ChannelController::class, 'getAllCoursesWithSegments'])->name('channels.all');
Route::get('/courses/segments/example', [App\Http\Controllers\ChannelController::class, 'runExampleQuery'])->name('channels.example');
Route::get('/courses/segments/nested', [App\Http\Controllers\ChannelController::class, 'getNestedStructure'])->name('channels.nested');
Route::post('/segments/import/{segmentId}', [App\Http\Controllers\ChannelController::class, 'importSegment'])->name('channels.import.segment');
Route::post('/segments/import-bulk', [App\Http\Controllers\ChannelController::class, 'importSegmentsBulk'])->name('channels.import.segments.bulk'); 