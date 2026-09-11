import { CanDeactivateFn } from '@angular/router';

export const preparationGuard: CanDeactivateFn<{ canLeavePreparation(): boolean }> =
  component => component.canLeavePreparation();
