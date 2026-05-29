<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Comprehensive update to service_orders table for full UMKM Jasa flow.
     * Uses hasColumn/hasIndex checks to avoid "already exists" errors.
     */
    public function up(): void
    {
        // Use raw SQL to safely change ENUM by temporarily making column non-ENUM
        // Step 1: Convert status to VARCHAR to allow UPDATE
        DB::statement("ALTER TABLE service_orders MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'layanan_diproses'");

        // Step 2: Update old status values to new ones
        DB::statement("UPDATE service_orders SET status = 'menunggu_konfirmasi_merchant' WHERE status = 'layanan_diproses'");

        // Step 3: Now convert to new ENUM
        Schema::table('service_orders', function (Blueprint $table) {
            // Use Laravel's enum helper which handles the ALTER properly
            if (Schema::hasColumn('service_orders', 'status')) {
                $table->enum('status', [
                    'menunggu_konfirmasi_merchant',
                    'diterima',
                    'ditolak',
                    'layanan_dikerjakan',
                    'menunggu_konfirmasi_selesai',
                    'selesai',
                ])->default('menunggu_konfirmasi_merchant')->change();
            }

            // 2. Rejection fields (when merchant rejects)
            if (!Schema::hasColumn('service_orders', 'rejection_reason')) {
                $table->string('rejection_reason')->nullable()->after('status');
            }
            if (!Schema::hasColumn('service_orders', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
            }

            // 3. Payment fields (ready for COD and future payment gateway)
            // payment_method: COD | MANUAL | (future: BANK_TRANSFER | E_WALLET | etc.)
            // payment_status: UNPAID | WAITING_CONFIRMATION | PAID | (future: PENDING | FAILED | EXPIRED)
            if (Schema::hasColumn('service_orders', 'payment_method')) {
                $table->string('payment_method')->nullable()->change();
            }
            if (!Schema::hasColumn('service_orders', 'payment_status')) {
                $table->string('payment_status', 30)->default('UNPAID')->after('payment_method');
            }
            if (!Schema::hasColumn('service_orders', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('service_orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('payment_reference');
            }

            // 4. Completion evidence (when merchant marks as selesai)
            if (!Schema::hasColumn('service_orders', 'completion_note')) {
                $table->text('completion_note')->nullable()->after('booking_note');
            }

            // 5. Customer confirmation (customer must confirm completed work)
            if (!Schema::hasColumn('service_orders', 'customer_confirmed')) {
                $table->boolean('customer_confirmed')->default(false)->after('is_reviewed');
            }
            if (!Schema::hasColumn('service_orders', 'customer_confirmed_at')) {
                $table->timestamp('customer_confirmed_at')->nullable()->after('customer_confirmed');
            }

            // 6. Order reference (for tracking linking)
            if (!Schema::hasColumn('service_orders', 'order_number')) {
                $table->string('order_number', 50)->nullable()->unique()->after('id');
            }

            // 7. Add indexes for performance (only if not exists)
            if (!Schema::hasIndex('service_orders', 'service_orders_status_created_at_index')) {
                $table->index(['status', 'created_at'], 'service_orders_status_created_at_index');
            }
            if (!Schema::hasIndex('service_orders', 'service_orders_payment_status_index')) {
                $table->index(['payment_status'], 'service_orders_payment_status_index');
            }
            if (!Schema::hasIndex('service_orders', 'service_orders_customer_id_status_index')) {
                $table->index(['customer_id', 'status'], 'service_orders_customer_id_status_index');
            }
            if (!Schema::hasIndex('service_orders', 'service_orders_merchant_id_status_index')) {
                $table->index(['merchant_id', 'status'], 'service_orders_merchant_id_status_index');
            }
        });

        // Generate order numbers for existing records
        DB::statement("
            UPDATE service_orders
            SET order_number = CONCAT('SO-', LPAD(id, 6, '0'))
            WHERE order_number IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (Schema::hasIndex('service_orders', 'service_orders_status_created_at_index')) {
                $table->dropIndex('service_orders_status_created_at_index');
            }
            if (Schema::hasIndex('service_orders', 'service_orders_payment_status_index')) {
                $table->dropIndex('service_orders_payment_status_index');
            }
            if (Schema::hasIndex('service_orders', 'service_orders_customer_id_status_index')) {
                $table->dropIndex('service_orders_customer_id_status_index');
            }
            if (Schema::hasIndex('service_orders', 'service_orders_merchant_id_status_index')) {
                $table->dropIndex('service_orders_merchant_id_status_index');
            }

            $table->dropColumn([
                'order_number',
                'rejection_reason',
                'rejected_at',
                'payment_status',
                'payment_reference',
                'paid_at',
                'completion_note',
                'customer_confirmed',
                'customer_confirmed_at',
            ]);

            // Revert ENUM to old values
            DB::statement("ALTER TABLE service_orders MODIFY COLUMN status ENUM(
                'layanan_diproses',
                'layanan_dikerjakan',
                'selesai'
            ) NOT NULL DEFAULT 'layanan_diproses'");
        });
    }
};