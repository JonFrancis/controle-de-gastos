export function removeAllocationRow(rows, index) {
    return rows.length > 1 ? rows.filter((_, rowIndex) => rowIndex !== index) : rows;
}

export function updateAllocationParticipant(row, participantId, euId) {
    const updated = { ...row, participant_id: participantId };

    if (row.participant_id_is_null) {
        if (participantId === euId) {
            updated.participant_id_is_null = true;
        } else {
            delete updated.participant_id_is_null;
        }
    }

    return updated;
}
