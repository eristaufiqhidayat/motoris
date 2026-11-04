<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Diskusi Laporan Kendala (spec 065): akun yang melakukan aktivitas terakhir, supaya penulisnya sendiri tidak
// dapat lencana "Ada balasan baru". Null untuk laporan lama = tidak memberi lencana.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laporan_kendala', function (Blueprint $table) {
            $table->unsignedBigInteger('aktivitas_oleh')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('laporan_kendala', function (Blueprint $table) {
            $table->dropColumn('aktivitas_oleh');
        });
    }
};
