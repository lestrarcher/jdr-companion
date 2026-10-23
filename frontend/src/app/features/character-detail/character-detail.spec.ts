import { CharacterSheet } from './components/character-sheet/character-sheet';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { of, Subject, throwError } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CharacterDetail } from './character-detail';
import { CampaignCharacters } from '../campaign-characters/campaign-characters';
import { CharacterApiService } from '@core/services/character-api.service';
import { DndReferenceApiService } from '@core/services/dnd-reference-api.service';
import { routes } from '../../app.routes';
import { CharacterLevelUpPage } from '../character-level-up-page/character-level-up-page';
import { CharacterHistory } from './components/character-history/character-history';
import { By } from '@angular/platform-browser';

describe('Character list/detail separation', () => {
  let api: any;
  let reference: any;
  let params: Record<string,string>;
  beforeEach(() => {
    params = { campaignId: '2', characterId: '7' };
    api = {
      list: vi.fn(() => of([])), getProfile: vi.fn(() => of({id:7,totalLevel:2})),
      getLevelUpOptions: vi.fn(() => of({canLevelUp:true})),
      levelUp: vi.fn(() => of({character:{id:7,totalLevel:3},message:'Niveau gagné'})),
      addProgression: vi.fn(() => of({})), removeProgression: vi.fn(() => of({})),
      getTrainingOptions: vi.fn(() => of({})),
    };
    reference = { getCampaignProgressions:vi.fn(() => of({progressions:[]})), getCampaignReference:vi.fn() };
    TestBed.configureTestingModule({providers:[
      provideRouter([]), {provide:ActivatedRoute,useFactory:()=>({snapshot:{paramMap:convertToParamMap(params)}})},
      {provide:CharacterApiService,useValue:api}, {provide:DndReferenceApiService,useValue:reference},
    ]});
    TestBed.overrideComponent(CharacterLevelUpPage,{set:{template:''}});
  });
  // Exercise transferred page orchestration independently of the unchanged child editors.
  function detail(): any {
    TestBed.overrideComponent(CharacterDetail,{set:{template:''}});
    return TestBed.createComponent(CharacterDetail).componentInstance;
  }
  it('list loads only the roster, never profiles, level-up options or references', () => {
    const f=TestBed.createComponent(CampaignCharacters);
    f.detectChanges();
    expect(api.list).toHaveBeenCalledWith(2);
    expect(api.getProfile).not.toHaveBeenCalled();
    expect(api.getLevelUpOptions).not.toHaveBeenCalled();
    expect(reference.getCampaignProgressions).not.toHaveBeenCalled();
    expect(reference.getCampaignReference).not.toHaveBeenCalled();
    expect(f.nativeElement.querySelector('app-character-roster')).not.toBeNull();
  });
  it('navigates on the existing roster selection event', () => {
    api.list.mockReturnValue(of([{id:7,name:'Aline',type:'player',totalLevel:0,classLevels:[],definition:{}}]));
    const f=TestBed.createComponent(CampaignCharacters); f.detectChanges();
    const navigate=vi.spyOn(TestBed.inject(Router),'navigate').mockResolvedValue(true);
    f.nativeElement.querySelector('.character-card').click();
    expect(navigate).toHaveBeenCalledWith(['/campaigns',2,'characters',7]);
  });
  it('loads a detail directly from URL identifiers', () => {
    const c=detail();
    expect(api.list).not.toHaveBeenCalled();
    expect(api.getProfile).toHaveBeenCalledWith(2,7);
    expect(api.getLevelUpOptions).toHaveBeenCalledWith(2,7);
    expect(reference.getCampaignProgressions).toHaveBeenCalledWith(2);
    expect(reference.getCampaignReference).not.toHaveBeenCalled();
    expect(c.profile().id).toBe(7);
  });
  it.each(['0','-1','bad','1.5','9007199254740992'])('rejects invalid character identifier %s without requests', id => {
    params['characterId']=id; const c=detail();
    expect(api.getProfile).not.toHaveBeenCalled();
    expect(c.profileError()).toContain('invalide');
    c.refreshProfile(); expect(api.getProfile).not.toHaveBeenCalled();
  });
  it('rejects an invalid campaign on both pages', () => {
    params['campaignId']='bad'; detail(); TestBed.createComponent(CampaignCharacters);
    expect(api.list).not.toHaveBeenCalled(); expect(api.getProfile).not.toHaveBeenCalled();
  });
  it('retains loading until requests finish and allows retry after failure', () => {
    const request=new Subject(); api.getProfile.mockReturnValueOnce(request);
    const c=detail(); expect(c.profileLoading()).toBe(true);
    request.error(new HttpErrorResponse({status:404,error:{message:'Introuvable'}}));
    expect(c.profileLoading()).toBe(false); expect(c.profileError()).toBe('Introuvable');
    c.refreshProfile(); expect(c.profile().id).toBe(7); expect(c.profileError()).toBeNull();
  });
  it('navigates to the GM page, forwards the payload, and reloads the profile on return', () => {
    const c=detail(); const payload={classId:3,hitPoints:5};
    const navigate=vi.spyOn(TestBed.inject(Router),'navigate').mockResolvedValue(true);
    c.openLevelUp();
    expect(navigate).toHaveBeenCalledWith(['/campaigns',2,'characters',7,'level-up']);
    const f=TestBed.createComponent(CharacterLevelUpPage); f.detectChanges();
    const levelPage=f.componentInstance as any; levelPage.submitLevelUp(payload);
    expect(api.levelUp).toHaveBeenCalledWith(2,7,payload);
    expect(navigate).toHaveBeenLastCalledWith(['/campaigns',2,'characters',7]);
    expect(levelPage.submitting()).toBe(false);
    api.getProfile.mockReturnValue(of({id:7,totalLevel:3})); c.refreshProfile();
    expect(c.profile().totalLevel).toBe(3); expect(c.activeEditor()).toBeNull();
    expect(api.getLevelUpOptions).toHaveBeenCalledTimes(3);
  });
  it('retains a failed level-up editor and displays server error', () => {
    const f=TestBed.createComponent(CharacterLevelUpPage); f.detectChanges();
    const c=f.componentInstance as any;
    const navigate=vi.spyOn(TestBed.inject(Router),'navigate').mockResolvedValue(true);
    const profile=c.profile(); const options=c.options();
    api.levelUp.mockReturnValue(throwError(()=>new HttpErrorResponse({status:409,error:{message:'Conflit'}})));
    c.submitLevelUp({});
    expect(c.error()).toBe('Conflit'); expect(navigate).not.toHaveBeenCalled();
    expect(c.submitting()).toBe(false);expect(c.profile()).toBe(profile);expect(c.options()).toBe(options);
  });
  it('keeps HP history updates and training requests', () => {
    const c=detail(); c.hitPointHistoryCompleted({character:{id:7,totalLevel:2},message:'PV corrigés'});
    expect(c.success()).toBe('PV corrigés'); expect(c.profile().id).toBe(7);
    const levelPage=TestBed.createComponent(CharacterLevelUpPage).componentInstance as any;
    levelPage.loadTraining(3,4);expect(api.getTrainingOptions).toHaveBeenCalledWith(7,3,4);
  });
  it('refreshes after assigning/removing progressions and preserves success messages', () => {
    const c=detail(); c.addProgression(4);
    expect(api.addProgression).toHaveBeenCalledWith(2,7,4);
    expect(c.success()).toBe('Progression attribuée.');
    c.removeProgression(4);expect(api.removeProgression).toHaveBeenCalledWith(2,7,4);
    expect(c.success()).toBe('Progression retirée.'); expect(api.getProfile).toHaveBeenCalledTimes(3);
  });
  it('preserves named spell routes and new before the generic detail route', () => {
    const paths=routes.flatMap(r=>(r.children??[]).map(c=>c.path));
    const base='campaigns/:campaignId/characters/';
    for(const suffix of ['new',':characterId/spellbook',':characterId/spell-revision',':characterId/spell-substitutions']) {
      expect(paths.indexOf(base+suffix)).toBeGreaterThanOrEqual(0);
      expect(paths.indexOf(base+suffix)).toBeLessThan(paths.indexOf(base+':characterId'));
    }
  });
  async function page() {
    api.getProfile.mockReturnValue(of({
      id:7,name:'Aline',type:'player',playerName:'Camille',race:{name:'Elfe'},totalLevel:2,progressions:[],
      features:[],resources:[],abilities:[],feats:[],classSummary:[],proficiencyBonus:2,
      hitPoints:{maximumValue:12,baseValue:10,constitutionBonus:2,complete:true,missingLevelPositions:[]},
      classLevels:[{classId:1,className:'Magicien',subclassName:'Évocation'},{classId:1,className:'Magicien',subclassName:null}],
    }));
    // Keep real page bindings; substitute only the unchanged child's presentation.
    TestBed.overrideComponent(CharacterSheet,{set:{template:'<button (click)="progressionsRequested.emit()">Progressions</button><ng-content />'}});
    const f=TestBed.createComponent(CharacterDetail); await f.whenStable(); return f;
  }
  it('shows the real identity, exactly five tabs and the sheet by default', async () => {
    const f=await page();
    expect(f.nativeElement.querySelector('h1').textContent).toBe('Aline');
    expect(f.nativeElement.querySelector('.character-heading').textContent).toContain('Magicien 2 · Évocation');
    expect(f.nativeElement.querySelector('.character-heading').textContent).toContain('Camille');
    const tabs=[...f.nativeElement.querySelectorAll('[role=tab]')] as HTMLButtonElement[];
    expect(tabs.map(t=>t.textContent?.trim())).toEqual(['Fiche','Équipement','Sorts','Historique','Capacités et ressources']);
    expect(tabs[0].getAttribute('aria-selected')).toBe('true');
    expect(f.nativeElement.querySelector('#panel-sheet').hidden).toBe(false);
  });
  it('moves spell tools into their panel without requests or destroying the sheet', async () => {
    const f=await page(); const profile=f.nativeElement.querySelector('app-character-sheet');
    f.nativeElement.querySelector('#tab-spells').click(); await f.whenStable();
    expect(f.nativeElement.querySelector('#panel-sheet').hidden).toBe(true);
    expect(f.nativeElement.querySelector('#panel-spells').hidden).toBe(false);
    const links=[...f.nativeElement.querySelectorAll('#panel-spells a')] as HTMLAnchorElement[];
    expect(links.map(a=>a.getAttribute('href'))).toEqual([
      '/campaigns/2/characters/7/spellbook','/campaigns/2/characters/7/spell-revision','/campaigns/2/characters/7/spell-substitutions',
    ]);
    expect(f.nativeElement.querySelector('#panel-sheet .spell-actions')).toBeNull();
    f.nativeElement.querySelector('#tab-sheet').click(); await f.whenStable();
    expect(f.nativeElement.querySelector('app-character-sheet')).toBe(profile);
    expect(api.getProfile).toHaveBeenCalledTimes(1);
    expect(api.getLevelUpOptions).toHaveBeenCalledTimes(1);
    expect(reference.getCampaignProgressions).toHaveBeenCalledTimes(1);
  });
  it('supports arrows, Home and End with roving keyboard focus', async () => {
    const f=await page(); const first=f.nativeElement.querySelector('#tab-sheet');
    first.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowLeft',bubbles:true})); await f.whenStable();
    expect(f.nativeElement.querySelector('#tab-resources').getAttribute('aria-selected')).toBe('true');
    expect(document.activeElement?.id).toBe('tab-resources');
    document.activeElement?.dispatchEvent(new KeyboardEvent('keydown',{key:'Home',bubbles:true})); await f.whenStable();
    expect(document.activeElement?.id).toBe('tab-sheet');
    first.dispatchEvent(new KeyboardEvent('keydown',{key:'End',bubbles:true})); await f.whenStable();
    expect(document.activeElement?.id).toBe('tab-resources');
  });
  it('keeps existing action outputs and the editor state while switching tabs', async () => {
    const f=await page(); const c=f.componentInstance as any;
    f.nativeElement.querySelector('app-character-sheet button').click(); await f.whenStable();
    expect(c.activeEditor()).toBe('progressions');
    const editor=f.nativeElement.querySelector('app-character-progressions');
    f.nativeElement.querySelector('#tab-history').click(); await f.whenStable();
    expect(c.activeEditor()).toBe('progressions');
    expect(f.nativeElement.querySelector('app-character-progressions')).toBe(editor);
    const history=f.debugElement.query(By.directive(CharacterHistory));
    expect(history).not.toBeNull();
    expect(history.componentInstance.character()).toBe(c.profile());
    expect(f.nativeElement.querySelector('#panel-history').hidden).toBe(false);
  });});
