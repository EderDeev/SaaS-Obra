export const rncDisciplinaLabel = (rnc) => {
    if (rnc?.disciplina?.sigla) {
        return `${rnc.disciplina.sigla} - ${rnc.disciplina.nome}`;
    }

    return rnc?.disciplina?.nome || rnc?.natureza || 'Sem disciplina';
};
