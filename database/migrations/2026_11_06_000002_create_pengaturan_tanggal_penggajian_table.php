<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tanggal cut off gaji, tanggal bayar gaji, dan tanggal bayar insentif (cut off insentif tetap akhir bulan), masing-masing dengan tanggal mulai berlaku. Perubahan
// menambah baris (riwayat tidak ditimpa); nilai yang berlaku = baris terbaru dengan berlaku_mulai <= hari itu.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_tanggal_penggajian', function (Blueprint $table) {
            $table->id();
            $table->string('kunci', 30); // cutoff_gaji | bayar_gaji | bayar_insentif
            $table->unsignedTinyInteger('tanggal');
            $table->date('berlaku_mulai');
            $table->unsignedBigInteger('diubah_oleh')->nullable();
            $table->timestamps();

            $table->unique(['kunci', 'berlaku_mulai']);
        });

        // Nilai awal berlaku sejak sebelum data mana pun.
        DB::table('pengaturan_tanggal_penggajian')->insert([
            ['kunci' => 'cutoff_gaji', 'tanggal' => 20, 'berlaku_mulai' => '2000-01-01', 'created_at' => now(), 'updated_at' => now()],
            ['kunci' => 'bayar_gaji', 'tanggal' => 25, 'berlaku_mulai' => '2000-01-01', 'created_at' => now(), 'updated_at' => now()],
            ['kunci' => 'bayar_insentif', 'tanggal' => 15, 'berlaku_mulai' => '2000-01-01', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_tanggal_penggajian');
    }
};
