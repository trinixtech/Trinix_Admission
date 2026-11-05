import { describe, expect, it, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import ReasonDialog from './ReasonDialog.vue';

beforeAll(() => {
    // jsdom has no <dialog> methods.
    HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
});

describe('ReasonDialog', () => {
    it('keeps the action disabled until a reason is given and the box is ticked', async () => {
        const w = mount(ReasonDialog, {
            props: { open: true, title: 'Close round', message: 'Sure?', action: 'Close round', confirmText: 'I understand' },
            attachTo: document.body,
        });
        await w.vm.$nextTick();
        const submit = w.get('button[type="submit"]');
        expect(submit.attributes('disabled')).toBeDefined();

        await w.get('textarea').setValue('Round complete');
        expect(submit.attributes('disabled')).toBeDefined();

        await w.get('input[type="checkbox"]').setValue(true);
        expect(submit.attributes('disabled')).toBeUndefined();

        await w.get('form').trigger('submit');
        expect(w.emitted('submit')?.[0]).toEqual(['Round complete']);
        w.unmount();
    });
});
