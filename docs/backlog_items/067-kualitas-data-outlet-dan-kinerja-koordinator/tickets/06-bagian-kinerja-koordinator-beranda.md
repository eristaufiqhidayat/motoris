# Bagian Kinerja Koordinator di Beranda admin

## Parent

Spec: [docs/backlog_items/067-kualitas-data-outlet-dan-kinerja-koordinator/spec.md](../spec.md)

## What to build

Prop tertunda grup `koordinator` di Beranda admin (cache 5 menit, cakupan akun). Bagian **Kinerja Koordinator**: 4
`StatCard` nasional (Tindak Lanjut Temuan, Temuan Menunggu > 7 Hari, Cakupan Join Visit, Koordinator Perlu Audit) dan
`DataTable` **Koordinator Perlu Perhatian** (10 baris, urut tindak lanjut lalu cakupan join visit terendah, kolom bisa
diurutkan, lencana Perlu audit). InfoPopover diperbarui.

## Acceptance criteria

- [x] Akun cakupan depo hanya melihat koordinator di depo-nya.
- [x] KPI/penyebaran tidak menunggu bagian ini (grup defer sendiri).
- [x] Hanya komponen dari `Components/ui/`; status pakai `Badge`.
- [x] Feature test props; Vitest tabel.
- [ ] Tampilan dicek di browser. (belum dicek)

## Blocked by

- [04-metrik-tindak-lanjut-temuan-koordinator.md](04-metrik-tindak-lanjut-temuan-koordinator.md)
- [05-metrik-hasil-tim-dan-join-visit.md](05-metrik-hasil-tim-dan-join-visit.md)
