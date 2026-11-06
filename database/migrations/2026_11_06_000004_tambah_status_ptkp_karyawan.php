<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Status keluarga untuk perhitungan PPh 21 (PTKP) dan BPJS: TK/0..TK/3 (tidak kawin) dan K/0..K/3 (kawin) dengan jumlah
// tanggungan. Opsional; diisi di form karyawan (KaryawanController::PTKP).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mk_karyawan', function (Blueprint $table) {
            $table->string('status_ptkp', 8)->nullable()->after('alamat');
        });
    }

    public function down(): void
    {
        Schema::table('mk_karyawan', function (Blueprint $table) {
            $table->dropColumn('status_ptkp');
        });
    }
};
