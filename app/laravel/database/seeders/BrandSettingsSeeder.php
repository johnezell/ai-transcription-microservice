<?php

namespace Database\Seeders;

use App\Models\BrandSetting;
use Illuminate\Database\Seeder;

class BrandSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brands = ['truefire', 'artistworks', 'blayze', 'faderpro'];

        foreach ($brands as $brandId) {
            // Set default LLM model
            BrandSetting::set($brandId, 'llm_model', 'us.anthropic.claude-haiku-4-5-20251001-v1:0');

            // Set default system prompt
            BrandSetting::set($brandId, 'system_prompt', BrandSetting::DEFAULT_PROMPTS[$brandId]);
        }

        $this->command->info('Brand settings seeded for: ' . implode(', ', $brands));
    }
}


