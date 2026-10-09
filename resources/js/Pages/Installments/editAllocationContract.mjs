export function allocationRowsForEdit(allocations, euId) {
    return allocations.map((allocation) => ({
        participant_id: allocation.participantId === null ? euId : allocation.participantId.toString(),
        participant_id_is_null: allocation.participantId === null,
        category_id: allocation.categoryId?.toString() ?? '',
        amount: (allocation.amountCents / 100).toFixed(2),
        percentage: allocation.percentageBasisPoints === null ? '' : (allocation.percentageBasisPoints / 100).toFixed(2),
    }));
}

export function serializeAllocationRule(mode, rows) {
    return { allocation_mode: mode, allocations: rows.map((row) => ({ ...row })) };
}

export function recalculateAmountRows(rows, previousTotalCents, nextTotalCents) {
    if (rows.length === 0 || previousTotalCents === nextTotalCents) return rows;

    const amounts = rows.map((row) => parseMoney(row.amount));
    const total = amounts.reduce((sum, amount) => sum + amount, 0);

    if (total !== previousTotalCents || total === 0) return rows;

    const scaledAmounts = apportion(nextTotalCents, amounts);

    return rows.map((row, index) => ({ ...row, amount: (scaledAmounts[index] / 100).toFixed(2) }));
}

function parseMoney(value) {
    return Math.round(Number(String(value ?? '').replace(',', '.')) * 100) || 0;
}

function apportion(totalCents, weights) {
    const totalWeight = weights.reduce((sum, weight) => sum + weight, 0);
    const amounts = weights.map((weight) => Math.floor(totalCents * weight / totalWeight));
    const remainders = weights.map((weight) => (totalCents * weight) % totalWeight);
    const remaining = totalCents - amounts.reduce((sum, amount) => sum + amount, 0);

    [...remainders.keys()]
        .sort((left, right) => remainders[right] - remainders[left])
        .slice(0, remaining)
        .forEach((index) => { amounts[index]++; });

    return amounts;
}
