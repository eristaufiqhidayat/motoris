# Kartu Kinerja per Depo (frontend)

## Parent

Spec: [docs/backlog_items/066-kinerja-per-depo-beranda-admin/spec.md](../spec.md)

## What to build

Kartu `Kinerja per Depo` di `Home/Dashboard.vue` (partial `Pages/Home/partials/KinerjaDepo.vue`) di bawah Achievement
Nasional, dibungkus `<Deferred data="kinerja">` dengan skeleton sendiri. Header: `DatePicker` dari/sampai + `Button`
Tampilkan yang memuat ulang hanya `kinerja` & `kinerjaFilter`. Isi: `DataTable` tanpa pagination, kolom Depo, Motoris
Aktif, Hari Kerja, AVG Call, Call Ach., AVG EC, EC Ach., AVG IPT, IPT Ach.; achievement diwarnai `warnaCapai`; kolom
bisa diurutkan; nama depo bertaut ke `/summary_all_depo/{id}` dengan rentang yang sama bila akun punya hak Lihat.
Tambah butir penjelasan di InfoPopover Beranda.

## Acceptance criteria

- [ ] KPI/funnel/tren tampil tanpa menunggu kartu ini; kartu menampilkan skeleton saat memuat.
- [ ] Mengganti rentang kartu tidak mengubah rentang KPI dan grafik lain.
- [ ] Urutan bawaan EC Achievement tertinggi; klik header kolom mengurutkan naik/turun.
- [ ] Tautan depo hanya muncul bila punya hak Lihat `summary_all_depo`.
- [ ] Hanya komponen UI dari `Components/ui/` (tanpa markup tabel manual); Vitest untuk partial.

## Blocked by

- [01-prop-kinerja-per-depo.md](01-prop-kinerja-per-depo.md)
