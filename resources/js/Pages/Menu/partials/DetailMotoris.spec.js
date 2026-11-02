import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DetailMotoris from './DetailMotoris.vue';

const detail = (lain = {}) => ({
    profil: { id: 1, nama: 'Rudi Hartono', no_hp: '0812', depo: 'Sidoarjo', koordinator: 'Koor Satu', area: ['Sidoarjo Kota'], tanggal_masuk: '2024-08-01', masa_kerja: '2 tahun 3 bulan', status: 'Aktif', foto: null },
    periode: { dari: '2026-11-01', sampai: '2026-11-15' },
    ringkasan: { hari_kerja: 12, target_call: 480, target_ec: 300, hadir: 2, terlambat: 1, kunjungan: 3, transaksi: 1, ec: 1, rasio_ec: 33.3, penjualan: 150000, qty: 5, rata_penjualan: 75000 },
    harian: [{ tanggal: '2026-11-03', masuk: '08:15', pulang: '17:05', terlambat: true, kunjungan: 2, ec: 1, transaksi: 1, penjualan: 150000 }],
    produk: [{ nama: 'Kopi Sachet', qty: 3, nilai: 90000 }],
    target: { call: 40, ec: 25 },
    tanda_motoris: [{ id: 5, tanggal: '2026-11-03', kategori_label: 'Penjualan fiktif', catatan: 'Nota janggal', oleh: 'koor', waktu: '2026-11-04 09:00', dicabut: false }],
    tanda_outlet: [{ id: 8, outlet_id: 70, outlet: 'Toko Makmur', kategori_label: 'Outlet fiktif', catatan: null, oleh: 'admin', waktu: '2026-11-05 09:00' }],
    ...lain,
});

let balasan;
let transaksiBalasan;

describe('DetailMotoris', () => {
    beforeEach(() => {
        balasan = { ok: true, status: 200, json: () => Promise.resolve(detail()) };
        transaksiBalasan = { ok: true, status: 200, json: () => Promise.resolve({ tanggal: '2026-11-03', transaksi: [{ kode: 'PJ-1', waktu: '2026-11-03 10:30', outlet: 'Toko Makmur', sku: 2, qty: 5, total: 150000, diskon: 1000, status: 'Sah' }] }) };
        global.fetch = vi.fn((url) => Promise.resolve(String(url).includes('/detail/transaksi') ? transaksiBalasan : balasan));
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    async function siapkan() {
        const wrapper = mount(DetailMotoris, { props: { salesId: 1 }, attachTo: document.body });
        await flushPromises();

        return wrapper;
    }

    it('memuat detail tanpa periode lalu menampilkan profil dan ringkasan', async () => {
        const wrapper = await siapkan();

        expect(fetch).toHaveBeenCalledWith('/tracking_sales/1/detail', expect.anything());
        expect(wrapper.text()).toContain('Rudi Hartono');
        expect(wrapper.text()).toContain('2 tahun 3 bulan');
        expect(wrapper.text()).toContain('Sidoarjo Kota');
        expect(wrapper.find('[data-kpi="hadir"]').text()).toContain('2/ 12 HK');
        expect(wrapper.find('[data-kpi="ec"]').text()).toContain('33,3% dari call');
        expect(wrapper.text()).toContain('08:15');
    });

    it('menampilkan jam pulang serta persen Call dan EC terhadap target, di ringkasan dan per hari', async () => {
        const wrapper = await siapkan();

        expect(wrapper.text()).toContain('Jam Pulang');
        expect(wrapper.text()).toContain('17:05');
        // Ringkasan: 3 dari 480 call = 1%, 1 dari 300 EC = 0%.
        expect(wrapper.find('[data-kpi="kunjungan"]').text()).toContain('/ 480');
        expect(wrapper.find('[data-kpi="kunjungan"]').text()).toContain('1%');
        expect(wrapper.find('[data-kpi="ec"]').text()).toContain('/ 300');
        // Harian: 2 dari 40 call = 5%, 1 dari 25 EC = 4%.
        const baris = wrapper.find('tbody tr').text();
        expect(baris).toContain('5%');
        expect(baris).toContain('4%');
    });

    it('tab Ditandai memuat tanda motoris dan tanda outlet motoris ini', async () => {
        const wrapper = await siapkan();
        const tab = wrapper.findAll('[role="tab"]').find((b) => b.text() === 'Ditandai (2)');

        await tab.trigger('click');
        expect(wrapper.text()).toContain('Nota janggal');
        expect(wrapper.text()).toContain('Outlet fiktif');
        expect(wrapper.text()).toContain('Toko Makmur');
    });

    it('tidak ada tab Transaksi dan transaksi tidak diambil sebelum tanggal diklik', async () => {
        const wrapper = await siapkan();

        expect(wrapper.findAll('[role="tab"]').map((b) => b.text())).not.toContain('Transaksi');
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(dialog()).toBeNull();
    });

    // Dialog transaksi di-teleport ke body, jadi dicari lewat document.
    const dialog = () => document.body.querySelector('[data-panel="transaksi-hari"]');

    it('klik satu tanggal di tabel Harian membuka dialog berisi transaksi tanggal itu', async () => {
        const wrapper = await siapkan();
        expect(dialog()).toBeNull();

        await wrapper.find('tbody tr').trigger('click');
        await flushPromises();

        expect(wrapper.find('tbody tr').classes()).toContain('baris-aktif');
        expect(fetch).toHaveBeenLastCalledWith('/tracking_sales/1/detail/transaksi?tanggal=2026-11-03', expect.anything());
        expect(document.body.querySelector('[role="dialog"][aria-label="Transaksi 3 Nov 2026"]')).not.toBeNull();
        expect(dialog().textContent).toContain('PJ-1');
        expect(dialog().textContent).toContain('Toko Makmur');

        document.body.querySelector('[aria-label="Tutup"]').click();
        await flushPromises();
        expect(dialog()).toBeNull();
        expect(wrapper.find('tbody tr').classes()).not.toContain('baris-aktif');
    });

    it('menampilkan pesan di dialog bila transaksi satu tanggal gagal dimuat', async () => {
        const wrapper = await siapkan();
        transaksiBalasan = { ok: false, status: 500, json: () => Promise.resolve({}) };

        await wrapper.find('tbody tr').trigger('click');
        await flushPromises();

        expect(dialog().textContent).toContain('Transaksi belum bisa dimuat');
    });

    it('tab Produk Terlaris menampilkan produknya', async () => {
        const wrapper = await siapkan();

        await wrapper.findAll('[role="tab"]').find((b) => b.text() === 'Produk Terlaris').trigger('click');
        expect(wrapper.text()).toContain('Kopi Sachet');
    });

    it('Tampilkan mengambil ulang dengan periode yang dipilih', async () => {
        const wrapper = await siapkan();
        const tampilkan = wrapper.findAll('button').find((b) => b.text() === 'Tampilkan');
        expect(tampilkan.attributes('disabled')).toBeDefined();

        wrapper.vm.$.setupState.dari = '2026-10-01';
        await wrapper.vm.$nextTick();
        expect(tampilkan.attributes('disabled')).toBeUndefined();

        await tampilkan.trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenLastCalledWith('/tracking_sales/1/detail?dari=2026-10-01&sampai=2026-11-15', expect.anything());
    });

    it('menampilkan pesan validasi periode dari server', async () => {
        balasan = { ok: false, status: 422, json: () => Promise.resolve({ errors: { sampai: ['Periode paling panjang 366 hari.'] } }) };
        const wrapper = await siapkan();

        expect(wrapper.text()).toContain('Periode paling panjang 366 hari.');
    });
});
