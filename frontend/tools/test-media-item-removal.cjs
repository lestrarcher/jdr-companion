// docker compose exec -T frontend node tools/test-media-item-removal.cjs
// Actual Angular templates, signals, HTTP contracts and removal handlers in jsdom.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const ts = require('typescript');
const rx = require('rxjs');
const { JSDOM } = require('jsdom');
const root = path.join(__dirname, '..');
const base = 'src/app/features/control-dashboard/components/';
let checks = 0;
const check = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
(async () => {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'http://localhost' });
  for (const key of ['window', 'document', 'Node', 'Element', 'HTMLElement', 'Event']) global[key] = dom.window[key];
  await import('@angular/compiler');
  const core = await import('@angular/core');
  const { TestBed } = await import('@angular/core/testing');
  const { BrowserTestingModule, platformBrowserTesting } = await import('@angular/platform-browser/testing');
  const http = await import('@angular/common/http');
  const httpTesting = await import('@angular/common/http/testing');
  TestBed.initTestEnvironment(BrowserTestingModule, platformBrowserTesting());
  const modules = { '@angular/core': core, '@angular/core/rxjs-interop': await import('@angular/core/rxjs-interop'), '@angular/common/http': http, rxjs: rx, 'rxjs/operators': rx };
  function load(file) {
    let source = fs.readFileSync(path.join(root, file), 'utf8');
    source = source.replace(/templateUrl: '([^']+)'/, (_, relative) => `template: ${JSON.stringify(fs.readFileSync(path.join(root, path.dirname(file), relative), 'utf8'))}`)
      .replace(/styleUrl: '[^']+'/, 'styles: []');
    const exports = {};
    vm.runInNewContext(ts.transpileModule(source, {
      compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, experimentalDecorators: true },
    }).outputText, { exports, window, document, console, require: name => {
      if (!(name in modules)) modules[name] = new Proxy({}, { get(target, key) { return target[key] ??= class {}; } });
      return modules[name];
    } });
    return exports;
  }
  for (const name of ['media-api', 'magic-item-api']) modules[`@core/services/${name}.service`] = load(`src/app/core/services/${name}.service.ts`);
  const MediaApi = modules['@core/services/media-api.service'].MediaApiService;
  const ItemApi = modules['@core/services/magic-item-api.service'].MagicItemApiService;
  TestBed.configureTestingModule({ providers: [http.provideHttpClient(), httpTesting.provideHttpClientTesting()] });
  const requests = TestBed.inject(httpTesting.HttpTestingController);
  TestBed.inject(MediaApi).remove(7, 12).subscribe();
  const mediaRequest = requests.expectOne('/api/campaigns/7/media/12');
  check(mediaRequest.request.method, 'DELETE', 'Media DELETE contract'); mediaRequest.flush(null);
  TestBed.inject(ItemApi).removeFromCharacter(8, 31).subscribe();
  const itemRequest = requests.expectOne('/api/characters/8/magic-items/31');
  check(itemRequest.request.method, 'DELETE', 'Assignment DELETE uses occurrence ID'); itemRequest.flush(null);
  requests.verify(); TestBed.resetTestingModule();

  const { MediaManager } = load(base + 'media-manager/media-manager.ts');
  const media = [{ id: 12, title: 'Scene', url: '/a.png' }, { id: 13, title: 'Other', url: '/b.png' }];
  let pending, deletes = 0;
  TestBed.configureTestingModule({ providers: [{ provide: MediaApi, useValue: {
    list: () => rx.of(media), remove: () => { deletes++; return pending = new rx.Subject(); },
  } }] });
  const fixture = TestBed.createComponent(MediaManager);
  const manager = fixture.componentInstance;
  // Runtime JIT transpilation does not generate the compiler's signal-input metadata.
  manager.campaignId = core.signal(7); manager.selectedUrl = core.signal('/a.png');
  fixture.detectChanges();
  const deleteButton = () => fixture.nativeElement.querySelector('[aria-label="Supprimer Scene"]');
  window.confirm = () => false; deleteButton().click();
  check(deletes, 0, 'Cancel media confirmation sends no request');
  window.confirm = () => true; deleteButton().click(); fixture.detectChanges();
  check(deleteButton().disabled, true, 'Deletion disables repeated clicks');
  pending.error({ error: { message: 'Encore utilisé' } }); fixture.detectChanges();
  check(fixture.nativeElement.querySelectorAll('.media-card').length, 2, 'Conflict preserves media list');
  check(fixture.nativeElement.querySelector('[role=alert]').textContent, 'Encore utilisé', 'Server conflict is shown');
  let cleared = 0; manager.mediaCleared.subscribe(() => cleared++);
  deleteButton().click(); pending.next(); pending.complete(); fixture.detectChanges();
  check(fixture.nativeElement.querySelectorAll('.media-card').length, 1, 'Successful deletion immediately removes card');
  check(cleared, 1, 'Deleted local selection cleared');
  fixture.destroy(); TestBed.resetTestingModule();

  const { CharacterStatisticsModal } = load(base + 'session-characters/character-statistics-modal/character-statistics-modal.ts');
  const character = { character: { id: 8, name: 'Hero' } };
  const first = { id: 31, quantity: 1, magicItem: { name: 'Ring' } };
  const second = { ...first, id: 32 };
  const inventory = { ownedItems: [first, second], effectiveAbilities: [], attunedCount: 0, attunementLimit: 3 };
  const modalFixture = TestBed.createComponent(CharacterStatisticsModal);
  const modal = modalFixture.componentInstance;
  modal.character = core.signal(character); modal.inventory = core.signal(inventory);
  modalFixture.detectChanges();
  let selected;
  modal.itemRemoved.subscribe(item => selected = item.id);
  modalFixture.nativeElement.querySelector('.stats-item button').click();
  check(selected, 31, 'Rendered removal action selects only the clicked assignment');

  const { SessionCharacters } = load(base + 'session-characters/session-characters.ts');
  const parent = Object.create(SessionCharacters.prototype);
  let itemDeletes = 0, refreshes = 0;
  Object.assign(parent, {
    statisticsCharacter: () => character, removingItem: core.signal(false), statisticsError: core.signal(null),
    statisticsCharacterId: core.signal(8), statisticsInventory: core.signal(inventory), statisticsLoading: core.signal(false),
    characterActionError: core.signal(null), inventoryRequestRevision: 0,
    closeProgression() {}, refreshCharacters() { refreshes++; },
    magicItemApi: {
      removeFromCharacter(characterId, id) { check(characterId, 8, 'Removal scoped to selected character'); check(id, 31, 'Removal scoped to selected occurrence'); itemDeletes++; return pending = new rx.Subject(); },
      listCharacterItems: () => rx.of({ ...inventory, ownedItems: [second] }),
    },
  });
  window.confirm = () => false; parent.removeOwnedItem(first);
  check(itemDeletes, 0, 'Cancelled item removal sends no request');
  window.confirm = () => true; parent.removeOwnedItem(first); parent.removeOwnedItem(first);
  check(itemDeletes, 1, 'Pending removal cannot be submitted twice');
  pending.next(); pending.complete();
  modal.inventory.set(parent.statisticsInventory()); modalFixture.detectChanges();
  check(modalFixture.nativeElement.querySelectorAll('.stats-item').length, 1, 'Inventory DOM refreshed after successful removal');
  check(parent.statisticsInventory().ownedItems[0].id, 32, 'Other identical occurrence remains');
  check(refreshes, 1, 'Character profile also refreshed');
  parent.removeOwnedItem(first); pending.error({ error: { message: 'Refus' } });
  check(parent.statisticsError(), 'Refus', 'Removal error shown');
  check(parent.statisticsInventory().ownedItems.length, 1, 'Failed removal keeps inventory');
  parent.removeOwnedItem(first); parent.closeStatistics(); pending.next(); pending.complete();
  check(parent.statisticsCharacterId(), null, 'Late removal response does not reopen closed modal');
  modalFixture.destroy();
  console.log(`OK: ${checks} frontend removal assertions.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
