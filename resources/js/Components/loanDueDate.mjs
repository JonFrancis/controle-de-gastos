export function suggestLoanDueDate(referenceDate, dueDay) {
    if (!referenceDate || !dueDay) return '';

    const reference = new Date(`${referenceDate.slice(0, 7)}-01T12:00:00Z`);
    const daysInMonth = new Date(Date.UTC(reference.getUTCFullYear(), reference.getUTCMonth() + 1, 0)).getUTCDate();
    const day = Math.min(Number(dueDay), daysInMonth);

    return `${reference.getUTCFullYear()}-${String(reference.getUTCMonth() + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}
