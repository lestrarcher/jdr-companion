import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PublicCharacterMagicItemApiService } from '@core/services/public-character-magic-item-api.service';
import { CharacterWeapons } from './character-weapons';

const owned = {
  id: 1, currentCharges: 3, magicItem: { name: 'Masse terrifiante', description: 'Une vague de terreur.', maximumCharges: 3, rechargeFormula: '1d3 à l’aube', requiresAttunement: true }, attuned: true,
};

describe('Character weapons details', () => {
  const api = { list: vi.fn(), changeCharges: vi.fn() };
  afterEach(() => { TestBed.resetTestingModule(); vi.clearAllMocks(); });
  function setup() {
    api.list.mockReturnValue(of({ ownedItems: [owned] }));
    TestBed.configureTestingModule({ providers: [{ provide: PublicCharacterMagicItemApiService, useValue: api }] });
    const f = TestBed.createComponent(CharacterWeapons);
    f.componentRef.setInput('accessToken', 'token');
    f.componentRef.setInput('weapons', [
      {id:1,name:'Masse terrifiante',equipped:true,attackBonus:7,damage:'1d6+4 contondants',notes:['Simple'],complete:true},
      {id:2,name:'Dague',equipped:false,attackBonus:5,damage:'1d4+3 perforants',notes:[],complete:true},
    ]);
    f.detectChanges();
    const dialog = f.nativeElement.querySelector('dialog') as HTMLDialogElement;
    dialog.showModal = vi.fn(() => dialog.setAttribute('open', ''));
    dialog.close = vi.fn(() => dialog.removeAttribute('open'));
    return f;
  }
  it('shows a compact table and modal special effects', () => {
    const f = setup();
    expect(f.nativeElement.querySelectorAll('tbody tr')).toHaveLength(2);
    expect(f.nativeElement.querySelector('dialog').hasAttribute('open')).toBe(false);
    f.nativeElement.querySelector('.details-toggle').click();
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Une vague de terreur');
    expect(f.nativeElement.textContent).toContain('3 / 3');
    expect(f.nativeElement.textContent).toContain('1d6+4');
    f.nativeElement.querySelector('.close').click();
    f.detectChanges();
    expect(f.nativeElement.querySelector('dialog').hasAttribute('open')).toBe(false);
  });
  it('spends one charge via the existing API', () => {
    const f = setup();
    api.changeCharges.mockReturnValue(of({ ownedItems: [{ ...owned, currentCharges: 2 }] }));
    f.nativeElement.querySelector('.details-toggle').click(); f.detectChanges();
    const spend = f.nativeElement.querySelector('.weapon-charges button');
    spend.click(); f.detectChanges();
    expect(api.changeCharges).toHaveBeenCalledWith('token', 1, -1);
    expect(f.nativeElement.textContent).toContain('2 / 3');
  });
  it('prevents spending in readonly mode', () => {
    const f = setup();
    f.componentRef.setInput('readonly', true);
    f.nativeElement.querySelector('.details-toggle').click(); f.detectChanges();
    expect(f.nativeElement.querySelector('.weapon-charges button')).toBeNull();
  });
  it('shows a discreet empty state', () => {
    const f = setup();
    f.componentRef.setInput('weapons', []); f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Aucune arme identifiée');
  });
});
