# Pangkas kategori tanda outlet jadi satu (Outlet fiktif)

## Parent

Spec: [docs/backlog_items/059-sederhanakan-kategori-tanda-outlet/spec.md](../spec.md)

## What to build

`tanda_outlet` hanya punya satu kategori (`outlet_fiktif`): config, controller (kategori selalu di-hardcode, tidak
lagi divalidasi dari input), dan pembersihan 12 baris lama `pola_kunjungan_tidak_wajar` di database pengembangan.

## Acceptance criteria

- [x] `config('marketama.tanda_outlet')` hanya berisi `outlet_fiktif => 'Outlet fiktif'`.
- [x] `POST /outlet_all/tanda` tidak menerima/mensyaratkan field `kategori`; baris yang disimpan selalu berkategori
      `outlet_fiktif`.
- [x] Menandai outlet yang sudah punya tanda aktif ditolak 422 dengan galat pada kunci `outlet_id` (bukan
      `kategori`), berisi nama pemberi dan waktu tanda yang sudah ada.
- [x] Baris `tanda_outlet` lama berkategori `pola_kunjungan_tidak_wajar` (12 baris) terhapus dari database
      pengembangan.

## Implementation steps

- [x] Edit `config/marketama.php` (`tanda_outlet`).
- [x] Edit `TandaOutletController@store`: hapus rule `kategori`, tambah konstanta `KATEGORI = 'outlet_fiktif'`,
      pindahkan galat tanda kembar ke kunci `outlet_id`.
- [x] Hapus baris `tanda_outlet` lama berkategori `pola_kunjungan_tidak_wajar`.
- [x] Sesuaikan `TandaOutletTest`.

## Blocked by

None
