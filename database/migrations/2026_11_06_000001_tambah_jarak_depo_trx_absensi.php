<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Absen Datang/Pulang kini divalidasi terhadap radius Depo (lihat config/mobile.php
// absen_radius_depo_m, docs/BUSINESS_RULES.md §3). Simpan jarak ke depo saat absen,
// sama seperti trx_checkin_depo.jarak_m, untuk ditampilkan di menu Absensi Sales/Koordinator.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_absensi', function (Blueprint $table) {
            $table->unsignedInteger('masuk_jarak_m')->nullable()->after('masuk_akurasi_m');
            $table->unsignedInteger('pulang_jarak_m')->nullable()->after('pulang_lng');
        });
    }

    public function down(): void
    {
        Schema::table('trx_absensi', function (Blueprint $table) {
            $table->dropColumn(['masuk_jarak_m', 'pulang_jarak_m']);
        });
    }
};
