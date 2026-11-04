import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import TransaksiOutlet from './TransaksiOutlet.vue';

const props = { url: '/tracking_sales/tanda/outlet-transaksi', outletId: 9501, nama: 'Titik A', ringkasan: { transaksi: 2, hari: 2, total: 120000 } };
const respon = {
    jumlah: 2,
    transaksi: [{
        id: 1, kode: 'T-1', tanggal: '2026-11-03', jam: '09:15', motoris: 'Rudi Hartono', total: 50000, effective_call: true,
        item: [{ produk: 'Susu Kaleng', sku: 'SK-1', qty: 2, harga: 25000, diskon: 0, total: 50000 }],
    }],
};

afterEach(() => vi.unstubAllGlobals());

describe('TransaksiOutlet', () => {
    it('menampilkan ringkasan dan memuat transaksi + item hanya saat tombol diklik', async () => {
        const fetchMock = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(respon), text: () => Promise.resolve('') }));
        // Ikon Lucide juga di-fetch; hitung hanya permintaan transaksi.
        const permintaan = () => fetchMock.mock.calls.filter(([u]) => String(u).includes('outlet-transaksi'));
        vi.stubGlobal('fetch', fetchMock);
        const w = mount(TransaksiOutlet, { props });

        expect(w.text()).toContain('2 transaksi');
        expect(permintaan()).toHaveLength(0);

        await w.find('.transaksi-outlet-klik').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/tracking_sales/tanda/outlet-transaksi?outlet_id=9501', expect.anything());
        expect(w.text()).toContain('Susu Kaleng');
        expect(w.text()).toContain('Rudi Hartono');

        // Tutup lalu buka lagi tidak memuat ulang.
        await w.find('.transaksi-outlet-klik').trigger('click');
        await w.find('.transaksi-outlet-klik').trigger('click');
        expect(permintaan()).toHaveLength(1);
    });

    it('outlet tanpa transaksi tidak punya tombol', () => {
        const w = mount(TransaksiOutlet, { props: { ...props, ringkasan: null } });

        expect(w.find('.transaksi-outlet-klik').exists()).toBe(false);
        expect(w.text()).toContain('Belum bertransaksi');
    });

    it('menampilkan pesan saat gagal memuat', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: false, status: 500 })));
        const w = mount(TransaksiOutlet, { props });

        await w.find('.transaksi-outlet-klik').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Gagal memuat transaksi');
    });
});
