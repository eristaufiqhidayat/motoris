import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DetailDepoTim from './DetailDepoTim.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';

const total = {
    hari_kerja: 4, call: 4, ec: 2, sales: 170000, sku: 6, point: 0,
    avg_call: 1, avg_ec: 0.5, avg_sales: 42500, avg_ipt: 3, avg_point: 0,
};
const sales = [
    { id: 1, nama: 'Rudi Hartono', aktif: true, hari_kerja: 3, call: 2, ec: 1, sales: 120000, sku: 4, point: 0, avg_call: 0.7, avg_ec: 0.3, avg_sales: 40000, avg_ipt: 4, avg_point: 0, ditandai: true },
    { id: 2, nama: 'Tegar Mahendra', aktif: true, hari_kerja: 1, call: 2, ec: 1, sales: 50000, sku: 2, point: 0, avg_call: 2, avg_ec: 1, avg_sales: 50000, avg_ipt: 2, avg_point: 0, ditandai: false },
];
const tim = [{ koordinator_id: 137, nama: 'Koor Satu', avg_visit: 1, sales, akumulasi: total }];

const pasang = (props = {}) => mount(DetailDepoTim, {
    props: { total, tim, ...props },
    global: { stubs: { Icon: true } },
});

describe('DetailDepoTim', () => {
    it('menampilkan kotak "Total keseluruhan depo" secara bawaan', () => {
        const wrapper = pasang();

        expect(wrapper.text()).toContain('Total keseluruhan depo');
    });

    it('menyembunyikan kotak itu saat tanpaTotalDepo diisi (Beranda Koordinator, cuma satu tim)', () => {
        const wrapper = pasang({ tanpaTotalDepo: true });

        expect(wrapper.text()).not.toContain('Total keseluruhan depo');
        // Baris Akumulasi di tabel tetap tampil — satu-satunya tempat total ini ditampilkan.
        expect(wrapper.text()).toContain('Akumulasi');
    });

    it('memakai judul kolom "Nama Motoris"', () => {
        expect(pasang().text()).toContain('Nama Motoris');
    });

    it('menampilkan lencana bendera hanya di samping nama motoris yang ditandai', () => {
        const wrapper = pasang();
        const baris = wrapper.findAll('tbody tr');

        expect(baris[0].text()).toContain('Rudi Hartono');
        expect(baris[0].findComponent(Icon).exists()).toBe(true);
        expect(baris[1].text()).toContain('Tegar Mahendra');
        expect(baris[1].findComponent(Icon).exists()).toBe(false);
    });
});
