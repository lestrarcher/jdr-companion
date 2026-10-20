import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CharacterSpellbook } from './character-spellbook';

describe('GM spellbook copies', () => {
  let fixture: ComponentFixture<CharacterSpellbook>;
  let http: HttpTestingController;
  const base = '/api/characters/12/spell-acquisitions';
  const options = { learningStyle: 'spellbook', available: [{ id: 7, name: 'Fiction', level: 1 }, { id: 8, name: 'Already copied', level: 1 }], acquisitions: [{ spell_id: 8 }] };
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]), { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap({ campaignId: '2', characterId: '12' }) } } }] });
    http = TestBed.inject(HttpTestingController); fixture = TestBed.createComponent(CharacterSpellbook);
    http.expectOne(base).flush({ characterName: 'Fixture', policies: [{ className: 'Wizard', status: 'policy-configured', policyIds: [3] }] });
  });
  afterEach(() => { http.verify(); vi.restoreAllMocks(); });
  function quote() {
    const editor = fixture.componentInstance;
    editor.policyId = 3; editor.selectPolicy(); http.expectOne(base + '/3').flush(options);
    editor.spellId = 7; editor.selectSpell(); http.expectOne(base + '/3/copy/7').flush({ spellId: 7, baseCostCp: 5000, baseMinutes: 120 });
    return editor;
  }
  it('shows the quote and excludes spells already acquired', () => {
    const editor = quote(); fixture.detectChanges();
    expect(editor.spells().map(s => s.id)).toEqual([7]);
    expect(fixture.nativeElement.textContent).toContain('50 po, 120 minutes');
    expect(editor.confirmed).toBe(false);
  });
  it('requires explicit confirmation and a reason for adjustments', () => {
    const editor = quote(); editor.source = 'Manuscript'; editor.submit();
    http.expectNone(r => r.method === 'POST');
    editor.confirmed = true; editor.adjustment = 'gm'; editor.submit();
    http.expectNone(r => r.method === 'POST');
  });
  it('submits the trace once, retains draft on conflict and does not debit the wallet', () => {
    const editor = quote(); editor.source = 'Manuscript'; editor.confirmed = true;
    editor.adjustment = 'gm'; editor.reason = 'Gift'; editor.costCp = 0; editor.minutes = 0;
    editor.submit(); editor.submit();
    const request = http.expectOne(r => r.method === 'POST');
    expect(request.request.url).toBe(base + '/3/copy/7');
    expect(request.request.body).toEqual({ baseCostCp: 5000, baseMinutes: 120, costCp: 0, minutes: 0, source: 'Manuscript', adjustment: 'gm', reason: 'Gift', confirmed: true });
    request.flush({ message: 'Conflit' }, { status: 409, statusText: 'Conflict' });
    expect(editor.source).toBe('Manuscript'); expect(editor.error()).toBe('Conflit'); expect(editor.busy()).toBe(false);
  });
  it('invalidates confirmation after changing adjustments and protects departure', () => {
    const editor = quote(); editor.source = 'Draft'; editor.confirmed = true; editor.adjustmentChanged();
    expect(editor.confirmed).toBe(false);
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    expect(editor.canLeavePreparation()).toBe(false);
  });
  it('refreshes available spells after success', () => {
    const editor = quote(); editor.source = 'Manuscript'; editor.confirmed = true; editor.submit();
    http.expectOne(r => r.method === 'POST').flush({ acquisitionId: 5 });
    http.expectOne(base + '/3').flush({ ...options, acquisitions: [{ spell_id: 7 }, { spell_id: 8 }] });
    expect(editor.spells()).toEqual([]); expect(editor.quote()).toBeNull(); expect(editor.source).toBe('');
  });
});
