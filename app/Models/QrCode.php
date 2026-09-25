<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy source: tbl_qr_codes (reconstructed — the subsystem never went
 * live; see docs/PHASE10.md 2.4). Column names preserved verbatim from
 * the save_qr_codes.php / save_qr_temp.php INSERTs.
 */
class QrCode extends Model
{
    protected $table = 'qr_codes';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function scans(): HasMany
    {
        return $this->hasMany(QrScanLog::class, 'qrcode_id', 'id');
    }
}
