import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { describe, it, expect } from 'vitest';
import { MediaManager } from './media-manager';
import { MediaApiService } from '@core/services/media-api.service';

describe('Media preview batches', () => {
  it('bounds the initial previews and exposes the remaining media progressively', async () => {
    TestBed.configureTestingModule({ providers: [{ provide: MediaApiService, useValue: {
      list: () => of(Array.from({ length: 100 }, (_, id) => ({ id, url: '/image-'+id, title: 'Image '+id }))),
    } }] });
    const fixture = TestBed.createComponent(MediaManager);
    fixture.componentRef.setInput('campaignId', 1);
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelectorAll('img').length).toBe(12);
    const button = Array.from(fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>)
      .find(button => button.textContent?.includes('supplémentaires'))!;
    button.click();
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelectorAll('img').length).toBe(24);
  });
});
