import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import StatCard from './StatCard.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        props: { href: String },
        setup: (props, { slots, attrs }) => () => h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
}));

const pasang = (props = {}, slots = {}) => mount(StatCard, {
    props: { label: 'Absen Datang', icon: 'user-check', ...props },
    slots: { default: '3 / 5', ...slots },
    global: { stubs: { Icon: true } },
});

describe('StatCard', () => {
    it('menampilkan label, nilai, keterangan, dan nada pada ikon', () => {
        const wrapper = pasang({ nada: 'peringatan' }, { keterangan: '2 belum absen' });

        expect(wrapper.find('.stat-card-label').text()).toBe('Absen Datang');
        expect(wrapper.find('.stat-card-nilai').text()).toBe('3 / 5');
        expect(wrapper.find('.stat-card-keterangan').text()).toBe('2 belum absen');
        expect(wrapper.find('.stat-card-ikon').classes()).toContain('stat-card-ikon-peringatan');
        expect(wrapper.element.tagName).toBe('DIV');
    });

    it('jadi tautan bila href diisi', () => {
        const wrapper = pasang({ href: '/motoris_ditandai' });

        expect(wrapper.element.tagName).toBe('A');
        expect(wrapper.attributes('href')).toBe('/motoris_ditandai');
        expect(wrapper.classes()).toContain('stat-card-link');
    });

    it('jadi tombol dan memancarkan click bila clickable diisi (tanpa pindah halaman)', async () => {
        const wrapper = pasang({ clickable: true });

        expect(wrapper.element.tagName).toBe('BUTTON');
        expect(wrapper.attributes('type')).toBe('button');
        expect(wrapper.classes()).toContain('stat-card-link');

        await wrapper.trigger('click');

        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('tetap berupa div, tidak interaktif, bila href dan clickable tidak diisi', () => {
        const wrapper = pasang();

        expect(wrapper.element.tagName).toBe('DIV');
        expect(wrapper.classes()).not.toContain('stat-card-link');
        expect(wrapper.find('.stat-card-panah').exists()).toBe(false);
    });

    it('menampilkan batang progres sesuai persen, dibatasi 0–100', () => {
        const bar = (progres) => pasang({ progres, nada: 'sukses' }).find('.progress-bar');

        expect(bar(60).attributes('style')).toContain('width: 60%');
        expect(bar(140).attributes('style')).toContain('width: 100%');
        expect(bar(60).classes()).toContain('progress-bar-sukses');
        expect(pasang().find('.progress').exists()).toBe(false);
    });

    it('menampilkan skeleton, bukan nilai, saat memuat', () => {
        const wrapper = pasang({ memuat: true, progres: 50 });

        expect(wrapper.find('.stat-card-nilai').exists()).toBe(false);
        expect(wrapper.find('.skeleton').exists()).toBe(true);
        expect(wrapper.find('.progress').exists()).toBe(false);
    });
});
