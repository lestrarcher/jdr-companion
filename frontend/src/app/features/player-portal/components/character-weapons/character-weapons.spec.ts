import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it } from 'vitest';
import { CharacterWeapons } from './character-weapons';

describe('Character weapons read model', () => {
  afterEach(() => TestBed.resetTestingModule());
  it('shows equipped and unequipped weapons, server totals and incomplete data without controls', () => {
    const f = TestBed.createComponent(CharacterWeapons);
    f.componentRef.setInput('weapons', [
      {id:1,name:'Épée',equipped:true,attackBonus:7,damage:'1d8+5 tranchant',notes:['Polyvalente (1d10+5)'],complete:true},
      {id:2,name:'Dague',equipped:false,attackBonus:5,damage:'1d4+3 perforant',notes:['Finesse'],complete:true},
      {id:3,name:'Personnalisée',equipped:false,attackBonus:null,damage:null,notes:['Profil incomplet'],complete:false},
    ]);
    f.detectChanges();
    expect(f.nativeElement.querySelectorAll('tbody tr')).toHaveLength(3);
    expect(f.nativeElement.textContent).toContain('+7');
    expect(f.nativeElement.textContent).toContain('1d10+5');
    expect(f.nativeElement.textContent).toContain('Profil incomplet');
    expect(f.nativeElement.querySelectorAll('small')).toHaveLength(1);
    expect(f.nativeElement.querySelector('button')).toBeNull();
    f.componentRef.setInput('weapons',[{id:1,name:'Épée',equipped:false,attackBonus:5,damage:'1d8+3 tranchant',notes:[],complete:true}]);
    f.detectChanges();
    expect(f.nativeElement.textContent).not.toContain('+7');
    expect(f.nativeElement.textContent).toContain('1d8+3');
  });
  it('shows a discreet empty state', () => {
    const f = TestBed.createComponent(CharacterWeapons);
    f.componentRef.setInput('weapons',[]); f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Aucune arme identifiée');
  });
});
