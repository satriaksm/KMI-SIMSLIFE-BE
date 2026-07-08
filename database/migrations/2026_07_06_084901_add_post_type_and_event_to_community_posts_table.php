<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', function (Blueprint $table) {
            $table->string('post_type')->default('general')->after('post_status'); // 'general' | 'event'
            $table->foreignId('event_id')->nullable()->after('post_type')
                ->constrained('events')->nullOnDelete();
            $table->index('post_type');
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('community_posts', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
            $table->dropColumn(['post_type', 'event_id']);
        });
    }
};
