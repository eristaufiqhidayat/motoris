import { computed, toValue } from 'vue';

// Angka turunan dan nada kartu yang dipakai bersama oleh tab-tab Beranda admin (spec 066/067). `data` (ref atau objek) berisi prop
// halaman: target, kendala, tautan, ringkasanDepo, funnel, tren, outletDepo, tandaMotoris, perbandingan, koordinator.

const persen = (a, b) => (b > 0 ? (a / b) * 100 : 0);

// Nada kartu: arah perubahan untuk metrik penyebaran, capaian target untuk metrik aktivitas (batas sama dengan warnaCapai).
export const nadaArah = (a, b) => (b <= 0 || a === b ? 'netral' : (a > b ? 'sukses' : 'bahaya'));
export const nadaCapai = (n) => (n === null || n === undefined ? 'netral' : (n >= 100 ? 'sukses' : (n >= 75 ? 'peringatan' : 'bahaya')));
// Metrik keburukan (outlet fiktif, NOO ditolak): turun = baik.
export const nadaTurun = (a, b) => nadaArah(b, a);
export const nadaJumlah = (n, nada = 'peringatan') => (n > 0 ? nada : 'sukses');

export function useMetrikBeranda(data) {
    const d = computed(() => toValue(data));
    const kini = computed(() => d.value.perbandingan?.sekarang ?? null);
    const lalu = computed(() => d.value.perbandingan?.sebelumnya ?? null);
    const kpi = computed(() => d.value.ringkasanDepo?.kpi ?? null);

    const rasio = (terbukti, wajar) => persen(terbukti ?? 0, (terbukti ?? 0) + (wajar ?? 0));

    return {
        kini,
        lalu,
        kpi,
        jangkauan: computed(() => (kini.value ? persen(kini.value.outlet, d.value.perbandingan.outlet_terdaftar) : 0)),
        roKini: computed(() => (kini.value ? persen(kini.value.ro, kini.value.outlet) : 0)),
        roLalu: computed(() => (lalu.value ? persen(lalu.value.ro, lalu.value.outlet) : 0)),
        fiktifKini: computed(() => rasio(kini.value?.fiktif_terbukti, kini.value?.fiktif_wajar)),
        fiktifLalu: computed(() => rasio(lalu.value?.fiktif_terbukti, lalu.value?.fiktif_wajar)),
        nooTolakKini: computed(() => rasio(kini.value?.noo_ditolak, kini.value?.noo_disetujui)),
        nooTolakLalu: computed(() => rasio(lalu.value?.noo_ditolak, lalu.value?.noo_disetujui)),
        strikeRate: computed(() => (kini.value ? persen(kini.value.ec, kini.value.call) : 0)),
        funnelOss: computed(() => d.value.funnel?.find((f) => f.key === 'oss')?.jumlah ?? 0),
    };
}

// Jumlah kartu bermasalah per tab, untuk lencana pada nama tab (supaya hal penting tidak terlewat saat tab-nya tidak dibuka).
export function lencanaTab(data) {
    const d = toValue(data);
    const k = d.koordinator?.nasional;

    return {
        // Tab Outlet & Motoris: antrean verifikasi NOO yang menunggu dan motoris yang ditandai sistem.
        outlet: (d.perbandingan?.menunggu_total > 0 ? 1 : 0) + (d.tandaMotoris?.motoris > 0 ? 1 : 0),
        // Tab Koordinator & Depo: depo tertinggal, koordinator perlu audit, temuan dan NOO yang menunggu lama.
        koordinator: (d.ringkasanDepo?.depoTertinggal > 0 ? 1 : 0) + (k ? (k.perlu_audit > 0 ? 1 : 0) + (k.menunggu_lama > 0 ? 1 : 0) + (k.noo_menunggu_lama > 0 ? 1 : 0) : 0),
    };
}
