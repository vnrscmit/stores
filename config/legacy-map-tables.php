<?php

/*
|--------------------------------------------------------------------------
| Legacy Table Map (storesd → stores_laravel)
|--------------------------------------------------------------------------
| Authoritative mapping for the migration pipeline. Legacy PRIMARY keys and
| column names are preserved VERBATIM; only table names are modernized.
|
|   new_name      Final table name in the Laravel database.
|   primary       Primary key columns (default: taken from the schema audit).
|   consolidate   Auth table merged into `users` during legacy:migrate-data.
|   ignore        Table intentionally NOT recreated (dead legacy structure).
|
| `item` is an abandoned InnoDB copy of the item master (no PK, 8 columns,
| near-empty) — excluded from the pipeline and documented only.
*/

return [

    // ---- Auth (consolidated into users) ------------------------------------
    'tbl_user' => [
        'new_name' => null,
        'consolidate' => 'users',
        'role' => 'admin-fallback',
        'note' => 'Master auth table; role column selects admin/operator/eindent/viewer.',
    ],
    'tbl_opr' => [
        'new_name' => null,
        'consolidate' => 'users',
        'role' => 'operator',
        'note' => 'Operator logins (name/login/pass/status/email/code).',
    ],
    'tbl_roles' => [
        'new_name' => null,
        'consolidate' => 'users',
        'role' => 'eindent',
        'note' => 'e-Indent raiser logins; id is referenced by tbl_ieindent.id.',
    ],
    'tbl_viewer' => [
        'new_name' => null,
        'consolidate' => 'users',
        'role' => 'viewer',
        'note' => 'Report viewer logins.',
    ],

    // ---- Dead structure -----------------------------------------------------
    'item' => [
        'new_name' => null,
        'ignore' => true,
        'note' => 'Abandoned InnoDB duplicate of the item master; no PK, near-empty. Excluded.',
    ],

    // ---- Company / fiscal year ---------------------------------------------
    'tbl_parameters' => ['new_name' => 'company_settings'],
    'tblyears' => ['new_name' => 'financial_years'],

    // ---- Masters ------------------------------------------------------------
    'tbl_classification' => ['new_name' => 'classifications'],
    'tbl_stores' => ['new_name' => 'items'],
    'tbl_partymaser' => ['new_name' => 'parties', 'note' => 'state/country are TEXT in legacy — no FK possible.'],
    'tbl_state' => ['new_name' => 'states'],
    'tbl_country' => ['new_name' => 'countries'],
    'tbl_warehouse' => [
        'new_name' => 'warehouses',
        'primary' => ['whid'],
        'note' => 'Legacy composite PK (whid, perticulars) collapsed to whid — required for FK targets.',
    ],
    'tbl_bin' => ['new_name' => 'bins'],
    'tbl_subbin' => ['new_name' => 'sub_bins', 'note' => 'sname is INT in legacy (kept verbatim).'],
    'tbl_report' => ['new_name' => 'report_definitions'],
    'tbl_order' => ['new_name' => 'reorder_levels'],

    // ---- e-Indent pipeline --------------------------------------------------
    'tbl_ieindent' => ['new_name' => 'e_indents'],
    'tbl_ieindent_sub' => ['new_name' => 'e_indent_items', 'note' => 'id_in -> e_indents.tid (the PK); tid column unused.'],
    'tbl_eindents' => ['new_name' => 'eindent_registers'],

    // ---- Issues --------------------------------------------------------------
    'tblissue' => ['new_name' => 'issues', 'note' => 'party_id=0 sentinel = no party (keep, filter >0).'],
    'tblissue_sub' => ['new_name' => 'issue_items'],
    'tblissue_sloc' => ['new_name' => 'issue_slocs', 'polymorphic' => true, 'note' => 'issue_tr_id varies by issue_type; issue_id -> issue_items.issuesub_id.'],

    // ---- Arrivals ------------------------------------------------------------
    'tblarrival' => ['new_name' => 'arrivals'],
    'tblarrival_sub' => ['new_name' => 'arrival_items'],
    'tblarr_sloc' => ['new_name' => 'arrival_slocs', 'polymorphic' => true, 'note' => 'arr_tr_id varies by arr_type; arr_id -> arrival_items.arrsub_id.'],

    // ---- Captive consumption ---------------------------------------------------
    'tbl_captive' => ['new_name' => 'captives'],
    'tbl_captivesub' => ['new_name' => 'captive_items', 'note' => 'id_in -> captives.tid.'],
    'tbl_captive_sloc' => ['new_name' => 'captive_slocs', 'polymorphic' => true, 'note' => 'Partial coverage; subbin/issue_rowid are varchar in legacy.'],
    'tbl_ecaptive' => ['new_name' => 'external_captives', 'note' => 'tid -> captives.tid.'],
    'tbl_icaptive' => ['new_name' => 'internal_captives'],

    // ---- Discard / excess / gate movements --------------------------------------
    'tbl_discard' => ['new_name' => 'discards'],
    'tbl_discard_sub' => ['new_name' => 'discard_items', 'note' => 'No parent column — linked through discard_slocs.discard_id.'],
    'tbl_discard_sloc' => ['new_name' => 'discard_slocs', 'note' => 'discard_trid -> discards.tid (477/477 verified); discard_id -> discard_items.did.'],
    'tbl_excess' => ['new_name' => 'excesses'],
    'tbl_excess_sub' => ['new_name' => 'excess_items', 'note' => 'esid -> excesses.tid.'],
    'tbl_dtog' => ['new_name' => 'dtogs'],
    'tbl_dtog_sub' => ['new_name' => 'dtog_items', 'note' => 'did -> dtogs.did.'],
    'tbl_gtod' => ['new_name' => 'gtods'],
    'tbl_gtod_sub' => ['new_name' => 'gtod_items', 'note' => 'gid -> gtods.gid.'],
    'tbl_gate' => ['new_name' => 'gate_passes', 'note' => 'trid is a polymorphic varchar — indexed only.'],

    // ---- Item transfers / sloc updation / cycle counts ------------------------------
    'tbl_iitr' => ['new_name' => 'item_transfers'],
    'tbl_iitr_sub' => ['new_name' => 'item_transfer_items', 'note' => 'iitr_id -> item_transfers.iitr_id.'],
    'tbl_sloc' => ['new_name' => 'slocs'],
    'tbl_sloc_sub' => ['new_name' => 'sloc_items', 'note' => 'slocid -> slocs.slid.'],
    'tbl_ci' => ['new_name' => 'cycle_counts', 'note' => 'classification_id/items_id are TEXT in legacy — constraint-ineligible.'],
    'tbl_ciupdation' => ['new_name' => 'cycle_count_updates', 'note' => 'ci_id -> cycle_counts.ci_id.'],

    // ---- Ledgers -----------------------------------------------------------------
    'tbl_stldg_good' => ['new_name' => 'stock_ledger_goods', 'polymorphic' => true, 'note' => 'Running-balance ledger; stlg_trid varies by stlg_trtype/subtype.'],
    'tbl_stldg_damage' => ['new_name' => 'stock_ledger_damages', 'polymorphic' => true],
    'tbl_party_ldg' => ['new_name' => 'party_ledgers', 'polymorphic' => true],

    // ---- Empty/structural registers (kept for compatibility) ---------------------
    'tbl_stock' => ['new_name' => 'stock_headers'],
    'tbl_issuestock' => ['new_name' => 'issue_stock_headers'],
    'tbl_pindents' => ['new_name' => 'pindents'],
    'tbl_ireturn' => ['new_name' => 'internal_returns', 'note' => 'date column is INT in legacy (kept verbatim, flagged).'],
];
