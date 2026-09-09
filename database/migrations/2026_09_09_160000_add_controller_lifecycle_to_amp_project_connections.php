<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amp_project_connections', function (Blueprint $table) {
            $table->string('controller_thread_id')->nullable()->after('controller_key');
            $table->timestamp('controller_last_acknowledged_at')->nullable()->after('controller_thread_id');
            $table->index('controller_thread_id');
        });
    }

    public function down(): void
    {
        Schema::table('amp_project_connections', function (Blueprint $table) {
            $table->dropIndex(['controller_thread_id']);
            $table->dropColumn(['controller_thread_id', 'controller_last_acknowledged_at']);
        });
    }
};
