<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('script_runs', function (Blueprint $table) {
            // pending | approved | rejected — the review state shown on the dashboard
            $table->string('approval_status', 16)->default('pending')->after('status');
            // done | failed | null — where the file was filed on approval
            $table->string('moved_to', 16)->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('moved_to');

            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('script_runs', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'moved_to', 'approved_at']);
        });
    }
};
