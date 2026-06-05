<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_report_id')->constrained('content_reports')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // user yang mengajukan sanggahan
            $table->text('appeal_text'); // isi sanggahan
            $table->enum('status', ['pending', 'reviewed', 'accepted', 'rejected'])->default('pending');
            $table->text('admin_response')->nullable(); // respons admin
            $table->foreignId('responded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index('content_report_id');
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_appeals');
    }
};
