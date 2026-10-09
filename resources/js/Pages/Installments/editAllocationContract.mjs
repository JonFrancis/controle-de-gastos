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
