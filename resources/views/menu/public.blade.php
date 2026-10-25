<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Menú · {{ $business->name }}</title>
    <style>
        :root { --ink:#1c1917; --muted:#78716c; --line:#e7e5e4; --bg:#fafaf9; --card:#fff; --accent:#b45309; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: Georgia, "Times New Roman", serif; background:var(--bg); color:var(--ink); }
        header { padding:1.5rem 1rem 1rem; text-align:center; border-bottom:1px solid var(--line); background:var(--card); }
        header h1 { margin:0; font-size:1.6rem; letter-spacing:.04em; }
        header p { margin:.4rem 0 0; color:var(--muted); font-size:.9rem; font-family:system-ui,sans-serif; }
        main { max-width:40rem; margin:0 auto; padding:1rem 1rem 3rem; }
        h2 { font-size:.8rem; letter-spacing:.14em; text-transform:uppercase; color:var(--accent); margin:1.75rem 0 .6rem; font-family:system-ui,sans-serif; }
        .item { display:flex; gap:.75rem; justify-content:space-between; align-items:flex-start; padding:.75rem 0; border-bottom:1px dashed var(--line); }
        .item h3 { margin:0; font-size:1.05rem; font-weight:600; }
        .item .desc { margin:.25rem 0 0; font-size:.85rem; color:var(--muted); font-family:system-ui,sans-serif; line-height:1.35; }
        .price { white-space:nowrap; font-weight:700; font-family:system-ui,sans-serif; font-size:.95rem; }
        .empty { text-align:center; color:var(--muted); padding:3rem 1rem; font-family:system-ui,sans-serif; }
        footer { text-align:center; padding:2rem 1rem; font-size:.75rem; color:var(--muted); font-family:system-ui,sans-serif; }
    </style>
</head>
<body>
    <header>
        <h1>{{ $business->name }}</h1>
        <p>Menú</p>
    </header>
    <main>
        @if ($categories->isEmpty())
            <p class="empty">El menú se está actualizando.</p>
        @else
            @foreach ($categories as $cat)
                <h2>{{ $cat['name'] }}</h2>
                @foreach ($cat['items'] as $item)
                    <article class="item">
                        <div>
                            <h3>{{ $item['name'] }}</h3>
                            @if ($item['description'])
                                <p class="desc">{{ $item['description'] }}</p>
                            @endif
                        </div>
                        <div class="price">RD$ {{ number_format($item['price'], 2) }}</div>
                    </article>
                @endforeach
            @endforeach
        @endif
    </main>
    <footer>Precios en pesos dominicanos · {{ $business->name }}</footer>
</body>
</html>
