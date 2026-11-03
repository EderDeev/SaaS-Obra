// Set to false to restore the previous neutral appearance without undoing other RDO changes.
export const PARTY_COLORS_ENABLED = true;
export const rdoParty = party => PARTY_COLORS_ENABLED && ['construtora', 'gerenciadora', 'cliente'].includes(party) ? party : undefined;
