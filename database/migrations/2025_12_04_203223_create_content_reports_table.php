<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedTinyInteger('report_reason_id');

            $table->morphs('reportable');

            $table->text('report_comment')->nullable();
            $table->enum('status', ['pending', 'in_review', 'resolved', 'dismissed'])->default('pending');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('admin_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->foreign('report_reason_id')->references('id')->on('report_reasons')->onDelete('cascade');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
    }
};
