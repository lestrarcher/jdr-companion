// In-memory component checks. No browser, network or application data writes.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const ts = require('typescript');
const { Subject } = require('rxjs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const signal = value => Object.assign(() => value, { set: next => value = next, update: fn => value = fn(value) });
const input = () => () => 1;
input.required = input;
function component(file, name, api) {
  const exports = {};
  const angular = { Component: () => target => target, signal, computed: fn => fn, inject: () => api, input, output: () => ({ emit() {} }) };
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  vm.runInNewContext(ts.transpileModule(source, { compilerOptions: {
    target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, experimentalDecorators: true,
  } }).outputText, { exports, confirm: () => true, require: name => name === '@angular/core' ? angular : name.startsWith('rxjs') ? require(name) : {} });
  return new exports[name]();
}
const definition = {
  id: 4, slug: 'test', name: 'Reference name', accentColor: '#123456', minimumValue: 0, maximumValue: null,
  description: null, gainLabel: 'Gain heading', spendLabel: 'Loss heading', custom: true, bulkAdjustmentEnabled: false,
  stages: [{ id: 2, label: 'Open', minimumValue: 12, maximumValue: null, iconUrl: null, description: 'Secret\nEffect', displayOrder: 1 },
    { id: 1, label: 'First', minimumValue: 0, maximumValue: 9, iconUrl: null, description: null, displayOrder: 0 }],
  adjustmentRules: [{ id: 2, direction: 'loss', triggerType: null, description: 'Loss', adjustmentLabel: '-1 to -2', displayOrder: 2 },
    { id: 1, direction: 'gain', triggerType: 'Automatic', description: 'Gain', adjustmentLabel: '+1 if failed', displayOrder: 0 }],
};
const other = { ...definition, id: 5, slug: 'other', name: 'Other', stages: [], adjustmentRules: [] };
const requests = [];
const gm = component('src/app/features/control-dashboard/components/session-characters/session-characters.ts', 'SessionCharacters', {
  getCampaignProgressions(campaignId) { assert.equal(campaignId, 1); const result = new Subject(); requests.push(result); return result; },
});
gm.refreshCharacters = () => {};
gm.campaignCharacters.set([{ id: 1 }]);
const session = { character: { id: 1, progressions: [
  { definitionId: 4, slug: 'test', name: 'Stale profile name', stages: [] },
  { definitionId: 5, slug: 'other', name: 'Other', stages: [] },
] }, state: { progressions: [{ id: 'test', currentValue: 0 }, { id: 'other', currentValue: 6 }] } };
gm.sessionStates.set([session]);
gm.openProgression({ character: { id: 1 } });
assert.equal(gm.progressionReferenceLoading(), true);
requests[0].next({ progressions: [definition, other] }); requests[0].complete();
assert.equal(gm.selectedProgression().name, 'Reference name');
assert.equal(gm.selectedProgression().current, 0);
assert.equal(gm.selectedProgression().stages[0].id, 1);
assert.equal(gm.selectedProgression().stage.id, 1);
assert.equal(gm.selectedProgression().accentColor, '#123456');
assert.equal(gm.selectedProgression().adjustmentRules[0].direction, 'gain');
assert.equal(gm.selectedProgression().adjustmentRules.some(rule => rule.direction === 'loss'), true);
for (const [value, expected] of [[9, 1], [10, undefined], [11, undefined], [12, 2], [150, 2]]) {
  session.state.progressions[0].currentValue = value;
  assert.equal(gm.selectedProgression().stage?.id, expected);
}
gm.toggleStage(2); assert.equal(gm.expandedStageIds().has(2), true);
gm.toggleStage(2); assert.equal(gm.expandedStageIds().size, 0);
gm.toggleStage(2); gm.progressionTab.set('actions');
gm.selectProgression('other');
assert.equal(gm.progressionTab(), 'phases'); assert.equal(gm.expandedStageIds().size, 0);
assert.equal(gm.selectedProgression().current, 6); assert.equal(gm.selectedProgression().adjustmentRules.some(rule => rule.direction === 'gain'), false);
gm.closeProgression(); gm.openProgression({ character: { id: 1 } });
requests[1].error(new Error('offline'));
assert.equal(gm.progressionReferenceLoading(), false); assert.ok(gm.progressionReferenceError());
gm.loadProgressionReferences(); gm.closeProgression();
requests[2].next({ progressions: [definition] }); requests[2].complete();
assert.equal(gm.progressionReferences().length, 0);

console.log('PASS: contextual reference loading/errors, stale responses, values/boundaries/gaps, stage sorting, rule grouping, disclosure/reset.');
