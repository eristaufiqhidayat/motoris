import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DataTable from './DataTable.vue';

const columns = { kode: 'Kode', nama_provinsi: 'Nama Provinsi', dibuat: 'Dibuat' };
const rows = [{ kode: '11', nama_provinsi: 'Aceh', dibuat: '2026-01-01' }];

function header(wrapper, kolom) {
    return wrapper.findAll('th').find((th) => th.text().includes(columns[kolom]));
}

function filterButton(wrapper, kolom) {
    return header(wrapper, kolom).find('button.filter-button');
}

describe('DataTable filter', () => {
    afterEach(() => vi.useRealTimers());

    it('renders no filter button when filterable is empty', () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });

        expect(wrapper.findAll('button.filter-button')).toHaveLength(0);
    });

    it('renders a filter button only for columns in filterable', () => {
        const wrapper = mount(DataTable, { props: { columns, rows, filterable: ['kode', 'nama_provinsi'] } });

        expect(wrapper.findAll('button.filter-button')).toHaveLength(2);
        expect(filterButton(wrapper, 'dibuat').exists()).toBe(false);
    });

    it('does not show the filter input until the flyout is opened', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows, filterable: ['kode'] } });

        expect(wrapper.find('.filter-flyout').exists()).toBe(false);

        await filterButton(wrapper, 'kode').trigger('click');

        expect(wrapper.find('.filter-flyout input.col-filter').exists()).toBe(true);
    });

    it('toggles the flyout closed when the same button is clicked again', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows, filterable: ['kode'] } });

        await filterButton(wrapper, 'kode').trigger('click');
        await filterButton(wrapper, 'kode').trigger('click');

        expect(wrapper.find('.filter-flyout').exists()).toBe(false);
    });

    it('shows the active filter value from filterValues', async () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows, filterable: ['kode'], filterValues: { kode: '11' } },
        });

        await filterButton(wrapper, 'kode').trigger('click');

        expect(wrapper.find('input.col-filter').element.value).toBe('11');
    });

    it('marks the filter button as active when the column has a value', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows, filterable: ['kode'], filterValues: { kode: '11' } },
        });

        expect(filterButton(wrapper, 'kode').classes()).toContain('filter-aktif');
    });

    it('emits all filter values once after the debounce delay', async () => {
        vi.useFakeTimers();
        const wrapper = mount(DataTable, { props: { columns, rows, filterable: ['kode', 'nama_provinsi'] } });

        await filterButton(wrapper, 'kode').trigger('click');
        await wrapper.find('input.col-filter').setValue('11');
        await filterButton(wrapper, 'nama_provinsi').trigger('click');
        await wrapper.find('input.col-filter').setValue('  Aceh ');

        expect(wrapper.emitted('filter')).toBeUndefined();

        vi.advanceTimersByTime(700);

        expect(wrapper.emitted('filter')).toEqual([[{ nilai: { kode: '11', nama_provinsi: 'Aceh' }, operator: {} }]]);
    });

    it('does not emit while the user is still typing within the delay', async () => {
        vi.useFakeTimers();
        const wrapper = mount(DataTable, { props: { columns, rows, filterable: ['kode'] } });

        await filterButton(wrapper, 'kode').trigger('click');
        const input = wrapper.find('input.col-filter');

        await input.setValue('1');
        vi.advanceTimersByTime(500);
        await input.setValue('11');
        vi.advanceTimersByTime(500);

        expect(wrapper.emitted('filter')).toBeUndefined();

        vi.advanceTimersByTime(200);

        expect(wrapper.emitted('filter')).toEqual([[{ nilai: { kode: '11' }, operator: {} }]]);
    });

    it('keeps text typed during a request when the server echoes older values', async () => {
        vi.useFakeTimers();
        const wrapper = mount(DataTable, { props: { columns, rows, filterable: ['kode'] } });

        await filterButton(wrapper, 'kode').trigger('click');
        const input = wrapper.find('input.col-filter');

        await input.setValue('1');
        vi.advanceTimersByTime(700);
        // User mengetik lagi sebelum respons request pertama datang.
        await input.setValue('11');
        await wrapper.setProps({ filterValues: { kode: '1' } });

        expect(input.element.value).toBe('11');
    });

    it('renders an operator select and emits the chosen operator for numeric columns', async () => {
        vi.useFakeTimers();
        const wrapper = mount(DataTable, {
            props: { columns, rows, filterable: ['kode'], filterAngka: ['kode'] },
        });

        await filterButton(wrapper, 'kode').trigger('click');
        await wrapper.find('select.col-filter-op').setValue('<');
        vi.advanceTimersByTime(700);

        expect(wrapper.emitted('filter')).toEqual([[{ nilai: { kode: '' }, operator: { kode: '<' } }]]);
    });

    it('uses a datepicker with an operator select for date columns', async () => {
        vi.useFakeTimers();
        const wrapper = mount(DataTable, {
            props: { columns, rows, filterable: ['dibuat'], filterAngka: [], filterTanggal: ['dibuat'] },
        });

        await filterButton(wrapper, 'dibuat').trigger('click');

        expect(wrapper.find('.filter-flyout .datepicker.col-filter').exists()).toBe(true);
        expect(wrapper.find('select.col-filter-op').exists()).toBe(true);

        await wrapper.find('.filter-flyout .datepicker-input').trigger('click');
        await wrapper.find('.datepicker-hari:not(.datepicker-hari-luar)').trigger('click');

        vi.advanceTimersByTime(700);

        const [[filter]] = wrapper.emitted('filter');
        expect(filter.nilai.dibuat).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(filter.operator).toEqual({ dibuat: '=' });
    });

    it('clears the column value and emits when the hapus button is clicked', async () => {
        vi.useFakeTimers();
        const wrapper = mount(DataTable, {
            props: { columns, rows, filterable: ['kode'], filterValues: { kode: '11' } },
        });

        await filterButton(wrapper, 'kode').trigger('click');
        await wrapper.find('.filter-flyout-aksi button').trigger('click');
        vi.advanceTimersByTime(700);

        expect(wrapper.find('.filter-flyout').exists()).toBe(false);
        expect(wrapper.emitted('filter')).toEqual([[{ nilai: { kode: '' }, operator: {} }]]);
    });

    it('closes the flyout when Escape is pressed', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows, filterable: ['kode'] }, attachTo: document.body });

        await filterButton(wrapper, 'kode').trigger('click');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await wrapper.vm.$nextTick();

        expect(wrapper.find('.filter-flyout').exists()).toBe(false);
        wrapper.unmount();
    });
});

