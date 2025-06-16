<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PromptTemplateService;

$service = new PromptTemplateService();

$template = 'This is {{#course_title}}a {{course_title}} lesson{{/course_title}}{{#instructor_name}} taught by {{instructor_name}}{{/instructor_name}}.';
$context = [
    'course_title' => '1-2-3 Fingerstyle Guitar',
    'instructor_name' => 'Muriel Anderson'
];

echo "Template: " . $template . "\n";
echo "Context: " . json_encode($context) . "\n";

$result = $service->render($template, $context);
echo "Result: " . $result . "\n";

// Let's also test step by step
echo "\n--- Debug Info ---\n";

// Test simple variable replacement
$simple = '{{course_title}}';
$simpleResult = $service->render($simple, $context);
echo "Simple {{course_title}} -> '$simpleResult'\n";

// Test conditional block manually
$conditional = '{{#course_title}}a {{course_title}} lesson{{/course_title}}';
$conditionalResult = $service->render($conditional, $context);
echo "Conditional block -> '$conditionalResult'\n"; 