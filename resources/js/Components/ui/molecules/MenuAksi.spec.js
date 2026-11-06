import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import MenuAksi from './MenuAksi.vue';

const items = [
    { kunci: 'hitung-ulang', label: 'Hitung Ulang', icon: 'calculator' },
    { kunci: 'finalkan', label: 'Finalkan', icon: 'check', disabled: true, alasan: 'Hanya draf yang bisa difinalkan.' },
    { kunci: 'ekspor', label: 'Ekspor Excel', icon: 'download', href: '/penggajian_sales/4/ekspor' },
    { kunci: 'batalkan', label: 'Batalkan', icon: 'x', variant: 'bahaya', pisah: true },
];

let wrapper;

const pasang = (props = {}) => {
    wrapper = mount(MenuAksi, { props: { items, ...props }, attachTo: document.body });

    return wrapper;
};

afterEach(() => wrapper?.unmount());

describe('MenuAksi', () => {
    it('shows only the trigger with its label until opened', () => {
        pasang();

        expect(wrapper.find('button').text()).toContain('Aksi');
        expect(wrapper.find('button').attributes('aria-haspopup')).toBe('menu');
        expect(wrapper.find('button').attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
    });

    it('uses a custom label', () => {
        pasang({ label: 'Lainnya' });

        expect(wrapper.find('button').text()).toContain('Lainnya');
    });

    it('opens on click and lists the items in the given order', async () => {
        pasang();

        await wrapper.find('button').trigger('click');

        expect(wrapper.find('button').attributes('aria-expanded')).toBe('true');
        expect(wrapper.findAll('[role="menuitem"]').map((el) => el.text())).toEqual(['Hitung Ulang', 'Finalkan', 'Ekspor Excel', 'Batalkan']);
    });

    it('emits pilih with the key and closes when an enabled item is clicked', async () => {
        pasang();
        await wrapper.find('button').trigger('click');

        await wrapper.findAll('[role="menuitem"]')[0].trigger('click');

        expect(wrapper.emitted('pilih')).toEqual([['hitung-ulang']]);
        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
    });

    it('does not emit for a disabled item, keeps the menu open, and explains why', async () => {
        pasang();
        await wrapper.find('button').trigger('click');
        const nonaktif = wrapper.findAll('[role="menuitem"]')[1];

        await nonaktif.trigger('click');

        expect(nonaktif.attributes('aria-disabled')).toBe('true');
        expect(nonaktif.attributes('title')).toBe('Hanya draf yang bisa difinalkan.');
        expect(nonaktif.classes()).toContain('menu-aksi-item-nonaktif');
        expect(wrapper.emitted('pilih')).toBeUndefined();
        expect(wrapper.find('[role="menu"]').exists()).toBe(true);
    });

    it('renders an item with href as a plain link (download) without emitting', async () => {
        pasang();
        await wrapper.find('button').trigger('click');
        const tautan = wrapper.findAll('[role="menuitem"]')[2];

        expect(tautan.element.tagName).toBe('A');
        expect(tautan.attributes('href')).toBe('/penggajian_sales/4/ekspor');

        await tautan.trigger('click');

        expect(wrapper.emitted('pilih')).toBeUndefined();
    });

    it('renders a disabled href item as a disabled button, not a link', async () => {
        pasang({ items: [{ kunci: 'ekspor', label: 'Ekspor Excel', href: '/x', disabled: true, alasan: 'Setelah final.' }] });
        await wrapper.find('button').trigger('click');
        const item = wrapper.find('[role="menuitem"]');

        expect(item.element.tagName).toBe('BUTTON');
        expect(item.attributes('href')).toBeUndefined();
    });

    it('puts a separator before an item marked pisah and styles danger items', async () => {
        pasang();
        await wrapper.find('button').trigger('click');

        const anak = [...wrapper.find('[role="menu"]').element.children];
        const iBatal = anak.findIndex((el) => el.textContent.includes('Batalkan'));

        expect(anak[iBatal - 1].getAttribute('role')).toBe('separator');
        expect(anak[iBatal].classList.contains('menu-aksi-item-bahaya')).toBe(true);
    });

    it('closes on Escape and returns focus to the trigger', async () => {
        pasang();
        await wrapper.find('button').trigger('click');

        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'Escape' });

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.find('button').element);
    });

    it('closes when clicking outside', async () => {
        pasang();
        await wrapper.find('button').trigger('click');

        document.body.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
    });

    it('moves focus between enabled items with the arrow keys, skipping disabled ones', async () => {
        pasang();
        await wrapper.find('button').trigger('click');
        const menu = wrapper.find('[role="menu"]');

        await menu.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement.textContent).toContain('Hitung Ulang');

        await menu.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement.textContent).toContain('Ekspor Excel'); // Finalkan nonaktif dilewati

        await menu.trigger('keydown', { key: 'ArrowUp' });
        expect(document.activeElement.textContent).toContain('Hitung Ulang');
    });

    it('renders nothing when there are no items', () => {
        pasang({ items: [] });

        expect(wrapper.find('button').exists()).toBe(false);
    });
});