describe('DataTable sort', () => {
    it('renders no sort button when sortable is empty', () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });

        expect(wrapper.findAll('button.sort-button')).toHaveLength(0);
    });

    it('renders sort buttons only for columns listed in sortable', () => {
        const wrapper = mount(DataTable, { props: { columns, rows, sortable: ['nama_provinsi'] } });

        expect(wrapper.findAll('button.sort-button')).toHaveLength(1);
        expect(header(wrapper, 'nama_provinsi').classes()).toContain('sortable');
        expect(header(wrapper, 'kode').classes()).not.toContain('sortable');
    });

    it('cycles naik → turun → netral when the same column is clicked', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows, sortable: ['nama_provinsi'] } });
        const button = wrapper.find('button.sort-button');

        await button.trigger('click');
        await wrapper.setProps({ sort: { kolom: 'nama_provinsi', arah: 'asc' } });
        await button.trigger('click');
        await wrapper.setProps({ sort: { kolom: 'nama_provinsi', arah: 'desc' } });
        await button.trigger('click');

        expect(wrapper.emitted('sort')).toEqual([
            [{ kolom: 'nama_provinsi', arah: 'asc' }],
            [{ kolom: 'nama_provinsi', arah: 'desc' }],
            [{ kolom: 'nama_provinsi', arah: null }],
        ]);
    });

    it('marks the active sort column and its direction', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows, sortable: ['nama_provinsi'], sort: { kolom: 'nama_provinsi', arah: 'asc' } },
        });

        expect(header(wrapper, 'nama_provinsi').classes()).toEqual(expect.arrayContaining(['sort-aktif', 'sort-asc']));
        expect(header(wrapper, 'nama_provinsi').attributes('aria-sort')).toBe('ascending');
    });
});

describe('DataTable baris bisa diklik', () => {
    const dua = [{ kode: '11', nama_provinsi: 'Aceh', dibuat: '2026-01-01' }, { kode: '12', nama_provinsi: 'Sumut', dibuat: '2026-01-02' }];

    it('tidak mengirim klik-baris dan tidak menandai baris bila barisBisaDiklik mati', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows: dua } });

        await wrapper.find('tbody tr').trigger('click');

        expect(wrapper.emitted('klik-baris')).toBeUndefined();
        expect(wrapper.find('tbody tr').classes()).not.toContain('baris-klik');
        expect(wrapper.find('tbody tr').attributes('tabindex')).toBeUndefined();
    });

    it('mengirim klik-baris berisi data baris saat baris diklik atau Enter ditekan', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows: dua, barisBisaDiklik: true } });
        const baris = wrapper.findAll('tbody tr');

        expect(baris[1].classes()).toContain('baris-klik');
        expect(baris[1].attributes('tabindex')).toBe('0');

        await baris[1].find('td').trigger('click');
        await baris[0].trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('klik-baris')).toEqual([[dua[1]], [dua[0]]]);
    });

    it('mengabaikan klik pada tombol atau tautan di dalam baris', async () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows: dua, barisBisaDiklik: true },
            slots: { 'cell-kode': '<button type="button" class="aksi-dalam">Buka</button>' },
        });

        await wrapper.find('button.aksi-dalam').trigger('click');

        expect(wrapper.emitted('klik-baris')).toBeUndefined();
    });
});

