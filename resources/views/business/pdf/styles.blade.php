{{-- Shared letter styles of business PDFs ($accent: hex colour). --}}
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.45; margin: 0; }
        .page { padding: 16mm 20mm 36mm 25mm; }
        .letterhead { position: absolute; top: 12mm; right: 20mm; text-align: right; font-size: 8.5pt; color: #4b5563; }
        .letterhead strong { display: block; font-size: 13pt; color: #111827; margin-bottom: 2pt; }
        .window { position: absolute; top: 45mm; left: 25mm; width: 85mm; }
        .sender { font-size: 7pt; color: #6b7280; border-bottom: 0.5pt solid #9ca3af; padding-bottom: 1pt; margin-bottom: 4pt; }
        .recipient { font-size: 10pt; white-space: pre-line; }
        .info { position: absolute; top: 60mm; right: 20mm; width: 65mm; font-size: 8.5pt; }
        .info td { padding: 1pt 0; }
        .info td.label { color: #6b7280; padding-right: 6pt; }
        .body { margin-top: 98mm; }
        h1 { font-size: 15pt; margin: 0 0 8pt; color: {{ $accent }}; }
        .logo { position: absolute; top: 12mm; left: 25mm; max-height: 20mm; max-width: 60mm; }
        .num { text-align: right; white-space: nowrap; }
        .note { margin-top: 10pt; }
        .muted { color: #6b7280; }
        .footer { position: fixed; bottom: 10mm; left: 25mm; right: 20mm; font-size: 7pt; color: #6b7280; border-top: 0.5pt solid #d1d5db; padding-top: 4pt; }
        .footer td { vertical-align: top; width: 33%; padding-right: 6pt; }
