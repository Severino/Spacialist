<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Reference extends Model
{
    use LogsActivity;

    protected $table = 'references';
    /**
     * The attributes that are assignable.
     *
     * @var array
     */
    protected $fillable = [
        'entity_id',
        'attribute_id',
        'bibliography_id',
        'description',
        'user_id',
    ];

    const rules = [
        'bibliography_id' => 'required|integer|exists:bibliography,id',
        'description' => 'required|string'
    ];

    const patchRules = [
        'description' => 'required|string'
    ];

    public function getActivitylogOptions() : LogOptions
{
        return LogOptions::defaults()
            ->logOnly(['id'])
            ->logFillable()
            ->dontLogIfAttributesChangedOnly(['user_id'])
            ->logOnlyDirty();
    }

    public static function add($values, $user) {
        $reference = new self();
        foreach($values as $k => $v) {
            $reference->{$k} = $v;
        }
        $reference->user_id = $user->id;
        $reference->save();

        return self::with('bibliography')->find($reference->id);
    }

    public function patch($values) {
        foreach($values as $k => $v) {
            $this->{$k} = $v;
        }
        $this->save();
    }

    public static function getByEntity($entityId) {
        $references = Reference::with(['attribute', 'bibliography'])->where('entity_id', $entityId)->get();

        $groupedReferences = [];
        foreach($references as $r) {
            if(isset($r->attribute)) {
                $key = $r->attribute->thesaurus_url;
            } else {
                $key = 'on_entity';
            }
            if(!isset($groupedReferences[$key])) {
                $groupedReferences[$key] = [];
            }
            unset($r->attribute);
            $groupedReferences[$key][] = $r;
        }

        return $groupedReferences;
    }

    public function user() {
        return $this->belongsTo('App\User');
    }

    public function entity() {
        return $this->belongsTo('App\Entity');
    }

    public function attribute() {
        return $this->belongsTo('App\Attribute');
    }

    public function bibliography() {
        return $this->belongsTo('App\Bibliography', 'bibliography_id');
    }
    
    public static function parseReferencedFromString($referenceString) {
        $referenceString = trim($referenceString);
        if($referenceString === '') {
            return [];
        }
        $references = explode(';', $referenceString);
        $parsedReferences = [];
        foreach($references as $reference) {
            $parsedReferences[] = static::parseImportReference($reference);
        }
        return $parsedReferences;
    }
    
    public static function parseImportReference($reference) {
        $reference = trim($reference);
        $parts = explode(':', $reference, 2);
        
        $citeKey = trim($parts[0]);
        $citeValue = (count($parts) > 1) ?  trim($parts[1]) : null;

        return [
            'valid' => count($parts) === 2,
            'input' => $reference,
            'citeKey' => $citeKey,
            'description' => $citeValue,
        ];
    }
    
    public static function checkIfCiteKeyIsValid($citeKey, &$bibliographyCache) {
            $bibliographyId = null;
            if(isset($bibliographyCache[$citeKey])) {
                $bibliographyId = $bibliographyCache[$citeKey];
            } else {
                try {
                    $bibliography = Bibliography::where('citekey', $citeKey)->firstOrFail();
                    $bibliographyId = $bibliography->id;
                    $bibliographyCache[$citeKey] = $bibliographyId;
                } catch(ModelNotFoundException $e) {
                    $bibliographyId = null;
                }
            }
            
            return $bibliographyId != null;
    }

    public static function importReferences($referencesString, $entityId, &$bibliographyCache) {
        $user = auth()->user();
        $referenceString = trim($referencesString);
        
        if($referenceString === '') {
            return;
        }
        
        $references = explode(';', $referencesString);

        foreach($references as $referenceString) {
            
            $reference = static::parseImportReference($referenceString);
            $citeKey = $reference['citeKey'];
            $citeValue = $reference['description'];
        
            if($reference['valid'] === false) {
                throw new \Exception("Reference is not in the correct format. It should be in the format 'type:value' given: '$referenceString'.");
            }

            $bibliographyId = null;
            if(isset($bibliographyCache[$citeKey])) {
                $bibliographyId = $bibliographyCache[$citeKey];
            } else {
                try {
                    $bibliography = Bibliography::where('citekey', $citeKey)->firstOrFail();
                    $bibliographyId = $bibliography->id;
                    $bibliographyCache[$citeKey] = $bibliographyId;
                } catch(ModelNotFoundException $e) {
                    throw new \Exception("Bibliography with cite key '$citeKey' does not exist.", );
                }
            }

            Reference::firstOrCreate([
                'entity_id' => $entityId,
                'user_id' => $user->id,
                'attribute_id' => null,
                'bibliography_id' => $bibliographyId,
                'description' => $citeValue,
            ]);
        }
    }
}
