#!/usr/bin/env python3
"""Generate reviewed-import candidates from D&D 5e SRD 2014 (not AideDD).
Run locally with Python 3 stdlib. Does not modify the database.
"""
import json
import pathlib
import urllib.request
import urllib.error
import unicodedata
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[1]
BASE = 'https://www.dnd5eapi.co/api/2014/monsters'
MAPPING = '''
aigle:eagle
aigle-geant:giant-eagle
araignee:spider
araignee-geante:giant-spider
araignee-loup-geante:giant-wolf-spider
babouin:baboon
bec-de-hache:axe-beak
belette:weasel
belette-geante:giant-weasel
blaireau:badger
blaireau-geant:giant-badger
cerf:deer
chacal:jackal
chameau:camel
chat:cat
chauve-souris:bat
chauve-souris-geante:giant-bat
cheval-de-guerre:warhorse
cheval-de-selle:riding-horse
cheval-de-trait:draft-horse
chouette:owl
chouette-geante:giant-owl
corbeau:raven
crabe:crab
crabe-geant:giant-crab
crapaud-geant:giant-toad
crocodile:crocodile
crocodile-geant:giant-crocodile
elan:elk
elan-geant:giant-elk
elephant:elephant
epaulard:killer-whale
faucon:hawk
faucon-de-sang:blood-hawk
grand-singe:ape
grenouille:frog
grenouille-geante:giant-frog
guepe-geante:giant-wasp
hippocampe:sea-horse
hippocampe-geant:giant-sea-horse
hyene:hyena
hyene-geante:giant-hyena
lezard:lizard
lezard-geant:giant-lizard
lion:lion
loup:wolf
loup-sanguinaire:dire-wolf
mammouth:mammoth
mille-pattes-geant:giant-centipede
molosse:mastiff
mule:mule
ours-brun:brown-bear
ours-noir:black-bear
ours-polaire:polar-bear
panthere:panther
pieuvre:octopus
pieuvre-geante:giant-octopus
piranha:quipper
poney:pony
rat:rat
rat-geant:giant-rat
requin-chasseur:hunter-shark
requin-de-recif:reef-shark
requin-geant:giant-shark
rhinoceros:rhinoceros
sanglier:boar
sanglier-geant:giant-boar
scarabee-de-feu-geant:giant-fire-beetle
scorpion:scorpion
scorpion-geant:giant-scorpion
serpent-constricteur:constrictor-snake
serpent-constricteur-geant:giant-constrictor-snake
serpent-venimeux:poisonous-snake
serpent-venimeux-geant:giant-poisonous-snake
serpent-volant:flying-snake
singe-geant:giant-ape
tigre:tiger
tigre-a-dents-de-sabre:saber-toothed-tiger
vautour:vulture
vautour-geant:giant-vulture
'''
MAP = dict(line.split(':',1) for line in MAPPING.strip().splitlines())

def fetch(url):
    req=urllib.request.Request(url,headers={'User-Agent':'JdRCompanion/1.0 (SRD import utility)','Accept':'application/json'})
    with urllib.request.urlopen(req,timeout=20) as res:
        return json.load(res)

def speed_to_meters(feet):
    return round(feet*0.3, 2)  # D&D 5e metric convention: 5 ft = 1.5 m

def make_entry(slug, data):
    if data.get('type','').lower()!='beast':
        raise ValueError('Type non-bête: '+str(data.get('type')))
    armor=data['armor_class']
    if isinstance(armor,list): armor=armor[0]['value']
    speeds={}
    for key,field in [('walk','walk'),('fly','fly'),('swim','swim'),('climb','climb'),('burrow','burrow')]:
        raw=data.get('speed',{}).get(field)
        if isinstance(raw,str) and raw.endswith(' ft.'):
            speeds[key]=speed_to_meters(int(raw[:-4]))
        elif isinstance(raw,str) and raw.endswith(' ft'):
            speeds[key]=speed_to_meters(int(raw[:-3]))
    actions=[]
    for action in data.get('actions',[]):
        actions.append({'name':action.get('name',''), 'description':action.get('desc',''),
                        'attackBonus':action.get('attack_bonus'), 'damage':action.get('damage',[])})
    block={'armorClass':int(armor),'hitPoints':int(data['hit_points']),
           'hitDice':data.get('hit_dice'), 'size':data.get('size'),
           'abilities':{key:int(data[field]) for key,field in [('str','strength'),('dex','dexterity'),('con','constitution'),('int','intelligence'),('wis','wisdom'),('cha','charisma')]},
           'speed':speeds, 'actions':actions, 'traits':data.get('special_abilities',[]),
           'proficiencies':data.get('proficiencies',[]), 'senses':data.get('senses',{}),
           'damageVulnerabilities':data.get('damage_vulnerabilities',[]),
           'damageResistances':data.get('damage_resistances',[]),
           'damageImmunities':data.get('damage_immunities',[]),
           'conditionImmunities':data.get('condition_immunities',[]),
           'source':{'rules':'2014','license':'SRD 5.1','url':BASE+'/'+data['index']}}
    return {'slug':slug,'name':slug.replace('-',' ').capitalize(),
            'challengeRating':str(data['challenge_rating']).replace('0.125','1/8').replace('0.25','1/4').replace('0.5','1/2'),
            'statBlock':block}

def main():
    xml_path=ROOT/'data'/'monster-list.xml'
    if not xml_path.exists():
        raise SystemExit('Copier monster-list.xml dans backend/data/monster-list.xml')
    slugs=[node.text for node in ET.parse(xml_path).getroot().findall('monster')]
    results=[]; report=[]
    for slug in slugs:
        idx=MAP.get(slug)
        if not idx:
            report.append({'slug':slug,'status':'not_mapped_or_not_in_srd'})
            continue
        try:
            data=fetch(BASE+'/'+idx)
            entry=make_entry(slug,data)
            results.append(entry)
            report.append({'slug':slug,'status':'imported','source':idx})
        except (urllib.error.URLError,ValueError,KeyError,TypeError) as exc:
            report.append({'slug':slug,'status':'error','detail':str(exc)})
    out=ROOT/'data'/'beasts-2014-stats.json'
    out.write_text(json.dumps(results,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    (ROOT/'data'/'beasts-2014-import-report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(f'{len(results)} fiches SRD exportées / {len(slugs)} références ; {len(slugs)-len(results)} à compléter.')
    print('Vérifier le rapport et les traductions avant --apply : '+str(out))

if __name__=='__main__': main()
