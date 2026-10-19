<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avail: the zones in Avail's Cloudflare account (Settings -> General). Custom subdomains in them get a
 * CNAME to the app's own address instead of an A record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('instance_settings', 'avail_cloudflare_zones')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->text('avail_cloudflare_zones')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('instance_settings', 'avail_cloudflare_zones')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropColumn('avail_cloudflare_zones');
            });
        }
    }
};
