import { describe, expect, it } from 'vitest';
import { date, scopeLabel, titleCase } from './format';

describe('format', () => {
    it('shows dates in Indian style without shifting the day', () => {
        expect(date('2027-03-01')).toBe('1 Mar 2027');
        expect(date(null)).toBe('—');
    });

    it('turns keys into words', () => {
        expect(titleCase('admission_committee')).toBe('Admission Committee');
    });

    it('describes a scope', () => {
        expect(scopeLabel({ branch: { name: 'Delhi' }, course: null, cycle: { name: 'Admissions 2027' } })).toBe('Delhi · Admissions 2027');
        expect(scopeLabel({})).toBe('All branches, courses and cycles');
    });
});
