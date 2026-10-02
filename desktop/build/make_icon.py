"""Generates desktop/assets/icon.ico + icon.png (red rounded square with a white play triangle).

Run with the desktop virtualenv:  python desktop/build/make_icon.py
"""
import os
import sys
from pathlib import Path

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtCore import QPointF, Qt  # noqa: E402
from PySide6.QtGui import QBrush, QColor, QGuiApplication, QImage, QPainter, QPolygonF  # noqa: E402

SIZE = 256
app = QGuiApplication(sys.argv)
image = QImage(SIZE, SIZE, QImage.Format.Format_ARGB32)
image.fill(Qt.GlobalColor.transparent)
p = QPainter(image)
p.setRenderHint(QPainter.RenderHint.Antialiasing)
p.setPen(Qt.PenStyle.NoPen)
p.setBrush(QBrush(QColor("#d62839")))
p.drawRoundedRect(8, 8, SIZE - 16, SIZE - 16, 52, 52)
p.setBrush(QBrush(QColor("white")))
p.drawPolygon(QPolygonF([QPointF(98, 70), QPointF(98, 186), QPointF(196, 128)]))
p.end()

out = Path(__file__).resolve().parents[1] / "assets"
out.mkdir(exist_ok=True)
assert image.save(str(out / "icon.png"), "PNG")
assert image.save(str(out / "icon.ico"), "ICO")
print("wrote", out / "icon.ico")
