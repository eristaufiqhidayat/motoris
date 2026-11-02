# Modal Detail Motoris dengan profil

## Parent

Spec: [docs/backlog_items/060-detail-motoris-lacak-motoris/spec.md](../spec.md)

## What to build

Irisan ujung-ke-ujung pertama: nama motoris di tabel Lacak Motoris membuka modal full-page yang memuat profil motoris
dari endpoint JSON baru `GET /tracking_sales/{id}/detail`.

## Acceptance criteria

- [x] Endpoint mengembalikan `profil` (nama, no HP, depo, koordinator, area aktif, tanggal masuk, masa kerja, status).
- [x] Hanya motoris kategori `sales` dalam cakupan role akun (di luar cakupan → 404); tanpa hak `tracking_sales` → 403.
- [x] Sel Nama di tabel `tracking_last_seen_sales` jadi tautan; klik membuka `Modal size="full"` "Detail Motoris · Nama".
- [x] Tanggal masuk teks bebas (dd/mm/yyyy, ISO, nama bulan Indonesia) dinormalkan; tak terbaca → "-".

## Blocked by

None
