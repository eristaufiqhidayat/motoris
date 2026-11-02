<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IsiGajiKaryawan extends Command
{
    protected $signature = 'karyawan:isi-gaji
                            {--paksa : Timpa juga kolom gaji mk_karyawan yang sudah terisi (bawaan hanya isi yang masih kosong)}';

    protected $description = 'Salin gaji pokok & tunjangan dari lap_karyawan (data lama Marketama) ke mk_karyawan, dicocokkan lewat source_id';

    private const KOLOM = [
        'basic_salary' => 'gaji_pokok',
        'fuel_and_meal_allowance' => 'tunjangan_bbm_makan',
        'motor_rent' => 'sewa_motor',
        'internet_data_allowance' => 'tunjangan_internet',
    ];

    public function handle(): int
    {
        $paksa = (bool) $this->option('paksa');
        $karyawan = DB::table('mk_karyawan')->select(['id', ...array_values(self::KOLOM)])->get()->keyBy('id');
        $jumlah = 0;

        DB::table('lap_karyawan')->whereNotNull('source_id')->orderBy('id')
            ->chunk(500, function ($baris) use ($karyawan, $paksa, &$jumlah) {
                foreach ($baris as $lap) {
                    $k = $karyawan->get($lap->source_id);

                    if (! $k) {
                        continue;
                    }

                    $isi = [];

                    foreach (self::KOLOM as $dari => $ke) {
                        if ($lap->{$dari} === null || (! $paksa && $k->{$ke} !== null)) {
                            continue;
                        }

                        $isi[$ke] = (float) $lap->{$dari};
                    }

                    if ($isi) {
                        DB::table('mk_karyawan')->where('id', $k->id)->update($isi);
                        $jumlah++;
                    }
                }
            });

        $this->info(number_format($jumlah, 0, ',', '.').' karyawan diisi gaji/tunjangannya dari lap_karyawan.');

        return self::SUCCESS;
    }
}
