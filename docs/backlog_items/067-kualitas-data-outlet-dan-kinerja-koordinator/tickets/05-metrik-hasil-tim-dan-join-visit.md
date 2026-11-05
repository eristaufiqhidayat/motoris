# Metrik hasil tim dan join visit per koordinator

## Parent

Spec: [docs/backlog_items/067-kualitas-data-outlet-dan-kinerja-koordinator/spec.md](../spec.md)

## What to build

Tambahan pada `KinerjaKoordinator`: outlet terjangkau per motoris tim (definisi tiket 02), cakupan join visit (% motoris
tim yang di-join visit minimal sekali), dan join visit sah (tanpa mock location, ada `selesai_at`, `jarak_m` ≤ ambang
config). Satu baris per koordinator.

## Acceptance criteria

- [x] Outlet per motoris tim tidak menghitung outlet terbukti fiktif.
- [x] Join visit dengan mock location, tanpa selesai, atau terlalu jauh tidak dihitung sah.
- [x] Feature test.

## Blocked by

- [02-koreksi-noo-dan-outlet-terjangkau.md](02-koreksi-noo-dan-outlet-terjangkau.md)
