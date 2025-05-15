<?php

namespace Database\Seeders;

use App\Models\TerminologyCategory;
use App\Models\Terminology;
use Illuminate\Database\Seeder;

class TerminologySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Restore original music categories
        $categories = [
            [
                'name' => 'Music Genres',
                'slug' => 'music_genres',
                'description' => 'Music genres',
                'color_class' => 'blue',
                'display_order' => 1,
            ],
            [
                'name' => 'Musical Instruments',
                'slug' => 'musical_instruments',
                'description' => 'Musical instruments',
                'color_class' => 'green',
                'display_order' => 2,
            ],
            [
                'name' => 'Skill Levels',
                'slug' => 'skill_levels',
                'description' => 'Skill levels',
                'color_class' => 'purple',
                'display_order' => 3,
            ],
            [
                'name' => 'Music Topics',
                'slug' => 'music_topics',
                'description' => 'Music topics / techniques',
                'color_class' => 'orange',
                'display_order' => 4,
            ],
            [
                'name' => 'TrueFire Series',
                'slug' => 'truefire_series',
                'description' => 'TrueFire Series',
                'color_class' => 'indigo',
                'display_order' => 6, // Assuming 5 was intentionally skipped or for another category
            ]
        ];

        // Restore original music terms, adapted to new structure
        $termsByCategorySlug = [
            'music_genres' => [
                'acoustic','acoustic blues','americana','acoustic rock','fingerstyle','classical','country','country rock','blues','folk','bluegrass','classic rock','gospel','jam band','multi genre','blues-rock','rock','country blues','modern country','western swing','funk','jazz','r&b','soul','jazz blues','metal','modern rock','hard rock','latin rock','gypsy jazz','progressive rock','funk rock','surf rock','world music','brazilian','celtic','flamenco','reggae','fingerstyle blues','singer-songwriter','roots rock','chicago blues','jazz rock','modern blues','british blues','jump blues','smooth jazz','southern rock','texas blues','honky-tonk','rockabilly','jazz funk','bebop','fingerstyle jazz','modern jazz','soul jazz','latin','latin jazz','swing jazz'
            ],
            'musical_instruments' => [
                'acoustic guitar','guitar','electric guitar','acoustic bass','mandolin','fiddle','violin','electric bass','bass','12-string guitar','banjo','dobro','upright bass','drums','saxophone','daw','harmonica','ukulele'
            ],
            'skill_levels' => [
                'late beginner','intermediate','beginner','late intermediate','advanced'
            ],
            'music_topics' => [
               'home recording','daw','chords','scales','theory','chord melody','chord progressions','songs','fingerpicking','technique','rhythm','soloing','improvisation','alternate tunings','licks','picking','slide','effects','bass grooves','bass lines','applied theory','comping','reference','songwriting','ear training','modes','accompaniment','practice','sight-reading','vocals','looping','jamming','caged system','solo guitar'
            ],
            'truefire_series' => [
                'series','none','licks you must know','song courses','song lessons','handbook','practice plan','core skills','artist series','style of','deep dive','genre study','survival guides','for beginners','flying solo','a closer look','home recording','toolkit courses','foundry','songpacks','factory','masterclasses','jump start','greatest hits','play guitar','take 5','guidebook','essentials','play with fire','my guitar heroes','on location','bootcamps','solo factory','chord studies','guitar lab','multi-track jam packs','fakebooks','in the jam: single artist','live plus','play like','in the jam: full band','practice sessions','trading solos','focus on','premium song lessons','playbook','indie','guitar gym','jam night','JamPlay'
            ]
        ];

        // Convert simple string arrays to the new structure ['term' => ..., 'patterns' => ...]
        foreach ($termsByCategorySlug as $slug => $termList) {
            $structuredTerms = [];
            foreach ($termList as $termString) {
                $structuredTerms[] = [
                    'term' => trim($termString),
                    'patterns' => [strtolower(trim($termString))] // Default pattern is lowercase term
                    // 'description' => null // Can add descriptions later if needed
                ];
            }
            $termsByCategorySlug[$slug] = $structuredTerms;
        }

        // Create categories
        foreach ($categories as $categoryData) {
            $category = TerminologyCategory::firstOrCreate(
                ['slug' => $categoryData['slug']],
                $categoryData
            );
            
            // Create terms for this category
            if (isset($termsByCategorySlug[$category->slug])) {
                foreach ($termsByCategorySlug[$category->slug] as $termData) {
                    Terminology::firstOrCreate(
                        [
                            'category_id' => $category->id,
                            'term' => $termData['term']
                        ],
                        [
                            'active' => true,
                            'patterns' => $termData['patterns'] ?? [strtolower($termData['term'])], // Use patterns or default
                            'description' => $termData['description'] ?? null
                        ]
                    );
                }
            }
        }
    }
} 