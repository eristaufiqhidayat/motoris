# Metrik tindak lanjut temuan per koordinator

## Parent

Spec: [docs/backlog_items/067-kualitas-data-outlet-dan-kinerja-koordinator/spec.md](../spec.md)

## What to build

Kelas baru (mis. `App\Support\Motoris\KinerjaKoordinator`) yang menghitung per koordinator aktif dalam cakupan, untuk
satu periode: temuan sistem tim dibuat, diperiksa, tingkat tindak lanjut, median hari ke `dicek_at`, Perlu dicek > 7
hari, rasio Terbukti, dan penanda Perlu audit (≥ 5 diperiksa dan rasio < separuh nasional). Ambang di
`config/marketama.php`. Agregasi dengan GROUP BY, bukan query per koordinator.

## Acceptance criteria

- [x] Temuan tanda motoris dan tanda outlet tim dihitung sesuai definisi kepemilikan tim yang sudah ada.
- [x] Median, tingkat tindak lanjut, dan Perlu audit benar untuk fixture yang mencakup kasus tanpa temuan.
- [x] Jumlah query tidak bertambah per koordinator.
- [x] Unit/feature test.

## Blocked by

- [01-sinkronisasi-temuan-otomatis.md](01-sinkronisasi-temuan-otomatis.md)
