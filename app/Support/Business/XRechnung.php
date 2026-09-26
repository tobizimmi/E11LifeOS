<?php

namespace App\Support\Business;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use XMLWriter;

/**
 * E-invoice in UN/CEFACT CII syntax according to EN 16931 / XRechnung 3.0 (the format German
 * businesses must be able to receive since 2025 and public authorities require).
 */
class XRechnung
{
    private const NS = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100',
    ];

    /**
     * UN/ECE Rec 20 unit codes for common German units.
     */
    private const UNITS = ['std' => 'HUR', 'stunde' => 'HUR', 'h' => 'HUR', 'stk' => 'H87', 'stück' => 'H87', 'tag' => 'DAY', 'tage' => 'DAY', 'km' => 'KMT', 'monat' => 'MON', 'monate' => 'MON', 'pauschal' => 'LS', 'pausch' => 'LS', 'kg' => 'KGM', 'm' => 'MTR', 'm²' => 'MTK', 'qm' => 'MTK', 'l' => 'LTR'];

    public static function cii(Invoice $invoice, BusinessProfile $profile): string
    {
        $invoice->loadMissing('items', 'customer', 'related');
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNs('rsm', 'CrossIndustryInvoice', self::NS['rsm']);

        foreach (['ram', 'udt', 'qdt'] as $prefix) {
            $xml->writeAttribute('xmlns:'.$prefix, self::NS[$prefix]);
        }

        $xml->startElement('rsm:ExchangedDocumentContext');
        self::wrap($xml, 'ram:BusinessProcessSpecifiedDocumentContextParameter', fn () => self::el($xml, 'ram:ID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0'));
        self::wrap($xml, 'ram:GuidelineSpecifiedDocumentContextParameter', fn () => self::el($xml, 'ram:ID', 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0'));
        $xml->endElement();

        $xml->startElement('rsm:ExchangedDocument');
        self::el($xml, 'ram:ID', (string) $invoice->number);
        self::el($xml, 'ram:TypeCode', $invoice->isCreditNote() ? '381' : '380');
        self::wrap($xml, 'ram:IssueDateTime', fn () => self::date($xml, 'udt:DateTimeString', $invoice->issued_on ?? today()));

        foreach (array_filter([$invoice->intro, $invoice->small_business ? __('According to § 19 UStG no VAT is charged.') : null, $invoice->closing]) as $note) {
            self::wrap($xml, 'ram:IncludedNote', fn () => self::el($xml, 'ram:Content', $note));
        }

        $xml->endElement();

        $xml->startElement('rsm:SupplyChainTradeTransaction');

        foreach ($invoice->items as $item) {
            self::line($xml, $invoice, $item);
        }

        // Parties
        $xml->startElement('ram:ApplicableHeaderTradeAgreement');
        self::el($xml, 'ram:BuyerReference', $invoice->customer?->buyer_reference ?: ($invoice->customer_id ? (string) $invoice->customer_id : (string) $invoice->number));
        $xml->startElement('ram:SellerTradeParty');
        self::el($xml, 'ram:Name', $profile->company_name);
        $xml->startElement('ram:DefinedTradeContact');
        self::el($xml, 'ram:PersonName', $profile->owner_name ?: $profile->company_name);

        if ($profile->phone) {
            self::wrap($xml, 'ram:TelephoneUniversalCommunication', fn () => self::el($xml, 'ram:CompleteNumber', $profile->phone));
        }

        if ($profile->email) {
            self::wrap($xml, 'ram:EmailURIUniversalCommunication', fn () => self::el($xml, 'ram:URIID', $profile->email));
        }

        $xml->endElement();
        self::address($xml, $profile->street, $profile->postal_code, $profile->city, 'DE');

        if ($profile->email) {
            self::wrap($xml, 'ram:URIUniversalCommunication', function () use ($xml, $profile) {
                self::el($xml, 'ram:URIID', $profile->email, ['schemeID' => 'EM']);
            });
        }

        if ($profile->tax_number) {
            self::wrap($xml, 'ram:SpecifiedTaxRegistration', fn () => self::el($xml, 'ram:ID', $profile->tax_number, ['schemeID' => 'FC']));
        }

        if ($profile->vat_id) {
            self::wrap($xml, 'ram:SpecifiedTaxRegistration', fn () => self::el($xml, 'ram:ID', $profile->vat_id, ['schemeID' => 'VA']));
        }

        $xml->endElement();

        $customer = $invoice->customer;
        $recipientLines = array_values(array_filter(array_map('trim', explode("\n", $invoice->recipient))));
        $xml->startElement('ram:BuyerTradeParty');
        self::el($xml, 'ram:Name', $customer?->name ?: ($recipientLines[0] ?? '—'));

        if ($customer) {
            self::address($xml, $customer->street, $customer->postal_code, $customer->city, self::countryCode($customer->country));
        } else {
            preg_match('/^(\d{4,5})\s+(.+)$/', (string) end($recipientLines), $zipCity);
            self::address($xml, $recipientLines[1] ?? null, $zipCity[1] ?? null, $zipCity[2] ?? (string) end($recipientLines), 'DE');
        }

        if ($customer?->email) {
            self::wrap($xml, 'ram:URIUniversalCommunication', fn () => self::el($xml, 'ram:URIID', $customer->email, ['schemeID' => 'EM']));
        }

        if ($customer?->vat_id) {
            self::wrap($xml, 'ram:SpecifiedTaxRegistration', fn () => self::el($xml, 'ram:ID', $customer->vat_id, ['schemeID' => 'VA']));
        }

        $xml->endElement();
        $xml->endElement();

        $xml->startElement('ram:ApplicableHeaderTradeDelivery');

        if ($invoice->issued_on) {
            self::wrap($xml, 'ram:ActualDeliverySupplyChainEvent', fn () => self::wrap($xml, 'ram:OccurrenceDateTime', fn () => self::date($xml, 'udt:DateTimeString', $invoice->issued_on)));
        }

        $xml->endElement();

        // Settlement
        $xml->startElement('ram:ApplicableHeaderTradeSettlement');
        self::el($xml, 'ram:PaymentReference', (string) $invoice->number);
        self::el($xml, 'ram:InvoiceCurrencyCode', 'EUR');

        if ($profile->iban) {
            $xml->startElement('ram:SpecifiedTradeSettlementPaymentMeans');
            self::el($xml, 'ram:TypeCode', '58');
            self::wrap($xml, 'ram:PayeePartyCreditorFinancialAccount', function () use ($xml, $profile) {
                self::el($xml, 'ram:IBANID', $profile->iban);
                self::el($xml, 'ram:AccountName', $profile->company_name);
            });

            if ($profile->bic) {
                self::wrap($xml, 'ram:PayeeSpecifiedCreditorFinancialInstitution', fn () => self::el($xml, 'ram:BICID', $profile->bic));
            }

            $xml->endElement();
        }

        foreach (self::taxGroups($invoice) as $group) {
            $xml->startElement('ram:ApplicableTradeTax');
            self::amount($xml, 'ram:CalculatedAmount', $group['vat']);
            self::el($xml, 'ram:TypeCode', 'VAT');

            if ($group['category'] === 'E') {
                self::el($xml, 'ram:ExemptionReason', 'Umsatzsteuerbefreit nach § 19 UStG (Kleinunternehmerregelung)');
            }

            self::amount($xml, 'ram:BasisAmount', $group['net']);
            self::el($xml, 'ram:CategoryCode', $group['category']);
            self::el($xml, 'ram:RateApplicablePercent', self::number($group['rate']));
            $xml->endElement();
        }

        $xml->startElement('ram:SpecifiedTradePaymentTerms');
        self::el($xml, 'ram:Description', $invoice->due_on ? __('Payable by :date without deduction.', ['date' => $invoice->due_on->format('d.m.Y')]) : __('Payable immediately without deduction.'));

        if ($invoice->due_on) {
            self::wrap($xml, 'ram:DueDateDateTime', fn () => self::date($xml, 'udt:DateTimeString', $invoice->due_on));
        }

        $xml->endElement();

        $xml->startElement('ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        self::amount($xml, 'ram:LineTotalAmount', $invoice->net_total);
        self::amount($xml, 'ram:TaxBasisTotalAmount', $invoice->net_total);
        self::amount($xml, 'ram:TaxTotalAmount', $invoice->vat_total, ['currencyID' => 'EUR']);
        self::amount($xml, 'ram:GrandTotalAmount', $invoice->gross_total);
        self::amount($xml, 'ram:TotalPrepaidAmount', $invoice->paid_total);
        self::amount($xml, 'ram:DuePayableAmount', $invoice->outstanding());
        $xml->endElement();

        if ($invoice->isCreditNote() && $invoice->related) {
            self::wrap($xml, 'ram:InvoiceReferencedDocument', function () use ($xml, $invoice) {
                self::el($xml, 'ram:IssuerAssignedID', (string) $invoice->related->number);

                if ($invoice->related->issued_on) {
                    self::wrap($xml, 'ram:FormattedIssueDateTime', fn () => self::date($xml, 'qdt:DateTimeString', $invoice->related->issued_on));
                }
            });
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private static function line(XMLWriter $xml, Invoice $invoice, InvoiceItem $item): void
    {
        [$category, $rate] = self::category($invoice, $item->vat_rate);
        $netPrice = round($item->unit_price * (1 - $item->discount_percent / 100), 4);

        $xml->startElement('ram:IncludedSupplyChainTradeLineItem');
        self::wrap($xml, 'ram:AssociatedDocumentLineDocument', fn () => self::el($xml, 'ram:LineID', (string) $item->position));
        self::wrap($xml, 'ram:SpecifiedTradeProduct', fn () => self::el($xml, 'ram:Name', mb_substr(strtok($item->description, "\n") ?: $item->description, 0, 250)));
        self::wrap($xml, 'ram:SpecifiedLineTradeAgreement', fn () => self::wrap($xml, 'ram:NetPriceProductTradePrice', fn () => self::el($xml, 'ram:ChargeAmount', self::number($netPrice, 4))));
        self::wrap($xml, 'ram:SpecifiedLineTradeDelivery', fn () => self::el($xml, 'ram:BilledQuantity', self::number($item->quantity, 4), ['unitCode' => self::unitCode($item->unit)]));
        $xml->startElement('ram:SpecifiedLineTradeSettlement');
        self::wrap($xml, 'ram:ApplicableTradeTax', function () use ($xml, $category, $rate) {
            self::el($xml, 'ram:TypeCode', 'VAT');
            self::el($xml, 'ram:CategoryCode', $category);
            self::el($xml, 'ram:RateApplicablePercent', self::number($rate));
        });
        self::wrap($xml, 'ram:SpecifiedTradeSettlementLineMonetarySummation', fn () => self::amount($xml, 'ram:LineTotalAmount', $item->lineTotal()));
        $xml->endElement();
        $xml->endElement();
    }

    /**
     * @return array<int, array{category: string, rate: float, net: float, vat: float}>
     */
    private static function taxGroups(Invoice $invoice): array
    {
        return $invoice->items->groupBy(fn (InvoiceItem $item) => implode('|', self::category($invoice, $item->vat_rate)))
            ->map(function ($items, string $key) {
                [$category, $rate] = explode('|', $key);
                $net = round($items->sum(fn (InvoiceItem $item) => $item->lineTotal()), 2);

                return ['category' => $category, 'rate' => (float) $rate, 'net' => $net, 'vat' => round($net * (float) $rate / 100, 2)];
            })->values()->all();
    }

    /**
     * @return array{0: string, 1: float} VAT category (S standard, Z zero rate, E exempt small business) and rate.
     */
    private static function category(Invoice $invoice, float $rate): array
    {
        return match (true) {
            $invoice->small_business => ['E', 0.0],
            $rate > 0 => ['S', $rate],
            default => ['Z', 0.0],
        };
    }

    private static function unitCode(?string $unit): string
    {
        return self::UNITS[rtrim(mb_strtolower(trim((string) $unit)), '.')] ?? 'C62';
    }

    private static function countryCode(?string $country): string
    {
        $country = mb_strtolower(trim((string) $country));

        return match (true) {
            $country === '' || in_array($country, ['deutschland', 'germany', 'de'], true) => 'DE',
            in_array($country, ['österreich', 'austria', 'at'], true) => 'AT',
            in_array($country, ['schweiz', 'switzerland', 'ch'], true) => 'CH',
            strlen($country) === 2 => strtoupper($country),
            default => 'DE',
        };
    }

    private static function address(XMLWriter $xml, ?string $street, ?string $postalCode, ?string $city, string $country): void
    {
        $xml->startElement('ram:PostalTradeAddress');

        if ($postalCode) {
            self::el($xml, 'ram:PostcodeCode', $postalCode);
        }

        if ($street) {
            self::el($xml, 'ram:LineOne', $street);
        }

        if ($city) {
            self::el($xml, 'ram:CityName', $city);
        }

        self::el($xml, 'ram:CountryID', $country);
        $xml->endElement();
    }

    private static function wrap(XMLWriter $xml, string $name, callable $content): void
    {
        $xml->startElement($name);
        $content();
        $xml->endElement();
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private static function el(XMLWriter $xml, string $name, string $value, array $attributes = []): void
    {
        $xml->startElement($name);

        foreach ($attributes as $attribute => $attributeValue) {
            $xml->writeAttribute($attribute, $attributeValue);
        }

        $xml->text($value);
        $xml->endElement();
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private static function amount(XMLWriter $xml, string $name, float $value, array $attributes = []): void
    {
        self::el($xml, $name, self::number($value), $attributes);
    }

    private static function date(XMLWriter $xml, string $name, \DateTimeInterface $date): void
    {
        self::el($xml, $name, $date->format('Ymd'), ['format' => '102']);
    }

    private static function number(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
