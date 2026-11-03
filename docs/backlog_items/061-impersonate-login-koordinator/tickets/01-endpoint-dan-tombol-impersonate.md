# Endpoint dan tombol impersonate koordinator

## Parent

Spec: [docs/backlog_items/061-impersonate-login-koordinator/spec.md](../spec.md)

## What to build

Irisan ujung-ke-ujung pertama: route + controller `login.impersonate`, flag config `auth.impersonate_login`, prop
`koordinatorImpersonasi` di `LoginController@create`, dan satu bagian baru di `Auth/Login.vue` dengan dropdown native
(`Select`) berisi koordinator aktif + tombol "Masuk sebagai akun ini".

## Acceptance criteria

- [x] `config/auth.php` punya key `impersonate_login` bawaan `(bool) env('IMPERSONATE_LOGIN', env('APP_ENV') === 'local')`.
- [x] `POST /login/impersonate/{user}` (`App\Http\Controllers\Auth\ImpersonateLoginController@store`, route model
      binding): 404 bila flag mati, 404 bila `$user->kategori !== 'koordinator'` atau `! $user->aktif`; selain itu
      `Auth::login($user)`, regenerate session, update `login_terakhir_pada`, redirect `intended('/home')`.
- [x] `LoginController@create` mengirim prop `koordinatorImpersonasi`: `null` bila flag mati; array
      `{id, name, username, depo_nama}` koordinator aktif (urut nama) bila flag aktif.
- [x] `Auth/Login.vue`: bagian baru di bawah demo, tampil hanya bila prop tidak null/kosong; `Select` berisi opsi
      `"{name} ({depo_nama atau username})"`; tombol submit `router.post('/login/impersonate/' + id)` disabled sampai
      ada pilihan.
- [x] Feature test: flag mati → 404; kategori/aktif salah → 404; sukses → login & redirect `/home`; prop
      `koordinatorImpersonasi` benar untuk kedua kondisi flag.

## Blocked by

None
