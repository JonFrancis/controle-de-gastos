import assert from 'node:assert/strict';
import test from 'node:test';
import { removeAllocationRow } from './installmentAllocationRows.mjs';

test('removes the selected participant row while another row remains', () => {
    const rows = [{ participant_id: '1' }, { participant_id: '2' }, { participant_id: '3' }];

    assert.deepEqual(removeAllocationRow(rows, 1), [rows[0], rows[2]]);
});

test('keeps the last participant row so the rateio remains valid', () => {
    const rows = [{ participant_id: '1' }];

    assert.deepEqual(removeAllocationRow(rows, 0), rows);
});
