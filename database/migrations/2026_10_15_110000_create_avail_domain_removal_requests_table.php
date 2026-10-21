<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avail: members ask the Owner to remove a custom domain; the Owner approves or rejects.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('avail_domain_removal_requests')) {
            return;
        }
        Schema::create('avail_domain_removal_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('host');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avail_domain_removal_requests');
    }
};
