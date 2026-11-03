<?php

namespace App\Support\Tracking;

use App\Models\User;
use App\Support\Menu\JamLokal;

// Hasil cek koordinator atas tanda sistem (spec 062), sama untuk tanda_motoris dan tanda_outlet: status Terbukti / Wajar,
// catatan hasil, dan siapa/kapan. Wajar juga mengisi dicabut_at supaya query "tanda aktif" yang sudah ada (lencana
// Ditandai, rekap, peta outlet bertanda) tidak lagi menghitungnya.
class HasilPeriksa
{
    /** @return array<string, mixed> kolom untuk update tanda yang diperiksa */
    public static function kolom(string $status, string $catatan, ?User $oleh): array
    {
        $sekarang = now();

        return [
            'status' => $status,
            'hasil_catatan' => $catatan,
            'dicek_at' => $sekarang,
            'dicek_oleh' => $oleh?->id,
            'dicek_nama' => $oleh?->username ?? $oleh?->name,
            'dicabut_at' => $status === 'wajar' ? $sekarang : null,
            'dicabut_oleh' => $status === 'wajar' ? $oleh?->id : null,
            'updated_at' => $sekarang,
        ];
    }

    /** @return array<string, mixed> status & hasil cek satu tanda untuk JSON */
    public static function bentuk(object $tanda, bool $bolehPeriksa): array
    {
        return [
            'status' => $tanda->status,
            'status_label' => config("marketama.status_tanda.{$tanda->status}", $tanda->status),
            'hasil_catatan' => $tanda->hasil_catatan,
            'dicek_oleh' => $tanda->dicek_nama,
            'dicek_waktu' => JamLokal::format($tanda->dicek_at, 'Y-m-d H:i'),
            'bisa_periksa' => $tanda->status === 'perlu_dicek' && $bolehPeriksa,
        ];
    }
}
