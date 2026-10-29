import { TestBed } from '@angular/core/testing';
import { describe, expect, it, vi } from 'vitest';
import { ReferencePicker } from './reference-picker';

describe('Reference picker', () => {
  it('searches accents, keeps the selection, clears optional relations and rejects forged choices', () => {
    const f = TestBed.createComponent(ReferencePicker);
    f.componentRef.setInput('options', [{ id: 8, name: 'Éclaireur', origin: 'OFFICIAL' }, { id: 9, name: 'Clerc des Brumes', origin: 'CUSTOM' }]);
    const p = f.componentInstance; const changed = vi.fn(); p.registerOnChange(changed);
    p.search.set('eclair'); expect(p.filtered().map(o => o.id)).toEqual([8]);
    p.choose('8'); p.search.set('brumes'); expect(p.filtered()).toHaveLength(2);
    p.choose('999'); expect(changed).toHaveBeenCalledTimes(1);
    p.choose(''); expect(changed).toHaveBeenLastCalledWith(null);
    p.setDisabledState(true); p.choose('9'); expect(changed).toHaveBeenCalledTimes(2);
    p.writeValue(9); expect(p.value()).toBe(9);
  });
});
