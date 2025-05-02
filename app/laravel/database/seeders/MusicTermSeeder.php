<?php

namespace Database\Seeders;

use App\Models\TermCategory;
use App\Models\Term;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MusicTermSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define categories with their colors
        $categories = [
            [
                'name' => 'Guitar Techniques',
                'slug' => 'guitar_techniques',
                'description' => 'Techniques used for playing guitar',
                'color_class' => 'blue',
                'display_order' => 1,
            ],
            [
                'name' => 'Guitar Parts',
                'slug' => 'guitar_parts',
                'description' => 'Parts and components of a guitar',
                'color_class' => 'green',
                'display_order' => 2,
            ],
            [
                'name' => 'Music Theory',
                'slug' => 'music_theory',
                'description' => 'Music theory concepts and terminology',
                'color_class' => 'purple',
                'display_order' => 3,
            ],
            [
                'name' => 'Music Equipment',
                'slug' => 'music_equipment',
                'description' => 'Music equipment and gear',
                'color_class' => 'orange',
                'display_order' => 4,
            ],
            [
                'name' => 'Performance Techniques',
                'slug' => 'performance_techniques',
                'description' => 'Performance techniques for various instruments',
                'color_class' => 'pink',
                'display_order' => 5,
            ],
            [
                'name' => 'Musical Genres',
                'slug' => 'musical_genres',
                'description' => 'Musical styles and genres',
                'color_class' => 'indigo',
                'display_order' => 6,
            ],
            [
                'name' => 'Recording Terms',
                'slug' => 'recording_terms',
                'description' => 'Recording and production terminology',
                'color_class' => 'cyan',
                'display_order' => 7,
            ],
        ];

        // Define terms by category
        $termsByCategory = [
            'guitar_techniques' => [
                'palm muting', 'hammer-on', 'pull-off', 'bending', 'vibrato', 
                'slide', 'tapping', 'sweep picking', 'tremolo picking', 'harmonics',
                'pinch harmonic', 'natural harmonic', 'artificial harmonic', 'legato', 
                'staccato', 'bend', 'release', 'riff', 'lick', 'arpeggio', 'sweep'
            ],
            'guitar_parts' => [
                'bridge', 'neck', 'fretboard', 'pickup', 'humbucker', 'single-coil', 
                'tuner', 'headstock', 'nut', 'fret', 'string', 'whammy bar', 'tremolo bar',
                'volume knob', 'tone knob', 'pickup selector', 'input jack', 'body'
            ],
            'music_theory' => [
                'chord', 'scale', 'mode', 'key', 'minor', 'major', 'pentatonic', 'blues scale',
                'dominant', 'diminished', 'augmented', 'seventh', 'ninth', 'sus4', 'sus2',
                'lydian', 'dorian', 'mixolydian', 'phrygian', 'locrian', 'ionian', 'aeolian'
            ],
            'music_equipment' => [
                'amplifier', 'amp', 'cabinet', 'effects pedal', 'distortion', 'overdrive', 
                'reverb', 'delay', 'chorus', 'flanger', 'phaser', 'wah-wah', 'looper',
                'compressor', 'equalizer', 'EQ', 'boost', 'buffer', 'noise gate'
            ],
            'performance_techniques' => [
                'vibrato', 'bend', 'release', 'slide', 'hammer-on', 'pull-off', 
                'muting', 'palm muting', 'articulation', 'accent', 'staccato', 'legato',
                'glissando', 'portamento'
            ],
            'musical_genres' => [
                'rock', 'blues', 'jazz', 'classical', 'country', 'folk', 'bluegrass', 
                'metal', 'heavy metal', 'punk', 'pop', 'r&b', 'soul', 'funk', 'disco'
            ],
            'recording_terms' => [
                'studio', 'recording', 'mixing', 'mastering', 'production', 'engineer',
                'producer', 'mic', 'microphone', 'condenser', 'dynamic', 'ribbon',
                'preamp', 'console', 'mixing desk', 'DAW'
            ],
        ];

        // Create categories
        foreach ($categories as $categoryData) {
            $category = TermCategory::create($categoryData);
            
            // Create terms for this category
            if (isset($termsByCategory[$category->slug])) {
                foreach ($termsByCategory[$category->slug] as $term) {
                    Term::create([
                        'category_id' => $category->id,
                        'term' => $term,
                        'active' => true,
                    ]);
                }
            }
        }
    }
}
