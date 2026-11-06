<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Absen Datang/Pulang dan checkin/checkout depo TERPISAH, tapi keduanya kini dicek terhadap lokasi
// Depo (klarifikasi product owner 2026-10-30, lihat docs/BUSINESS_RULES.md §3): absen memakai radius
// ketat mobile.absen_radius_depo_m (bawaan 15 m), checkin/checkout depo (loading barang) tetap
// mobile.radius_depo_m (bawaan 100 m). Lihat juga HariKerjaSalesTest (langkah 2 & 5) untuk alur
// gabungan satu hari kerja lengkap.
class AbsenCheckinDepoTest extends MobileApiTestCase
{
    public function test_absen_masuk_tidak_membuat_checkin_depo(): void
    {
        $this->sales();
        $this->login();
        $this->api('POST', 'attendances/check-in', $this->diDepoDekat() + ['photo_id' => $this->foto()])
            ->assertCreated()->assertJsonMissingPath('data.depot_visit_id');

        $this->assertSame(0, DB::table('trx_checkin_depo')->count());
    }

    public function test_absen_dalam_radius_15m_diterima_dan_jarak_disimpan(): void
    {
        $this->sales();
        $this->login();

        $r = $this->api('POST', 'attendances/check-in', $this->diDepoDekat() + ['photo_id' => $this->foto()])
            ->assertCreated();
        $jarak = $r->json('data.distance_to_depot_m');

        $this->assertLessThanOrEqual(15, $jarak);
        $this->assertSame($jarak, DB::table('trx_absensi')->value('masuk_jarak_m'));
    }

    public function test_absen_ditolak_di_luar_radius_15m_meski_dekat_secara_lama(): void
    {
        $this->sales();
        $this->login();

        // diDepo() sengaja ±15,6 m dari depo (lihat MobileApiTestCase): dalam radius checkin depo lama
        // (100 m) tapi di luar radius Absen Datang/Pulang baru (15 m).
        $this->assertError(
            $this->api('POST', 'attendances/check-in', $this->diDepo() + ['photo_id' => $this->foto()]),
            422,
            'outside_depot_radius'
        )->assertJsonPath('error.fields.radius_m', 15);
        $this->assertSame(0, DB::table('trx_absensi')->count());
        $this->assertSame(0, DB::table('trx_checkin_depo')->count());
    }

    public function test_absen_ditolak_jauh_dari_depo(): void
    {
        $this->sales();
        $this->login();

        $this->assertError(
            $this->api('POST', 'attendances/check-in', ['lat' => -6.3, 'lng' => 106.8, 'photo_id' => $this->foto()]),
            422,
            'outside_depot_radius'
        );
        $this->assertSame(0, DB::table('trx_absensi')->count());
    }

    public function test_absen_ditolak_tanpa_depo(): void
    {
        $this->sales();
        DB::table('mk_karyawan')->where('id', 1)->update(['depo_id' => null]);
        $this->login();

        $this->assertError(
            $this->api('POST', 'attendances/check-in', $this->diDepoDekat() + ['photo_id' => $this->foto()]),
            409,
            'no_depot'
        );
    }

    public function test_absen_pulang_juga_dicek_radius_depo(): void
    {
        $this->sales();
        $this->login();
        $this->api('POST', 'attendances/check-in', $this->diDepoDekat() + ['photo_id' => $this->foto()])->assertCreated();

        $this->assertError(
            $this->api('POST', 'attendances/check-out', $this->diDepo()),
            422,
            'outside_depot_radius'
        );
        $this->assertNull(DB::table('trx_absensi')->value('pulang_at'));

        $r = $this->api('POST', 'attendances/check-out', $this->diDepoDekat())->assertOk();
        $this->assertLessThanOrEqual(15, $r->json('data.distance_to_depot_m'));
        $this->assertNotNull(DB::table('trx_absensi')->value('pulang_at'));
        $this->assertNotNull(DB::table('trx_absensi')->value('pulang_jarak_m'));
    }

    public function test_absen_koordinator_juga_dicek_radius_depo(): void
    {
        User::factory()->create(['username' => 'koor', 'password' => 'rahasia123', 'kategori' => 'koordinator', 'karyawan_id' => 137]);
        $this->login('koor');

        // Koordinator 137 di depo yang sama (46): radius 15 m juga berlaku, bukan gabungan area tim.
        $this->api('POST', 'attendances/check-in', $this->diDepoDekat() + ['photo_id' => $this->foto()])
            ->assertCreated()->assertJsonMissingPath('data.depot_visit_id');
        $this->assertSame(0, DB::table('trx_checkin_depo')->count());
    }

    public function test_checkin_depo_terpisah_tidak_mempengaruhi_absen(): void
    {
        $this->sales();
        $this->login();
        $this->assertError($this->api('POST', 'depot-visits/check-in', $this->diDepo()), 409, 'not_checked_in');

        $this->api('POST', 'attendances/check-in', $this->diDepoDekat() + ['photo_id' => $this->foto()])->assertCreated();
        // Checkin depo tetap radius lama (100 m): diDepo() (±15,6 m) tetap diterima di sini.
        $this->api('POST', 'depot-visits/check-in', $this->diDepo())->assertCreated();
        $this->assertSame(1, DB::table('trx_checkin_depo')->count());

        // Checkout depo tidak ikut menutup absen (dan sebaliknya) — lihat HariKerjaSalesTest langkah 5.
        $this->api('POST', 'depot-visits/check-out', [])->assertOk();
        $this->assertNull(DB::table('trx_absensi')->value('pulang_at'));
    }

    public function test_migrasi_melengkapi_checkin_depo_dari_absen_lama(): void
    {
        DB::table('trx_absensi')->insert([
            ['karyawan_id' => 1, 'depo_id' => 46, 'tanggal' => '2026-10-24', 'masuk_at' => '2026-10-24 00:55:00', 'pulang_at' => '2026-10-24 10:00:00',
                'masuk_lat' => -6.2, 'masuk_lng' => 106.8, 'sumber' => 'motoris'],
            ['karyawan_id' => 2, 'depo_id' => 46, 'tanggal' => '2026-10-24', 'masuk_at' => '2026-10-24 01:00:00', 'pulang_at' => '2026-10-24 11:00:00',
                'masuk_lat' => -6.2, 'masuk_lng' => 106.8, 'sumber' => 'motoris'],
        ]);
        // Karyawan 2 sudah checkin depo tapi belum checkout.
        DB::table('trx_checkin_depo')->insert(['karyawan_id' => 2, 'depo_id' => 46, 'tanggal' => '2026-10-24', 'checkin_at' => '2026-10-24 01:05:00']);

        $migrasi = require database_path('migrations/2026_10_28_000003_satukan_absen_checkin_depo.php');
        $migrasi->up();

        $satu = DB::table('trx_checkin_depo')->where('karyawan_id', 1)->first();
        $this->assertSame(['2026-10-24 00:55:00', '2026-10-24 10:00:00'], [(string) $satu->checkin_at, (string) $satu->checkout_at]);
        $this->assertSame('2026-10-24 11:00:00', (string) DB::table('trx_checkin_depo')->where('karyawan_id', 2)->value('checkout_at'));
        $this->assertSame(2, DB::table('trx_checkin_depo')->count());
    }
}
