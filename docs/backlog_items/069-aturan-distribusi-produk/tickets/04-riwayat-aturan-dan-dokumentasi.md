# Riwayat aturan distribusi dan dokumentasi

## What to build

Admin melihat riwayat perubahan aturan di halaman Distribusi Produk (produk, kota atau depo, nilai, tanggal berlaku,
pengubah), termasuk aturan yang dijadwalkan di masa depan, supaya bisa menjawab aturan apa yang berlaku pada tanggal
tertentu.

## Acceptance criteria

- [ ] Riwayat tampil terbaru lebih dulu, memuat baris kota dan depo, dan mengikuti cakupan akun.
- [ ] Baris yang tanggal berlakunya di masa depan diberi lencana "Terjadwal".
- [ ] Baris "ikut kota" tampil sebagai "Kembali ke aturan kota".
- [ ] Riwayat dimuat dengan `Inertia::defer`, seperti halaman Kuota Motoris.
- [ ] `CLAUDE.md` mencatat modul `ProdukDistribusi` di bagian arsitektur.

## Implementation steps

- [ ] Tambah query riwayat di controller, dibatasi cakupan akun.
- [ ] Tambah bagian riwayat di halaman Distribusi Produk.
- [ ] Perbarui `CLAUDE.md`.
- [ ] Tambah feature test riwayat dan cakupan, lalu jalankan `php artisan test --filter=Distribusi` dan
      `vendor/bin/pint`.

## Blocked by

- `03-pengecualian-per-depo.md`
