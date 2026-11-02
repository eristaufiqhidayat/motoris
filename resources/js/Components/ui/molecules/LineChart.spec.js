import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LineChart from './LineChart.vue';

const labels = ['01/11', '02/11', '03/11'];
const series = [
    { key: 'call', label: 'Call', warna: 'biru', values: [10, 40, 20] },
    { key: 'ec', label: 'Effective Call', warna: 'jingga', values: [5, 25, 15] },
];

describe('LineChart', () => {
    it('menggambar satu garis dan satu titik per data untuk tiap seri', () => {
        const wrapper = mount(LineChart, { props: { labels, series } });

        expect(wrapper.findAll('polyline')).toHaveLength(2);
        expect(wrapper.findAll('circle')).toHaveLength(6);
    });

    it('menampilkan legenda bila seri lebih dari satu', () => {
        const wrapper = mount(LineChart, { props: { labels, series } });
        const legenda = wrapper.find('[aria-label="Legenda grafik"]');

        expect(legenda.text()).toContain('Call');
        expect(legenda.text()).toContain('Effective Call');
        expect(mount(LineChart, { props: { labels, series: [series[0]] } }).find('[aria-label="Legenda grafik"]').exists()).toBe(false);
    });

    it('tooltip titik memuat label, nama seri, dan nilai', () => {
        const wrapper = mount(LineChart, { props: { labels, series } });

        expect(wrapper.findAll('circle title').map((t) => t.text())).toContain('02/11 · Effective Call: 25');
    });

    it('sumbu Y memakai batas atas bulat yang sama untuk semua seri, dan garis ber-warna sesuai seri', () => {
        const wrapper = mount(LineChart, { props: { labels, series } });

        // Nilai terbesar 40 -> batas 50; garis bantu 0, 25, 50.
        expect(wrapper.findAll('svg > g > text').slice(0, 3).map((t) => t.text())).toEqual(['0', '25', '50']);
        expect(wrapper.findAll('polyline')[0].classes()).toContain('stroke-blue-500');
        expect(wrapper.findAll('polyline')[1].classes()).toContain('stroke-orange-500');
    });

    describe('lebar', () => {
        const ukuran = (px) => vi.spyOn(Element.prototype, 'clientWidth', 'get').mockReturnValue(px);
        const tanpaPengamat = () => { delete global.ResizeObserver; };

        afterEach(() => vi.restoreAllMocks());

        it('mengisi seluruh lebar wadah saat titik sedikit', async () => {
            tanpaPengamat();
            ukuran(900);
            const wrapper = mount(LineChart, { props: { labels, series } });
            await wrapper.vm.$nextTick();

            expect(wrapper.find('svg').attributes('width')).toBe('900');
        });

        it('melebar sesuai jumlah titik (digeser mendatar) bila titik banyak untuk wadah sempit', async () => {
            tanpaPengamat();
            ukuran(400);
            const banyak = Array.from({ length: 31 }, (_, i) => String(i + 1));
            const wrapper = mount(LineChart, { props: { labels: banyak, series: [{ key: 'call', label: 'Call', values: banyak.map(() => 1) }] } });
            await wrapper.vm.$nextTick();

            expect(Number(wrapper.find('svg').attributes('width'))).toBe(36 + 12 + 31 * 28);
        });

        it('mengikuti perubahan ukuran wadah lewat ResizeObserver', async () => {
            let panggil;
            global.ResizeObserver = class { constructor(fn) { panggil = fn; } observe() {} disconnect() {} };
            const ukur = ukuran(600);
            const wrapper = mount(LineChart, { props: { labels, series } });
            await wrapper.vm.$nextTick();
            expect(wrapper.find('svg').attributes('width')).toBe('600');

            ukur.mockReturnValue(800);
            panggil();
            await wrapper.vm.$nextTick();

            expect(wrapper.find('svg').attributes('width')).toBe('800');
            delete global.ResizeObserver;
        });
    });

    it('menampilkan pesan bila tidak ada data', () => {
        const wrapper = mount(LineChart, { props: { labels: [], series: [{ key: 'call', label: 'Call', values: [] }] } });

        expect(wrapper.text()).toContain('Tidak ada data.');
        expect(wrapper.find('svg').exists()).toBe(false);
    });
});
