<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_qr_scan_log (verbatim utility/setup_qrcode_db.php
 * DDL — created here as a proper migration; see docs/PHASE10.md 2.5).
 */
class QrScanLog extends Model
{
    protected $table = 'qr_scan_logs';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class, 'qrcode_id', 'id');
    }
}
