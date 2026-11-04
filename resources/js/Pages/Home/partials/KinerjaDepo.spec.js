import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import KinerjaDepo from './KinerjaDepo.vue';

vi.mock('@/Components/ui/atoms/Icon.vue', () => ({ default: { render: () => null } }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { get: vi.fn() } }));

const kinerja = [
    { id: 1, nama: 'Sidoarjo', total_motoris: 10, hari_kerja: 200, avg_call: 40, call_achievement: 80, avg_ec: 20, ec_achievement: 100, avg_ipt: 3, ipt_achievement: 100 },
    { id: 2, nama: 'Balaraja', total_motoris: 5, hari_kerja: 90, avg_call: 50, call_achievement: 100, avg_ec: 10, ec_achievement: 50, avg_ipt: 2, ipt_achievement: 66.7 },
];
const filter = { dari: '2026-10-01', sampai: '2026-10-30' };
const namaUrut = (w) => w.findAll('tbody tr').map((tr) => tr.find('td').text());

describe('KinerjaDepo', () => {
    it('menampilkan satu baris per depo sesuai urutan server', () => {
        const w = mount(KinerjaDepo, { props: { kinerja, filter } });

        expect(namaUrut(w)).toEqual(['Sidoarjo', 'Balaraja']);
        expect(w.text()).toContain('AVG Call');
        expect(w.text()).toContain('100%');
    });

    it('mengurutkan kolom di sisi klien: naik, turun, lalu kembali ke urutan server', async () => {
        const w = mount(KinerjaDepo, { props: { kinerja, filter } });
        const tombol = () => w.findAll('.sort-button').find((b) => b.text().includes('AVG Call'));

        await tombol().trigger('click');
        expect(namaUrut(w)).toEqual(['Sidoarjo', 'Balaraja']); // 40 lalu 50
        await tombol().trigger('click');
        expect(namaUrut(w)).toEqual(['Balaraja', 'Sidoarjo']);
        await tombol().trigger('click');
        expect(namaUrut(w)).toEqual(['Sidoarjo', 'Balaraja']);
    });

    it('menautkan nama depo ke Summary All Depo dengan rentang yang sama hanya bila punya hak', () => {
        const tanpa = mount(KinerjaDepo, { props: { kinerja, filter } });
        const dengan = mount(KinerjaDepo, { props: { kinerja, filter, tautan: true } });

        expect(tanpa.find('tbody a').exists()).toBe(false);
        expect(dengan.find('tbody a').attributes('href')).toBe('/summary_all_depo/1?date_from=2026-10-01&date_to=2026-10-30');
    });
});
