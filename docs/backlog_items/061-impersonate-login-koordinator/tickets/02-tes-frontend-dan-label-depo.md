# Tes frontend dan label depo pada daftar impersonate

## Parent

Spec: [docs/backlog_items/061-impersonate-login-koordinator/spec.md](../spec.md)

## What to build

Lengkapi ticket 01 dengan join `mk_karyawan`/`mk_depo` untuk label `depo_nama` (bila belum penuh di ticket 01) dan
tes Vitest untuk bagian impersonate di `Login.vue`.

## Acceptance criteria

- [x] Query koordinator pakai `leftJoin('mk_karyawan', ...)` + `leftJoin('mk_depo', ...)` sehingga `depo_nama` terisi
      untuk koordinator yang `karyawan_id`-nya terhubung ke `mk_karyawan.depo_id` yang valid; `null` bila tidak
      terhubung (bukan error).
- [x] Koordinator nonaktif (`aktif = false`) tidak pernah muncul di daftar, walau flag aktif.
- [x] Vitest `Login.spec.js`: bagian impersonate tidak dirender bila `koordinatorImpersonasi` null atau `[]`; bila
      berisi data, dropdown menampilkan label `"{name} ({depo_nama atau username})"` dan menekan tombol memanggil
      `router.post` dengan URL `/login/impersonate/{id}` yang dipilih.

## Blocked by

- [01-endpoint-dan-tombol-impersonate.md](01-endpoint-dan-tombol-impersonate.md)
