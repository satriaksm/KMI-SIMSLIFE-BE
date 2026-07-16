<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('merchant_id')->nullable()->constrained()->onDelete('cascade');
            $table->unsignedInteger('event_id')->nullable();
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');

            $table->string('voucher_name');
            $table->string('voucher_code');
            $table->enum('voucher_status', ['active', 'inactive'])->default('active');
            $table->boolean('is_secret')->default(false);
            $table->enum('voucher_type', ['percent', 'fixed'])->default('percent');
            $table->text('voucher_description')->nullable();

            $table->date('voucher_start_date');
            $table->date('voucher_end_date');

            $table->decimal('value', 15, 2);
            $table->decimal('max_discount_amount', 15, 2)->nullable();
            $table->decimal('min_purchase_amount', 15, 2)->default(0);

            $table->unsignedSmallInteger('usage_limit_per_user')->default(1);
            $table->unsignedSmallInteger('usage_limit')->nullable();

            $table->timestamps();

            $table->index('voucher_status');
            $table->index('voucher_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
