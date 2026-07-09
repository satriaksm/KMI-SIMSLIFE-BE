<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Change orders.status from ENUM to VARCHAR(50) to support both product/kuliner and jasa statuses.
     * No data is lost - existing ENUM values are all valid VARCHAR(50) values.
     */
    public function up(): void
    {
        // MySQL ENUM cannot be changed to VARCHAR directly with Schema::table()
        // Use DB::statement for raw ALTER TABLE
        DB::statement("ALTER TABLE `orders` CHANGE `status` `status` VARCHAR(50) NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     *
     * NOTE: This rollback will FAIL if new VARCHAR values exist that are not valid ENUM values.
     * Only use in development/testing. Production rollback requires data cleanup first.
     */
    public function down(): void
    {
        // Try to rollback to original ENUM - only works if no invalid values exist
        DB::statement("ALTER TABLE `orders` CHANGE `status` `status` ENUM('pending','paid','proses','selesai','batal') NOT NULL DEFAULT 'pending'");
    }
};
