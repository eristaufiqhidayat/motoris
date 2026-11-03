<?php

namespace App\Console\Commands;

use App\Support\Mobile\Lokasi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Kolom koordinat lap_* dulu decimal(20, 4), jadi koordinat kunjungan & checkin depo lama yang sudah disalin
// enesis:migrasi-transaksi terpotong ke grid ~11 m (spec 062). enesis:migrasi-transaksi tidak menimpa baris yang sudah
// ada, jadi setelah kolomnya diperlebar dan CSV diimpor ulang (marketama:import --only=lap_checkin_outlet
// --only=lap_checkin_checkout), perintah ini menyalin ulang koordinat presisi penuh ke baris lama (sumber = "lama",
// dicocokkan lewat sumber_id = source_id). Hanya lat/lng (dan jarak_m kunjungan) yang diubah; aman diulang.
class PerbaruiKoordinatLama extends Command
{
    protected $signature = 'enesis:perbarui-koordinat-lama';

    protected $description = 'Salin ulang koordinat presisi penuh dari lap_* ke kunjungan & checkin depo lama (trx_*)';

    /** tabel trx => [tabel lap, kolom lat, kolom lng, kolom lat outlet, kolom lng outlet (null = tanpa jarak_m)] */
    private const SUMBER = [
        'trx_kunjungan' => ['lap_checkin_outlet', 'sales_latitude', 'sales_longitude', 'outlet_latitude', 'outlet_longitude'],
        'trx_checkin_depo' => ['lap_checkin_checkout', 'location_lat', 'location_long', null, null],
    ];

    public function handle(): int
    {
        foreach (self::SUMBER as $trx => [$lap, $lat, $lng, $oLat, $oLng]) {
            if (! Schema::hasTable($lap) || ! Schema::hasTable($trx)) {
                continue;
            }

            $diubah = 0;
            $hasJarak = $oLat !== null && Schema::hasColumn($trx, 'jarak_m');

            DB::table($lap)->whereNotNull('source_id')->orderBy('id')
                ->chunkById(2000, function ($baris) use ($trx, $lat, $lng, $oLat, $oLng, $hasJarak, &$diubah) {
                    $id = DB::table($trx)->where('sumber', 'lama')->whereIn('sumber_id', $baris->pluck('source_id'))->pluck('id', 'sumber_id');

                    DB::transaction(function () use ($baris, $id, $trx, $lat, $lng, $oLat, $oLng, $hasJarak, &$diubah) {
                        foreach ($baris as $r) {
                            [$a, $b] = $this->titik($r->{$lat}, $r->{$lng});

                            if (! isset($id[$r->source_id]) || $a === null) {
                                continue;
                            }

                            $ubah = ['lat' => $a, 'lng' => $b];

                            if ($hasJarak) {
                                [$c, $d] = $this->titik($r->{$oLat}, $r->{$oLng});
                                $ubah['jarak_m'] = $c !== null ? Lokasi::jarak($a, $b, $c, $d) : null;
                            }

                            $diubah += DB::table($trx)->where('id', $id[$r->source_id])->update($ubah);
                        }
                    });
                });

            $this->info("{$trx}: ".number_format($diubah, 0, ',', '.').' baris diperbarui dari '.$lap);
        }

        return self::SUCCESS;
    }

    /** Sama dengan MigrasiTransaksi::titik: koordinat kosong/nol/di luar batas = null. */
    private function titik(mixed $lat, mixed $lng): array
    {
        $lat = is_numeric($lat) ? (float) $lat : null;
        $lng = is_numeric($lng) ? (float) $lng : null;

        return ! $lat || ! $lng || abs($lat) > 90 || abs($lng) > 180 ? [null, null] : [$lat, $lng];
    }
}
