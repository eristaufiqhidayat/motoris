import { mount as pasangVue } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard.vue';

// Deferred sungguhan hanya menampilkan isinya setelah semua prop yang diminta tiba, selain itu fallback.
const state = vi.hoisted(() => ({ props: {} }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Deferred: {
        props: ['data'],
        setup: (p, { slots }) => () => ([].concat(p.data).every((k) => state.props[k] != null) ? slots.default?.() : slots.fallback?.()),
    },
    usePage: () => ({ props: { appName: 'Enesis' } }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/ui/atoms/Icon.vue', () => ({ default: { render: () => null } }));
vi.mock('@/Components/ui/molecules/InfoPopover.vue', () => ({ default: { template: '<span data-info><slot /></span>' } }));
vi.mock('./partials/TrenHarianChart.vue', () => ({ default: { render: () => null } }));
vi.mock('./partials/FunnelOutletChart.vue', () => ({ default: { render: () => null } }));

const dasar = {
    slug: 'home',
    judul: 'Beranda',
    periode: { dari: '04 Sep 2026', sampai: '03 Okt 2026', sama: false },
    target: { call: 40, ec: 35, ipt: 3 },
    kendala: { terbuka: 3, ada_balasan: 1 },
    tautan: { laporan_kendala: '/laporan_kendala', outlet_oss: '/outlet_oss', summary_all_depo: null, motoris_ditandai: '/motoris_ditandai' },
};
const periode = (lain) => ({
    call: 1000, ec: 500, ipt: 3, hari_kerja: 100, avg_call: 10, avg_ec: 5,
    call_achievement: 25, ec_achievement: 14.3, ipt_achievement: 100, outlet: 200, ro: 50, noo: 20, noo_menunggu: 4,
    fiktif_terbukti: 5, fiktif_wajar: 15, noo_disetujui: 18, noo_ditolak: 2, ...lain,
});
const lengkap = {
    ...dasar,
    ringkasanDepo: { kpi: { motoris_aktif: 10, depo_aktif: 3, sk_aktif: 2 }, depoTertinggal: 2 },
    funnel: [{ key: 'oss', jumlah: 7 }],
    tren: [],
    outletDepo: [{ id: 1, nama: 'Sidoarjo', outlet: 20, motoris: 4, per_motoris: 5 }],
    tandaMotoris: { motoris: 1, total: 2 },
    perbandingan: {
        sekarang: periode(), sebelumnya: periode({ outlet: 160, ro: 48, noo: 25, fiktif_terbukti: 10, fiktif_wajar: 10, noo_ditolak: 4, noo_disetujui: 16 }),
        outlet_terdaftar: 1000, menunggu_total: 6, menunggu_umur: 9,
    },
    koordinator: {
        nasional: { koordinator: 10, temuan: 40, diperiksa: 30, tindak_lanjut: 75, median_hari: 2, menunggu_lama: 3, rasio_terbukti: 20, cakupan_join_visit: 60, perlu_audit: 1, noo_median_hari: 1.5, noo_menunggu_lama: 4 },
        sebelumnya: { tindak_lanjut: 70, cakupan_join_visit: 50 },
        perhatian: [{ id: 1, nama: 'Koor Satu', depo: 'Sidoarjo', motoris: 5, outlet_per_motoris: 2, temuan: 4, diperiksa: 2, terbukti: 0, tindak_lanjut: 50, median_hari: 1.5, menunggu_lama: 1, rasio_terbukti: 0, perlu_audit: false, join_visit: 3, cakupan_join_visit: 40, join_visit_sah: 66.7, noo_median_hari: 2, noo_menunggu_lama: 0 }],
    },
};

const mount = (komponen, { props }) => {
    state.props = props;

    return pasangVue(komponen, { props });
};

const bukaTab = async (w, label) => {
    await w.findAll('[role="tab"]').find((t) => t.text().startsWith(label)).trigger('click');
};

describe('Beranda admin (tab)', () => {
    it('membuka tab Ringkasan sebagai tab utama dengan kartu kunci dan tren', () => {
        const w = mount(Dashboard, { props: lengkap });
        const teks = w.text();

        expect(w.findAll('[role="tab"]').map((t) => t.text().replace(/\d+$/, '').trim())).toEqual(['Ringkasan', 'Outlet & Motoris', 'Koordinator & Depo']);
        expect(w.find('[role="tab"][aria-selected="true"]').text()).toBe('Ringkasan');
        ['Outlet Terjangkau', 'Repeat Order', 'Rasio Fiktif', 'Tindak Lanjut Temuan', 'Call', 'Effective Call', 'Laporan Kendala', 'Depo Tertinggal', 'Tren Harian'].forEach((j) => expect(teks).toContain(j));
        expect(teks).toContain('▲ 25%'); // outlet terjangkau 160 → 200
        // Rincian tab lain tidak ikut tampil di Ringkasan.
        const panel = w.find('[role="tabpanel"]').text(); // popover info memuat semua istilah, jadi periksa isi panel saja
        expect(panel).not.toContain('Funnel Outlet');
        expect(panel).not.toContain('Kualitas Data Outlet');
    });

    it('membuka tab sesuai prop tab dari URL', () => {
        const w = mount(Dashboard, { props: { ...lengkap, tab: 'koordinator' } });

        expect(w.find('[role="tab"][aria-selected="true"]').text()).toContain('Koordinator & Depo');
        expect(w.text()).toContain('Koordinator Perlu Perhatian');
    });

    it('tab Outlet & Motoris memuat penyebaran, kualitas data outlet, aktivitas motoris, dan funnel', async () => {
        const w = mount(Dashboard, { props: lengkap });
        await bukaTab(w, 'Outlet');
        const teks = w.text();

        ['Penyebaran Produk ke Outlet', 'Outlet Baru (NOO)', 'Outlet Stok Kosong', 'Kualitas Data Outlet', 'Outlet Terbukti Fiktif', 'NOO Ditolak', 'Funnel Outlet'].forEach((j) => expect(teks).toContain(j));
        expect(w.find('[role="tabpanel"]').text()).not.toContain('Outlet Terjangkau per Motoris'); // grafik depo ada di tab Koordinator & Depo
        expect(teks).toContain('20% dari 1.000 terdaftar');
        expect(teks).toContain('▼ 5 poin'); // repeat order 30% → 25%
        expect(teks).toContain('4 menunggu verifikasi');
        // Rasio fiktif 25% (5 dari 20), sebelumnya 50% → turun 25 poin = membaik.
        expect(teks).toContain('▼ 25 poin');
        expect(teks).toContain('5 terbukti dari 20 diperiksa');
        expect(teks).toContain('Tertua 9 hari.');
    });

    it('tab Outlet & Motoris juga memuat aktivitas motoris, tanpa depo', async () => {
        const w = mount(Dashboard, { props: lengkap });
        await bukaTab(w, 'Outlet');
        const teks = w.text();

        expect(w.find('[role="tabpanel"]').text()).not.toContain('Depo Tertinggal');
        ['Aktivitas Motoris', 'IPT', 'Motoris Aktif', 'Hari Kerja', 'Motoris Ditandai'].forEach((j) => expect(teks).toContain(j));
        expect(teks).toContain('strike rate 50%');
        expect(teks).toContain('10 hari / motoris');
        expect(w.findAll('a').map((a) => a.attributes('href'))).toContain('/motoris_ditandai');
    });

    it('tab Koordinator & Depo memuat depo dan kinerja koordinator, bukan data outlet', async () => {
        const w = mount(Dashboard, { props: lengkap });
        await bukaTab(w, 'Koordinator');
        const panel = w.find('[role="tabpanel"]').text();

        ['Depo Tertinggal', 'Outlet Terjangkau per Motoris', 'Kinerja Koordinator', 'Koordinator Perlu Perhatian', 'Tindak Lanjut Temuan'].forEach((j) => expect(panel).toContain(j));
        expect(panel).not.toContain('Kualitas Data Outlet');
    });

    it('klik kartu di Ringkasan membuka tab rinciannya dan mengganti URL', async () => {
        const ganti = vi.spyOn(window.history, 'replaceState');
        const w = mount(Dashboard, { props: lengkap });

        await w.findAll('button').find((b) => b.text().includes('Outlet Terjangkau')).trigger('click');

        expect(w.find('[role="tab"][aria-selected="true"]').text()).toContain('Outlet');
        expect(ganti.mock.calls.at(-1)[2].search).toBe('?tab=outlet');
        ganti.mockRestore();
    });

    it('lencana tab menghitung kartu bermasalah di tab itu', () => {
        const w = mount(Dashboard, { props: lengkap });
        const lencana = Object.fromEntries(w.findAll('[role="tab"]').map((t) => [t.text().replace(/\d+$/, '').trim(), t.find('.badge').exists() ? t.find('.badge').text() : null]));

        expect(lencana).toEqual({ Ringkasan: null, 'Outlet & Motoris': '2', 'Koordinator & Depo': '4' });
    });

    it('menautkan kartu Laporan Kendala di Ringkasan', () => {
        const href = mount(Dashboard, { props: lengkap }).findAll('a').map((a) => a.attributes('href'));

        expect(href).toContain('/laporan_kendala');
    });

    it('tetap bisa dirender sebelum prop tertunda tiba', () => {
        const w = mount(Dashboard, { props: dasar });

        expect(w.find('[role="tab"][aria-selected="true"]').text()).toBe('Ringkasan');
        expect(w.find('[aria-busy="true"]').exists()).toBe(true);
    });
});
