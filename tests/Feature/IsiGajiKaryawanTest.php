<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Backfill gaji_pokok/tunjangan_bbm_makan/sewa_motor/tunjangan_internet di mk_karyawan dari lap_karyawan (data lama Marketama).
class IsiGajiKaryawanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('mk_karyawan')->insert([
            ['id' => 1, 'kategori' => 'sales', 'nama' => 'Sudah Ada Gaji', 'aktif' => true, 'gaji_pokok' => 1_000_000],
            ['id' => 2, 'kategori' => 'sales', 'nama' => 'Belum Ada Gaji', 'aktif' => true, 'gaji_pokok' => null],
            ['id' => 3, 'kategori' => 'sales', 'nama' => 'Tanpa Riwayat Lap Karyawan', 'aktif' => true, 'gaji_pokok' => null],
        ]);
        DB::table('lap_karyawan')->insert([
            ['source_id' => 1, 'fullname' => 'Sudah Ada Gaji', 'basic_salary' => 5_000_000, 'fuel_and_meal_allowance' => 900_000, 'motor_rent' => 250_000, 'internet_data_allowance' => 50_000],
            ['source_id' => 2, 'fullname' => 'Belum Ada Gaji', 'basic_salary' => 4_200_000, 'fuel_and_meal_allowance' => 800_000, 'motor_rent' => null, 'internet_data_allowance' => 50_000],
            ['source_id' => 99, 'fullname' => 'Tidak Dikenal mk_karyawan', 'basic_salary' => 1_234_000, 'fuel_and_meal_allowance' => null, 'motor_rent' => null, 'internet_data_allowance' => null],
        ]);
    }

    public function test_isi_hanya_yang_masih_kosong(): void
    {
        $this->artisan('karyawan:isi-gaji')->assertSuccessful();

        $sudahAda = DB::table('mk_karyawan')->find(1);
        $belumAda = DB::table('mk_karyawan')->find(2);
        $tanpaRiwayat = DB::table('mk_karyawan')->find(3);

        // Sudah punya gaji_pokok sebelumnya: tidak ditimpa.
        $this->assertSame(1_000_000.0, (float) $sudahAda->gaji_pokok);

        // Masih kosong: diisi dari lap_karyawan, kolom null (motor_rent) tetap null.
        $this->assertSame([4_200_000.0, 800_000.0, null, 50_000.0], [
            (float) $belumAda->gaji_pokok, (float) $belumAda->tunjangan_bbm_makan, $belumAda->sewa_motor, (float) $belumAda->tunjangan_internet,
        ]);

        // Tidak ada baris lap_karyawan yang cocok: tetap kosong.
        $this->assertNull($tanpaRiwayat->gaji_pokok);
    }

    public function test_opsi_paksa_menimpa_yang_sudah_terisi(): void
    {
        $this->artisan('karyawan:isi-gaji --paksa')->assertSuccessful();

        $this->assertSame(5_000_000.0, (float) DB::table('mk_karyawan')->find(1)->gaji_pokok);
    }
}
