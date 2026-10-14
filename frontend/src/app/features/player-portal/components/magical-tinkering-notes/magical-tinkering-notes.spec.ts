import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { describe, it, expect } from 'vitest';
import { MagicalTinkeringNotes } from './magical-tinkering-notes';

describe('Magical tinkering notes', () => {
  it('shows saved notes read-only outside LIVE and PATCHes explicit notes in LIVE', async () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const fixture = TestBed.createComponent(MagicalTinkeringNotes);
    fixture.componentRef.setInput('token', 'test');
    fixture.componentRef.setInput('revision', 4);
    fixture.componentRef.setInput('notes', 'Lampe rouge');
    await fixture.whenStable();
    expect(fixture.nativeElement.textContent).toContain('Lampe rouge');
    expect(fixture.nativeElement.querySelector('textarea')).toBeNull();
    fixture.componentInstance.save();
    const http = TestBed.inject(HttpTestingController);
    http.expectNone('/api/public/characters/test');
    fixture.componentRef.setInput('editable', true);
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('textarea').value).toBe('Lampe rouge');
    fixture.componentInstance.draft.set('Clochette');
    fixture.componentInstance.save();
    const request = http.expectOne('/api/public/characters/test');
    expect(request.request.body).toEqual({ revision: 4, state: { magicalTinkeringNotes: 'Clochette' } });
    request.flush({});
    http.verify();
  });
});
