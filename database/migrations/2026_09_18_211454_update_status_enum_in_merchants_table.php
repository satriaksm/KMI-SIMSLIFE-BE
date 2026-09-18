<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update the ENUM column to include 'suspended' and 'archived'
        DB::statement("ALTER TABLE merchants MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'suspended', 'archived') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Change any suspended or archived back to pending before reverting the enum
        DB::table('merchants')->whereIn('status', ['suspended', 'archived'])->update(['status' => 'pending']);
        
        DB::statement("ALTER TABLE merchants MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
    }
};
