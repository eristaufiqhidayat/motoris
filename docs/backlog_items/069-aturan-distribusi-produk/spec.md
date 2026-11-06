# Aturan Distribusi Produk per Kota dengan Override per Depo

GitHub issue: [#201](https://github.com/eristaufiqhidayat/enesis/issues/201)

## Masalah

Admin menentukan produk apa saja yang boleh didistribusikan di suatu kota, tetapi aturan ini belum ada di Enesis. Form
muatan canvassing menawarkan seluruh `mk_produk` ke setiap depo, sehingga koordinator bisa menyiapkan produk yang
tidak seharusnya beredar di kotanya untuk motoris.

Mengatur satu per satu per depo juga berat: satu kota bisa punya banyak depo, dan aturannya umumnya sama. Di sisi lain
ada depo yang perlu berbeda dari kotanya. Aturan juga bisa berubah sewaktu-waktu, dan perubahan tidak boleh mengubah
makna data yang sudah terjadi.

## Solusi

Admin menetapkan daftar produk per kota (City Group). Semua depo di kota itu otomatis mengikuti, termasuk saat aturan
kota berubah. Depo bisa punya pengecualian per produk (izinkan atau larang), dan bisa dikembalikan ke aturan kota.

Setiap aturan punya tanggal mulai berlaku, jadi perubahan menambah baris baru dan riwayat tetap ada. Form muatan
canvassing hanya menawarkan produk yang diizinkan untuk depo dan tanggal muatan itu, dan server menolak yang lain.

## User stories

1. Sebagai admin, saya memilih kota dan mencentang produk yang boleh didistribusikan, supaya semua depo di kota itu
   mengikuti tanpa diatur satu per satu.
2. Sebagai admin, saya mengubah aturan kota, dan semua depo yang tidak punya pengecualian otomatis ikut.
3. Sebagai admin, saya mengizinkan produk di satu depo walaupun kotanya tidak mengizinkan.
4. Sebagai admin, saya melarang produk di satu depo walaupun kotanya mengizinkan.
5. Sebagai admin, saya melihat depo mana yang punya pengecualian dan produk apa saja, supaya perbedaannya tidak
   tersembunyi.
6. Sebagai admin, saya mengembalikan pengecualian depo ke aturan kota.
7. Sebagai admin, saya menentukan tanggal mulai berlaku, termasuk tanggal yang akan datang, supaya perubahan bisa
   disiapkan lebih dulu.
8. Sebagai admin, saya melihat riwayat perubahan (produk, kota/depo, tanggal berlaku, pengubah), supaya bisa menjawab
   aturan apa yang berlaku pada tanggal tertentu.
9. Sebagai admin dengan cakupan sebagian depo, saya hanya bisa mengubah aturan depo dalam cakupan saya, dan tidak bisa
   mengubah aturan kota yang berdampak ke depo di luar cakupan saya.
10. Sebagai koordinator atau staf depo, saya hanya melihat produk yang diizinkan saat menyiapkan muatan, supaya tidak
    salah pilih.
11. Sebagai koordinator atau staf depo, saya mendapat pesan yang jelas bila produk ditolak.
12. Sebagai koordinator atau staf depo, saya tetap bisa mengubah qty muatan Disiapkan walaupun salah satu produknya
    baru saja dilarang.
13. Sebagai koordinator di depo yang kotanya belum diatur, saya tetap bisa menyiapkan muatan seperti biasa, supaya
    fitur ini tidak menghentikan operasional saat diluncurkan.

## Keputusan

- **Kunci kota adalah `mk_depo.city_group_id`, bukan nama.** Nama City Group (`city_group.nama_city_group`) hanya untuk
  tampilan, jadi aturan tidak rusak bila kota diganti namanya. Ini sama dengan sumber kota di
  `KuotaMotoris::depo()`, kecuali cadangan `data.kota_besar` yang tidak punya ID.
- **Depo mengalahkan kota.** Urutan penyelesaian untuk produk P di depo D pada tanggal T:
  1. Ambil baris depo terbaru dengan `berlaku_mulai <= T`. Bila `diizinkan` true atau false, itu hasilnya.
  2. Bila tidak ada, atau `diizinkan` null (ikut kota), ambil baris kota terbaru dengan `berlaku_mulai <= T`.
  3. Bila baris kota ada, nilainya hasilnya. Bila tidak ada, produk tidak diizinkan.
- **Depo yang belum diatur tidak dibatasi.** Bila pada tanggal T tidak ada satu pun baris aturan untuk depo itu maupun
  kotanya, semua produk diizinkan. Tanpa ini, semua form muatan akan kosong begitu fitur dirilis. Berbeda dari
  rancangan awal yang menolak bila tidak ada aturan: larangan hanya berlaku setelah kota atau depo itu pernah diatur.
- **Depo tanpa City Group** (`city_group_id` null) hanya bisa diatur per depo. Halaman menampilkannya sebagai kelompok
  "Tanpa kota" dengan keterangan bahwa aturan kota tidak berlaku.
- **Riwayat memakai baris bertanggal**, pola yang sama dengan `master_kuota_motoris` tetapi berbasis tanggal, karena
  muatan terjadi harian (`trx_canvassing.tanggal`). Tidak ada baris yang ditimpa antartanggal atau dihapus.
- **Tanggal berlaku tidak boleh di masa lalu.** Minimal hari ini menurut `Pengaturan::sekarang()`, supaya riwayat tidak
  berubah diam-diam. Simpan ulang dengan tanggal berlaku yang sama memperbarui baris itu; rincian perubahannya tercatat
  di `Akses::catat`.
- **Kembali ke aturan kota** menambah baris depo dengan `diizinkan` null, bukan menghapus pengecualian.
- **Penegakan: disaring di form dan ditolak di server.** Hanya produk yang **baru ditambahkan** yang diperiksa.
  Produk yang sudah ada di muatan Disiapkan tetap boleh diubah qty-nya atau dihapus walaupun aturannya berubah.
- **Tanggal pemeriksaan adalah tanggal muatan**, bukan hari ini.
- **Cakupan:** daftar depo mengikuti `Cakupan::untuk($user)->depoIds()`. Aturan kota hanya bisa diubah bila seluruh depo
  kota itu ada dalam cakupan akun.

## Rincian

1. **Migration** `master_produk_distribusi`:
   - `id`, `lingkup` (`kota` atau `depo`), `lingkup_id` (ID `city_group` atau `mk_depo`), `produk_id`.
   - `diizinkan` (boolean nullable, null hanya untuk lingkup depo), `berlaku_mulai` (date).
   - `diubah_oleh`, timestamps.
   - Unique `(lingkup, lingkup_id, produk_id, berlaku_mulai)`. Kolom `kota` dan `depo_id` terpisah tidak dipakai karena
     unique index tidak menegakkan baris ber-NULL.
2. **Modul** `App\Support\Distribusi\ProdukDistribusi`, satu-satunya tempat aturan penyelesaian:
   - `untukDepo(int $depoId, string $tanggal)`: produk yang diizinkan, atau penanda "belum diatur".
   - `diizinkan(int $depoId, array $produkIds, string $tanggal)`: produk yang ditolak, untuk validasi.
   - Penyimpanan aturan kota dan depo, dengan baris yang hanya dibuat bila nilainya berubah dari yang berlaku.
3. **Halaman** Master Data › Distribusi Produk, slug `distribusi_produk`, didaftarkan seperti `kuota_motoris`
   (`config/marketama.php`, route dengan middleware `hak:distribusi_produk`, role yang relevan):
   - `DistribusiProdukController` dan `Pages/Distribusi/Index.vue`, memakai `Card`, `Badge`, `Button`, `DatePicker`,
     `Checkbox`, `Tabs`.
   - Pilih kota dan tanggal berlaku, lalu daftar produk dengan pencarian dan centang.
   - Daftar depo kota itu dengan lencana "N pengecualian". Depo dibuka untuk mengubah pengecualian per produk dan
     tombol "Kembalikan ke aturan kota".
   - Bagian riwayat seperti `Motoris/Kuota.vue`.
4. **Canvassing** (`BarangCanvassingController`):
   - `opsi` menerima tanggal dan menyaring `produk` lewat `ProdukDistribusi`. Form memuat ulang opsi saat depo atau
     tanggal berubah.
   - `store` menolak produk yang tidak diizinkan untuk depo dan tanggal muatan.
   - `ubahItem` memakai `depo_id` dan `tanggal` muatan, dan hanya memeriksa produk yang belum ada di muatan.
5. **Backup**: `master_produk_distribusi` masuk daftar `ENESIS` di `App\Support\Backup\DaftarTabel`.
6. **CLAUDE.md**: tambahkan catatan modul ini di bagian arsitektur.

## Pengujian

Seam utama adalah HTTP: `$this->post/put` lalu `assertInertia` atau JSON, tidak membaca ulang database untuk hal yang
sudah dikembalikan response. Modul `ProdukDistribusi` diuji langsung untuk aturan penyelesaian karena kombinasinya
banyak.

- Modul: kota saja, depo mengizinkan, depo melarang, depo null ikut kota, belum diatur, tanggal berlaku masa depan
  belum berlaku, baris terbaru menang, depo tanpa City Group.
- `opsi` hanya mengembalikan produk yang diizinkan, sesuai tanggal yang diminta.
- `store` menolak produk terlarang dengan pesan pada `items.*.produk_id`.
- `ubahItem` menerima produk lama yang kini dilarang dan menolak produk baru yang dilarang.
- Halaman: hak `ubah` wajib, tanggal lampau ditolak, aturan kota ditolak bila ada depo di luar cakupan.
- Vitest untuk komponen Vue bila ada logika tampilan yang tidak sepele.

## Di luar cakupan

- Kuantitas atau kuota per produk; aturan hanya boleh atau tidak.
- Pelaporan pelanggaran pada data lama, dan laporan produk di luar aturan.
- Aturan per koordinator atau per motoris.
- Perubahan aplikasi HP; motoris menerima muatan yang sudah disiapkan.
- `php artisan` `SiapkanCanvassing` dan impor Marketama tidak diperiksa aturan ini.
- Tabel `template_barang_canvassing`.

## Catatan

- Aturan kota dibaca dari `city_group_id` depo saat ini. Bila depo pindah kota, penelusuran tanggal lampau memakai kota
  barunya. Ini cukup selama aturan hanya dipakai untuk muatan baru, tetapi perlu dipertimbangkan bila kelak dipakai
  untuk laporan historis.
- Depo yang hanya punya `data.kota_besar` tanpa `city_group_id` masuk kelompok "Tanpa kota". Jumlahnya perlu dicek di
  data produksi sebelum rilis.
- Kota yang semua produknya dilarang membuat form muatan kosong. Halaman admin menampilkan peringatan sebelum simpan.

## Pertanyaan terbuka

Tidak ada. Keputusan "belum diatur berarti tidak dibatasi" dan "depo tanpa City Group hanya diatur per depo" sudah
dikonfirmasi pemilik produk (2026-11-06).
