<?php

namespace App\Models;

use App\Support\ShoppingAisles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A store of the household (e.g. "REWE Hauptstraße") with its own order of sections, so the
 * list follows the walking route through that store.
 */
#[Fillable(['household_id', 'name', 'chain', 'aisle_order', 'position'])]
class ShoppingStore extends Model
{
    /**
     * Known chains with their weekly flyer page.
     *
     * @var array<string, array{name: string, flyer: string}>
     */
    public const CHAINS = [
        'rewe' => ['name' => 'REWE', 'flyer' => 'https://www.rewe.de/angebote/'],
        'edeka' => ['name' => 'EDEKA', 'flyer' => 'https://www.edeka.de/eh/angebote.jsp'],
        'aldi_sued' => ['name' => 'ALDI SÜD', 'flyer' => 'https://www.aldi-sued.de/de/angebote.html'],
        'aldi_nord' => ['name' => 'ALDI Nord', 'flyer' => 'https://www.aldi-nord.de/angebote.html'],
        'lidl' => ['name' => 'Lidl', 'flyer' => 'https://www.lidl.de/c/online-prospekte/s10005610'],
        'kaufland' => ['name' => 'Kaufland', 'flyer' => 'https://filiale.kaufland.de/angebote/aktuelle-woche.html'],
        'penny' => ['name' => 'PENNY', 'flyer' => 'https://www.penny.de/angebote'],
        'netto' => ['name' => 'Netto Marken-Discount', 'flyer' => 'https://www.netto-online.de/filialangebote'],
        'norma' => ['name' => 'NORMA', 'flyer' => 'https://www.norma-online.de/de/angebote/'],
        'globus' => ['name' => 'Globus', 'flyer' => 'https://www.globus.de/angebote/'],
        'dm' => ['name' => 'dm', 'flyer' => 'https://www.dm.de/angebote'],
        'rossmann' => ['name' => 'Rossmann', 'flyer' => 'https://www.rossmann.de/de/angebote'],
        'mueller' => ['name' => 'Müller', 'flyer' => 'https://www.mueller.de/angebote/'],
        'other' => ['name' => 'Other', 'flyer' => ''],
    ];

    protected function casts(): array
    {
        return [
            'aisle_order' => 'array',
        ];
    }

    /**
     * Section keys in this store's order (sections added later go to the end).
     *
     * @return list<string>
     */
    public function aisleOrder(): array
    {
        $all = array_keys(ShoppingAisles::all());
        $own = array_values(array_intersect($this->aisle_order ?? [], $all));

        return array_values(array_unique([...$own, ...$all]));
    }

    public function chainName(): ?string
    {
        return $this->chain && $this->chain !== 'other' ? (self::CHAINS[$this->chain]['name'] ?? null) : null;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
