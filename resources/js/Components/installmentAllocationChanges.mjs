export function allocationChangeLabels(currentMode, currentRows, initialMode, initialRows) {
    const labels = [];
    const currentParticipants = currentRows.map((row) => row.participant_id).sort();
    const initialParticipants = initialRows.map((row) => row.participant_id).sort();
    const currentCategories = currentRows.map((row) => `${row.participant_id}:${row.category_id}`).sort();
    const initialCategories = initialRows.map((row) => `${row.participant_id}:${row.category_id}`).sort();
    const currentAmounts = currentRows.map((row) => row.amount).sort();
    const initialAmounts = initialRows.map((row) => row.amount).sort();
    const currentPercentages = currentRows.map((row) => row.percentage).sort();
    const initialPercentages = initialRows.map((row) => row.percentage).sort();

    if (currentMode !== initialMode) labels.push('modo do Rateio');
    if (JSON.stringify(currentParticipants) !== JSON.stringify(initialParticipants)) labels.push('Participantes do Rateio');
    if (JSON.stringify(currentAmounts) !== JSON.stringify(initialAmounts)) labels.push('valores do Rateio');
    if (JSON.stringify(currentPercentages) !== JSON.stringify(initialPercentages)) labels.push('percentuais do Rateio');
    if (JSON.stringify(currentCategories) !== JSON.stringify(initialCategories)) labels.push('Categorias do Rateio');

    return labels;
}
