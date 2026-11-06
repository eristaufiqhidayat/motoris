import { mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import FormKaryawan from './FormKaryawan.vue';

let galatServer = {};

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data) => {
        const form = reactive({
            ...data,
            errors: {},
            isDirty: false,
            processing: false,
            transform() {
                return form;
            },
            post(url, opsi) {
                form.errors = galatServer;
                opsi.onError();
            },
        });

        return form;
    },
}));

const nilai = {
    nama: 'Sinta', nik: '', tempat_lahir: '', tanggal_lahir: '', alamat: '', status_ptkp: '', depo_id: '5', koordinator_id: '7',
    wilayah_depo_id: '', area_ids: ['1'], area_aktif_ids: [], jabatan: '', email: '', no_hp: '0812', tanggal_mulai: '2026-01-02', aktif: '1',
};

function pasang(props = {}, slots = {}) {
    return mount(FormKaryawan, {
        props: {
            slug: 'karyawan_sales', kategori: 'sales', nama: 'motoris', item: { id: 1, nama: 'Sinta', foto: null },
            nilai, opsi: { depo: [], ptkp: [], koordinator: [], area: [] }, bisa: { ubah: true, tambah: true }, ...props,
        },
        slots,
    });
}

const labelTab = (w) => w.findAll('nav[role="tablist"]')[0].findAll('[role="tab"]').map((t) => t.text());
const tabAktif = (w) => w.find('nav[role="tablist"] [aria-selected="true"]').text();

describe('FormKaryawan', () => {
    it('hides Penempatan for supervisor and shows Riwayat only when the slot is given', () => {
        expect(labelTab(pasang({ kategori: 'supervisor' }))).toEqual(['Data diri', 'Kontak & kepegawaian', 'Gaji & tunjangan']);
        expect(labelTab(pasang({}, { riwayat: 'isi' }))).toEqual(['Data diri', 'Penempatan', 'Kontak & kepegawaian', 'Gaji & tunjangan', 'Riwayat']);
    });

    it('turns off native validation so required fields in closed tabs cannot silently block saving', () => {
        expect(pasang().find('form').attributes('novalidate')).toBeDefined();
    });

    it('flags a tab with empty required fields before saving', () => {
        const wrapper = pasang({ nilai: { ...nilai, koordinator_id: '', area_ids: [] } });
        const penempatan = wrapper.findAll('[role="tab"]').find((t) => t.text().startsWith('Penempatan'));

        expect(penempatan.find('.badge').text()).toBe('2');
        expect(penempatan.find('.badge').classes()).toContain('badge-warning');
    });

    it('opens the first tab with a server error and counts array errors under their field', async () => {
        galatServer = { 'area_ids.0': 'Area harus milik depo yang dipilih.', no_hp: 'No HP wajib diisi.' };
        const wrapper = pasang();

        await wrapper.find('form').trigger('submit');
        await nextTick();

        expect(tabAktif(wrapper)).toContain('Penempatan');
        expect(wrapper.findAll('[role="tab"]').find((t) => t.text().startsWith('Kontak')).find('.badge-danger').text()).toBe('1');
    });

    it('stays on the current tab when it has its own error', async () => {
        galatServer = { nama: 'Nama wajib diisi.', no_hp: 'No HP wajib diisi.' };
        const wrapper = pasang();
        await wrapper.findAll('[role="tab"]').find((t) => t.text().startsWith('Kontak')).trigger('click');

        await wrapper.find('form').trigger('submit');
        await nextTick();

        expect(tabAktif(wrapper)).toContain('Kontak');
    });

    it('hides the save bar on the Riwayat tab and for read-only users', async () => {
        const wrapper = pasang({}, { riwayat: 'isi' });
        expect(wrapper.find('.form-aksi-tetap').exists()).toBe(true);

        await wrapper.findAll('[role="tab"]').find((t) => t.text() === 'Riwayat').trigger('click');
        expect(wrapper.find('.form-aksi-tetap').exists()).toBe(false);

        expect(pasang({ bisa: { ubah: false, tambah: false } }).find('.form-aksi-tetap').exists()).toBe(false);
    });
});
