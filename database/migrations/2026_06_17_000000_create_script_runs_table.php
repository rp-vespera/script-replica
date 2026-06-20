<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('script_runs', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('status', 16);          // success | failed
            $table->longText('output')->nullable(); // captured echo/print output
            $table->longText('error')->nullable();  // exception message + trace on failure
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index('filename');
            $table->index('status');
            $table->index('ran_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_runs');
    }
};
