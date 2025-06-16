<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PromptTemplateService;

$context = [
    'course_title' => '1-2-3 Fingerstyle Guitar',
    'instructor_name' => 'Muriel Anderson'
];

echo "=== DEBUGGING MUSTACHE TEMPLATE RENDERING ===\n";
echo "Context: " . json_encode($context) . "\n\n";

// Test the actual regex pattern
$template = '{{#course_title}}a {{course_title}} lesson{{/course_title}}';
echo "Template: $template\n\n";

echo "Testing the regex pattern:\n";
if (preg_match('/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s', $template, $matches)) {
    echo "Matches found:\n";
    echo "  Full match: '{$matches[0]}'\n";
    echo "  Variable (group 1): '{$matches[1]}'\n";
    echo "  Variable length: " . strlen($matches[1]) . "\n";
    echo "  Variable bytes: " . bin2hex($matches[1]) . "\n";
    echo "  Content (group 2): '{$matches[2]}'\n";
} else {
    echo "No matches found!\n";
}

echo "\nTesting string construction:\n";
$variable = 'course_title';
$search = "{{$variable}}";
echo "Variable: '$variable'\n";
echo "Search string: '$search'\n";
echo "Search string length: " . strlen($search) . "\n";

echo "\nTesting direct replacement:\n";
$content = 'a {{course_title}} lesson';
$result = str_replace('{{course_title}}', '1-2-3 Fingerstyle Guitar', $content);
echo "Direct replacement result: '$result'\n";

// Test 1: Simple conditional block step by step
$template = '{{#course_title}}a {{course_title}} lesson{{/course_title}}';
echo "1. Testing template: $template\n";

// Manually execute the regex that handles conditional blocks
$result = preg_replace_callback('/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s', function ($matches) use ($context) {
    $variable = $matches[1];
    $content = $matches[2];
    
    echo "   - Variable: '$variable'\n";
    echo "   - Content: '$content'\n";
    echo "   - Context value: '" . ($context[$variable] ?? 'NOT FOUND') . "'\n";
    echo "   - Context value length: " . strlen($context[$variable] ?? '') . "\n";
    echo "   - Context value bytes: " . bin2hex($context[$variable] ?? '') . "\n";
    
    if (!empty($context[$variable])) {
        // Step 1: Check str_replace parameters
        $searchString = "{{$variable}}";
        $replaceValue = $context[$variable];
        echo "   - Search string: '$searchString'\n";
        echo "   - Search string length: " . strlen($searchString) . "\n";
        echo "   - Replace value: '$replaceValue'\n";
        echo "   - Replace value length: " . strlen($replaceValue) . "\n";
        echo "   - Replace value bytes: " . bin2hex($replaceValue) . "\n";
        
        echo "   - Before str_replace: '$content'\n";
        $processedContent = str_replace($searchString, $replaceValue, $content);
        echo "   - After str_replace: '$processedContent'\n";
        
        // Test manual replacement to see what's happening
        $manualTest = str_replace('{{course_title}}', '1-2-3 Fingerstyle Guitar', 'a {{course_title}} lesson');
        echo "   - Manual test result: '$manualTest'\n";
        
        return $processedContent;
    }
    
    return '';
}, $template);

echo "Final result: '$result'\n\n";

// Test 2: Use the actual service
echo "3. Using PromptTemplateService:\n";
$service = new PromptTemplateService();
$serviceResult = $service->render($template, $context);
echo "Service result: '$serviceResult'\n\n";

// Test 3: Check if there are any differences
echo "4. Results match: " . ($result === $serviceResult ? 'YES' : 'NO') . "\n"; 