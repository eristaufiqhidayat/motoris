# Impersonate login sebagai koordinator asli (lokal)

## Masalah

Fitur "Demo masuk cepat" di halaman login hanya membuat/memakai satu akun palsu per kategori (`demo.koordinator`,
dsb.) yang tidak punya `karyawan_id`. Login sebagai akun demo ini tidak menunjukkan data sungguhan: cakupan role
`downline` di `Akses::batasiKaryawan` butuh `karyawan_id` akun untuk tahu siapa bawahannya, jadi akun demo melihat
data kosong atau tidak representatif. Untuk menguji tampilan dari sudut pandang koordinator sungguhan (outlet,
sales, kunjungan, laporan yang benar-benar dia lihat), developer harus tahu password akun itu — yang tidak tersedia
di lokal (password akun hasil migrasi di-hash, bukan teks biasa).

## Solusi

Tambah pilihan **"Impersonate koordinator"** di halaman login, di samping tombol demo yang sudah ada: daftar akun
`User` sungguhan berkategori `koordinator` (aktif), dipilih dari dropdown pencarian, masuk ke akun itu tanpa
password. Hanya aktif di environment lokal, sama seperti `demo_login`.

## User stories

1. Sebagai developer di lokal, saya membuka halaman login dan melihat bagian baru "Impersonate koordinator"
   berisi dropdown berisi nama koordinator asli (dari tabel `users`, kategori `koordinator`, aktif) beserta nama
   depo/area agar mudah membedakan yang namanya sama.
2. Sebagai developer, saya memilih satu koordinator dan menekan tombol "Masuk sebagai akun ini", lalu langsung masuk
   ke sesi akun itu (tanpa isi password) dan diarahkan ke `/home`, melihat sidebar/menu/data persis seperti yang
   dilihat koordinator itu kalau dia login sendiri.
3. Sebagai developer, saya tidak melihat bagian ini sama sekali bila fitur dimatikan (mis. `APP_ENV` bukan `local`
   dan flag env tidak diaktifkan) — perilaku identik dengan bagaimana tombol demo disembunyikan sekarang.
4. Sebagai developer, saya tidak bisa memicu endpoint impersonate ini lewat permintaan langsung (curl dsb.) ketika
   fitur dimatikan atau akun targetnya bukan koordinator aktif — keduanya dibalas 404, sama seperti pola
   `DemoLoginController`.

## Keputusan implementasi

- **Config** `config/auth.php`: key baru `impersonate_login` bawaan `(bool) env('IMPERSONATE_LOGIN', env('APP_ENV') === 'local')` — flag sendiri (tidak digabung ke `demo_login`) supaya bisa dimatikan terpisah, tapi bawaan sama (lokal saja). Tidak ada key daftar peran statis seperti `demo_peran`; daftar koordinator diambil langsung dari tabel `users` saat request.
- **Route** `POST /login/impersonate/{user}` (route model binding ke `App\Models\User`, nama rute `login.impersonate`), ditangani `App\Http\Controllers\Auth\ImpersonateLoginController@store`:
  - `abort_unless(config('auth.impersonate_login'), 404)`.
  - `abort_unless($user->kategori === 'koordinator' && $user->aktif, 404)` — hanya koordinator aktif, baik akun itu tidak ditemukan (model binding 404 otomatis) atau kategorinya salah.
  - `Auth::login($user)`, `session()->regenerate()`, update `login_terakhir_pada`, redirect `intended('/home')` — pola sama persis dengan `DemoLoginController::store`, tidak melalui cek password/`Akses::bolehWeb` (konsisten dengan demo login yang juga melewati pengecekan itu; developer yang pilih daftarnya sendiri).
- **`LoginController@create`**: tambah prop Inertia `koordinatorImpersonasi` — null bila `! config('auth.impersonate_login')`, selain itu array hasil query:
  `User::where('kategori', 'koordinator')->where('aktif', true)->leftJoin('mk_karyawan', 'mk_karyawan.id', '=', 'users.karyawan_id')->leftJoin('mk_depo', 'mk_depo.id', '=', 'mk_karyawan.depo_id')->orderBy('users.name')->get(['users.id', 'users.name', 'users.username', 'mk_depo.nama as depo_nama'])`
  — setiap entri `{id, name, username, depo_nama}` (`depo_nama` bisa null bila karyawan tidak punya depo atau akun tidak terhubung `karyawan_id`).
- **UI `Auth/Login.vue`**: bagian baru di bawah bagian demo, tampil hanya bila `koordinatorImpersonasi` tidak null dan tidak kosong. Label tiap opsi: `"{name} ({depo_nama atau username})"`. Dropdown pencarian pakai `Select` atom yang sudah ada (opsi diurutkan nama); kalau daftarnya panjang di data sungguhan, cukup `Select` native (tidak perlu komponen combobox baru) karena ini halaman dev-only, bukan halaman produk. Tombol submit memanggil `router.post('/login/impersonate/' + id)`, disabled sampai ada pilihan.
- **Tidak ada banner "kembali ke akun admin"**: ini login langsung sebagai akun koordinator (bukan admin yang menyamar sementara lalu kembali) — untuk kembali, developer logout dan login ulang seperti biasa. Ini sesuai keputusan di pertanyaan klarifikasi (kontrol ditempatkan di halaman login, bukan sesi admin).
- **Tidak tercatat di `riwayat_admin`**: simetris dengan `DemoLoginController` yang juga tidak mencatat riwayat admin untuk aksi login itu sendiri.
- **Tidak mengubah `demo_login`/`DemoLoginController`** — dua fitur berdampingan, bagian terpisah di halaman yang sama.

## Testing

Feature test `tests/Feature/Auth/ImpersonateLoginTest.php`:
- `impersonate_login` aktif + koordinator aktif → POST ke `login.impersonate` login sebagai akun itu, redirect `/home`.
- `impersonate_login` nonaktif → 404 walau user koordinator aktif ada.
- Target bukan koordinator (mis. kategori `sales`) → 404.
- Target koordinator tapi `aktif = false` → 404.
- `LoginController@create`: prop `koordinatorImpersonasi` null ketika flag mati; berisi daftar koordinator aktif (urut nama, dengan `depo_nama`) ketika flag aktif; koordinator nonaktif tidak ikut daftar.

Vitest `Login.spec.js` (tambahan pada spec yang sudah ada bila ada, atau baru): bagian impersonate tidak tampil bila
prop null/kosong; tampil dan memanggil endpoint yang benar saat ada pilihan dan tombol ditekan.

## Out of Scope

- Impersonate kategori lain (`sales`, `lainnya`, `super_admin`) — hanya koordinator, sesuai permintaan.
- Mode "sesi ganda" (admin tetap login, lihat sebagai koordinator di tab terpisah, lalu kembali) — login langsung
  mengganti sesi, sama seperti demo login.
- Aktif di staging/production — tetap dev-only lewat flag env, sama seperti `demo_login`.
- Audit trail/log impersonasi di `riwayat_admin`.
