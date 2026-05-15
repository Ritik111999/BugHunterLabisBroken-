<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_analytics', function (Blueprint $table) {
            if (! Schema::hasColumn('meeting_analytics', 'insights')) {
                $table->json('insights')->nullable()->after('sentiment');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meeting_analytics', function (Blueprint $table) {
            if (Schema::hasColumn('meeting_analytics', 'insights')) {
                $table->dropColumn('insights');
            }
        });
    }
};
