<?php

namespace App\Modules\Visittransfer\Models;

use App\Models\Sys\Token;
use App\Modules\Visittransfer\Exceptions\Application\ReferenceAlreadySubmittedException;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Modules\Ais\Models\Fir
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Modules\Ais\Models\Aerodrome[]  $airfields
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Modules\Ais\Models\Fir\Sector[] $sectors
 */
class Reference extends Model
{

    protected $table      = "vt_reference";
    protected $fillable   = [
        "application_id",
        "account_id",
        "email",
        "relationship",
    ];
    protected $touches    = ["application"];
    public    $timestamps = false;

    const STATUS_DRAFT        = 10;
    const STATUS_REQUESTED    = 30;
    const STATUS_UNDER_REVIEW = 50;
    const STATUS_ACCEPTED     = 90;
    const STATUS_REJECTED     = 95;

    static $REFERENCE_IS_SUBMITTED = [
        self::STATUS_UNDER_REVIEW,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
    ];

    public static function scopePending($query)
    {
        return $query->whereNull("submitted_at");
    }

    public static function scopeStatus($query, $status)
    {
        return $query->where("status", "=", $status);
    }

    public static function scopeStatusIn($query, Array $stati)
    {
        return $query->whereIn("status", $stati);
    }

    public static function scopeDraft($query)
    {
        return $query->status(self::STATUS_DRAFT);
    }

    public static function scopeRequested($query)
    {
        return $query->status(self::STATUS_REQUESTED);
    }

    public static function scopeSubmitted($query)
    {
        return $query->statusIn(self::$REFERENCE_IS_SUBMITTED);
    }

    public static function scopeUnderReview($query)
    {
        return $query->status(self::STATUS_UNDER_REVIEW);
    }

    public static function scopeAccepted($query)
    {
        return $query->status(self::STATUS_ACCEPTED);
    }

    public static function scopeRejected($query)
    {
        return $query->status(self::STATUS_REJECTED);
    }

    public function account()
    {
        return $this->belongsTo(\App\Models\Mship\Account::class);
    }

    public function application()
    {
        return $this->belongsTo(\App\Modules\Visittransfer\Models\Application::class);
    }

    public function tokens()
    {
        return $this->morphOne(Token::class, 'related');
    }

    public function getTokenAttribute()
    {
        return $this->tokens;
    }

    public function getIsSubmittedAttribute()
    {
        return in_array($this->state, self::$REFERENCE_IS_SUBMITTED);
    }

    public function getIsRequestedAttribute()
    {
        return $this->status == self::STATUS_REQUESTED;
    }

    public function getStatusStringAttribute()
    {
        switch ($this->attributes['status']) {
            case self::STATUS_DRAFT:
                return "Draft";
            case self::STATUS_REQUESTED:
                return "Requested";
            case self::STATUS_UNDER_REVIEW:
                return "Under Review";
            case self::STATUS_ACCEPTED:
                return "Accepted";
            case self::STATUS_REJECTED:
                return "Rejected";
        }
    }

    public function generateToken()
    {
        $expiryTimeInMinutes = 1440 * 14; // 14 days

        return Token::generate("visittransfer_reference_request", false, $this, $expiryTimeInMinutes);
    }

    public function submit($referenceContent)
    {
        $this->guardAgainstReSubmittingReference();

        $this->reference = $referenceContent;
        $this->status = self::STATUS_UNDER_REVIEW;
        $this->save();
    }

    private function guardAgainstReSubmittingReference()
    {
        if (!$this->is_requested) {
            throw new ReferenceAlreadySubmittedException($this);
        }
    }
}