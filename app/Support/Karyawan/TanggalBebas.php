<?php

namespace App\Support\Karyawan;

use Carbon\CarbonImmutable;

/** Tanggal mk_karyawan yang tersimpan bebas (aplikasi lama: "dd/mm/yyyy", "1 Maret 2026", ISO) dinormalkan ke Y-m-d. */
class TanggalBebas
{
    private const BULAN = ['januari' => 'January', 'februari' => 'February', 'maret' => 'March', 'april' => 'April', 'mei' => 'May', 'juni' => 'June',
        'juli' => 'July', 'agustus' => 'August', 'september' => 'September', 'oktober' => 'October', 'november' => 'November', 'desember' => 'December'];

    public static function keYmd(mixed $nilai): ?string
    {
        $nilai = trim((string) $nilai);

        if ($nilai === '' || $nilai === '-') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y-m-d H:i:s'] as $format) {
            try {
                $t = CarbonImmutable::createFromFormat($format, $nilai);

                if ($t && $t->format($format) === $nilai) {
                    return $t->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        try {
            return CarbonImmutable::parse(str_ireplace(array_keys(self::BULAN), array_values(self::BULAN), $nilai))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
