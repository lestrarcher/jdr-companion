import { TestBed } from '@angular/core/testing';
import { describe, it, expect, vi } from 'vitest';
import { CharacterResources } from './character-resources';
import { CharacterStateService } from '@core/services/character-state.service';

describe('Tinkering resource controls', () => {
  it('shows the ordinary counter, allows both directions and disables all edits in readonly mode', async () => {
    const adjustResource = vi.fn();
    TestBed.configureTestingModule({providers:[{provide:CharacterStateService,useValue:{adjustResource}}]});
    const fixture = TestBed.createComponent(CharacterResources);
    fixture.componentRef.setInput('resources',[{id:'magical-tinkering',name:'Bricolages magiques',currentValue:2,maximumValue:3,resetPeriod:'manual',allowManualIncrease:true}]);
    await fixture.whenStable();
    expect(fixture.nativeElement.textContent).toContain('Bricolages magiques');
    expect(fixture.nativeElement.querySelector('.counter').textContent.replace(/\s+/g,'')).toContain('2/3');
    const buttons = fixture.nativeElement.querySelectorAll('.counter button');
    buttons[0].click(); buttons[1].click();
    expect(adjustResource.mock.calls).toEqual([['magical-tinkering',-1],['magical-tinkering',1]]);
    fixture.componentRef.setInput('readonly',true); await fixture.whenStable();
    expect([...buttons].every((button:any)=>button.disabled)).toBe(true);
    buttons[0].click(); buttons[1].click(); expect(adjustResource).toHaveBeenCalledTimes(2);
  });
});
