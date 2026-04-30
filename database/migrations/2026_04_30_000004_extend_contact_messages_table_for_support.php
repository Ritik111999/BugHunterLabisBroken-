<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('contact_messages')) {
            return;
        }

        Schema::table('contact_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('contact_messages', 'status')) {
                $table->string('status')->default('open')->after('message');
            }
            if (!Schema::hasColumn('contact_messages', 'admin_reply')) {
                $table->longText('admin_reply')->nullable()->after('status');
            }
            if (!Schema::hasColumn('contact_messages', 'replied_at')) {
                $table->timestamp('replied_at')->nullable()->after('admin_reply');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('contact_messages')) {
            return;
        }

        Schema::table('contact_messages', function (Blueprint $table) {
            $drops = [];
            foreach (['replied_at', 'admin_reply', 'status'] as $col) {
                if (Schema::hasColumn('contact_messages', $col)) {
                    $drops[] = $col;
                }
            }
            if ($drops) {
                $table->dropColumn($drops);
            }
        });
    }
};

