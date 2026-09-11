// Focused in-memory checks of the actual portal RxJS pipeline and BroadcastChannel opt-out.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const ts = require('typescript');
const rx = require('rxjs');
const root = path.join(__dirname, '..');
const signal = value => Object.assign(() => value, { set: next => value = next, update: fn => value = fn(value), asReadonly() { return this; } });
let broadcastCount = 0;
function load(file, name) {
  const exports = {};
  vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, experimentalDecorators: true },
  }).outputText, {
    exports, console,
    BroadcastChannel: class { constructor() { ++broadcastCount; } close() {} },
    require: module => module === '@angular/core' ? { Component: () => c => c, Injectable: () => c => c, signal } :
      module === '@angular/core/rxjs-interop' ? { takeUntilDestroyed: () => source => source } :
      module === 'rxjs' ? rx : module.endsWith('character-api.mapper') ? { characterProfileToCharacter: (_p, s) => s } : {},
  });
  return exports[name];
}
const wait = () => new Promise(resolve => setTimeout(resolve, 460));
(async () => {
  let checks = 0;
  const check = (actual, expected, label) => { assert.deepEqual(actual, expected, label); ++checks; };
  const Portal = load('src/app/features/player-portal/player-portal.ts', 'PlayerPortal');
  const portal = Object.create(Portal.prototype);
  let revision = 1;
  let reloads = 0;
  const requests = [];
  Object.assign(portal, {
    accessToken: 'test', loadGeneration: 0, serverRevision: 3, remoteSynchronization: new rx.Subject(),
    characterStateService: { revision: () => revision, applyServerState: () => {} },
    characterSessionStateApi: { updateByAccessToken(token, state, expected) {
      const response = new rx.Subject(); requests.push({ token, state, expected, response }); return response;
    } },
    character: signal(null), features: signal([]), sessionStatus: signal('live'), levelUpAllowed: signal(false),
    saveStatus: signal('idle'), saveError: signal(null),
    loadCharacter() { ++reloads; ++this.loadGeneration; },
  });
  portal.initializeRemoteSynchronization();
  portal.remoteSynchronization.next({ state: { value: 1 }, revision, generation: 0 });
  await wait();
  check(requests[0].expected, 3, 'First request uses server revision');
  revision = 2;
  portal.remoteSynchronization.next({ state: { value: 2 }, revision, generation: 0 });
  requests[0].response.next({ revision: 4 }); requests[0].response.complete();
  await wait();
  check(requests[1].expected, 4, 'Queued local edit uses acknowledged revision even when earlier response is locally stale');
  revision = 3;
  portal.remoteSynchronization.next({ state: { value: 3 }, revision, generation: 0 });
  requests[1].response.error({ status: 409 });
  await wait();
  check(reloads, 1, 'Conflict reloads current server state');
  check(requests.length, 2, 'Stale queued writes are discarded, never retried');
  check(portal.saveStatus(), 'error', 'Conflict is surfaced');
  check(portal.saveError().includes('Modification concurrente'), true, 'Conflict explanation is visible');
  portal.remoteSynchronization.complete();

  const State = load('src/app/core/services/character-state.service.ts', 'CharacterStateService');
  const state = new State();
  state.cloneCharacter = value => value;
  state.initialize('campaign', { id: 'test' }, false, false);
  check(broadcastCount, 0, 'Server-controlled portal ignores unversioned local broadcasts');
  state.initialize('campaign', { id: 'test' }, false);
  check(broadcastCount, 1, 'Other local callers retain BroadcastChannel behavior');
  console.log(`OK: ${checks} player concurrency frontend assertions.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
