<?php

namespace App\Support\Mail;

/**
 * The actions a mail-list swipe can trigger (Spark-style): each maps to a component method, a label,
 * an icon, the colour shown behind the row and whether the row animates away after it (archive/trash
 * leave the current folder, read/flag stay). "none" disables that swipe direction.
 */
class MailSwipeActions
{
    public const DEFAULT_RIGHT = 'flag';

    public const DEFAULT_LEFT = 'archive';

    /**
     * @return array<string, array{method: string, label: string, icon: string, bg: string, leave: bool}>
     */
    public static function all(): array
    {
        return [
            'archive' => ['method' => 'archive', 'label' => __('Archive'), 'icon' => '🗄', 'bg' => 'bg-amber-500', 'leave' => true],
            'trash' => ['method' => 'trash', 'label' => __('Delete'), 'icon' => '🗑', 'bg' => 'bg-red-600', 'leave' => true],
            'read' => ['method' => 'toggleRead', 'label' => __('Read / unread'), 'icon' => '✉️', 'bg' => 'bg-indigo-500', 'leave' => false],
            'flag' => ['method' => 'toggleFlag', 'label' => __('Flag'), 'icon' => '⭐', 'bg' => 'bg-yellow-500', 'leave' => false],
            'none' => ['method' => '', 'label' => __('None'), 'icon' => '', 'bg' => 'bg-gray-400', 'leave' => false],
        ];
    }

    /**
     * @return array{method: string, label: string, icon: string, bg: string, leave: bool}
     */
    public static function resolve(?string $key): array
    {
        $all = self::all();

        return $all[$key] ?? $all['none'];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }
}
