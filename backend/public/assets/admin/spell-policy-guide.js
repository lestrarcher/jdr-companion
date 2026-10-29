/* Progressive enhancement: the authoritative JSON form and CSRF remain unchanged. */
(() => {
  'use strict';
  const textarea = document.querySelector('[name="document"]');
  const host = document.getElementById('spell-policy-guide');
  if (!host || !textarea) return;
  const options = JSON.parse(host.dataset.options);
  const enums = {
    sourceType: ['class', 'subclass'], ability: ['strength','dexterity','constitution','intelligence','wisdom','charisma'],
    learningStyle: ['known','prepared','spellbook'], quota: ['known','cantrips','spellbook','free'],
    result: ['known','spellbook','cast'],
  };
  const labels = {
    sourceType:'Type de source', sourceId:'Source officielle', policy:'Politique', key:'Identité stable', version:'Version',
    ability:'Caractéristique', learningStyle:'Fonctionnement', levels:'Quotas par niveau de classe',
    maxSpellLevel:'Niveau maximal du sort', cantrips:'Sorts mineurs', known:'Sorts connus', bookAdditions:'Ajouts au grimoire',
    replacements:'Remplacements', preparationBase:'Base de préparation', provenance:'Provenance', limitations:'Limites',
    rules:'Règles de choix', parameters:'Critères et budgets', listClassId:'Liste de sélection', replacementListClassId:'Liste de remplacement',
    castingClassId:'Classe de lancement', requiredSpellId:'ID du sort imposé', allClassLists:'Toutes les listes',
    optionalReplacement:'Option à confirmer explicitement', minimumSpellLevel:'Niveau minimum du sort',
    replacementMinimumSpellLevel:'Minimum au remplacement', replacementBudget:'Budget partagé de remplacement',
    opportunities:'Opportunités par niveau', maxSpellLevels:'Niveau maximal par niveau de classe', schools:'Écoles autorisées',
    replacementSchools:'Écoles de remplacement', grantId:'Attribution officielle', classIds:'Listes autorisées',
    budgetKey:'Budget partagé', basePolicyId:'Version de base figée', quota:'Budget consommé', result:'Résultat',
  };
  let draft;
  let emitted = '';
  const fields = document.createElement('div');
  const status = document.createElement('p'); status.setAttribute('role','status');
  const open = document.createElement('button'); open.type='button'; open.textContent='Ouvrir le formulaire guidé';
  host.append(open, status, fields);
  const emit = () => { emitted=JSON.stringify(draft,null,2); textarea.value=emitted; };
  textarea.addEventListener('input', () => { if (textarea.value!==emitted) { fields.replaceChildren(); status.textContent='Rouvrez le guide pour charger le document modifié.'; } });
  const element = (tag, text) => { const e=document.createElement(tag); if (text!==undefined) e.textContent=text; return e; };
  const choices = key => {
    if (key==='sourceId') return options[draft.sourceType==='subclass'?'subclasses':'classes'];
    if (['listClassId','replacementListClassId','castingClassId','classIds'].includes(key)) return options.classes;
    if (key==='grantId') return options.grants;
    if (key==='requiredSpellId') return options.spells;
    if (key==='basePolicyId') return options.policies.map(p=>({id:p.id,name:`${p.sourceName} — version ${p.version}`}));
    return null;
  };
  const render = (parent, key, value, set, levelLabel='') => {
    const label=labels[key] || key;
    if (Array.isArray(value) && ['schools','replacementSchools','classIds'].includes(key)) {
      const box=element('fieldset'); box.append(element('legend',label));
      const refs=key==='classIds'?choices(key):['abjuration','conjuration','divination','enchantment','evocation','illusion','necromancy','transmutation'].map(s=>({id:s,name:s}));
      refs.forEach(r=> {
        const l=element('label'); const input=element('input'); input.type='checkbox'; input.checked=value.includes(r.id);
        input.addEventListener('change',()=> { set(input.checked?[...value,r.id]:value.filter(x=>x!==r.id)); emit(); draw(); });
        l.append(input,document.createTextNode(r.name)); box.append(l);
      }); parent.append(box); return;
    }
    if (value && typeof value==='object') {
      const box=element('fieldset'); box.append(element('legend',levelLabel || label));
      Object.entries(value).forEach(([k,v])=>{
        render(box,k,v,newValue=>value[k]=newValue,Array.isArray(value)?`Niveau / entrée ${Number(k)+1}`:'');
        if(key==='rules' && Array.isArray(value)) {
          const remove=element('button',`Retirer le choix ${Number(k)+1} du brouillon`); remove.type='button';
          remove.addEventListener('click',()=>{value.splice(Number(k),1);emit();draw();});box.append(remove);
        }
      });
      parent.append(box); return;
    }
    const l=element('label',levelLabel || label); const refs=choices(key);
    let input;
    if (refs || enums[key]) {
      input=element('select');
      if (refs) { const empty=element('option','Non renseigné'); empty.value=''; input.append(empty); }
      (refs || enums[key].map(s=>({id:s,name:s}))).forEach(r=> { const option=element('option',r.name); option.value=String(r.id); input.append(option); });
      input.value=value===null?'':String(value);
      input.addEventListener('change',()=> { set(refs?(input.value===''?null:Number(input.value)):input.value); if(key==='sourceType') { draft.sourceId=null; draw(); } emit(); });
    } else {
      input=element(typeof value==='string' && ['provenance','reason'].includes(key)?'textarea':'input');
      const numeric=typeof value==='number' || value===null;
      if(typeof value==='boolean') { input.type='checkbox'; input.checked=value; }
      else { if(input.tagName==='INPUT') input.type=numeric?'number':'text'; input.value=value??''; }
      input.addEventListener('input',()=> { set(typeof value==='boolean'?input.checked:numeric?(input.value===''?null:Number(input.value)):input.value); emit(); });
    }
    l.append(input); parent.append(l);
  };
  const draw = () => {
    fields.replaceChildren();
    Object.entries(draft).forEach(([key,value])=>render(fields,key,value,v=>draft[key]=v));
    if (draft.policy) {
      const add=element('button','Ajouter une règle de choix'); add.type='button';
      add.addEventListener('click',()=> {
        draft.rules??=[]; draft.rules.push({key:'nouveau-choix',listClassId:null,replacementListClassId:null,castingClassId:null,requiredSpellId:null,parameters:{
          opportunities:Array(20).fill(0),maxSpellLevels:Array(20).fill(0),minimumSpellLevel:0,replacementMinimumSpellLevel:0,
          schools:[],replacementSchools:[],quota:'cantrips',result:'known',replacementBudget:'mineurs',replacements:Array(20).fill(0),allClassLists:false,optionalReplacement:false,
        }}); emit(); draw();
      }); fields.append(add);
    }
  };
  open.addEventListener('click',()=> {
    try { draft=JSON.parse(textarea.value); if(!draft || Array.isArray(draft) || typeof draft!=='object') throw Error(); draw(); status.textContent='Les champs modifient le document avancé. Enregistrez ensuite la nouvelle version.'; }
    catch { status.textContent='Document JSON invalide : corrigez-le avant de rouvrir le guide.'; fields.replaceChildren(); }
  });
  const fresh=element('button','Nouveau document du type sélectionné'); fresh.type='button';
  fresh.addEventListener('click',()=> {
    if(!window.confirm('Remplacer le brouillon affiché ?')) return;
    draft=document.querySelector('[name="kind"]').value==='policy'?structuredClone(options.example):{grantId:null,version:1,budgetKey:'substitutions',schools:[],opportunities:Array(20).fill(0),classIds:[],provenance:''};
    emit(); draw();
  }); host.append(fresh);
})();
