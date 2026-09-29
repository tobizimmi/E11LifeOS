<?php

namespace App\Support;

use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

/**
 * Letters that come up in everyday life (cancelling a contract, objecting to a notice, reporting a new
 * address …): each template fills subject and text from a contract entry and can then be edited
 * before it becomes a PDF ready to print or send.
 */
class LetterTemplates
{
    public const CANCELLATION = 'cancellation';

    public const OBJECTION = 'objection';

    public const ADDRESS_CHANGE = 'address_change';

    public const GENERIC = 'generic';

    /**
     * @return array<string, array{name: string, icon: string}>
     */
    public static function all(): array
    {
        return [
            self::CANCELLATION => ['name' => __('Cancellation'), 'icon' => '✂️'],
            self::OBJECTION => ['name' => __('Objection'), 'icon' => '✋'],
            self::ADDRESS_CHANGE => ['name' => __('Change of address'), 'icon' => '🏡'],
            self::GENERIC => ['name' => __('Blank letter'), 'icon' => '✉️'],
        ];
    }

    /**
     * Subject and text of a template, filled with what is known about the contract.
     *
     * @param  array{contract?: ?string, provider?: ?string, customer_number?: ?string, ends_on?: ?string}  $contract
     * @return array{subject: string, body: string}
     */
    public static function fill(string $template, array $contract = []): array
    {
        $title = trim((string) ($contract['contract'] ?? ''));
        $name = $title ?: __('my contract');
        $number = trim((string) ($contract['customer_number'] ?? ''));
        $reference = $number !== '' ? ' '.__('(customer number :number)', ['number' => $number]) : '';
        $endsOn = ! empty($contract['ends_on']) ? __('on :date', ['date' => Carbon::parse($contract['ends_on'])->format('d.m.Y')]) : __('at the next possible date');

        return match ($template) {
            self::CANCELLATION => [
                'subject' => ($title !== '' ? __('Cancellation: :contract', ['contract' => $title]) : __('Cancellation')).($number !== '' ? ' – '.__('Customer number').' '.$number : ''),
                'body' => self::paragraphs([
                    __('I hereby cancel :contract:reference :ends_on.', ['contract' => $name, 'reference' => $reference, 'ends_on' => $endsOn]),
                    __('Please confirm the cancellation and the date on which the contract ends in writing. I do not consent to being contacted for the purpose of winning me back.'),
                    __('If I have given you a direct debit mandate, it expires when the contract ends.'),
                ]),
            ],
            self::OBJECTION => [
                'subject' => ($title !== '' ? __('Objection: :contract', ['contract' => $title]) : __('Objection')).($number !== '' ? ' – '.__('Customer number').' '.$number : ''),
                'body' => self::paragraphs([
                    __('I hereby object to your letter dated [date] regarding :contract:reference.', ['contract' => $name, 'reference' => $reference]),
                    __('Reason: [please describe]'),
                    __('Please review the matter and send me a corrected version. Until then I will not make any payment based on it.'),
                ]),
            ],
            self::ADDRESS_CHANGE => [
                'subject' => __('New address').($number !== '' ? ' – '.__('Customer number').' '.$number : ''),
                'body' => self::paragraphs([
                    __('please note my new address for :contract:reference as of [date]:', ['contract' => $name, 'reference' => $reference]),
                    __('[new street and number]')."\n".__('[postcode and town]'),
                    __('Please send all future mail to this address.'),
                ]),
            ],
            default => ['subject' => '', 'body' => __('Dear Sir or Madam,')."\n\n"],
        };
    }

    /**
     * The salutation followed by the given paragraphs.
     *
     * @param  list<string>  $paragraphs
     */
    private static function paragraphs(array $paragraphs): string
    {
        return implode("\n\n", [__('Dear Sir or Madam,'), ...$paragraphs]);
    }

    /**
     * Renders the letter as a DIN-5008-style PDF.
     *
     * @param  array{sender: string, recipient: string, place: string, subject: string, body: string}  $letter
     */
    public static function pdf(User $user, array $letter): string
    {
        $html = view('letters.pdf', ['letter' => $letter, 'user' => $user])->render();
        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('DejaVu Sans'));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
