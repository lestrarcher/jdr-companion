import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { PolicyGuide } from './policy-guide';
describe('Guided policy form',()=>{
  it('preserves advanced fields while adding a typed choice rule',()=>{
    const f=TestBed.createComponent(PolicyGuide);
    f.componentRef.setInput('document',JSON.stringify({sourceType:'class',sourceId:2,basePolicyId:null,policy:{key:'fixture',version:1,levels:Array(20).fill({known:0}),limitations:['Preserved']}}));
    f.detectChanges();const c=f.componentInstance;c.open();
    let result='';c.changed.subscribe(v=>result=v);c.addRule();
    expect(JSON.parse(result).policy.limitations).toEqual(['Preserved']);
    expect(JSON.parse(result).rules[0].parameters.opportunities).toHaveLength(20);
  });
  it('rejects malformed input without emitting a replacement document',()=>{
    const f=TestBed.createComponent(PolicyGuide);f.componentRef.setInput('document','{}');f.detectChanges();
    let emitted=false;f.componentInstance.changed.subscribe(()=>emitted=true);f.componentInstance.open();
    expect(f.componentInstance.error()).not.toBe('');expect(emitted).toBe(false);
  });
});
