export function removeAllocationRow(rows, index) {
    return rows.length > 1 ? rows.filter((_, rowIndex) => rowIndex !== index) : rows;
}
