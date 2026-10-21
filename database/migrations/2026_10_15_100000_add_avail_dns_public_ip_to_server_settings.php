<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avail: the public IP that Cloudflare records for custom domains point at, for servers that
 * AvailCoolify reaches over a private address.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('server_settings', 'avail_dns_public_ip')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->string('avail_dns_public_ip')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('server_settings', 'avail_dns_public_ip')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->dropColumn('avail_dns_public_ip');
            });
        }
    }
};