describe('DataTable pilihan baris (checkbox)', () => {
    const data = [
        { id: 1, kode: '11', nama_provinsi: 'Aceh', dibuat: 'a', final: true },
        { id: 2, kode: '12', nama_provinsi: 'Sumut', dibuat: 'b', final: false },
        { id: 3, kode: '13', nama_provinsi: 'Riau', dibuat: 'c', final: true },
    ];
    const pasang = (props = {}) => mount(DataTable, {
        props: { columns, rows: data, dapatDipilih: true, bisaDipilih: (r) => r.final, alasanTidakBisaDipilih: 'Hanya Final.', modelValue: [], ...props },
    });

    it('tanpa dapatDipilih tidak ada kolom checkbox', () => {
        const wrapper = mount(DataTable, { props: { columns, rows: data } });

        expect(wrapper.findAll('input[type=checkbox]')).toHaveLength(0);
    });

    it('menampilkan checkbox per baris plus header; baris yang tidak bisa dipilih nonaktif dengan alasan', () => {
        const wrapper = pasang();
        const kotak = wrapper.findAll('tbody input[type=checkbox]');

        expect(kotak).toHaveLength(3);
        expect(kotak.map((k) => k.element.disabled)).toEqual([false, true, false]);
        expect(wrapper.findAll('tbody tr')[1].find('td').attributes('title')).toBe('Hanya Final.');
        expect(wrapper.findAll('tbody tr')[0].find('td').attributes('title')).toBeUndefined();
    });

    it('memilih dan melepas satu baris mempertahankan pilihan lain', async () => {
        const wrapper = pasang({ modelValue: [3] });

        await wrapper.findAll('tbody input[type=checkbox]')[0].setValue(true);
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([[3, 1]]);

        await wrapper.findAll('tbody input[type=checkbox]')[2].setValue(false);
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([[]]);
    });

    it('checkbox header memilih semua baris yang bisa dipilih saja, dan melepasnya', async () => {
        const wrapper = pasang({ modelValue: [99] }); // 99 = pilihan dari halaman lain

        await wrapper.find('thead input[type=checkbox]').setValue(true);
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([[99, 1, 3]]);

        await wrapper.setProps({ modelValue: [99, 1, 3] });
        expect(wrapper.find('thead input[type=checkbox]').element.checked).toBe(true);

        await wrapper.find('thead input[type=checkbox]').setValue(false);
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([[99]]);
    });

    it('checkbox header indeterminate bila hanya sebagian terpilih', async () => {
        const wrapper = pasang({ modelValue: [1] });

        await nextTick();
        const header = wrapper.find('thead input[type=checkbox]').element;

        expect(header.indeterminate).toBe(true);
        expect(header.checked).toBe(false);
    });

    it('klik checkbox tidak memicu klik-baris dan baris kosong ber-colspan termasuk kolom checkbox', async () => {
        const wrapper = pasang({ barisBisaDiklik: true });

        await wrapper.findAll('tbody input[type=checkbox]')[0].trigger('click');
        expect(wrapper.emitted('klik-baris')).toBeUndefined();

        const kosong = pasang({ rows: [] });
        expect(kosong.find('tbody td[colspan]').attributes('colspan')).toBe('4');
    });
});

describe('DataTable penanda klik dan baris total', () => {
    const data = [{ kode: '11', nama_provinsi: 'Aceh', dibuat: 'a' }, { kode: '12', nama_provinsi: 'Sumut', dibuat: 'b' }];

    it('menampilkan kolom panah hanya bila penandaKlik dan barisBisaDiklik aktif', () => {
        expect(mount(DataTable, { props: { columns, rows: data, barisBisaDiklik: true, penandaKlik: true } }).findAll('tbody td.kolom-panah')).toHaveLength(2);
        expect(mount(DataTable, { props: { columns, rows: data, penandaKlik: true } }).findAll('td.kolom-panah')).toHaveLength(0);
        expect(mount(DataTable, { props: { columns, rows: data, barisBisaDiklik: true } }).findAll('td.kolom-panah')).toHaveLength(0);
    });

    it('baris kosong ber-colspan termasuk kolom panah', () => {
        const wrapper = mount(DataTable, { props: { columns, rows: [], barisBisaDiklik: true, penandaKlik: true } });

        expect(wrapper.find('tbody td[colspan]').attributes('colspan')).toBe('4');
    });

    it('menampilkan baris total di tfoot sesuai kolom dan tidak muncul saat data kosong', () => {
        const wrapper = mount(DataTable, { props: { columns, rows: data, barisTotal: { kode: 'Total', dibuat: '99' } } });
        const sel = wrapper.findAll('tfoot td').map((td) => td.text());

        expect(sel).toEqual(['Total', '', '99']);
        expect(mount(DataTable, { props: { columns, rows: [], barisTotal: { kode: 'Total' } } }).find('tfoot').exists()).toBe(false);
        expect(mount(DataTable, { props: { columns, rows: data } }).find('tfoot').exists()).toBe(false);
    });
});
