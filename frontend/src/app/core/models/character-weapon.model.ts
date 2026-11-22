export interface CharacterWeapon {
  id: number | string;
  name: string;
  equipped: boolean;
  attackBonus: number | null;
  damage: string | null;
  notes: string[];
  complete: boolean;
  ability?: string;
  proficient?: boolean;
}
