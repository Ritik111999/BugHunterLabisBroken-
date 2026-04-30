<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $pages = [
            [
                'type' => 'about',
                'title' => 'About Us',
                'content' => 'WeChirp helps teams turn meetings into searchable insights.',
            ],
            [
                'type' => 'privacy',
                'title' => 'Privacy Policy',
                'content' => 'This is placeholder Privacy Policy content for development/testing.',
            ],
            [
                'type' => 'terms',
                'title' => 'Terms of Service',
                'content' => 'These are placeholder Terms of Service for development/testing.',
            ],
        ];

        foreach ($pages as $p) {
            Page::query()->updateOrCreate(
                ['type' => $p['type']],
                ['title' => $p['title'], 'content' => $p['content']]
            );
        }
    }
}
