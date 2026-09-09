<?php

/*
|--------------------------------------------------------------------------
| Legacy Relation Map (FK candidates → enforced in stores_laravel)
|--------------------------------------------------------------------------
| child_table.child_column -> parent_table.parent_column
|
| Each entry: nullable (always true — legacy sentinels exist), on-delete
| behaviour, and whether the pair is constraint-eligible. Ineligible pairs
| (text-typed, polymorphic) are documented and indexed only.
*/

return [

    'eligible' => [
        // Location hierarchy
        ['child' => 'bins', 'column' => 'whid', 'parent' => 'warehouses', 'parentKey' => 'whid'],
        ['child' => 'sub_bins', 'column' => 'binid', 'parent' => 'bins', 'parentKey' => 'binid'],
        ['child' => 'sub_bins', 'column' => 'whid', 'parent' => 'warehouses', 'parentKey' => 'whid'],

        // Item master
        ['child' => 'items', 'column' => 'classification_id', 'parent' => 'classifications', 'parentKey' => 'classification_id'],

        // e-Indent pipeline
        ['child' => 'e_indent_items', 'column' => 'id_in', 'parent' => 'e_indents', 'parentKey' => 'tid'],

        // Issues
        ['child' => 'issue_items', 'column' => 'issue_id', 'parent' => 'issues', 'parentKey' => 'issue_id'],
        ['child' => 'issue_slocs', 'column' => 'issue_tr_id', 'parent' => 'issues', 'parentKey' => 'issue_id', 'when' => "issue_type = 'eindent'"],

        // Arrivals
        ['child' => 'arrival_items', 'column' => 'arrival_id', 'parent' => 'arrivals', 'parentKey' => 'arrival_id'],
        ['child' => 'arrival_slocs', 'column' => 'arr_tr_id', 'parent' => 'arrivals', 'parentKey' => 'arrival_id', 'when' => "arr_type = 'vendor'"],

        // Captive
        ['child' => 'captive_items', 'column' => 'id_in', 'parent' => 'captives', 'parentKey' => 'tid'],
        ['child' => 'external_captives', 'column' => 'tid', 'parent' => 'captives', 'parentKey' => 'tid'],

        // Discard family
        ['child' => 'discard_slocs', 'column' => 'discard_trid', 'parent' => 'discards', 'parentKey' => 'tid'],

        // Excess
        ['child' => 'excess_items', 'column' => 'esid', 'parent' => 'excesses', 'parentKey' => 'tid'],

        // Gate movements
        ['child' => 'dtog_items', 'column' => 'did', 'parent' => 'dtogs', 'parentKey' => 'did'],
        ['child' => 'gtod_items', 'column' => 'gid', 'parent' => 'gtods', 'parentKey' => 'gid'],

        // Item transfers
        ['child' => 'item_transfer_items', 'column' => 'iitr_id', 'parent' => 'item_transfers', 'parentKey' => 'iitr_id'],

        // Sloc updation
        ['child' => 'sloc_items', 'column' => 'slocid', 'parent' => 'slocs', 'parentKey' => 'slid'],

        // Cycle counts
        ['child' => 'cycle_count_updates', 'column' => 'ci_id', 'parent' => 'cycle_counts', 'parentKey' => 'ci_id'],
    ],

    /*
    | Constraint-ineligible: polymorphic discriminators, text-typed FK columns
    | and partial-coverage links. Kept as plain indexed columns.
    */
    'ineligible' => [
        ['table' => 'issue_slocs', 'column' => 'issue_id', 'note' => '-> issue_items.issuesub_id for eindent rows; partial elsewhere.'],
        ['table' => 'arrival_slocs', 'column' => 'arr_id', 'note' => '-> arrival_items.arrsub_id for vendor rows; partial elsewhere.'],
        ['table' => 'captive_slocs', 'column' => 'issue_trid', 'note' => 'Partial coverage; type-discriminated.'],
        ['table' => 'captive_slocs', 'column' => 'isue_id', 'note' => 'varchar subbin/isue_id in legacy — text-typed.'],
        ['table' => 'cycle_counts', 'column' => 'classification_id', 'note' => 'TEXT-typed in legacy.'],
        ['table' => 'cycle_counts', 'column' => 'items_id', 'note' => 'TEXT-typed in legacy.'],
        ['table' => 'gate_passes', 'column' => 'trid', 'note' => 'Polymorphic varchar across transaction types.'],
        ['table' => 'stock_ledger_goods', 'column' => 'stlg_trid', 'note' => 'Polymorphic across stlg_trtype/stlg_trsubtype.'],
        ['table' => 'stock_ledger_damages', 'column' => 'stld_trid', 'note' => 'Polymorphic.'],
        ['table' => 'party_ledgers', 'column' => 'pldg_trid', 'note' => 'Polymorphic.'],
        ['table' => 'issues', 'column' => 'party_id', 'note' => '0 = no-party sentinel (42k+ rows); FK would reject. Filtered in code.'],
        ['table' => 'arrivals', 'column' => 'party_id', 'note' => 'Same sentinel pattern.'],
        ['table' => 'captives', 'column' => 'party_id', 'note' => 'Same sentinel pattern.'],
    ],
];
