import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { describe, it, expect } from 'vitest';
import { CharacterTraining } from './character-training';

describe('Missing proficiency choices', () => {
  it('loads current choices, requires two skills and a tool, sends only missing proficiency groups', async () => {
    TestBed.configureTestingModule({providers: [provideHttpClient(), provideHttpClientTesting()]});
    const fixture = TestBed.createComponent(CharacterTraining);
    const http = TestBed.inject(HttpTestingController);
    fixture.componentRef.setInput('characterId', 35);
    await fixture.whenStable();
    const get = http.expectOne('/api/characters/35/training/options');
    expect(get.request.params.has('classId')).toBe(false);
    const options = {
      proficiencyRules: [
        {ruleId:166, label:'Outil artisan', choiceCount:1, optionIds:[68,69]},
        {ruleId:167, label:'Compétences artificier', choiceCount:2, optionIds:[3,6,9]},
        {ruleId:10, label:'Choix historique conservé', choiceCount:1, optionIds:[12]},
      ],
      choices: [
        {key:'proficiency:166',kind:'proficiency',ruleId:166,choiceCount:1,status:'missing',selected:[]},
        {key:'proficiency:167',kind:'proficiency',ruleId:167,choiceCount:2,status:'missing',selected:[]},
        {key:'proficiency:10',kind:'proficiency',ruleId:10,choiceCount:1,status:'complete',selected:[12]},
        {key:'language:race:human-phb',kind:'language',choiceCount:1,status:'complete',selected:['dwarvish']},
      ],
      definitions:{proficiencies:[{id:68,name:'Alchimiste'},{id:69,name:'Brasseur'},{id:3,name:'Arcanes'},{id:6,name:'Histoire'},{id:9,name:'Investigation'}],languages:[]},
    };
    get.flush(options); await fixture.whenStable();
    expect(fixture.nativeElement.querySelectorAll('fieldset').length).toBe(2);
    expect(fixture.componentInstance.complete()).toBe(false);
    fixture.componentInstance.save(); http.expectNone(request => request.method === 'PATCH');
    for (const index of [0,2,3]) fixture.nativeElement.querySelectorAll('input')[index].click();
    await fixture.whenStable();
    expect(fixture.componentInstance.complete()).toBe(true);
    fixture.componentInstance.save();
    const patch = http.expectOne('/api/characters/35/training/choices');
    expect(patch.request.body).toEqual({proficiencyChoices:[{ruleId:166,proficiencyIds:[68]},{ruleId:167,proficiencyIds:[3,6]}]});
    patch.flush(options); http.verify();
  });
});
