#!/usr/bin/env python3
# Генерация иконок и сплэша Android из лого клуба.
# Фон #0e0e11 (тёмная тема сайта) + белое лого (logo.png).
import os
from PIL import Image, ImageDraw

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO = os.path.dirname(ROOT)
RES = os.path.join(ROOT, "android/app/src/main/res")
LOGO = os.path.join(REPO, "public_html/assets/img/logo.png")  # белое лого, прозрачный фон
BG = (14, 14, 17, 255)  # #0e0e11

logo = Image.open(LOGO).convert("RGBA")

def fit(img, box):
    """Вписать img в квадрат box, сохранив пропорции, вернуть RGBA box×box с центром."""
    canvas = Image.new("RGBA", (box, box), (0, 0, 0, 0))
    w, h = img.size
    scale = min(box / w, box / h)
    nw, nh = max(1, int(w * scale)), max(1, int(h * scale))
    r = img.resize((nw, nh), Image.LANCZOS)
    canvas.paste(r, ((box - nw) // 2, (box - nh) // 2), r)
    return canvas

def rounded_mask(size, radius):
    m = Image.new("L", (size, size), 0)
    d = ImageDraw.Draw(m)
    d.rounded_rectangle([0, 0, size - 1, size - 1], radius=radius, fill=255)
    return m

def circle_mask(size):
    m = Image.new("L", (size, size), 0)
    d = ImageDraw.Draw(m)
    d.ellipse([0, 0, size - 1, size - 1], fill=255)
    return m

# --- Легаси иконки (mipmap-*): полноразмерная плитка ---
legacy = {"mdpi": 48, "hdpi": 72, "xhdpi": 96, "xxhdpi": 144, "xxxhdpi": 192}
# --- Адаптивный foreground: 108dp канвас, лого в безопасной зоне (~58%) ---
fg = {"mdpi": 108, "hdpi": 162, "xhdpi": 216, "xxhdpi": 324, "xxxhdpi": 432}

for dens, sz in legacy.items():
    d = os.path.join(RES, f"mipmap-{dens}")
    os.makedirs(d, exist_ok=True)
    inner = fit(logo, int(sz * 0.66))
    # квадратная (со скруглением)
    tile = Image.new("RGBA", (sz, sz), BG)
    tile.paste(inner, ((sz - inner.width) // 2, (sz - inner.height) // 2), inner)
    tile.putalpha(rounded_mask(sz, int(sz * 0.18)))
    tile.save(os.path.join(d, "ic_launcher.png"))
    # круглая
    rnd = Image.new("RGBA", (sz, sz), BG)
    rnd.paste(inner, ((sz - inner.width) // 2, (sz - inner.height) // 2), inner)
    rnd.putalpha(circle_mask(sz))
    rnd.save(os.path.join(d, "ic_launcher_round.png"))
    # foreground (прозрачный фон, лого меньше — фон даёт адаптивный слой)
    fsz = fg[dens]
    f = Image.new("RGBA", (fsz, fsz), (0, 0, 0, 0))
    li = fit(logo, int(fsz * 0.52))
    f.paste(li, ((fsz - li.width) // 2, (fsz - li.height) // 2), li)
    f.save(os.path.join(d, "ic_launcher_foreground.png"))

# фон адаптивной иконки -> тёмный
with open(os.path.join(RES, "values/ic_launcher_background.xml"), "w", encoding="utf-8") as fh:
    fh.write('<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
             '    <color name="ic_launcher_background">#0e0e11</color>\n</resources>\n')

# --- Сплэш: перегенерировать каждый существующий splash.png в его же размер ---
import glob
count = 0
for path in glob.glob(os.path.join(RES, "**/splash.png"), recursive=True):
    with Image.open(path) as ex:
        w, h = ex.size
    canvas = Image.new("RGBA", (w, h), BG)
    box = int(min(w, h) * 0.34)
    li = fit(logo, box)
    canvas.paste(li, ((w - li.width) // 2, (h - li.height) // 2), li)
    canvas.convert("RGB").save(path)
    count += 1

print(f"icons: {len(legacy)*3} mipmap files, splash: {count} files")
