<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\ContractOverview;
use App\Support\LetterTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Letter templates (cancellation, objection, change of address …) for the app: the filled-in text
 * for a template, optionally from a contract, and the finished letter as a PDF.
 */
class LettersController extends ApiController
{
    public function templates(): JsonResponse
    {
        $user = $this->user();

        return $this->ok([
            'templates' => collect(LetterTemplates::all())->map(fn (array $definition, string $key) => ['key' => $key, 'name' => $definition['name'], 'icon' => $definition['icon']])->values()->all(),
            'sender' => (string) ($user->postal_address ?: $user->name),
            'place' => (string) ($user->weather_place ?? ''),
        ]);
    }

    public function fill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template' => ['required', 'in:'.implode(',', array_keys(LetterTemplates::all()))],
            'contract' => ['nullable', 'integer'],
        ]);
        $contract = $this->contract($data['contract'] ?? null);

        return $this->ok($this->filled($data['template'], $contract) + ['recipient' => (string) ($contract['provider'] ?? '')]);
    }

    public function pdf(Request $request): Response
    {
        $data = $request->validate([
            'sender' => ['required', 'string', 'max:500'],
            'recipient' => ['required', 'string', 'max:500'],
            'place' => ['nullable', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'remember_address' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('remember_address', true)) {
            $this->user()->update(['postal_address' => trim($data['sender'])]);
        }

        $pdf = LetterTemplates::pdf($this->user(), [
            'sender' => $data['sender'], 'recipient' => $data['recipient'], 'place' => (string) ($data['place'] ?? ''),
            'subject' => $data['subject'], 'body' => $data['body'],
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.(Str::slug(Str::limit($data['subject'], 60, '')) ?: 'brief').'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $contract
     * @return array{subject: string, body: string}
     */
    private function filled(string $template, ?array $contract): array
    {
        return LetterTemplates::fill($template, $contract ? [
            'contract' => $contract['item']->title,
            'provider' => $contract['provider'],
            'customer_number' => $contract['customer_number'],
            'ends_on' => $contract['ends_on'],
        ] : []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contract(?int $id): ?array
    {
        $household = $this->user()->currentHousehold();

        if (! $id || ! $household) {
            return null;
        }

        return collect(ContractOverview::for($this->user(), $household->id)['contracts'])->first(fn (array $contract) => $contract['item']->id === $id);
    }
}
