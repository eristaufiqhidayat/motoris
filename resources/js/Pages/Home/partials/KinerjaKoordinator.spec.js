import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import KinerjaKoordinator from './KinerjaKoordinator.vue';

vi.mock('@/Components/ui/molecules/InfoPopover.vue', () => ({ default: { template: '<span data-info><slot /></span>' } }));
vi.mock('@/Components/ui/atoms/Icon.vue', () => ({ default: { render: () => null } }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { get: vi.fn() } }));

const baris = (lain) => ({
    id: 1, nama: 'Koor Satu', depo: 'Sidoarjo', motoris: 5, outlet_per_motoris: 2, temuan: 4, diperiksa: 2, terbukti: 1, tindak_lanjut: 50,
    median_hari: 1.5, menunggu_lama: 1, rasio_terbukti: 50, perlu_audit: false, join_visit: 3, cakupan_join_visit: 40, join_visit_sah: 66.7, noo_median_hari: 2, noo_menunggu_lama: 0, ...lain,
});
const koordinator = {
    nasional: { koordinator: 3, temuan: 40, diperiksa: 30, tindak_lanjut: 75, median_hari: 2, menunggu_lama: 3, rasio_terbukti: 20, cakupan_join_visit: 60, perlu_audit: 1, noo_median_hari: 1.5, noo_menunggu_lama: 4 },
    sebelumnya: { tindak_lanjut: 70, cakupan_join_visit: 50 },
    perhatian: [baris({ id: 1, nama: 'Koor Satu', tindak_lanjut: 50 }), baris({ id: 2, nama: 'Koor Dua', tindak_lanjut: 90, perlu_audit: true, menunggu_lama: 0 }), baris({ id: 3, nama: 'Koor Tiga', tindak_lanjut: null, temuan: 0, diperiksa: 0, median_hari: null, rasio_terbukti: null, join_visit_sah: null })],
};
const nama = (w) => w.findAll('tbody tr').map((tr) => tr.find('td').text().replace('Perlu audit', '').trim());

describe('KinerjaKoordinator', () => {
    it('menampilkan empat kartu nasional dan tabel koordinator sesuai urutan server', () => {
        const w = mount(KinerjaKoordinator, { props: { koordinator } });

        ['Tindak Lanjut Temuan', 'Temuan Menunggu Lama', 'Cakupan Join Visit', 'Verifikasi NOO', 'Koordinator Perlu Audit'].forEach((judul) => expect(w.text()).toContain(judul));
        expect(w.text()).toContain('75%');
        expect(w.text()).toContain('▲ 5 poin'); // 70% → 75%
        expect(nama(w)).toEqual(['Koor Satu', 'Koor Dua', 'Koor Tiga']);
        expect(w.text()).toContain('1,5 hari'); // median verifikasi NOO nasional
        expect(w.text()).toContain('4 menunggu lebih dari 3 hari');
    });

    it('judul tabel punya popover penjelasan perhitungan', () => {
        const w = mount(KinerjaKoordinator, { props: { koordinator } });

        expect(w.find('[data-info]').text()).toContain('Rasio Terbukti');
        expect(w.find('[data-info]').text()).toContain('Cakupan Join Visit');
    });

    it('memberi lencana Perlu audit hanya pada koordinator yang ditandai', () => {
        const w = mount(KinerjaKoordinator, { props: { koordinator } });
        const lencana = w.findAll('tbody tr').map((tr) => tr.text().includes('Perlu audit'));

        expect(lencana).toEqual([false, true, false]);
    });

    it('koordinator tanpa temuan menampilkan strip, bukan 0%', () => {
        const w = mount(KinerjaKoordinator, { props: { koordinator } });
        const tiga = w.findAll('tbody tr')[2].text();

        expect(tiga).toContain('–');
        expect(tiga).not.toContain('0% (');
    });

    it('mengurutkan kolom di sisi klien lalu kembali ke urutan server', async () => {
        const w = mount(KinerjaKoordinator, { props: { koordinator } });
        const tombol = () => w.findAll('.sort-button').find((b) => b.text().includes('Tindak Lanjut'));

        await tombol().trigger('click');
        expect(nama(w)).toEqual(['Koor Tiga', 'Koor Satu', 'Koor Dua']); // null dianggap terendah
        await tombol().trigger('click');
        expect(nama(w)).toEqual(['Koor Dua', 'Koor Satu', 'Koor Tiga']);
        await tombol().trigger('click');
        expect(nama(w)).toEqual(['Koor Satu', 'Koor Dua', 'Koor Tiga']);
    });
});
