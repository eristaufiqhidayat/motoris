import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Input from './Input.vue';

describe('Input', () => {
    it('emits the value as typed by default', async () => {
        const wrapper = mount(Input);

        await wrapper.find('input').setValue('0812 34');

        expect(wrapper.emitted('update:modelValue')).toEqual([['0812 34']]);
    });

    it('strips everything except digits from the field and the emitted value with hanyaAngka', async () => {
        const wrapper = mount(Input, { props: { hanyaAngka: true } });
        const input = wrapper.find('input');

        await input.setValue('+62 812-34a');

        expect(input.element.value).toBe('6281234');
        expect(wrapper.emitted('update:modelValue')).toEqual([['6281234']]);
    });

    it('leaves an existing value untouched until the user changes it', () => {
        const wrapper = mount(Input, { props: { hanyaAngka: true, modelValue: '0812 9439' } });

        expect(wrapper.find('input').element.value).toBe('0812 9439');
    });
});
