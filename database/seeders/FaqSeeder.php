<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

class FaqSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        Faq::insert([
            [
                'question' => 'What is this app?',
                'answer' => 'This app analyzes meetings.',
                'order' => 1,
            ],
            [
                'question' => 'Is audio stored?',
                'answer' => 'No, only analytics are stored.',
                'order' => 2,
            ],
        ]);
    }
}
