<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Koordinat lap_* dulu terpotong ke 4 desimal (spec 062); setelah diperlebar dan CSV diimpor ulang, baris lama di
// trx_kunjungan / trx_checkin_depo diperbarui dari lap_* lewat sumber_id, tanpa menyentuh data dari HP.
class PerbaruiKoordinatLamaTest extends TestCase
{
    use RefreshDatabase;

    public function test_koordinat_dan_jarak_kunjungan_lama_diperbarui_dari_lap(): void
    {
        DB::table('lap_checkin_outlet')->insert([
            ['source_id' => 10, 'sales_latitude' => -6.4655189, 'sales_longitude' => 106.8883876, 'outlet_latitude' => -6.4655951, 'outlet_longitude' => 106.8883581],
            ['source_id' => 11, 'sales_latitude' => 0, 'sales_longitude' => 0, 'outlet_latitude' => null, 'outlet_longitude' => null], // kosong: tidak diubah
        ]);
        DB::table('lap_checkin_checkout')->insert(['source_id' => 20, 'location_lat' => -6.1234567, 'location_long' => 106.7654321]);
        $kunjungan = fn (?int $sumberId, string $sumber) => DB::table('trx_kunjungan')->insertGetId([
            'karyawan_id' => 1, 'tanggal' => '2026-10-16', 'checkin_at' => '2026-10-16 04:00:00',
            'lat' => -6.4655, 'lng' => 106.8884, 'jarak_m' => 0, 'sumber' => $sumber, 'sumber_id' => $sumberId,
        ]);
        $lama = $kunjungan(10, 'lama');
        $kosong = $kunjungan(11, 'lama');
        $hp = $kunjungan(null, 'motoris');
        $depo = DB::table('trx_checkin_depo')->insertGetId([
            'karyawan_id' => 1, 'depo_id' => 46, 'tanggal' => '2026-10-16', 'lat' => -6.1235, 'lng' => 106.7654, 'sumber' => 'lama', 'sumber_id' => 20,
        ]);

        $this->artisan('enesis:perbarui-koordinat-lama')->assertSuccessful();

        $k = DB::table('trx_kunjungan')->find($lama);
        $this->assertSame([-6.4655189, 106.8883876], [round((float) $k->lat, 7), round((float) $k->lng, 7)]);
        $this->assertSame(9, (int) $k->jarak_m); // jarak HP ke toko kini terukur, bukan 0 karena grid
        $this->assertSame(-6.4655, round((float) DB::table('trx_kunjungan')->where('id', $kosong)->value('lat'), 7));
        $this->assertSame(-6.4655, round((float) DB::table('trx_kunjungan')->where('id', $hp)->value('lat'), 7));
        $this->assertSame(-6.1234567, round((float) DB::table('trx_checkin_depo')->where('id', $depo)->value('lat'), 7));
    }
}
