<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\RecipePdfController;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Support\CalendarEvents;
use App\Support\Navigation;
use App\Support\PdfTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Small system screens of the app: the family screen (kiosk), the member's menu and the PDF design.
 */
class SystemExtrasController extends ApiController
{
    /**
     * The day's agenda and the open shopping list for the wall display.
     */
    public function kiosk(): JsonResponse
    {
        $user = $this->user();
        $household = $user->currentHousehold();
        $today = today();

        if (! $household) {
            return $this->ok(['date' => $today->toDateString(), 'agenda' => [], 'shopping' => []]);
        }

        $agenda = CalendarEvents::between(
            $user,
            $household->id,
            $today->copy()->startOfDay(),
            $today->copy()->endOfDay(),
            [CalendarEvents::TASKS, CalendarEvents::DATES, CalendarEvents::MEALS, CalendarEvents::LIFE, CalendarEvents::EXTERNAL],
        );

        $shopping = ShoppingListEntry::where('household_id', $household->id)
            ->where('checked', false)
            ->whereIn('shopping_list_id', ShoppingList::where('household_id', $household->id)->select('id'))
            ->orderBy('aisle')->orderBy('position')->orderBy('id')
            ->limit(60)->get();

        return $this->ok([
            'date' => $today->toDateString(),
            'agenda' => collect($agenda)->map(fn (array $event): array => [
                'time' => $event['time'] ?? null,
                'title' => (string) $event['title'],
                'overdue' => $event['overdue'],
            ])->values()->all(),
            'shopping' => $shopping->map(fn (ShoppingListEntry $entry): array => [
                'id' => $entry->id,
                'label' => $entry->label,
                'quantity' => $entry->quantity,
            ])->all(),
        ]);
    }

    public function menu(): JsonResponse
    {
        return $this->ok($this->menuData());
    }

    public function menuPreset(Request $request): JsonResponse
    {
        $data = $request->validate(['preset' => ['required', 'in:'.implode(',', array_keys(Navigation::presets()))]]);
        Navigation::applyPreset($this->user(), $data['preset']);

        return $this->ok($this->menuData());
    }

    public function menuToggle(Request $request): JsonResponse
    {
        $data = $request->validate(['module' => ['required', 'string'], 'action' => ['required', 'in:pin,hide']]);
        abort_unless(array_key_exists($data['module'], Navigation::modules($this->user())), 404);

        if ($data['action'] === 'hide') {
            abort_unless(Navigation::isHideable($data['module']), 422);
            Navigation::toggleHidden($this->user(), $data['module']);
        } else {
            Navigation::togglePinned($this->user(), $data['module']);
        }

        return $this->ok($this->menuData());
    }

    public function pdfDesign(): JsonResponse
    {
        return $this->ok($this->designData());
    }

    public function savePdfDesign(Request $request): JsonResponse
    {
        $household = $this->user()->currentHousehold() ?? abort(404);
        $data = $request->validate(['general' => ['nullable', 'array'], 'recipe' => ['nullable', 'array']]);
        PdfTheme::save($household->id, 'general', (array) ($data['general'] ?? []));
        PdfTheme::save($household->id, 'recipe', (array) ($data['recipe'] ?? []));

        return $this->ok($this->designData());
    }

    public function resetPdfDesign(): JsonResponse
    {
        $household = $this->user()->currentHousehold() ?? abort(404);
        PdfTheme::save($household->id, 'general', PdfTheme::defaults('general'));
        PdfTheme::save($household->id, 'recipe', PdfTheme::defaults('recipe'));

        return $this->ok($this->designData());
    }

    /**
     * A real recipe as PDF with the (unsaved) settings of the editor.
     */
    public function pdfPreview(Request $request, int $recipe): Response
    {
        $household = $this->user()->currentHousehold() ?? abort(404);
        $model = Recipe::query()->visibleTo($this->user(), $household->id)->findOrFail($recipe);
        $draft = json_encode([
            'general' => (array) $request->input('general', []),
            'recipe' => (array) $request->input('recipe', []),
        ]);
        $request->query->set('draft', $draft);
        $request->query->set('inline', '1');

        return app(RecipePdfController::class)($request, $model);
    }

    /**
     * @return array<string, mixed>
     */
    private function menuData(): array
    {
        $user = $this->user();
        $preferences = Navigation::preferences($user);
        $titles = Navigation::groups();
        $groupsData = [];

        foreach (Navigation::modules($user) as $key => $module) {
            $groupsData[$module['group']] ??= ['key' => $module['group'], 'title' => $titles[$module['group']] ?? $module['group'], 'modules' => []];
            $groupsData[$module['group']]['modules'][] = [
                'key' => $key,
                'label' => $module['label'],
                'pinned' => in_array($key, $preferences['pinned'], true),
                'hidden' => in_array($key, $preferences['hidden'], true),
                'hideable' => Navigation::isHideable($key),
            ];
        }

        $groupsData = array_values($groupsData);

        return [
            'presets' => collect(Navigation::presets())->map(fn (array $preset, string $key): array => ['key' => $key, 'label' => $preset['label'], 'description' => $preset['description']])->values()->all(),
            'groups' => $groupsData,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function designData(): array
    {
        $user = $this->user();
        $household = $user->currentHousehold() ?? abort(404);

        return [
            'general' => PdfTheme::for($household->id, 'general'),
            'recipe' => PdfTheme::for($household->id, 'recipe'),
            'fonts' => collect(PdfTheme::FONTS)->map(fn (array $font, string $key): array => ['key' => $key, 'label' => $font['label']])->values()->all(),
            'colors' => PdfTheme::COLORS,
            'recipes' => Recipe::query()->visibleTo($user, $household->id)->whereNull('archived_at')
                ->orderByRaw('image_path IS NULL')->orderByDesc('cooked_count')->limit(50)->get(['id', 'title'])
                ->map(fn (Recipe $recipe): array => ['id' => $recipe->id, 'title' => $recipe->title])->all(),
        ];
    }
}
