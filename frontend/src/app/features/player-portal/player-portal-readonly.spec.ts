import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PlayerPortal } from './player-portal';
import { CharacterStateService } from '@core/services/character-state.service';
import { CampaignConfigurationRegistryService } from '@core/services/campaign-configuration-registry.service';
import { RestRequestApiService } from '@core/services/rest-request-api.service';
import { CharacterVitals } from './components/character-vitals/character-vitals';
import { CharacterResources } from './components/character-resources/character-resources';
import { CharacterMagicItems } from './components/character-magic-items/character-magic-items';
import { CharacterProgressions } from './components/character-progressions/character-progressions';
import { CharacterStoredValues } from './components/character-stored-values/character-stored-values';

function snapshot(status: string, artificer: boolean): any {
  return { revision: 4, campaign:{id:2,configurationKey:'strahd'}, session:{id:3,status}, levelUpAllowed:false,
    character:{slug:'test',name:artificer?'Bloup':'Nikita',type:'player',totalLevel:1,
      hitPoints:{maximumValue:10},classSummary:[],progressions:[],abilities:[],feats:[],actions:[],
      training:{proficiencies:[],languages:[],expertises:[]}, proficiencyBonus:2,
      features:artificer?[{slug:'magical-tinkering',name:'Bricolages magiques',visible:true}]:[],
      resources:[{slug:'spell-slot-1',name:'Emplacements niveau 1',maximum:3,rechargeType:'long-rest'},
        ...(artificer?[{slug:'magical-tinkering',name:'Bricolages magiques',maximum:3,rechargeType:'none'}]:[])],
      definition:{resources:[{id:'magical-tinkering',resetPeriod:'manual',allowManualIncrease:true}]},
    },
    state:{hitPoints:{current:8,temporary:1},hitDice:[],resources:[{id:'magical-tinkering',currentValue:2}],progressions:[],
      magicalTinkeringNotes:'Lampe rouge',inspiration:true},
  };
}

async function setup(status: string, artificer: boolean, desktop = false) {
  vi.stubGlobal('matchMedia', vi.fn(() => ({matches:desktop,addEventListener:vi.fn(),removeEventListener:vi.fn()})));
  TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([]),
    {provide:ActivatedRoute,useValue:{snapshot:{paramMap:convertToParamMap({campaignId:'2',sessionId:'3',accessToken:'test'})}}},
    {provide:CampaignConfigurationRegistryService,useValue:{getConfiguration:()=>({id:'2',name:'Test campaign'})}},
    {provide:RestRequestApiService,useValue:{getLatest:()=>of(null)}},
  ]});
  const fixture=TestBed.createComponent(PlayerPortal);
  const http=TestBed.inject(HttpTestingController);
  http.expectOne('/api/public/characters/test').flush(snapshot(status,artificer));
  await fixture.whenStable();
  return {fixture,http,component:fixture.componentInstance as any};
}

afterEach(()=> { TestBed.resetTestingModule(); vi.unstubAllGlobals(); });

describe('One player sheet, read-only outside LIVE',()=> {
  it.each([['live',true],['closed',true],['live',false],['closed',false]])('renders the same sections: %s artificer=%s',async(status,artificer)=> {
    const {fixture,http,component}=await setup(status as string,artificer as boolean);
    const closed=status==='closed';
    expect(fixture.nativeElement.querySelector('.character-dashboard')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('.portal-message')).toBeNull();
    expect(fixture.nativeElement.textContent).toContain('Emplacements niveau 1');
    const vitals=fixture.debugElement.query(By.directive(CharacterVitals)).componentInstance as any;
    const resources=fixture.debugElement.query(By.directive(CharacterResources)).componentInstance as any;
    expect(vitals.readonly()).toBe(closed); expect(resources.readonly()).toBe(closed);
    if(artificer) {
      expect(fixture.nativeElement.textContent).toContain('Bricolages magiques');
      if(closed) { expect(fixture.nativeElement.textContent).toContain('Lampe rouge'); expect(fixture.nativeElement.querySelector('textarea')).toBeNull(); }
    }
    if(closed) {
      const state=TestBed.inject(CharacterStateService); const before=structuredClone(state.character());
      vitals.hitPointAmount=2; vitals.applyDamage(); vitals.heal(); vitals.changeTemporaryHitPoints(1);
      resources.changeResource(state.character()!.resources[0],-1);
      expect(state.character()).toEqual(before);
    }
    component.selectTab('status'); await fixture.whenStable();
    expect(fixture.debugElement.query(By.directive(CharacterProgressions)).componentInstance.readonly()).toBe(closed);
    expect(fixture.debugElement.query(By.directive(CharacterStoredValues)).componentInstance.readonly()).toBe(closed);
    for(const tab of ['statistics','features','spells']) {
      component.selectTab(tab); await fixture.whenStable();
      expect(component.activeTab()).toBe(tab);
      expect(fixture.nativeElement.querySelector('.character-dashboard')).not.toBeNull();
    }
    expect(fixture.nativeElement.textContent).toContain('Sorts et incantations');
    component.selectTab('possessions'); await fixture.whenStable();
    http.expectOne('/api/public/characters/test/wallet').flush({wallet:{goldPieces:12}});
    http.expectOne('/api/public/characters/test/magic-items').flush({ownedItems:[],attunedCount:0,attunementLimit:3});
    await fixture.whenStable();
    expect(fixture.nativeElement.textContent).toContain('Bourse');
    if(closed) {
      expect(fixture.nativeElement.querySelector('.wallet-operation')).toBeNull();
      const items=fixture.debugElement.query(By.directive(CharacterMagicItems)).componentInstance as any;
      items.toggleEquipped({}); items.toggleAttuned({}); items.spendCharge({}); items.restoreCharge({});
    }
    http.expectNone(r=>r.method!=='GET');
    for (const request of http.match('/api/public/characters/test')) request.flush(snapshot(status as string, artificer as boolean));
    fixture.destroy(); http.verify({ignoreCancelled:true});
  });

  it('updates the same sheet from LIVE to CLOSED and back without writing on status changes', async () => {
    const {fixture,http,component}=await setup('live',true);
    for (const status of ['closed','live']) {
      const pending=http.match('/api/public/characters/test');
      if (pending.length) pending.forEach(request=>request.flush(snapshot(status,true)));
      else {
        component.refreshCharacterIfChanged();
        http.expectOne('/api/public/characters/test').flush(snapshot(status,true));
      }
      await fixture.whenStable();
      expect(component.canEdit()).toBe(status==='live');
      expect(fixture.nativeElement.querySelector('.character-dashboard')).not.toBeNull();
      expect(fixture.debugElement.query(By.directive(CharacterVitals)).componentInstance.readonly()).toBe(status==='closed');
      http.expectNone(request=>request.method!=='GET');
    }
    fixture.destroy(); http.verify({ignoreCancelled:true});
  });

  it('keeps desktop navigation usable despite pending preparation/rest in CLOSED',async()=> {
    const {fixture,http,component}=await setup('closed',true,true);
    component.characterActions.set({prepared:[],preparationPending:true});
    component.actions.set([{slug:'aid',requiresPreparation:true}]);
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('.dashboard-content').hasAttribute('inert')).toBe(false);
    expect(fixture.nativeElement.querySelector('app-action-preparation-modal')).toBeNull();
    component.selectTab('features'); await fixture.whenStable(); expect(component.activeTab()).toBe('features');
    http.expectNone(r=>r.method!=='GET'); fixture.destroy();
  });
});
