# Lencana Ada balasan baru

## Parent

Spec: [docs/backlog_items/065-diskusi-laporan-kendala/spec.md](../spec.md)

## What to build

`laporan_kendala_dibaca.dibaca_at` diperbarui setiap akun (termasuk pelapor) membuka laporan; daftar "Sudah dibaca
oleh" tetap mengecualikan pelapor. Daftar menampilkan badge **Ada balasan baru** (menggantikan **Diperbarui**) bila
`aktivitas_at` > `dibaca_at` akun dan akun itu bukan penulis aktivitas terakhir, kolom **Diskusi** (jumlah komentar),
serta filter "Ada balasan baru". Angka sidebar dan kartu Beranda menambahkan hitungan ini.

## Acceptance criteria

- [x] Supervisor membalas → pelapor melihat badge dan angka sidebar; setelah membuka laporan, badge hilang hanya untuk pelapor.
- [x] Penulis komentar tidak melihat badge untuk komentarnya sendiri.
- [x] Kartu Beranda dan sidebar menghitung Belum dibaca + Ada balasan baru.
- [x] Test lencana Diperbarui lama diganti; feature test lencana baru.

## Blocked by

- 01-komentar-di-detail-laporan.md
- 03-peristiwa-selesai-dan-buka-kembali.md
