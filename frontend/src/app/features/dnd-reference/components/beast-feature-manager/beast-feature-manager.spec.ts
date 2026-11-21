import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { BeastFeatureManager } from './beast-feature-manager';
import { BeastProfileEditor } from './beast-profile-editor';

describe('Beast administration', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting()]});
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => http.verify());
  it('keeps a fully loaded profile after native upload clicks, replacement and deletion without submitting', async () => {
    const fixture = TestBed.createComponent(BeastFeatureManager); fixture.detectChanges();
    http.expectOne('/api/dnd/beast-features').flush({beasts:[{id:55,name:'Loup',slug:'loup',imageFilename:null},{id:4,name:'Araignée',slug:'araignee',imageFilename:null}],features:[],links:[]});
    fixture.detectChanges();
    http.expectOne('/api/dnd/beast-features/55').flush({beast:{id:55,name:'Loup',slug:'loup',challengeRating:'1',sourceUrl:null,
      statBlock:{armorClass:14,hitPoints:37,hitDice:'5d10+10',abilities:{str:17,dex:15,con:15,int:3,wis:12,cha:7},speed:{walk:15}},
      habitats:['foret'],naturalWeapons:[{id:42,name:'Morsure',attackType:'melee',attackBonus:5,damageComponents:[],description:'Allonge 1,50 m.'}]}});
    fixture.detectChanges(); await fixture.whenStable();
    const form = fixture.nativeElement.querySelector('app-beast-profile-editor form');
    expect(form).not.toBeNull();
    let submits = 0;
    form.addEventListener('submit', () => submits++);
    const location = window.location.href;
    for (const filename of ['first.png','replacement.png']) {
      const input = fixture.nativeElement.querySelector('input[type=file]');
      expect(input.form).toBeNull();
      // The absolute file input needs a local containing block; otherwise native
      // focus scrolls the hidden document instead of the dashboard scroll container.
      expect(getComputedStyle(input).position).toBe('absolute');
      expect(getComputedStyle(input.parentElement).position).toBe('relative');
      let fileClicks = 0;
      input.addEventListener('click', () => fileClicks++, {once:true});
      fixture.nativeElement.querySelector('.beast-manager__image-actions label').click();
      fixture.detectChanges(); await fixture.whenStable();
      expect(fileClicks).toBe(1);
      expect(submits).toBe(0);
      expect(fixture.nativeElement.querySelector('app-beast-profile-editor form')).toBe(form);
      Object.defineProperty(input,'files',{value:[new File(['image'],filename,{type:'image/png'})],configurable:true});
      input.dispatchEvent(new Event('change')); fixture.detectChanges();
      const request = http.expectOne('/api/dnd/beast-features/55/image');
      expect(request.request.method).toBe('POST');
      request.flush({beastId:55,imageUrl:`/api/public/beasts/images/${filename}`});
      fixture.detectChanges(); await fixture.whenStable();
      expect(fixture.nativeElement.querySelector('app-beast-profile-editor form')).toBe(form);
      expect(form.querySelector('[name=hp]').value).toBe('37');
      expect(form.querySelector('article input').value).toBe('Morsure');
      expect(form.querySelectorAll('.habitats input')[4].checked).toBe(true);
      expect(fixture.nativeElement.querySelector('.beast-manager__beast--selected').textContent).toContain('Loup');
      expect(fixture.nativeElement.querySelector('.beast-manager__image-editor img').getAttribute('src')).toBe(`/api/public/beasts/images/${filename}/thumbnail`);
      http.expectNone('/api/dnd/beast-features/55');
      http.expectNone('/api/dnd/beast-features/55/profile');
      expect(submits).toBe(0);
      expect(window.location.href).toBe(location);
    }
    fixture.nativeElement.querySelector('.beast-manager__image-actions button').click();
    const deletion = http.expectOne('/api/dnd/beast-features/55/image');
    expect(deletion.request.method).toBe('DELETE');
    deletion.flush({beastId:55,imageUrl:null});
    fixture.detectChanges(); await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('app-beast-profile-editor form')).toBe(form);
    expect(fixture.nativeElement.querySelector('.beast-manager__image-editor img')).toBeNull();
    expect(submits).toBe(0);
    const search = fixture.nativeElement.querySelector('input[type=search]');
    search.value = 'Araignée'; search.dispatchEvent(new Event('input')); fixture.detectChanges();
    const other = fixture.nativeElement.querySelector('.beast-manager__beast');
    expect(other.textContent).toContain('Araignée'); other.click(); fixture.detectChanges();
    http.expectOne('/api/dnd/beast-features/4').flush({beast:{id:4,name:'Araignée',slug:'araignee',challengeRating:null,sourceUrl:null,statBlock:null,habitats:[],naturalWeapons:[]}});
    fixture.detectChanges(); await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('.beast-manager__beast--selected').textContent).toContain('Araignée');
  });
  it('renders imported beasts with null names and supports filtering', () => {
    const fixture = TestBed.createComponent(BeastFeatureManager);
    fixture.componentRef.setInput('mode','assignments'); fixture.detectChanges();
    http.expectOne('/api/dnd/beast-features').flush({beasts:[{id:6,name:null,slug:'auroch',imageFilename:null},{id:55,name:'Loup sanguinaire',slug:'loup-sanguinaire'}],features:[],links:[]});
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelectorAll('.beast-manager__beast').length).toBe(2);
    expect(fixture.nativeElement.textContent).toContain('auroch');
    const search = fixture.nativeElement.querySelector('input[type=search]');
    search.value = 'loup'; search.dispatchEvent(new Event('input')); fixture.detectChanges();
    expect(fixture.nativeElement.querySelectorAll('.beast-manager__beast').length).toBe(1);
  });
  it('shows load failures even when no beast is selected', () => {
    const fixture = TestBed.createComponent(BeastFeatureManager); fixture.detectChanges();
    http.expectOne('/api/dnd/beast-features').flush({}, {status:403,statusText:'Forbidden'}); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[role=status]').textContent).toContain('Impossible de charger');
  });
  it('preserves stats and weapon IDs without overwriting separately edited trait assignments', () => {
    const fixture = TestBed.createComponent(BeastProfileEditor); fixture.componentRef.setInput('beastId',55); fixture.detectChanges();
    http.expectOne('/api/dnd/beast-features/55').flush({beast:{id:55,name:'Loup',slug:'loup',challengeRating:'1',sourceUrl:null,
      statBlock:{armorClass:14,hitPoints:37,hitDice:'5d10+10',abilities:{str:17,dex:15,con:15,int:3,wis:12,cha:7},speed:{walk:15},source:{keep:true}},
      habitats:['foret'],featureIds:[9],naturalWeapons:[{id:42,name:'Morsure',attackType:'melee',attackBonus:5,damageComponents:[],description:'Allonge 1,50 m.'}]}});
    fixture.detectChanges();
    fixture.nativeElement.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
    const request = http.expectOne('/api/dnd/beast-features/55/profile');
    expect(request.request.body.statBlock.source).toEqual({keep:true});
    expect(request.request.body.naturalWeapons[0].id).toBe(42);
    expect(request.request.body.featureIds).toBeUndefined();
    request.flush({beast:{...request.request.body,id:55}});
  });
  it('cancels an old detail request when selection changes', () => {
    const fixture = TestBed.createComponent(BeastProfileEditor); fixture.componentRef.setInput('beastId',4); fixture.detectChanges();
    const old = http.expectOne('/api/dnd/beast-features/4');
    fixture.componentRef.setInput('beastId',55); fixture.detectChanges();
    expect(old.cancelled).toBe(true);
    http.expectOne('/api/dnd/beast-features/55').flush({beast:{id:55,name:'Loup',slug:'loup',challengeRating:null,sourceUrl:null,statBlock:null,habitats:[],naturalWeapons:[]}});
  });
});
