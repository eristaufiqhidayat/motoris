import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PeriksaTanda from './PeriksaTanda.vue';

const URL = '/tracking_sales/tanda/5/periksa';

let balasan;

function pasangFetch() {
    global.fetch = vi.fn(() => Promise.resolve(balasan));
}

function siapkan(props = {}) {
    return mount(PeriksaTanda, { props: { url: URL, ...props }, attachTo: document.body });
}

const tombolSimpan = (wrapper) => wrapper.findAll('button').find((b) => b.text() === 'Simpan hasil');

async function isi(wrapper, status, catatan) {
    if (status) await wrapper.find(`input[type="radio"]`).element.closest('form').querySelectorAll('input[type="radio"]')[status === 'terbukti' ? 0 : 1].dispatchEvent(new Event('change'));
    if (catatan) await wrapper.find('textarea').setValue(catatan);
}

describe('PeriksaTanda', () => {
    beforeEach(() => {
        balasan = { ok: true, status: 200, json: () => Promise.resolve({ status: 'terbukti' }) };
        document.head.innerHTML = '<meta name="csrf-token" content="token-rahasia">';
        pasangFetch();
    });

    afterEach(() => {
        document.head.innerHTML = '';
        document.body.innerHTML = '';
    });

    it('Simpan hasil nonaktif sampai hasil cek dipilih dan catatan diisi', async () => {
        const wrapper = siapkan();

        expect(tombolSimpan(wrapper).attributes('disabled')).toBeDefined();

        await isi(wrapper, 'wajar', null);
        expect(tombolSimpan(wrapper).attributes('disabled')).toBeDefined();

        await isi(wrapper, null, '   ');
        expect(tombolSimpan(wrapper).attributes('disabled')).toBeDefined();

        await isi(wrapper, null, 'Toko asli, ada papan nama.');
        expect(tombolSimpan(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('mengirim PATCH berisi status dan catatan dengan header CSRF, lalu memberi tahu induknya', async () => {
        const wrapper = siapkan();
        await isi(wrapper, 'wajar', 'Toko asli, ada papan nama.');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const [url, opsi] = fetch.mock.calls[0];
        expect(url).toBe(URL);
        expect(opsi.method).toBe('PATCH');
        expect(opsi.headers['X-CSRF-TOKEN']).toBe('token-rahasia');
        expect(JSON.parse(opsi.body)).toEqual({ status: 'wajar', catatan: 'Toko asli, ada papan nama.' });
        expect(wrapper.emitted('selesai')).toHaveLength(1);
    });

    it('menampilkan keterangan cakupan di atas form bila diberikan', () => {
        const wrapper = siapkan({ keterangan: 'Berlaku untuk 7 outlet di titik ini.' });

        expect(wrapper.text()).toContain('Berlaku untuk 7 outlet di titik ini.');
    });

    it('menampilkan galat validasi dan tidak menutup form', async () => {
        balasan = { ok: false, status: 422, json: () => Promise.resolve({ errors: { catatan: ['Catatan wajib diisi.'] } }) };
        const wrapper = siapkan();
        await isi(wrapper, 'terbukti', 'x');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('Catatan wajib diisi.');
        expect(wrapper.emitted('selesai')).toBeUndefined();
    });

    it('menjelaskan bila tanda sudah diperiksa orang lain (404)', async () => {
        balasan = { ok: false, status: 404, json: () => Promise.resolve({}) };
        const wrapper = siapkan();
        await isi(wrapper, 'wajar', 'x');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('sudah diperiksa');
        expect(wrapper.emitted('selesai')).toBeUndefined();
    });

    it('menampilkan pesan umum bila pengiriman gagal', async () => {
        balasan = { ok: false, status: 500, json: () => Promise.resolve({}) };
        const wrapper = siapkan();
        await isi(wrapper, 'wajar', 'x');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('Gagal menyimpan hasil cek.');
    });

    it('Batal memberi tahu induknya tanpa mengirim apa pun', async () => {
        const wrapper = siapkan();

        await wrapper.findAll('button').find((b) => b.text() === 'Batal').trigger('click');

        expect(wrapper.emitted('batal')).toHaveLength(1);
        expect(fetch).not.toHaveBeenCalled();
    });
});
