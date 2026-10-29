import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SpellPolicies } from './spell-policies';

describe('Personal policy administration', () => {
  const url = '/api/reference/custom/spell-policies';
  const example = { sourceType: 'class', sourceId: 2, policy: { version: 1 } };
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { http.verify(); vi.restoreAllMocks(); });
  function load(migrationRequired = false) {
    const fixture = TestBed.createComponent(SpellPolicies);
    http.expectOne(url).flush({ classes: [], subclasses: [], grants: [], policies: [], substitutions: [], example, migrationRequired });
    fixture.detectChanges();
    return fixture;
  }
  it('blocks saves until the schema is available', () => {
    const f = load(true); f.componentInstance.save();
    http.expectNone(r => r.method === 'POST');
    expect(f.nativeElement.textContent).toContain('migration');
  });
  it('rejects invalid JSON before sending a request', () => {
    const c = load().componentInstance; c.document = '[]'; c.save();
    expect(c.error()).toContain('objet JSON');
    http.expectNone(r => r.method === 'POST');
  });
  it('preserves a rejected draft and prevents duplicate submissions', () => {
    const c = load().componentInstance;
    c.document = JSON.stringify({ ...example, sourceId: 7 });
    c.save(); c.save();
    expect(c.canLeavePreparation()).toBe(false);
    http.expectOne(url).flush({ message: 'Référence refusée' }, { status: 403, statusText: 'Forbidden' });
    expect(c.document).toContain('"sourceId":7');
    expect(c.error()).toBe('Référence refusée');
    expect(c.busy()).toBe(false);
  });
  it('does not prepare official versions for mutation', () => {
    const c = load().componentInstance; const before = c.document;
    c.edit({ id: 1, origin: 'OFFICIAL', version: 1, document: example }, 'policy');
    expect(c.document).toBe(before);
  });
  it('increments only the personal draft version and protects unsaved work', () => {
    const c = load().componentInstance;
    c.edit({ id: 1, origin: 'CUSTOM', version: 1, document: example }, 'policy');
    expect(JSON.parse(c.document).policy.version).toBe(2);
    expect(example.policy.version).toBe(1);
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    expect(c.canLeavePreparation()).toBe(false);
  });
  it('posts substitution rules to their own endpoint then refreshes versions', () => {
    const c = load().componentInstance; c.kind = 'substitution'; c.document = '{"grantId":3}';
    c.save(); http.expectOne(url + '/substitution-rules').flush({ id: 7 });
    http.expectOne(url).flush({ classes: [], subclasses: [], grants: [], policies: [], substitutions: [], example, migrationRequired: false });
    expect(c.success()).toContain('Version enregistrée');
    expect(c.canLeavePreparation()).toBe(true);
  });
});
