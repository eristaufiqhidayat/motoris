<?php

namespace Tests\Feature;

use App\Support\Mobile\Pengaturan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Pengaturan::depo() dipakai aplikasi HP (lewat ProfilController) untuk ambil target call/EC, koordinat,
// dan minimal transaksi depo. Kolom asli mk_depo (diisi lewat form Enesis atau App\Support\Master\MasterDepo::
// satukanDariMasterDepo()) harus diutamakan dari JSON "data" hasil tarikan, tapi kolom yang masih kosong
// tetap jatuh ke JSON itu (backward compatible untuk depo yang belum disatukan).
class PengaturanDepoTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_asli_mk_depo_diutamakan_dari_json_data(): void
    {
        DB::table('mk_depo')->insert([
            'id' => 46, 'nama' => 'Sidoarjo',
            // JSON data (hasil tarikan) sengaja beda nilai supaya ketahuan kolom mana yang dipakai.
            'data' => json_encode(['latitude' => '-6.2', 'longitude' => '106.8', 'minimal_value_transaksi' => '99999', 'target_call' => '99', 'target_ec' => '99']),
            'target_call' => 45, 'target_ec' => 35, 'latitude' => -7.446839, 'longitude' => 112.717598,
            'minimal_value_transaksi' => 35000, 'durasi_tracking_gps' => 180,
        ]);

        $depo = Pengaturan::depo(46);

        $this->assertSame(45, $depo['target']['call']);
        $this->assertSame(35, $depo['target']['ec']);
        $this->assertSame(35000, $depo['minimal_transaksi']);
        $this->assertEqualsWithDelta(-7.446839, $depo['lat'], 0.0001);
        $this->assertEqualsWithDelta(112.717598, $depo['lng'], 0.0001);
        $this->assertSame(180, $depo['interval_gps_detik']);
    }

    public function test_kolom_kosong_jatuh_ke_json_data(): void
    {
        // Depo belum disatukan (satukanDariMasterDepo belum menemukan pasangannya): kolom asli kosong semua.
        DB::table('mk_depo')->insert([
            'id' => 22, 'nama' => 'Balaraja',
            'data' => json_encode(['latitude' => '-6.2', 'longitude' => '106.8', 'minimal_value_transaksi' => '35.000', 'target_call' => '40', 'target_ec' => '25']),
        ]);

        $depo = Pengaturan::depo(22);

        $this->assertSame(40, $depo['target']['call']);
        $this->assertSame(25, $depo['target']['ec']);
        $this->assertSame(35000, $depo['minimal_transaksi']);
    }
}
