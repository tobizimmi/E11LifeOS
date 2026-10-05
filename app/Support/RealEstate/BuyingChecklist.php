<?php

namespace App\Support\RealEstate;

/**
 * What to look at before buying a property, grouped into the areas that decide whether a purchase
 * is worth it: location, the building, the documents, the money and the contract. Guidance, not
 * legal advice – a surveyor, tax adviser and the notary stay the authority.
 */
class BuyingChecklist
{
    /**
     * @return list<array{title: string, icon: string, items: list<string>}>
     */
    public static function groups(): array
    {
        return [
            [
                'title' => __('Location & market'),
                'icon' => '📍',
                'items' => [
                    __('Micro-location: shops, schools, public transport, noise and the direct neighbourhood – walk the street at different times of day.'),
                    __('Development of the area: jobs, population, planned building projects or roads nearby (ask the city planning office).'),
                    __('Compare the price per m² and the rent with the local rent index and current listings – is the price in line with the market?'),
                    __('Vacancy in the area and how long comparable flats take to let or sell.'),
                ],
            ],
            [
                'title' => __('Building & condition'),
                'icon' => '🏚',
                'items' => [
                    __('Have the building checked by an independent surveyor – not only by the seller\'s or agent\'s people.'),
                    __('Renovation backlog: roof, façade, windows, pipes, electrics, damp and the basement.'),
                    __('Energy certificate: consumption, heating system and its age – a new heating system or insulation can quickly cost five figures.'),
                    __('For a flat: condition of the common parts (stairwell, roof, heating) – you pay your share of those too.'),
                ],
            ],
            [
                'title' => __('Documents'),
                'icon' => '📄',
                'items' => [
                    __('Land register (Grundbuch): owner, mortgages, rights of way and other charges in section II.'),
                    __('For a flat: declaration of division (Teilungserklärung), the last three owners\' meeting minutes and the business plan.'),
                    __('Maintenance reserve (Instandhaltungsrücklage): how much is saved and what is already planned or resolved.'),
                    __('Building encumbrances (Baulasten), floor plans, the building permit and – for a let flat – the current tenancy agreement.'),
                ],
            ],
            [
                'title' => __('Money & financing'),
                'icon' => '💶',
                'items' => [
                    __('Plan the closing costs (transfer tax, notary, land registry, agent) from your own money – banks rarely finance them.'),
                    __('Keep a reserve for repairs and vacancy – do not put every euro into the equity.'),
                    __('Get the financing confirmed before you bid, and compare the follow-up rate after the fixed-interest period.'),
                    __('Check subsidies (KfW, BAFA, state programmes) and apply before signing or starting work.'),
                ],
            ],
            [
                'title' => __('Contract & purchase'),
                'icon' => '✍️',
                'items' => [
                    __('Read the draft purchase contract in good time and have open points changed before the notary appointment.'),
                    __('For a let property: can you really raise the rent, and does the tenant have special rights?'),
                    __('The ten-year speculation period: selling earlier makes the gain taxable (unless you lived in it yourself).'),
                    __('Never let pressure ("another buyer is waiting") push you – a bad purchase costs far more than a missed one.'),
                ],
            ],
        ];
    }
}
