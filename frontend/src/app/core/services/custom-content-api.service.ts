import { inject, Injectable } from '@angular/core';
import { REFERENCE_EDITOR_SCOPE, REFERENCE_VIEW_ORIGIN, matchesReferenceOrigin } from './reference-editor-context';
import { PersonalCatalogueCategory, PersonalCatalogueItem } from '@core/models/personal-catalogue.model';
import { Spell, SpellOptions, SpellPage, SpellPayload, SpellGrant, SpellGrantPayload, SpellGrantOptions } from '@core/models/spell.model';
import { HttpClient } from '@angular/common/http';
import { map } from 'rxjs';
import { BackgroundOptions, BackgroundPayload, CustomBackground } from '@core/models/custom-background.model';
import { CreateCustomResourcePayload, CustomFeature, CustomFeatureRule, CustomResource, CustomResourceRule, UpdateCustomResourcePayload } from '@core/models/custom-content.model';

@Injectable({ providedIn: 'root' })
export class CustomContentApiService {
  private readonly http = inject(HttpClient);
  readonly scope = inject(REFERENCE_EDITOR_SCOPE);
  readonly viewOrigin = inject(REFERENCE_VIEW_ORIGIN);
  private readonly base = `/api/reference/${this.scope}`;

  spells(params: Record<string, string | number>) {
    return this.http.get<SpellPage>(`${this.base}/spells`, { params: this.viewOrigin ? { ...params, origin: this.viewOrigin } : params, withCredentials: true });
  }
  spellOptions() { return this.http.get<SpellOptions>(`${this.base}/spells/options`, { withCredentials: true }); }
  saveSpell(id: number | null, payload: Partial<SpellPayload>) {
    const request = id === null
      ? this.http.post<{ spell: Spell }>(`${this.base}/spells`, payload, { withCredentials: true })
      : this.http.patch<{ spell: Spell }>(`${this.base}/spells/${id}`, payload, { withCredentials: true });
    return request.pipe(map(response => response.spell));
  }
  deleteSpell(id: number) { return this.http.delete<void>(`${this.base}/spells/${id}`, { withCredentials: true }); }
  spellGrants(id: number) { return this.http.get<{ grants: SpellGrant[] }>(`${this.base}/spells/${id}/grants`, { withCredentials: true }).pipe(map(r => r.grants)); }
  spellGrantOptions() { return this.http.get<SpellGrantOptions>(`${this.base}/spells/grant-options`, { withCredentials: true }); }
  saveSpellGrant(spellId: number, id: number | null, payload: Partial<SpellGrantPayload>) {
    const url = `${this.base}/spells/${spellId}/grants`;
    return id === null ? this.http.post<{ grant: SpellGrant }>(url, payload, { withCredentials: true }) : this.http.patch<{ grant: SpellGrant }>(`${url}/${id}`, payload, { withCredentials: true });
  }
  deleteSpellGrant(spellId: number, id: number) { return this.http.delete<void>(`${this.base}/spells/${spellId}/grants/${id}`, { withCredentials: true }); }

  backgrounds() {
    return this.http.get<{ backgrounds: CustomBackground[] }>(`${this.base}/backgrounds`, { withCredentials: true }).pipe(map(r => r.backgrounds.filter(item => matchesReferenceOrigin(item, this.viewOrigin))));
  }
  backgroundOptions() {
    return this.http.get<BackgroundOptions>(`${this.base}/backgrounds/options`, { withCredentials: true });
  }
  saveBackground(id: number | null, payload: Partial<BackgroundPayload>) {
    const request = id === null
      ? this.http.post<{ background: CustomBackground }>(`${this.base}/backgrounds`, payload, { withCredentials: true })
      : this.http.patch<{ background: CustomBackground }>(`${this.base}/backgrounds/${id}`, payload, { withCredentials: true });
    return request.pipe(map(r => r.background));
  }
  deleteBackground(id: number) {
    return this.http.delete<void>(`${this.base}/backgrounds/${id}`, { withCredentials: true });
  }

