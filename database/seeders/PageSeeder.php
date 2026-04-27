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
        Page::insert([
            [
                'type' => 'about',
                'title' => 'About Us',
                'content' => 'This is about us content',
            ],
            [
                'type' => 'privacy',
                'title' => 'Privacy Policy',
                'content' => 'This is privacy policy',
            ],
            [
                'type' => 'terms',
                'title' => 'Terms of Service',
                'content' => 'These are terms',
            ],
        ]);
    }
}
