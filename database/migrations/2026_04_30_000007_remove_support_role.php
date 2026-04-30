<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('roles')) {
            return;
        }

        $support = Role::query()->where('name', 'support')->first();
        if (!$support) {
            return;
        }

        if (Schema::hasTable('role_user')) {
            DB::table('role_user')->where('role_id', $support->id)->delete();
        }

        $support->delete();
    }

    public function down(): void
    {
        // no-op (we intentionally remove the support role)
    }
};

