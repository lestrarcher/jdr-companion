import { Component, inject, signal, computed, OnInit, input } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { REFERENCE_READ_ONLY } from '@core/services/reference-editor-context';

type Beast = { id: number; name: string; slug: string; imageFilename: string | null };
type Feature = { id: number; name: string; description: string | null };
type Link = { beastId: number; featureId: number };

@Component({
  selector: 'app-beast-feature-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './beast-feature-manager.html',
  styleUrl: './beast-feature-manager.scss',
})
export class BeastFeatureManager implements OnInit {
  readonly mode = input<'catalog' | 'assignments'>('catalog');
  private readonly http = inject(HttpClient);
  protected readonly readOnly = inject(REFERENCE_READ_ONLY);
  protected readonly beasts = signal<Beast[]>([]);
  protected readonly features = signal<Feature[]>([]);
  protected readonly links = signal<Link[]>([]);
  protected readonly selectedBeast = signal<number | null>(null);
  protected readonly selectedFeatures = signal<number[]>([]);
  protected readonly busy = signal(false);
  protected readonly imageBusy = signal(false);
  protected readonly selectedImageUrl = computed(() => {
    const filename = this.beasts().find(b => b.id === this.selectedBeast())?.imageFilename;
    return filename ? `/api/public/beasts/images/${filename}` : null;
  });
  protected readonly message = signal('');
  protected readonly filter = signal('');
  protected readonly filteredBeasts = computed(() => this.beasts().filter(b => b.name.toLocaleLowerCase('fr').includes(this.filter().toLocaleLowerCase('fr'))));
  protected readonly selectedBeastName = computed(() => this.beasts().find(b => b.id === this.selectedBeast())?.name ?? '');
  protected readonly assignedFeatures = computed(() => this.features().filter(f => this.selectedFeatures().includes(f.id)));
  protected readonly availableFeatures = computed(() => this.features().filter(f => !this.selectedFeatures().includes(f.id) && f.name.toLocaleLowerCase('fr').includes(this.featureSearch().toLocaleLowerCase('fr'))));
  protected readonly featureSearch = signal('');
  protected readonly featureToAdd = signal<number | null>(null);

  ngOnInit(): void {
    this.http.get<{beasts: Beast[]; features: Feature[]; links: Link[]}>('/api/dnd/beast-features').subscribe({
      next: result => {
        this.beasts.set((result.beasts ?? []).map(b => ({...b, id: Number(b.id)})));
        this.features.set((result.features ?? []).map(f => ({...f, id: Number(f.id)})));
        this.links.set((result.links ?? []).map(l => ({beastId: Number(l.beastId), featureId: Number(l.featureId)})));
        if (result.beasts?.length) this.selectBeast(Number(result.beasts[0].id));
      },
      error: () => this.message.set('Impossible de charger les capacités des bêtes (droits administrateur requis).'),
    });
  }

  protected selectBeast(id: number): void {
    this.selectedBeast.set(id);
    this.selectedFeatures.set(this.links().filter(l => Number(l.beastId) === id).map(l => Number(l.featureId)));
    this.message.set('');
  }

  protected uploadImage(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const id = this.selectedBeast();
    if (!file || id === null || this.readOnly || this.imageBusy()) return;
    if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type) || file.size > 5 * 1024 * 1024) {
      this.message.set('Image JPEG, PNG, WebP ou GIF de 5 Mo maximum.'); input.value = ''; return;
    }
    const data = new FormData(); data.append('image', file);
    this.imageBusy.set(true);
    this.http.post<{imageUrl: string}>(`/api/dnd/beast-features/${id}/image`, data).subscribe({
      next: result => {
        const filename = result.imageUrl.split('/').pop() ?? null;
        this.beasts.update(items => items.map(b => b.id === id ? {...b, imageFilename: filename} : b));
        this.imageBusy.set(false); this.message.set('Illustration enregistrée.'); input.value = '';
      },
      error: e => { this.imageBusy.set(false); this.message.set(e.error?.detail ?? 'Upload impossible.'); input.value = ''; },
    });
  }

  protected deleteImage(): void {
    const id = this.selectedBeast();
    if (id === null || this.readOnly || this.imageBusy()) return;
    this.imageBusy.set(true);
    this.http.delete(`/api/dnd/beast-features/${id}/image`).subscribe({
      next: () => { this.beasts.update(items => items.map(b => b.id === id ? {...b, imageFilename: null} : b)); this.imageBusy.set(false); this.message.set('Illustration supprimée.'); },
      error: () => { this.imageBusy.set(false); this.message.set('Suppression impossible.'); },
    });
  }

  protected addFeature(): void {
    const id = this.featureToAdd();
    if (id === null || this.selectedFeatures().includes(id)) return;
    this.selectedFeatures.update(current => [...current, id]);
    this.featureToAdd.set(null);
  }

  protected removeFeature(id: number): void {
    this.selectedFeatures.update(current => current.filter(value => value !== id));
  }

  protected save(): void {
    const id = this.selectedBeast();
    if (id === null || this.busy() || this.readOnly) return;
    this.busy.set(true);
    this.http.put<{beastId: number; featureIds: number[]}>(`/api/dnd/beast-features/${id}`, {featureIds: this.selectedFeatures()}).subscribe({
      next: result => {
        this.links.update(old => [...old.filter(link => Number(link.beastId) !== id), ...result.featureIds.map(featureId => ({beastId: id, featureId}))]);
        this.busy.set(false);
        this.message.set('Capacités de la bête enregistrées.');
      },
      error: e => { this.busy.set(false); this.message.set(e.error?.message ?? 'Enregistrement impossible.'); },
    });
  }
}
