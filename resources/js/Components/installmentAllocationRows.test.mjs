import assert from 'node:assert/strict';
import test from 'node:test';
import { allocationChangeLabels } from './installmentAllocationChanges.mjs';
import { removeAllocationRow, updateAllocationParticipant } from './installmentAllocationRows.mjs';
import { allocationRowsForEdit, recalculateAmountRows, serializeAllocationRule } from '../Pages/Installments/editAllocationContract.mjs';

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

test('clears the legacy null marker when Eu changes to another participant', () => {
    const row = { participant_id: '1', participant_id_is_null: true, category_id: '2', amount: '50.00', percentage: '' };

    assert.deepEqual(updateAllocationParticipant(row, '3', '1'), { participant_id: '3', category_id: '2', amount: '50.00', percentage: '' });
    assert.equal(updateAllocationParticipant(row, '1', '1').participant_id_is_null, true);
});

test('Edit contract preserves every Rateio mode and serializes participant data', () => {
    for (const mode of ['equal', 'amount', 'percentage']) {
        const rows = allocationRowsForEdit([{ participantId: null, categoryId: 2, amountCents: 6000, percentageBasisPoints: mode === 'percentage' ? 6000 : null }], '1');

        assert.equal(serializeAllocationRule(mode, rows).allocation_mode, mode);
        assert.deepEqual(serializeAllocationRule(mode, rows).allocations[0], {
            participant_id: '1',
            participant_id_is_null: true,
            category_id: '2',
            amount: '60.00',
            percentage: mode === 'percentage' ? '60.00' : '',
        });
    }
});

test('Edit contract recalculates visible amount rows before serializing a changed total', () => {
    const rows = [
        { participant_id: '2', participant_id_is_null: false, category_id: '4', amount: '60.00', percentage: '' },
        { participant_id: '3', participant_id_is_null: false, category_id: '', amount: '40.00', percentage: '' },
    ];

    const recalculatedRows = recalculateAmountRows(rows, 10000, 12000);

    assert.deepEqual(recalculatedRows, [
        { participant_id: '2', participant_id_is_null: false, category_id: '4', amount: '72.00', percentage: '' },
        { participant_id: '3', participant_id_is_null: false, category_id: '', amount: '48.00', percentage: '' },
    ]);
    assert.deepEqual(serializeAllocationRule('amount', recalculatedRows), {
        allocation_mode: 'amount',
        allocations: recalculatedRows,
    });

    const invalidRows = rows.map((row, index) => ({ ...row, amount: index === 0 ? '61.00' : '40.00' }));
    assert.deepEqual(recalculateAmountRows(invalidRows, 10000, 12000), invalidRows);
});
