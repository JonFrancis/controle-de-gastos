import assert from 'node:assert/strict';
import test from 'node:test';
import { allocationChangeLabels } from './installmentAllocationChanges.mjs';
import { removeAllocationRow } from './installmentAllocationRows.mjs';

test('removes the selected participant row while another row remains', () => {
    const rows = [{ participant_id: '1' }, { participant_id: '2' }, { participant_id: '3' }];

    assert.deepEqual(removeAllocationRow(rows, 1), [rows[0], rows[2]]);
});

test('keeps the last participant row so the rateio remains valid', () => {
    const rows = [{ participant_id: '1' }];

    assert.deepEqual(removeAllocationRow(rows, 0), rows);
});

test('describes every changed rateio dimension in the confirmation contract', () => {
    const initialRows = [{ participant_id: '1', category_id: '2', amount: '60.00', percentage: '60.00' }, { participant_id: '3', category_id: '', amount: '40.00', percentage: '40.00' }];
    const currentRows = [{ participant_id: '4', category_id: '5', amount: '70.00', percentage: '55.00' }, { participant_id: '6', category_id: '', amount: '30.00', percentage: '45.00' }];

    assert.deepEqual(allocationChangeLabels('percentage', currentRows, 'amount', initialRows), [
        'modo do Rateio',
        'Participantes do Rateio',
        'valores do Rateio',
        'percentuais do Rateio',
        'Categorias do Rateio',
    ]);
});

test('detects amount and percentage swaps by participant instead of as unordered values', () => {
    const initialRows = [{ participant_id: '1', category_id: '', amount: '60.00', percentage: '60.00' }, { participant_id: '3', category_id: '', amount: '40.00', percentage: '40.00' }];
    const swappedRows = [{ participant_id: '1', category_id: '', amount: '40.00', percentage: '40.00' }, { participant_id: '3', category_id: '', amount: '60.00', percentage: '60.00' }];

    assert.deepEqual(allocationChangeLabels('amount', swappedRows, 'amount', initialRows), ['valores do Rateio', 'percentuais do Rateio']);
});
