# Status dibaca

## Parent

Spec: [docs/backlog_items/064-laporan-kendala-lapangan/spec.md](../spec.md)

## What to build

Membuka detail oleh akun selain pelapor mencatat/memperbarui `laporan_kendala_dibaca`. Daftar menampilkan lencana
**Belum dibaca** (Terbuka, belum pernah dibuka akun ini) dan **Diperbarui** (`updated_at` > `dibaca_at`), plus filter
"Belum dibaca". Detail menampilkan bagi pelapor siapa yang sudah membaca dan kapan; kolom Dibaca di daftar menunjukkan
jumlah pembaca.

## Acceptance criteria

- [x] Pelapor membuka laporannya sendiri tidak tercatat sebagai dibaca.
- [x] Supervisor membuka detail → lencana Belum dibaca hilang untuk dia saja, tidak untuk Admin lain.
- [x] Pelapor mengubah laporan setelah dibaca → pembaca melihat lencana Diperbarui.
- [x] Pelapor melihat daftar pembaca dengan waktu baca.
- [x] Feature test pencatatan dibaca dan lencana.

## Blocked by

- 03-detail-dan-tandai-selesai.md
