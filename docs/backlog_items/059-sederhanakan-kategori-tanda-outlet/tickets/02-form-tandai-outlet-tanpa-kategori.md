# Form Tandai Outlet jadi dialog konfirmasi tanpa pilihan kategori

## Parent

Spec: [docs/backlog_items/059-sederhanakan-kategori-tanda-outlet/spec.md](../spec.md)

## What to build

`TandaOutlet.vue` tidak lagi menampilkan Select kategori — hanya catatan opsional, lalu Simpan/Batal. Prop `saran`
dihapus (tidak ada lagi pemanggil yang mengirimnya, lihat tiket 03).

## Acceptance criteria

- [x] Form Tandai outlet hanya punya kolom catatan (opsional) dan tombol Simpan/Batal — tidak ada pemilihan kategori.
- [x] Kirim tanda tidak menyertakan `kategori` di body request.
- [x] Galat 422 pada `outlet_id` (tanda kembar) ditampilkan sebagai pesan umum di atas form, bukan `FieldError` pada
      field yang tidak ada lagi.
- [x] Komponen tidak lagi punya prop `saran` atau pengisian otomatis darinya.

## Implementation steps

- [x] Edit `Pages/Menu/partials/TandaOutlet.vue`: hapus `Select`, `kategori`, `pilihanKategori`, `kategoriDipilih`,
      prop `saran` dan pengisian otomatisnya; `simpan()` tidak mengirim `kategori`; galat non-catatan ditampilkan
      lewat `pesan`.
- [x] Sesuaikan `TandaOutlet.spec.js`.
- [x] Edit `Pages/Menu/partials/RuteHarianPeta.vue` dan `Pages/Menu/Show.vue`: `bukaTandaOutlet`/pemanggilan
      `<TandaOutlet>` tidak lagi mengirim `saran`.

## Blocked by

[01-pangkas-kategori-tanda-outlet.md](01-pangkas-kategori-tanda-outlet.md) (kontrak server — hardcode kategori,
kunci galat `outlet_id`)
