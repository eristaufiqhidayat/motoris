# Sederhanakan Kategori Tanda Outlet

## Perubahan

Meninjau ulang desain **tanda outlet** (spec 053) dan saran otomatisnya dari **indikasi jarak dekat antar kunjungan**
(spec 054), ditemukan dua masalah: dua dari empat kategori tanda outlet tidak pernah terpakai, dan satu kategori
melekat ke entitas yang salah.

**Kalibrasi sebelum perubahan** (27 baris `tanda_outlet`): `outlet_fiktif` 15, `pola_kunjungan_tidak_wajar` 12
(semuanya dari saran otomatis spec 054, bukan pilihan manual koordinator), `alamat_tidak_sesuai` 0,
`potensi_kecurangan_lainnya` 0.

- **Kategori tanda outlet dipangkas jadi satu: `outlet_fiktif`** ("Outlet fiktif"). `alamat_tidak_sesuai` dan
  `potensi_kecurangan_lainnya` dihapus — belum pernah dipakai, dan begitu `pola_kunjungan_tidak_wajar` dipindah (lihat
  bawah) tidak ada lagi alasan outlet-level lain yang masuk akal dibedakan dari "outlet fiktif" dalam praktik.
- **`pola_kunjungan_tidak_wajar` dihapus dari tanda outlet, dipindah jadi saran otomatis untuk tanda motoris**
  (kategori `kunjungan_tidak_wajar`, sudah ada di `marketama.tanda_motoris`). Runtun kunjungan berturutan berjarak
  dekat (spec 054) adalah soal **perilaku checkin motoris pada hari itu**, bukan sifat permanen outletnya —
  melekatkannya ke outlet bertentangan dengan asumsi desain spec 053 sendiri ("outlet fiktif tetap fiktif siapa pun
  yang mengunjunginya"): motoris lain yang mengunjungi outlet yang sama secara wajar besok tidak menghapus cap itu.
- **Form Tandai Outlet tidak lagi punya pilihan kategori** — hanya catatan opsional lalu Simpan/Batal (modal yang
  membukanya sudah berfungsi sebagai dialog konfirmasi). Kategori selalu `outlet_fiktif` di server: koordinator yang
  menandai outlet secara manual dari drawer Riwayat hanya punya satu alasan masuk akal untuk itu.
- **Saran otomatis dari runtun jarak dekat sekarang mengisi form Tandai Motoris**, bukan Tandai Outlet — tombol
  Tandai outlet pada outlet-outlet dalam runtun itu tidak lagi terisi otomatis apa pun. Bila indikasi GPS tidak wajar
  (spec 050) dan indikasi jarak dekat sama-sama ada pada tanggal yang dilihat, GPS diprioritaskan (sinyal perangkat
  lebih langsung daripada pola jarak antar kunjungan).
- **12 tanda outlet lama berkategori `pola_kunjungan_tidak_wajar` dihapus** dari tabel `tanda_outlet` (data
  pengembangan, bukan data produksi — tidak dipindah/dipetakan ke `tanda_motoris`).

## Dampak pada implementasi

- `config/marketama.php`: `tanda_outlet` jadi `['outlet_fiktif' => 'Outlet fiktif']`.
- `TandaOutletController@store`: tidak lagi menerima input `kategori`; selalu menyimpan `outlet_fiktif`. Galat tanda
  kembar sekarang dilaporkan di kunci `outlet_id` (bukan `kategori`), karena hanya ada satu kategori untuk dikonflikkan.
- `Pages/Menu/partials/TandaOutlet.vue`: form tanpa Select kategori. Prop `saran` dihapus (tidak pernah lagi dikirim
  pemanggil).
- `App\Support\Tracking\AnomaliJarakKunjungan::bentuk()`: `saran.kategori` jadi `kunjungan_tidak_wajar` (bukan
  `pola_kunjungan_tidak_wajar`).
- `Pages/Menu/partials/RuteHarianPeta.vue`: `saran` yang diteruskan ke `TandaMotoris` sekarang gabungan dari indikasi
  GPS (prioritas) dan indikasi jarak kunjungan (fallback); `bukaTandaOutlet` tidak lagi menyertakan saran apa pun.

## Out of Scope

- Mengubah cara runtun jarak dekat ditandai di daftar kunjungan (lencana "Jarak dekat" pada baris kunjungan tetap
  seperti spec 054).
- Menambah kategori tanda outlet baru di masa depan (item backlog tersendiri bila dibutuhkan).
