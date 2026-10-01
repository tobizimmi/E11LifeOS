{{-- Recipe PDF: layout and accent from the PDF editor. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $recipe->title }}</title>
    <style>
        @include('recipes.partials.pdf-styles')
    </style>
</head>
<body>
    @include('recipes.partials.pdf-recipe')
</body>
</html>