  features() {
    return this.http.get<{ features: CustomFeature[] }>(`${this.base}/features`, { withCredentials: true })
      .pipe(map(response => response.features));
  }

  resources() {
    return this.http.get<{ resources: CustomResource[] }>(`${this.base}/resources`, { withCredentials: true })
      .pipe(map(response => response.resources));
  }

  createResource(payload: CreateCustomResourcePayload) {
    return this.http.post<{ resource: CustomResource }>(`${this.base}/resources`, payload, { withCredentials: true })
      .pipe(map(response => response.resource));
  }

  updateResource(id: number, payload: UpdateCustomResourcePayload) {
    return this.http.patch<{ resource: CustomResource }>(`${this.base}/resources/${id}`, payload, { withCredentials: true })
      .pipe(map(response => response.resource));
  }

  deleteResource(id: number) {
    return this.http.delete<void>(`${this.base}/resources/${id}`, { withCredentials: true });
  }

  featureRules() {
    return this.http.get<{ rules: CustomFeatureRule[] }>(`${this.base}/feature-rules`, { withCredentials: true })
      .pipe(map(response => response.rules));
  }

  resourceRules() {
    return this.http.get<{ rules: CustomResourceRule[] }>(`${this.base}/resource-rules`, { withCredentials: true })
      .pipe(map(response => response.rules));
  }

  saveFeature(id: number | null, payload: { name: string; description: string | null; resourceDefinitionId: number | null }) {
    const url = `${this.base}/features`;
    const request = id === null ? this.http.post<{ feature: CustomFeature }>(url, payload, { withCredentials: true }) : this.http.patch<{ feature: CustomFeature }>(`${url}/${id}`, payload, { withCredentials: true });
    return request.pipe(map(r => r.feature));
  }
  deleteFeature(id: number) { return this.http.delete<void>(`${this.base}/features/${id}`, { withCredentials: true }); }
  saveFeatureRule(id: number | null, payload: { featureDefinitionId: number; sourceType: string; sourceId: number; unlockLevel: number | null; progressionThreshold: number | null }) {
    const url = `${this.base}/feature-rules`;
    const request = id === null ? this.http.post<{ rule: CustomFeatureRule }>(url, payload, { withCredentials: true }) : this.http.patch<{ rule: CustomFeatureRule }>(`${url}/${id}`, payload, { withCredentials: true });
    return request.pipe(map(r => r.rule));
  }
  deleteFeatureRule(id: number) { return this.http.delete<void>(`${this.base}/feature-rules/${id}`, { withCredentials: true }); }
  saveResourceRule(id: number | null, payload: { resourceDefinitionId: number; sourceType: string; sourceId: number; unlockLevel: number; maximumBonus: number }) {
    const url = `${this.base}/resource-rules`;
    const request = id === null ? this.http.post<{ rule: CustomResourceRule }>(url, payload, { withCredentials: true }) : this.http.patch<{ rule: CustomResourceRule }>(`${url}/${id}`, payload, { withCredentials: true });
    return request.pipe(map(r => r.rule));
  }
  deleteResourceRule(id: number) { return this.http.delete<void>(`${this.base}/resource-rules/${id}`, { withCredentials: true }); }

  personalCatalogue(category: PersonalCatalogueCategory) {
    return this.http.get<{ items: PersonalCatalogueItem[] }>(`${this.base}/${category}`, { withCredentials: true }).pipe(map(r => r.items));
  }
  savePersonalCatalogue(category: PersonalCatalogueCategory, id: number | null, payload: Record<string, unknown>) {
    const url = `${this.base}/${category}`;
    return id === null
      ? this.http.post<{ item: PersonalCatalogueItem }>(url, payload, { withCredentials: true }).pipe(map(r => r.item))
      : this.http.patch<{ item: PersonalCatalogueItem }>(`${url}/${id}`, payload, { withCredentials: true }).pipe(map(r => r.item));
  }

}
