import { Injectable } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class ReferenceFormService {
  slugify(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase('fr')
      .trim()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  nullableString(value: string | null | undefined): string | null {
    const normalizedValue = value?.trim() ?? '';
    return normalizedValue || null;
  }

  errorMessage(
    error: unknown,
    fallback = 'Une erreur est survenue.',
  ): string {
    if (!this.isRecord(error)) {
      return fallback;
    }

    const responseBody = error['error'];

    if (typeof responseBody === 'string' && responseBody.trim()) {
      return responseBody;
    }

    if (!this.isRecord(responseBody)) {
      return fallback;
    }

    const message = responseBody['error'] ?? responseBody['message'];
    return typeof message === 'string' && message.trim()
      ? message
      : fallback;
  }

  sortByName<T extends { name: string }>(items: T[]): T[] {
    return [...items].sort((first, second) =>
      first.name.localeCompare(second.name, 'fr'),
    );
  }

  replaceById<T extends { id: number }>(
    items: T[],
    savedItem: T,
  ): T[] {
    const exists = items.some(item => item.id === savedItem.id);

    return exists
      ? items.map(item => item.id === savedItem.id ? savedItem : item)
      : [...items, savedItem];
  }

  private isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
  }
}
