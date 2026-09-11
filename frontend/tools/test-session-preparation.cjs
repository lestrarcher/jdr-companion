// Run: docker compose exec frontend node tools/test-session-preparation.cjs
// Actual Angular templates, sanitizer, signals and router in an isolated jsdom document.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const ts = require('typescript');
const rx = require('rxjs');
const { JSDOM } = require('jsdom');
const root = path.join(__dirname, '..');
const feature = 'src/app/features/control-dashboard/';
const prep = feature + 'components/session-preparation/';
let checks = 0;
const check = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };

(async () => {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'http://localhost' });
  for (const key of ['window', 'document', 'Node', 'Element', 'HTMLElement', 'Event', 'BeforeUnloadEvent']) {
    if (dom.window[key]) global[key] = dom.window[key];
  }
  await import('@angular/compiler');
  const core = await import('@angular/core');
  const interop = await import('@angular/core/rxjs-interop');
  const { TestBed } = await import('@angular/core/testing');
  const { BrowserTestingModule, platformBrowserTesting } = await import('@angular/platform-browser/testing');
  const router = await import('@angular/router');
  const { RouterTestingHarness } = await import('@angular/router/testing');
  const http = await import('@angular/common/http');
  const httpTesting = await import('@angular/common/http/testing');
  TestBed.initTestEnvironment(BrowserTestingModule, platformBrowserTesting());
  const modules = { '@angular/core': core, '@angular/core/rxjs-interop': interop, '@angular/router': router, '@angular/common/http': http, rxjs: rx, marked: await import('marked') };
  function load(file) {
    const exports = {};
    vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), {
      compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, experimentalDecorators: true },
    }).outputText, { exports, window, document, console, TextEncoder, require: name => {
      if (!(name in modules)) modules[name] = new Proxy({}, { get(target, key) { return target[key] ??= class {}; } });
      return modules[name];
    } });
    return exports;
  }
  const apiModule = load('src/app/core/services/game-session-api.service.ts');
  modules['@core/services/game-session-api.service'] = apiModule;
  const Api = apiModule.GameSessionApiService;
  modules['./preparation-markdown'] = load(prep + 'preparation-markdown.ts');

  // Inline the external Angular resources before evaluating the component.
  // This avoids TestBed trying to resolve templateUrl/styleUrl in this custom VM loader.
  const template = fs.readFileSync(path.join(root, prep + 'session-preparation.html'), 'utf8');
  const preparationSourcePath = path.join(root, prep + 'session-preparation.ts');
  const preparationSource = fs.readFileSync(preparationSourcePath, 'utf8')
    .replace(
      "templateUrl: './session-preparation.html',",
      `template: ${JSON.stringify(template)},`,
    )
    .replace(
      "styleUrl: './session-preparation.scss',",
      "styles: [],",
    );

  function loadSource(source, filename = 'inline.ts') {
    const exports = {};
    vm.runInNewContext(ts.transpileModule(source, {
      compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, experimentalDecorators: true },
      fileName: filename,
    }).outputText, { exports, window, document, console, TextEncoder, require: name => {
      if (!(name in modules)) modules[name] = new Proxy({}, { get(target, key) { return target[key] ??= class {}; } });
      return modules[name];
    } });
    return exports;
  }

  const { SessionPreparation } = loadSource(preparationSource, preparationSourcePath);
  modules['./components/session-preparation/session-preparation'] = { SessionPreparation };
  const { ControlDashboard } = load(feature + 'control-dashboard.ts');
  const { preparationGuard } = load('src/app/core/guards/preparation-guard.ts');

  // HTTP contract: notes are a targeted PATCH, display uses the safe endpoint.
  TestBed.configureTestingModule({ providers: [http.provideHttpClient(), httpTesting.provideHttpClientTesting()] });
  const client = TestBed.inject(Api), requests = TestBed.inject(httpTesting.HttpTestingController);
  client.updatePreparation(7, '# Raw').subscribe();
  const patch = requests.expectOne('/api/sessions/7');
  check(patch.request.method, 'PATCH', 'Notes use existing PATCH');
  check(
    JSON.parse(JSON.stringify(patch.request.body)),
    { preparationNotes: '# Raw' },
    'Save sends only raw notes',
  );
  patch.flush({ session: { id: 7, preparationNotes: '# Raw' } });
  client.getDisplay(7).subscribe();
  requests.expectOne('/api/sessions/7/display').flush({ session: { id: 7 } });
  check(fs.readFileSync(path.join(root, 'src/app/features/player-display/player-display.ts'), 'utf8').includes('gameSessionApi.getDisplay(sessionId)'), true, 'Shared screen uses display-only API');
  requests.verify();
  TestBed.resetTestingModule();

  const sessions = new Map([[1, '# Session one'], [2, '# Session two']]);
  let pendingSave, pendingLoad, failLoad = false;
  const gets = [], saves = [];
  const api = {
    get(id) {
      gets.push(id);
      if (failLoad) return rx.throwError(() => new Error('offline'));
      if (pendingLoad) return pendingLoad;
      return rx.of({ id, campaignId: 1, status: 'draft', preparationNotes: sessions.get(id) });
    },
    updatePreparation(id, value) { saves.push({ id, value }); pendingSave = new rx.Subject(); return pendingSave; },
  };
  TestBed.configureTestingModule({ imports: [SessionPreparation], providers: [{ provide: Api, useValue: api }] });
  let fixture = TestBed.createComponent(SessionPreparation);
  fixture.componentRef.setInput('sessionId', 1);
  fixture.detectChanges();
  let component = fixture.componentInstance;
  check(component.draft(), '# Session one', 'Selected session notes load');
  check(component.dirty(), false, 'Loaded notes are clean');
  const textarea = fixture.nativeElement.querySelector('textarea');
  textarea.value = '# Changed\n\n**Bold**'; textarea.dispatchEvent(new Event('input')); fixture.detectChanges();
  check(component.dirty(), true, 'Textarea input marks dirty');
  check(fixture.nativeElement.textContent.includes('Modifications non enregistrées'), true, 'Dirty indicator rendered');
  check(saves.length, 0, 'Editing does not autosave');
  fixture.nativeElement.querySelector('.preparation-save').click(); fixture.detectChanges();
  check(saves[0], { id: 1, value: '# Changed\n\n**Bold**' }, 'Save targets selected session');
  check(component.canLeave(), false, 'Navigation waits for pending save');
  pendingSave.next({ preparationNotes: saves[0].value }); pendingSave.complete(); fixture.detectChanges();
  check(component.dirty(), false, 'Save clears dirty state');
  check(fixture.nativeElement.querySelector('[role=status]').textContent.trim(), 'Enregistré', 'Saved indicator rendered');

  component.edit('first edit'); component.save(); component.edit('newer edit');
  pendingSave.next({ preparationNotes: 'first edit' }); pendingSave.complete();
  check(component.draft(), 'newer edit', 'Save response preserves newer typing');
  check(component.dirty(), true, 'Newer typing remains dirty');
  component.save(); pendingSave.error(new Error('offline')); fixture.detectChanges();
  check(component.draft(), 'newer edit', 'Failed save preserves draft');
  check(component.error().includes('conservées'), true, 'Failed save explained');

  let confirmations = 0;
  window.confirm = () => { confirmations++; return false; };
  check(component.canLeave(), false, 'Cancel keeps unsaved notes');
  check(confirmations, 1, 'Unsaved navigation prompts');
  window.confirm = () => true;
  check(component.canLeave(), true, 'Explicit discard permits navigation');
  const unload = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(unload);
  check(unload.defaultPrevented, true, 'Browser unload warns on dirty notes');
  fixture.componentRef.setInput('sessionId', 2); fixture.detectChanges();
  check(component.draft(), '# Session two', 'New selected session loads its own notes');
  check(component.dirty(), false, 'Session switch establishes new baseline');

  component.edit('# Heading\n\n**bold** *italic*\n\n1. Ordered\n\n- Unordered\n- [x] Done\n\n> Quote\n\n---\n\n[Safe](https://example.com)\n\n`inline`\n\n```js\nconst x = 1;\n```\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[Bad](javascript:alert(1))');
  component.mode.set('preview'); fixture.detectChanges();
  const preview = fixture.nativeElement.querySelector('.preparation-preview');
  for (const selector of ['h1', 'strong', 'em', 'ol', 'ul', 'blockquote', 'hr', 'a[href="https://example.com"]', 'code', 'pre code']) check(!!preview.querySelector(selector), true, 'Markdown renders ' + selector);
  check(preview.textContent.includes('☑'), true, 'Task lists render inert markers');
  check(preview.querySelector('script,img,[onerror],[onclick]'), null, 'Raw HTML and executable attributes absent');
  check([...preview.querySelectorAll('a')].some(a => /^javascript:/i.test(a.getAttribute('href') ?? '')), false, 'Actual Angular binding sanitizes dangerous URLs');
  component.edit('é'.repeat(50_001)); component.save();
  check(component.tooLarge(), true, 'UTF-8 byte limit enforced in editor');
  component.edit(' \n '); component.save(); pendingSave.next({ preparationNotes: null }); pendingSave.complete();
  check(component.draft(), '', 'Server blank normalization reflected');
  check(component.dirty(), false, 'Normalized blank is clean');
  fixture.destroy();

  failLoad = true;
  fixture = TestBed.createComponent(SessionPreparation); fixture.componentRef.setInput('sessionId', 1); fixture.detectChanges();
  check(fixture.componentInstance.loaded(), false, 'Failed load cannot be edited/saved');
  check(fixture.nativeElement.textContent.includes('Réessayer'), true, 'Load error has retry');
  failLoad = false; fixture.componentInstance.load(); fixture.detectChanges();
  check(fixture.componentInstance.draft(), '# Session one', 'Retry loads correct notes');
  fixture.destroy(); TestBed.resetTestingModule();

  // Real Angular navigation with the actual dashboard constructor and route guard.
  const live = { state: core.signal({ status: 'draft' }), initialize() {}, updateState(value) { this.state.update(s => ({ ...s, ...value })); } };
  const registryToken = modules['@core/services/campaign-configuration-registry.service'].CampaignConfigurationRegistryService;
  const liveToken = modules['@core/services/live-session.service'].LiveSessionService;
  const restToken = modules['@core/services/rest-request-api.service'].RestRequestApiService;

  // This block tests route reuse / dirty-draft navigation only.
  // Do not exercise the dashboard's unrelated campaign/session loading here:
  // those services are already tested elsewhere and their custom VM mocks can
  // produce cross-realm Observable issues with forkJoin.
  ControlDashboard.prototype.loadDashboardContext = function () {
    this.campaign = { id: 'test' };
    this.dashboardLoading.set(false);
  };

  TestBed.configureTestingModule({ providers: [
    router.provideRouter([{ path: 'campaigns/:campaignId/sessions/:sessionId/control', component: ControlDashboard, canDeactivate: [preparationGuard] }]),
    { provide: Api, useValue: api },
    { provide: registryToken, useValue: { getCampaign: () => rx.of({ campaign: { id: 1 }, configuration: { id: 'test' } }) } },
    { provide: liveToken, useValue: live },
    { provide: restToken, useValue: { listPending: () => rx.of([]) } },
  ] });
  // For the router-reuse test, the child component does not need to be rendered:
  // SessionPreparation behavior (including input changes) is tested above.
  // Here we only verify that the reused dashboard consults canLeavePreparation()
  // before accepting new route parameters.
  TestBed.overrideComponent(ControlDashboard, { set: {
    imports: [], styleUrl: undefined, styles: [], templateUrl: undefined,
    template: '',
  } });
  const harness = await RouterTestingHarness.create();
  const route = id => `/campaigns/1/sessions/${id}/control`;
  const dashboard = await harness.navigateByUrl(route(1), ControlDashboard);
  harness.detectChanges();
  check(dashboard.backendSessionId, 1, 'Dashboard initializes from route parameters');

  let draft = 'Keep this draft';
  dashboard.preparation = () => ({
    canLeave: () => window.confirm('discard?'),
    draft: () => draft,
  });

  window.confirm = () => false;
  await harness.navigateByUrl(route(2)); harness.detectChanges();
  check(TestBed.inject(router.Router).url, route(1), 'Actual router cancels session switch with unsaved notes');
  check(draft, 'Keep this draft', 'Cancelled route preserves draft');

  window.confirm = () => true;
  await harness.navigateByUrl(route(2), ControlDashboard); harness.detectChanges();
  check(dashboard.backendSessionId, 2, 'Reused dashboard observes new route parameters');
  const source = fs.readFileSync(path.join(root, feature + 'control-dashboard.html'), 'utf8');
  check(source.indexOf('<app-session-preparation') < source.indexOf('@switch (activeTab())'), true, 'Production template retains notebook outside tab switch');
  check(fs.readFileSync(path.join(root, 'src/app/app.routes.ts'), 'utf8').includes('canDeactivate: [preparationGuard]'), true, 'Production route installs guard');
  TestBed.resetTestingModule(); dom.window.close();
  console.log(`OK: ${checks} session preparation frontend assertions.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
