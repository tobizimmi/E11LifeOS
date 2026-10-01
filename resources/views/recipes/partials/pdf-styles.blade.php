{{-- Styles of the recipe PDF (single recipe and cookbook); accent, font size and spacing from the PDF editor. --}}
@php
    $accent = $theme['accent'];
    $small = $layout['paper'] === 'A5';
@endphp
@page { margin: {{ $small ? '12mm 12mm 16mm' : '16mm 16mm 20mm' }}; }
body { font-size: {{ $small ? '9.5pt' : '10.5pt' }}; color: #1d1d1f; line-height: 1.5; }
.photo { width: 100%; border-radius: 10pt; margin-bottom: {{ $small ? '10pt' : '16pt' }}; }
.photo-small { width: {{ $small ? '34mm' : '46mm' }}; border-radius: 8pt; }
.eyebrow { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.2pt; color: {{ $accent }}; margin: 0 0 4pt; }
h1 { font-size: {{ $small ? '19pt' : '26pt' }}; line-height: 1.1; letter-spacing: -0.4pt; margin: 0; font-weight: bold; }
.lead { font-size: {{ $small ? '9.5pt' : '11pt' }}; color: #3a3a3c; margin: 8pt 0 0; }
.facts { width: 100%; border-collapse: collapse; margin: {{ $small ? '10pt' : '16pt' }} 0 {{ $small ? '12pt' : '20pt' }}; }
.facts td { padding: 6pt 10pt 6pt 0; border-top: 0.6pt solid #d2d2d7; border-bottom: 0.6pt solid #d2d2d7; vertical-align: top; }
.fact-label { display: block; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.8pt; color: #86868b; }
.fact-value { display: block; font-size: {{ $small ? '9.5pt' : '11pt' }}; font-weight: bold; }
h2 { font-size: 8pt; text-transform: uppercase; letter-spacing: 1.2pt; color: #86868b; margin: 0 0 8pt; font-weight: bold; }
.layout { width: 100%; border-collapse: collapse; }
.layout td { vertical-align: top; }
.ingredients-cell { width: {{ $small ? '40%' : '36%' }}; padding-right: {{ $small ? '12pt' : '18pt' }}; }
.card { background: #f5f5f7; border-radius: 10pt; padding: {{ $small ? '9pt 10pt' : '12pt 14pt' }}; }
.ingredients { width: 100%; border-collapse: collapse; }
.ingredients td { padding: 3.5pt 0; border-bottom: 0.5pt solid #e5e5ea; vertical-align: top; }
.ingredients tr.last td { border-bottom: none; }
.amount { width: 34%; padding-right: 8pt !important; font-weight: bold; white-space: nowrap; }
.group { font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8pt; color: {{ $accent }}; padding-top: 8pt !important; border-bottom: none !important; }
.split { width: 100%; border-collapse: collapse; }
.split td { width: 50%; vertical-align: top; }
.step { width: 100%; border-collapse: collapse; margin-bottom: {{ $small ? '7pt' : '10pt' }}; page-break-inside: avoid; }
.step td { vertical-align: top; }
.step-number { width: {{ $small ? '18pt' : '22pt' }}; }
.badge { width: {{ $small ? '13pt' : '16pt' }}; height: {{ $small ? '13pt' : '16pt' }}; line-height: {{ $small ? '13pt' : '16pt' }}; border-radius: 8pt; background: {{ $accent }}; color: #ffffff; text-align: center; font-size: {{ $small ? '7pt' : '8pt' }}; font-weight: bold; }
.nutrition { width: 100%; border-collapse: separate; border-spacing: 6pt 0; margin: 0 -6pt; }
.nutrition td { background: #f5f5f7; border-radius: 8pt; padding: 7pt 8pt; text-align: center; }
.nutrition-value { display: block; font-size: {{ $small ? '10pt' : '12pt' }}; font-weight: bold; }
.nutrition-label { display: block; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #86868b; }
.section { margin-top: {{ $small ? '14pt' : '20pt' }}; }
.notes-line { border-bottom: 0.6pt solid #d2d2d7; height: {{ $small ? '16pt' : '20pt' }}; }
.meta { margin-top: {{ $small ? '14pt' : '20pt' }}; font-size: 7.5pt; color: #86868b; }
.tag { color: {{ $accent }}; }
