import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TandaOutlet from './TandaOutlet.vue';

const URL = '/outlet_all/tanda';
const props = { url: URL, outletId: 10 };
const tanda = [
    { id: 5, kategori: 'outlet_fiktif', kategori_label: 'Diduga outlet fiktif', catatan: '4 outlet didaftarkan Wawan08 di koordinat yang sama persis.', oleh: 'Sistem', waktu: '2026-10-25 09:00', status: 'perlu_dicek', status_label: 'Perlu dicek', hasil_catatan: null, dicek_oleh: null, dicek_waktu: null, bisa_periksa: true, sekelompok: 3 },
    { id: 6, kategori: 'outlet_fiktif', kategori_label: 'Diduga outlet fiktif', catatan: null, oleh: 'Sistem', waktu: '2026-10-25 10:00', status: 'terbukti', status_label: 'Terbukti', hasil_catatan: 'Toko tidak ada.', dicek_oleh: 'koor', dicek_waktu: '2026-10-26 08:00', bisa_periksa: false, sekelompok: 0 },
];
const outlet = { nama: 'Warung Aming', dibuat_oleh: 'Budi Santoso', dibuat_pada: '2026-10-22 11:23' };

let daftar;

function pasangFetch() {
    global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ outlet, tanda: daftar }) }));
}

async function siapkan(lain = {}) {
    const wrapper = mount(TandaOutlet, { props: { ...props, ...lain } });
    await flushPromises();

    return wrapper;
}

const tombolPeriksa = (wrapper) => wrapper.findAll('button').find((b) => b.text().includes('Periksa'));

describe('TandaOutlet', () => {
    beforeEach(() => {
        daftar = tanda;
        pasangFetch();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('memuat tanda outlet dan pendaftar outletnya', async () => {
        const wrapper = await siapkan();

        expect(fetch.mock.calls[0][0]).toBe(`${URL}?outlet_id=10`);
        expect(wrapper.text()).toContain('Didaftarkan oleh: Budi Santoso');
        expect(wrapper.text()).toContain('Diduga outlet fiktif');
        expect(wrapper.text()).toContain('Perlu dicek');
        expect(wrapper.text()).toContain('Terbukti');
    });

    it('menampilkan alasan dari sistem dan hasil cek koordinator', async () => {
        const wrapper = await siapkan();

        expect(wrapper.text()).toContain('4 outlet didaftarkan Wawan08');
        expect(wrapper.text()).toContain('Hasil cek (koor · 2026-10-26 08:00): Toko tidak ada.');
    });

    it('tidak punya tombol Tandai: tanda hanya dibuat sistem', async () => {
        const wrapper = await siapkan();

        expect(wrapper.findAll('button').map((b) => b.text())).not.toContain('Tandai');
        expect(wrapper.text()).not.toContain('Tandai outlet');
    });

    it('tombol Periksa hanya pada tanda Perlu dicek yang boleh diperiksa akun', async () => {
        const wrapper = await siapkan();

        expect(wrapper.findAll('button').filter((b) => b.text().includes('Periksa'))).toHaveLength(1);

        daftar = tanda.map((t) => ({ ...t, bisa_periksa: false }));
        expect(tombolPeriksa(await siapkan())).toBeUndefined();
    });

    it('membuka form hasil cek dengan keterangan titik sekelompok, dan menutupnya lewat Batal', async () => {
        const wrapper = await siapkan();

        await tombolPeriksa(wrapper).trigger('click');
        expect(wrapper.find('form[aria-label="Hasil cek tanda"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Berlaku juga untuk 3 outlet lain di titik yang sama.');

        await wrapper.findAll('button').find((b) => b.text() === 'Batal').trigger('click');
        expect(wrapper.find('form[aria-label="Hasil cek tanda"]').exists()).toBe(false);
    });

    it('setelah pemeriksaan selesai memuat ulang dan memberi tahu induknya', async () => {
        const wrapper = await siapkan();
        await tombolPeriksa(wrapper).trigger('click');
        fetch.mockClear();

        wrapper.findComponent({ name: 'PeriksaTanda' }).vm.$emit('selesai', {});
        await flushPromises();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('berubah')).toHaveLength(1);
        expect(wrapper.find('form[aria-label="Hasil cek tanda"]').exists()).toBe(false);
    });

    it('memuat ulang tanda dan menutup form saat outlet diganti', async () => {
        const wrapper = await siapkan();
        await tombolPeriksa(wrapper).trigger('click');

        await wrapper.setProps({ outletId: 20 });
        await flushPromises();

        expect(fetch.mock.calls.at(-1)[0]).toBe(`${URL}?outlet_id=20`);
        expect(wrapper.find('form[aria-label="Hasil cek tanda"]').exists()).toBe(false);
    });

    it('menampilkan pesan bila memuat gagal dan keterangan kosong bila tidak ada tanda', async () => {
        daftar = [];
        expect((await siapkan()).text()).toContain('Belum ada tanda untuk outlet ini.');

        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }));
        expect((await siapkan()).text()).toContain('Gagal memuat tanda outlet.');
    });
});
