import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it } from 'vitest';
import { InventoryItemCard } from './inventory-item-card';
import { CharacterMagicItemResponse } from '@core/services/public-character-magic-item-api.service';

describe('Infused inventory item properties', () => {
  afterEach(() => TestBed.resetTestingModule());
  it('renders backend item-scoped bonuses and removes them after refresh', () => {
    const item: CharacterMagicItemResponse = {
      id:42, custom:false, quantity:1, currentCharges:0, attuned:false, equipped:true, effectActive:false, notes:null,
      magicItem:{id:5, name:'Arme test', description:null, rarity:null, category:'weapon', equippable:true,
        requiresAttunement:false, maximumCharges:null, rechargeType:'none', rechargeFormula:null, abilityEffects:[]},
      infusion:{id:1, name:'Arme améliorée', creatorName:'Artificière test', creatorLevel:10,
        description:'Bonus limité à cette arme.', weaponBonuses:{attack:2,damage:2,itemId:42,requiresUse:true}},
    };
    const f = TestBed.createComponent(InventoryItemCard);
    f.componentRef.setInput('item', item);
    f.componentRef.setInput('accessToken', 'token');
    f.componentRef.setInput('expanded', true);
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Arme améliorée');
    expect(f.nativeElement.textContent).toContain('Artificière test');
    expect(f.nativeElement.textContent).toContain('attaque +2, dégâts +2');
    f.componentRef.setInput('item', {...item, equipped:false, infusion:{...item.infusion!, weaponBonuses:{attack:0,damage:0,itemId:42,requiresUse:true}}});
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('attaque +0, dégâts +0');
    f.componentRef.setInput('item', {...item, infusion:null});
    f.detectChanges();
    expect(f.nativeElement.textContent).not.toContain('Arme améliorée');
    expect(f.nativeElement.textContent).not.toContain('attaque +');
  });
});
