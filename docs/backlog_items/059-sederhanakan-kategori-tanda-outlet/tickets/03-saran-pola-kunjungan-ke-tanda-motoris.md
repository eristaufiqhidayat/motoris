# Pindahkan saran "pola kunjungan tidak wajar" ke Tandai Motoris

## Parent

Spec: [docs/backlog_items/059-sederhanakan-kategori-tanda-outlet/spec.md](../spec.md)

## What to build

Saran otomatis dari runtun kunjungan berturutan berjarak dekat (`AnomaliJarakKunjungan`, spec 054) mengisi form
**Tandai Motoris** (kategori `kunjungan_tidak_wajar`, sudah ada di `marketama.tanda_motoris`), bukan Tandai Outlet.
Bila indikasi GPS tidak wajar (spec 050) juga ada pada tanggal itu, saran GPS diprioritaskan.

## Acceptance criteria

- [x] `AnomaliJarakKunjungan::analisis()` mengembalikan `saran.kategori = 'kunjungan_tidak_wajar'` (bukan
      `pola_kunjungan_tidak_wajar`).
- [x] Drawer Riwayat: membuka Tandai outlet pada outlet dalam runtun jarak dekat tidak lagi mengisi apa pun secara
      otomatis.
- [x] Membuka Tandai motoris pada tanggal yang punya indikasi jarak dekat (dan tidak ada indikasi GPS) mengisi
      kategori `kunjungan_tidak_wajar` dengan catatan ringkasan runtun.
- [x] Bila indikasi GPS dan indikasi jarak dekat sama-sama ada, form Tandai motoris terisi dari saran GPS.

## Implementation steps

- [x] Edit `App\Support\Tracking\AnomaliJarakKunjungan::bentuk()`: ganti kategori saran.
- [x] Edit `Pages/Menu/partials/RuteHarianPeta.vue`: computed `saranMotoris` (GPS lalu jarak-kunjungan sebagai
      fallback) dikirim ke `<TandaMotoris :saran>`; `bukaTandaOutlet` tidak lagi menyertakan `saran`.
- [x] Sesuaikan `AnomaliJarakKunjunganTest`, `IndikasiJarakKunjunganDrawerTest`, `RuteHarianPeta.spec.js`,
      `pusatKendali.spec.js`.

## Blocked by

None
