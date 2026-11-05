<?php

namespace App\Console\Commands;

use App\Support\Mobile\Pengaturan;
use App\Support\Tracking\SinkronTemuan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// Sinkronisasi temuan otomatis (spec 067): tanpa ini temuan sistem hanya muncul bila koordinator menekan tombol Sinkronisasi
// Data, sehingga koordinator yang tidak pernah menekannya terlihat tanpa temuan. Dijalankan tiap malam untuk kemarin (data
// harinya sudah lengkap) per tim koordinator dengan aturan yang sama dengan tombol manual (SinkronTemuan::jalankan).
class SinkronTemuanMalam extends Command
{
    protected $signature = 'temuan:sinkron {--tanggal= : Tanggal yang diperiksa (Y-m-d), bawaan kemarin}';

    protected $description = 'Buat tanda temuan sistem (lokasi palsu, outlet fiktif) untuk semua tim koordinator pada satu tanggal';

    public function handle(): int
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $tanggal = $this->option('tanggal') ?: Pengaturan::sekarang()->subDay()->toDateString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || strtotime($tanggal) === false || $tanggal > $hariIni) {
            $this->error('Tanggal harus berformat Y-m-d dan tidak di masa depan.');

            return self::FAILURE;
        }

        // Satu kelompok per koordinator; motoris aktif tanpa koordinator (koordinator_id kosong) satu kelompok tersendiri.
        $tim = DB::table('mk_karyawan')->where('kategori', 'sales')->where('aktif', true)
            ->get(['id', 'koordinator_id'])
            ->groupBy(fn ($m) => $m->koordinator_id ?? 0)
            ->map(fn ($anggota) => $anggota->pluck('id')->map(fn ($id) => (int) $id)->all());

        $gagal = 0;

        foreach ($tim as $koordinatorId => $motoris) {
            try {
                $hasil = SinkronTemuan::jalankan($motoris, $tanggal, $tanggal);
                $this->line(sprintf('Tim %s: %d motoris-hari diperiksa, %d tanda motoris, %d tanda outlet.', $koordinatorId ?: 'tanpa koordinator', $hasil['diperiksa'], $hasil['tanda_motoris'], $hasil['tanda_outlet']));
            } catch (Throwable $e) {
                $gagal++;
                Log::error("temuan:sinkron gagal untuk tim {$koordinatorId}: {$e->getMessage()}");
                $this->error("Tim {$koordinatorId} gagal: {$e->getMessage()}");
            }
        }

        $this->info("Selesai: {$tim->count()} tim, {$gagal} gagal, tanggal {$tanggal}.");

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
