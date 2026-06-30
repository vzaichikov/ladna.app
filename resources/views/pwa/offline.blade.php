<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#3B223F">
        <title>Ladna offline</title>
        <style>
            :root {
                color-scheme: light;
                font-family: Manrope, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background: #FAF8F5;
                color: #2B2B2F;
            }

            body {
                min-height: 100vh;
                margin: 0;
                display: grid;
                place-items: center;
                padding: 24px;
                background:
                    radial-gradient(circle at 12% 4%, rgb(231 221 201 / 0.64), transparent 34rem),
                    radial-gradient(circle at 86% 20%, rgb(220 207 240 / 0.68), transparent 32rem),
                    #FAF8F5;
            }

            main {
                width: min(100%, 560px);
                border: 1px solid #E7DDC9;
                border-radius: 16px;
                background: rgb(255 255 255 / 0.86);
                box-shadow: 0 24px 64px rgb(59 34 63 / 0.12);
                padding: 32px;
            }

            .mark {
                width: 56px;
                height: 56px;
                border-radius: 16px;
                display: grid;
                place-items: center;
                background: #3B223F;
                color: white;
                font-size: 28px;
                font-weight: 700;
            }

            h1 {
                margin: 24px 0 12px;
                color: #2B1731;
                font-size: clamp(32px, 7vw, 48px);
                line-height: 1.04;
            }

            p {
                margin: 0;
                color: rgb(77 49 82 / 0.78);
                font-size: 16px;
                line-height: 1.7;
            }

            .uk {
                margin-top: 18px;
                padding-top: 18px;
                border-top: 1px solid #E7DDC9;
            }
        </style>
    </head>
    <body>
        <main>
            <div class="mark" aria-hidden="true">L</div>
            <h1>Ladna is offline</h1>
            <p>Check your internet connection and reload the page. Ladna keeps fresh studio data online, so schedules, bookings, and payments are not cached on this device.</p>
            <p class="uk">Немає з'єднання з інтернетом. Перевірте підключення й оновіть сторінку. Ladna не зберігає робочі дані студії на цьому пристрої без мережі.</p>
        </main>
    </body>
</html>
